<?php

namespace App\Services;

use App\Exceptions\AiServiceUnavailableException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HTTP client for the Dodo Python AI microservice (ADR-002 §3).
 *
 * Auth model (Phase B):
 *   - dodo backend doesn't yet hold a per-user Pandora Core JWT (ADR-007 Phase 5
 *     not done), so we use the same internal-secret pattern as
 *     ConversionEventPublisher: ``X-Internal-Secret`` header + per-request
 *     ``X-Pandora-User-Uuid`` to attribute usage and cost.
 *   - Phase F switches to real Bearer JWT pass-through; this client only needs
 *     to swap the header at that point.
 *
 * Failure model:
 *   - ``base_url`` / ``shared_secret`` not set => throw AiServiceUnavailableException
 *     (controllers convert to 503). Lets dev / Phase A run without the service up.
 *   - 5xx / connect / timeout => single retry, then throw AiServiceUnavailableException.
 *   - 4xx (validation / safety / 429) => throw AiServiceUnavailableException with
 *     a descriptive errorCode so controllers can keep the 503 surface uniform.
 */
class AiServiceClient
{
    /**
     * Scan a meal photo via URL. Downloads the image, then forwards bytes to
     * POST /v1/vision/recognize as multipart.
     *
     * Used by admin / server-side flows that already have a stored URL. The
     * frontend mobile/web flow uses {@see scanMealFromBytes} (base64 → bytes).
     *
     * @param  array<string, mixed>  $context  optional per-meal hints (meal_type etc.)
     * @return array<string, mixed>
     */
    public function scanMeal(User $user, string $imageUrl, array $context = []): array
    {
        $this->ensureEnabled();

        // Download image — short timeout, separate from the AI call budget.
        try {
            $imageResponse = Http::timeout(10)->retry(1, 200, throw: false)->get($imageUrl);
        } catch (ConnectionException $e) {
            throw new AiServiceUnavailableException('IMAGE_FETCH_FAILED', $e->getMessage());
        }
        if (! $imageResponse->successful()) {
            throw new AiServiceUnavailableException('IMAGE_FETCH_FAILED', 'image url returned '.$imageResponse->status());
        }
        $bytes = (string) $imageResponse->body();
        $contentType = (string) ($imageResponse->header('Content-Type') ?: 'image/jpeg');

        return $this->postVisionRecognize($user, $bytes, $contentType, $context);
    }

    /**
     * Scan a meal photo from in-memory bytes (e.g. base64 from mobile camera).
     *
     * This is the fast path — the Python service expects multipart anyway, so
     * skipping a round-trip self-download cuts latency + avoids a SSRF surface.
     *
     * @param  array<string, mixed>  $context  optional per-meal hints (meal_type etc.)
     * @return array<string, mixed>
     */
    public function scanMealFromBytes(
        User $user,
        string $bytes,
        string $contentType = 'image/jpeg',
        array $context = [],
    ): array {
        $this->ensureEnabled();

        return $this->postVisionRecognize($user, $bytes, $contentType, $context);
    }

    /**
     * Internal: POST bytes as multipart to /v1/vision/recognize.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function postVisionRecognize(User $user, string $bytes, string $contentType, array $context): array
    {
        $mealType = is_string($context['meal_type'] ?? null) ? $context['meal_type'] : 'lunch';

        try {
            $response = $this->baseRequest($user)
                ->attach('image', $bytes, 'meal.jpg', ['Content-Type' => $contentType])
                ->post($this->url('/v1/vision/recognize'), ['meal_type' => $mealType]);
        } catch (ConnectionException $e) {
            throw new AiServiceUnavailableException('AI_SERVICE_TIMEOUT', $e->getMessage());
        }

        return $this->jsonOrThrow($response, 'vision/recognize');
    }

    /**
     * Estimate nutrition from a free-form text description (口述/打字食物紀錄).
     *
     * Calls POST /v1/vision/recognize-text on the Python service. Same auth
     * model as scanMeal — X-Internal-Secret + X-Pandora-User-Uuid until Phase F
     * swaps to JWT pass-through.
     *
     * @param  array<string, mixed>  $context  optional `{hint?: string}`
     * @return array<string, mixed> envelope: foods / total_calories / confidence /
     *                              manual_input_required / ai_feedback / ...
     */
    public function describeMeal(User $user, string $description, array $context = []): array
    {
        $this->ensureEnabled();

        $payload = [
            'user_uuid' => (string) ($user->pandora_user_uuid ?? ''),
            'description' => $description,
        ];
        if (isset($context['hint']) && is_string($context['hint']) && $context['hint'] !== '') {
            $payload['hint'] = $context['hint'];
        }

        try {
            $response = $this->baseRequest($user)
                ->asJson()
                ->post($this->url('/v1/vision/recognize-text'), $payload);
        } catch (ConnectionException $e) {
            throw new AiServiceUnavailableException('AI_SERVICE_TIMEOUT', $e->getMessage());
        }

        return $this->jsonOrThrow($response, 'vision/recognize-text');
    }

