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
            'messages.*.content' => 'required|string',
            'temperature'=>'required|numeric|min:0|max:2',
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://openrouter.ai/api/v1/chat/completions', [

            'model' => 'openrouter/free',

            'messages' => $validated['messages'],
            'temperature'=>$validated['temperature']

        ]);

        if (!$response->successful()) {
            return response()->json([
                'reply' => 'Error communicating with the AI service.',
                'error' => $response->body(),
            ], 500);
        }

        return response()->json([
            'reply' => $response['choices'][0]['message']['content'],
        ]);
    }
}