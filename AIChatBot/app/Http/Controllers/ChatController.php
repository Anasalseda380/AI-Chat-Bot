<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    public function chat(Request $request)
    {
        // Validate user message
        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);


        // Send message to OpenRouter
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://openrouter.ai/api/v1/chat/completions', [

            'model' => 'openrouter/free',

            'messages' => [
                [
                    'role' => 'user',
                    'content' => $validated['message'],
                ]
            ]

        ]);


        // Return AI response
        return response()->json([
            'reply' => $response['choices'][0]['message']['content']
        ]);
    }
}