    /**
     * Streaming chat — proxies SSE from POST /v1/chat/stream.
     *
     * Returns a Symfony StreamedResponse the controller can return directly.
     * On disconnect (Laravel's connection_aborted()) we stop pulling from the
     * upstream stream so the Python side can free resources.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function chatStream(
        User $user,
        string $message,
        string $sessionId,
        array $history = [],
        ?string $scenario = null,
    ): StreamedResponse {
        $this->ensureEnabled();

        $body = [
            'session_id' => $sessionId,
            'message' => $message,
            'history' => array_values($history),
        ];
        if ($scenario !== null) {
            $body['scenario'] = $scenario;
        }

        $base = $this->base();
        $secret = (string) config('services.meal_ai_service.shared_secret');
        $timeout = $this->timeout();
        $userUuid = (string) ($user->pandora_user_uuid ?? '');

        return new StreamedResponse(function () use ($base, $secret, $timeout, $userUuid, $body) {
            // We use Laravel's underlying Guzzle client via Http facade with
            // sink-as-callable so chunks can be forwarded as they arrive.
            $client = Http::withHeaders([
                'X-Internal-Secret' => $secret,
                'X-Pandora-User-Uuid' => $userUuid,
                'Accept' => 'text/event-stream',
            ])
                ->timeout($timeout)
                ->withOptions([
                    'stream' => true,
                ]);

            try {
                $response = $client->post($base.'/v1/chat/stream', $body);
            } catch (ConnectionException $e) {
                $this->emitErrorFrame('AI_SERVICE_TIMEOUT', $e->getMessage());

                return;
            }

            if (! $response->successful()) {
                $this->emitErrorFrame(
                    'AI_SERVICE_ERROR',
                    'upstream status '.$response->status(),
                );

                return;
            }

            // Pump bytes from Guzzle stream to the client. Stop early if the
            // browser disconnects so we don't keep generating tokens.
            $stream = $response->toPsrResponse()->getBody();
            while (! $stream->eof()) {
                if (connection_aborted()) {
                    break;
                }
                $chunk = $stream->read(4096);
                if ($chunk === '') {
                    continue;
                }
                echo $chunk;
                @ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->base() !== '' && (string) config('services.meal_ai_service.shared_secret') !== '';
    }

    private function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new AiServiceUnavailableException;
        }
    }

    private function baseRequest(User $user): PendingRequest
    {
        return Http::withHeaders([
            'X-Internal-Secret' => (string) config('services.meal_ai_service.shared_secret'),
            'X-Pandora-User-Uuid' => (string) ($user->pandora_user_uuid ?? ''),
            'Accept' => 'application/json',
        ])
            ->timeout($this->timeout())
            ->retry(1, 250, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOrThrow(Response $response, string $endpoint): array
    {
        if (! $response->successful()) {
            $code = $response->status() >= 500 ? 'AI_SERVICE_ERROR' : 'AI_SERVICE_REJECTED';
            Log::warning('[AiServiceClient] non-success from ai-service', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 500),
            ]);
            throw new AiServiceUnavailableException($code, 'ai-service returned '.$response->status());
        }

        try {
            $json = $response->json();
        } catch (RequestException $e) {
            throw new AiServiceUnavailableException('AI_SERVICE_BAD_JSON', $e->getMessage());
        }
        if (! is_array($json)) {
            throw new AiServiceUnavailableException('AI_SERVICE_BAD_JSON', 'response was not a JSON object');
        }

        return $json;
    }

    private function base(): string
    {
        return rtrim((string) config('services.meal_ai_service.base_url'), '/');
    }

    private function url(string $path): string
    {
        return $this->base().$path;
    }

    private function timeout(): int
    {
        return (int) config('services.meal_ai_service.timeout', 30);
    }

    private function emitErrorFrame(string $code, string $detail): void
    {
        // SSE-shaped error so the existing client SSE parser can surface it
        // without a special transport-error path.
        $payload = json_encode(['error_code' => $code, 'detail' => $detail], JSON_UNESCAPED_UNICODE);
        echo "event: error\ndata: ".$payload."\n\n";
        echo "event: done\ndata: {}\n\n";
        @ob_flush();
        flush();
    }
}
