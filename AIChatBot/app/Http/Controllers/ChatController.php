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
            'messages.*.content' => 'required',
            'temperature' => 'required|numeric|min:0|max:2',
        ]);

        return response()->stream(function () use ($validated) {
            $response = Http::withOptions(['stream' => true])->timeout(0)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',])
                    
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
                    'messages' => $validated['messages'],
                    'temperature' => $validated['temperature'],
                    'reasoning' => ['enabled' => true],
                    'stream' => true,
                ]);

            $body = $response->toPsrResponse()->getBody();

           while (!$body->eof()) {
                if (connection_aborted()) {
                    break;
                }

                echo $body->read(1024);
                @ob_flush();
                @flush();
            }

            $body->close();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}