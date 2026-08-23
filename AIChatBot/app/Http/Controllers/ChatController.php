<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    /**
     * Folder (relative to resource_path()) that holds one *.md file per
     * skill. To add a new skill, drop a new .md file in there — nothing
     * else in this controller needs to change.
     */
    private const SKILLS_DIR = 'skills';

    /**
     * Small always-on nudge telling the model that a use_skill tool
     * exists. The actual catalog (slug + description for every skill)
     * lives inside the use_skill tool's own description — see
     * skillTool() — because tool definitions are sent to OpenRouter on
     * every single request regardless (the completions API is
     * stateless), so that's the one place we only have to write the
     * catalog once and have it reliably reach the model on every turn.
     */
    private const SKILL_TOOL_HINT =
        'You have access to a use_skill tool that loads detailed, '
        . 'specialized instructions for certain topics. Check its '
        . 'description for the list of available skills. If the user\'s '
        . 'request clearly matches one, call use_skill with that skill\'s '
        . 'exact slug before answering. If nothing matches, just answer '
        . 'normally.';

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string',
            'messages.*.content' => 'present',
            'temperature' => 'required|numeric|min:0|max:2',
            'system_prompt' => 'nullable|string',
        ]);

        $messages = $validated['messages'];

        $systemParts = [self::SKILL_TOOL_HINT];

        if (trim($validated['system_prompt'] ?? '') !== '') {
            $systemParts[] = trim($validated['system_prompt']);
        }

        $messages = array_merge(
            [
                [
                    'role' => 'system',
                    'content' => implode("\n\n", $systemParts),
                ],
            ],
            $messages
        );

        return response()->stream(function () use ($messages, $validated) {
            $this->streamChat(
                $messages,
                $validated['temperature']
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function streamChat(
        array $messages,
        float $temperature,
        int $toolDepth = 0
    ): void {

        // Prevent infinite tool-calling loops
        if ($toolDepth > 5) {
            echo "data: " . json_encode([
                'error' => 'Maximum tool-call depth reached.'
            ]) . "\n\n";

            echo "data: [DONE]\n\n";

            @ob_flush();
            @flush();

            return;
        }

        $response = Http::withOptions([
            'stream' => true,
        ])
            ->timeout(0)
            ->withHeaders([
                'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
                'messages' => $messages,
                'temperature' => $temperature,
                'reasoning' => [
                    'enabled' => true,
                ],
                'tools' => $this->tools(),
                'tool_choice' => 'auto',
                'stream' => true,
            ]);

        if ($response->failed()) {
            echo "data: " . json_encode([
                'error' => 'OpenRouter request failed.',
                'status' => $response->status(),
            ]) . "\n\n";

            echo "data: [DONE]\n\n";

            @ob_flush();
            @flush();

            return;
        }

        $body = $response->toPsrResponse()->getBody();

        $buffer = '';
        $toolCalls = [];

        while (!$body->eof()) {
            if (connection_aborted()) {
                break;
            }

            $chunk = $body->read(1024);

            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = trim(substr($line, 6));

                if ($data === '[DONE]') {
                    continue;
                }

                $decoded = json_decode($data, true);

                if (!is_array($decoded)) {
                    continue;
                }

                $delta = $decoded['choices'][0]['delta'] ?? [];

                $hasContent =
                    isset($delta['content']) &&
                    $delta['content'] !== '';

                $hasReasoning =
                    isset($delta['reasoning']) &&
                    $delta['reasoning'] !== '';

                if ($hasContent || $hasReasoning) {
                    echo $line . "\n\n";

                    @ob_flush();
                    @flush();
                }

                foreach ($delta['tool_calls'] ?? [] as $tc) {
                    $index = $tc['index'] ?? 0;

                    $toolCalls[$index]['id'] ??=
                        $tc['id'] ?? null;

                    $toolCalls[$index]['type'] =
                        'function';

                    $toolCalls[$index]['function']['name'] =
                        ($toolCalls[$index]['function']['name'] ?? '') .
                        ($tc['function']['name'] ?? '');

                    $toolCalls[$index]['function']['arguments'] =
                        ($toolCalls[$index]['function']['arguments'] ?? '') .
                        ($tc['function']['arguments'] ?? '');
                }
            }
        }

        $body->close();

        if (!empty($toolCalls)) {
            $this->runTools(
                $messages,
                array_values($toolCalls),
                $temperature,
                $toolDepth
            );

            return;
        }

        echo "data: [DONE]\n\n";

        @ob_flush();
        @flush();
    }

    private function runTools(
        array $messages,
        array $toolCalls,
        float $temperature,
        int $toolDepth
    ): void {
        $messages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => array_map(
                fn ($tool) => [
                    'id' => $tool['id'],
                    'type' => 'function',
                    'function' => $tool['function'],
                ],
                $toolCalls
            ),
        ];

        foreach ($toolCalls as $tool) {
            echo "data: " . json_encode([
                'tool_status' => $tool['function']['name']
            ]) . "\n\n";

            @ob_flush();
            @flush();

            $args = json_decode(
                $tool['function']['arguments'],
                true
            ) ?? [];

            $result = match ($tool['function']['name']) {
                'web_search' => $this->webSearch(
                    $args['query'] ?? ''
                ),

                'get_weather' => $this->weather(
                    $args['location'] ?? ''
                ),

                'calculator' => $this->calculate(
                    $args['expression'] ?? ''
                ),

                'use_skill' => $this->useSkill(
                    $args['skill'] ?? ''
                ),

                default => 'Unknown tool',
            };

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tool['id'],
                'name' => $tool['function']['name'],
                'content' => is_string($result)
                    ? $result
                    : json_encode($result),
            ];
        }

        $this->streamChat(
            $messages,
            $temperature,
            $toolDepth + 1
        );
    }

    private function tools(): array
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' =>
                        'Search the web for current information.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' =>
                                    'The search query.',
                            ],
                        ],
                        'required' => ['query'],
                        'additionalProperties' => false,
                    ],
                ],
            ],

            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' =>
                        'Get current weather for a location.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => [
                                'type' => 'string',
                                'description' =>
                                    'City or location name.',
                            ],
                        ],
                        'required' => ['location'],
                        'additionalProperties' => false,
                    ],
                ],
            ],

            [
                'type' => 'function',
                'function' => [
                    'name' => 'calculator',
                    'description' =>
                        'Evaluate a mathematical expression.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'expression' => [
                                'type' => 'string',
                                'description' =>
                                    'Mathematical expression to evaluate.',
                            ],
                        ],
                        'required' => ['expression'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        $skillTool = $this->skillTool();

        if ($skillTool) {
            $tools[] = $skillTool;
        }

        return $tools;
    }

    /**
     * Build the use_skill tool definition. Its description carries the
     * full skill catalog (slug + description for every skill found on
     * disk) — this is what lets the model decide, on its own, whether a
     * given user message matches a skill, without us doing any keyword
     * matching server-side anymore.
     */
    private function skillTool(): ?array
    {
        $skills = $this->loadSkills();

        if (empty($skills)) {
            return null;
        }

        $catalog = collect($skills)
            ->map(fn ($skill) => "- {$skill['slug']}: {$skill['description']}")
            ->implode("\n");

        return [
            'type' => 'function',
            'function' => [
                'name' => 'use_skill',
                'description' =>
                    "Load detailed instructions for a specialized skill "
                    . "before answering. Available skills:\n\n"
                    . $catalog
                    . "\n\nCall this once with the exact skill slug when "
                    . "the user's request clearly matches one of the "
                    . "skills above. Do not call it otherwise.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'skill' => [
                            'type' => 'string',
                            'description' =>
                                'The exact slug of the skill to load.',
                            'enum' => array_keys($skills),
                        ],
                    ],
                    'required' => ['skill'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Return the instructions for one skill — this is what gets sent
     * back to the model as the tool result after it calls use_skill.
     */
    private function useSkill(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '') {
            \Log::info('use_skill: empty slug received');
            return 'Error: no skill slug provided.';
        }

        $skills = $this->loadSkills();

        if (!isset($skills[$slug])) {
            \Log::warning('use_skill: unknown slug', [
                'slug' => $slug,
                'available' => array_keys($skills),
            ]);

            return "Error: unknown skill '{$slug}'. Available skills: "
                . implode(', ', array_keys($skills));
        }

        \Log::info('use_skill: loaded', ['slug' => $slug]);

        return $skills[$slug]['instructions'];
    }

    /**
     * Load every *.md file under resources/skills, parse its frontmatter
     * (name, description) and body (instructions), and key the result by
     * filename slug. To add a new skill, just drop a new .md file in
     * that folder — no code changes needed here.
     */
    private function loadSkills(): array
    {
        $dir = resource_path(self::SKILLS_DIR);

        if (!is_dir($dir)) {
            return [];
        }

        $skills = [];

        foreach (glob($dir . '/*.md') as $path) {
            $skill = $this->parseSkillFile($path);

            if ($skill) {
                $skills[$skill['slug']] = $skill;
            }
        }

        return $skills;
    }

    /**
     * Parse a single skill .md file. Expects:
     *
     *   ---
     *   name: some_name
     *   description: One line describing when this skill applies.
     *   ---
     *
     *   Free-form instructions body...
     *
     * Returns null if the file doesn't have a valid frontmatter block or
     * is missing a description.
     */
    private function parseSkillFile(string $path): ?array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $raw, $m)) {
            return null;
        }

        $meta = [];

        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([a-zA-Z0-9_]+):\s*(.*)$/', trim($line), $lm)) {
                $meta[$lm[1]] = trim($lm[2]);
            }
        }

        if (empty($meta['description'])) {
            return null;
        }

        return [
            'slug' => pathinfo($path, PATHINFO_FILENAME),
            'name' => $meta['name'] ?? pathinfo($path, PATHINFO_FILENAME),
            'description' => $meta['description'],
            'instructions' => trim($m[2]),
        ];
    }

    private function calculate(string $expr): string
    {
        if (!preg_match('/^[0-9+\-*\/().\s]+$/', $expr)) {
            return 'Error: invalid expression';
        }

        try {
            $parser = new \MathParser\StdMathParser();

            return (string) $parser
                ->parse($expr)
                ->accept(
                    new \MathParser\Interpreting\Evaluator()
                );
        } catch (\Throwable $e) {
            return 'Error: could not evaluate';
        }
    }

    private function weather(string $location): array
    {
        if (trim($location) === '') {
            return [
                'error' => 'Location is required.'
            ];
        }

        try {
            $geo = Http::timeout(10)
                ->get(
                    'https://geocoding-api.open-meteo.com/v1/search',
                    [
                        'name' => $location,
                    ]
                )
                ->json();

            if (empty($geo['results'])) {
                return [
                    'error' => 'Location not found'
                ];
            }

            [
                'latitude' => $lat,
                'longitude' => $lon
            ] = $geo['results'][0];

            return Http::timeout(10)
                ->get(
                    'https://api.open-meteo.com/v1/forecast',
                    [
                        'latitude' => $lat,
                        'longitude' => $lon,
                        'current_weather' => true,
                    ]
                )
                ->json()['current_weather']
                ?? [
                    'error' => 'Weather unavailable'
                ];
        } catch (\Throwable $e) {
            return [
                'error' => 'Weather request failed.'
            ];
        }
    }

    private function webSearch(string $query): array
    {
        if (trim($query) === '') {
            return [
                'error' =>
                    'Empty search query received from model'
            ];
        }

        $apiKey = env('SERPER_API_KEY');

        if (empty($apiKey)) {
            return [
                'error' =>
                    'Search is not configured (missing API key)'
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->post(
                    'https://google.serper.dev/search',
                    [
                        'q' => $query,
                    ]
                );

            if ($response->failed()) {
                return [
                    'error' => 'Search request failed',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ];
            }

            $data = $response->json();

            $results = $data['organic'] ?? [];

            if (
                empty($results) &&
                !empty($data['answerBox'])
            ) {
                $results = [
                    $data['answerBox']
                ];
            }

            if (
                empty($results) &&
                !empty($data['knowledgeGraph'])
            ) {
                $results = [
                    $data['knowledgeGraph']
                ];
            }

            return $results ?: [
                'error' => 'No search results found',
                'query' => $query,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'Search request failed',
                'exception' => $e->getMessage(),
            ];
        }
    }
}