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
            $this->streamChat($validated['messages'], $validated['temperature']);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function streamChat(array $messages, float $temperature)
    {
        $response = Http::withOptions(['stream' => true])->timeout(0)
            ->withHeaders([
                'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                'Content-Type' => 'application/json',])

            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
                'messages' => $messages,
                'temperature' => $temperature,
                'reasoning' => ['enabled' => true],
                'tools' => $this->tools(),
                'tool_choice' => 'auto',
                'stream' => true,
            ]);

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $toolCalls = [];

        while (!$body->eof()) {
            if (connection_aborted()) {
                break;
            }

            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                if (!str_starts_with($line, 'data: ') || trim(substr($line, 6)) === '[DONE]') {
                    continue;
                }

                $delta = json_decode(substr($line, 6), true)['choices'][0]['delta'] ?? [];

                // FIX: use isset()/!== '' instead of !empty() — empty("0") is true in PHP,
                // which was silently dropping any delta chunk whose content/reasoning
                // was the single character "0" (e.g. streaming "2026" digit-by-digit
                // could lose the "0" and render as "226").
                $hasContent = isset($delta['content']) && $delta['content'] !== '';
                $hasReasoning = isset($delta['reasoning']) && $delta['reasoning'] !== '';

                if ($hasContent || $hasReasoning) {
                    echo $line . "\n\n"; // forwarded exactly as received — content/reasoning untouched
                    @ob_flush();
                    @flush();
                }

                foreach ($delta['tool_calls'] ?? [] as $tc) {
                    $i = $tc['index'];
                    $toolCalls[$i]['id'] ??= $tc['id'] ?? null;
                    $toolCalls[$i]['function']['name'] = ($toolCalls[$i]['function']['name'] ?? '') . ($tc['function']['name'] ?? '');
                    $toolCalls[$i]['function']['arguments'] = ($toolCalls[$i]['function']['arguments'] ?? '') . ($tc['function']['arguments'] ?? '');
                }
            }
        }
        $body->close();

        if (!empty($toolCalls)) {
            $this->runTools($messages, array_values($toolCalls), $temperature);
        } else {
            echo "data: [DONE]\n\n";
            @ob_flush();
            @flush();
        }
    }

    private function runTools(array $messages, array $toolCalls, float $temperature)
    {
        $messages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => array_map(fn ($t) => ['id' => $t['id'], 'type' => 'function', 'function' => $t['function']], $toolCalls),
        ];

        foreach ($toolCalls as $t) {
            // status event so the frontend can show "Using web_search..." during the pause
            echo "data: " . json_encode(['tool_status' => $t['function']['name']]) . "\n\n";
            @ob_flush();
            @flush();

            $args = json_decode($t['function']['arguments'], true) ?? [];
            $result = match ($t['function']['name']) {
                'web_search' => $this->webSearch($args['query'] ?? ''),
                'get_weather' => $this->weather($args['location'] ?? ''),
                'calculator' => $this->calculate($args['expression'] ?? ''),
                default => 'Unknown tool',
            };

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $t['id'],
                'name' => $t['function']['name'],
                'content' => is_string($result) ? $result : json_encode($result),
            ];
        }

        $this->streamChat($messages, $temperature); // recurse to stream the final answer
    }

    private function tools(): array
    {
        return [
            ['type' => 'function', 'function' => [
                'name' => 'web_search',
                'description' => 'Search the web for current information.',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'get_weather',
                'description' => 'Get current weather for a location.',
                'parameters' => ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'calculator',
                'description' => 'Evaluate a math expression.',
                'parameters' => ['type' => 'object', 'properties' => ['expression' => ['type' => 'string']], 'required' => ['expression']],
            ]],
        ];
    }

    private function calculate(string $expr): string
    {
        if (!preg_match('/^[0-9+\-*\/().\s]+$/', $expr)) return 'Error: invalid expression';
        try {
            $parser = new \MathParser\StdMathParser();
            return (string) $parser->parse($expr)->accept(new \MathParser\Interpreting\Evaluator());
        } catch (\Exception $e) {
            return 'Error: could not evaluate';
        }
    }

    private function weather(string $location): array
    {
        $geo = Http::get('https://geocoding-api.open-meteo.com/v1/search', ['name' => $location])->json();
        if (empty($geo['results'])) return ['error' => 'Location not found'];
        ['latitude' => $lat, 'longitude' => $lon] = $geo['results'][0];
        return Http::get('https://api.open-meteo.com/v1/forecast', [
            'latitude' => $lat, 'longitude' => $lon, 'current_weather' => true,
        ])->json()['current_weather'] ?? ['error' => 'Weather unavailable'];
    }

    private function webSearch(string $query): array
    {
        return Http::withHeaders(['X-API-KEY' => env('SERPER_API_KEY')])
            ->post('https://google.serper.dev/search', ['q' => $query])
            ->json()['organic'] ?? [];
    }
}