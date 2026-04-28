<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\AiServiceClient;
use App\Services\ChatStarterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly AiServiceClient $ai,
        private readonly ChatStarterService $starters,
    ) {}

    /**
     * Send a chat message. Persists the user message even when AI is down so
     * we don't lose the user's input — when the AI service comes online,
     * a follow-up call can replay missing assistant replies.
     *
     * Returns SSE stream when ai-service is wired; falls back to 503 JSON
     * when AiServiceUnavailableException fires (no base_url / timeout / 5xx).
     */
    public function message(Request $request): Response
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:4000'],
            'scenario' => ['nullable', 'string', 'max:64'],
            'session_id' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();

        // Always persist the user message — that's the user's data, not the
        // assistant's. Lossless even if AI is down.
        $userMsg = Conversation::create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $data['content'],
            'scenario' => $data['scenario'] ?? null,
        ]);

        $sessionId = (string) ($data['session_id'] ?? ('conv-'.$userMsg->id));

        try {
            return $this->ai->chatStream(
                user: $user,
                message: $data['content'],
                sessionId: $sessionId,
                history: [],
                scenario: $data['scenario'] ?? null,
            );
        } catch (AiServiceUnavailableException $e) {
            return new JsonResponse([
                'error_code' => $e->errorCode,
                'message' => 'AI 陪跑服務暫時不可用，你的訊息已保留',
            ], 503);
        }
    }

    public function starters(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'welcome' => $this->starters->welcome($user),
            'starters' => $this->starters->starters($user),
        ]);
    }
}
