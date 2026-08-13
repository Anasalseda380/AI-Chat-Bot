<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string',
            'messages.*.content' => 'present',
            'temperature' => 'required|numeric|min:0|max:2',
        ]);

        return response()->stream(function () use ($validated) {
            $this->streamChat(
                $validated['messages'],
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
        return [
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
                    'error' =>
                        "Search request failed (HTTP {$response->status()})"
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
                'error' =>
                    'Search request failed.'
            ];
        }
    }
}