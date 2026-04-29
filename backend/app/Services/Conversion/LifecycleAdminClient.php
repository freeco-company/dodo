<?php

namespace App\Services\Conversion;

use Illuminate\Support\Facades\Http;

/**
 * py-service admin lifecycle override client.
 *
 * Wraps `POST /api/v1/internal/admin/users/{uuid}/lifecycle/transition`
 * (X-Internal-Secret). Used by Filament FranchiseLeadResource override action
 * (and any other admin tooling) to manually correct a user's lifecycle stage.
 *
 * Auth & transport reuse the same env as ConversionEventPublisher /
 * LifecycleClient — there is one shared HMAC secret per environment.
 */
class LifecycleAdminClient
{
    /**
     * Force-transition a user to `$toStatus` on py-service.
     *
     * Throws \RuntimeException on non-2xx so callers can surface the failure
     * to admin (Filament will show a notification toast).
     *
     * @return array{id:int, from_status:?string, to_status:string} as returned by py-service
     */
    public function override(
        string $pandoraUserUuid,
        string $toStatus,
        string $reason,
        string $actor,
    ): array {
        $base = rtrim((string) config('services.pandora_conversion.base_url'), '/');
        $secret = (string) config('services.pandora_conversion.shared_secret');
        if ($base === '' || $secret === '') {
            throw new \RuntimeException('pandora_conversion not configured');
        }
        $timeout = (int) config('services.pandora_conversion.timeout', 5);

        $response = Http::withHeaders([
            'X-Internal-Secret' => $secret,
            'Accept' => 'application/json',
        ])
            ->timeout($timeout)
            ->post(
                $base.'/api/v1/internal/admin/users/'.urlencode($pandoraUserUuid).'/lifecycle/transition',
                [
                    'to_status' => $toStatus,
                    'reason' => $reason,
                    'actor' => $actor,
                ],
            );

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'lifecycle override failed: status=%d body=%s',
                $response->status(),
                substr((string) $response->body(), 0, 200),
            ));
        }

        /** @var array{id:int, from_status:?string, to_status:string} $body */
        $body = (array) $response->json();

        return $body;
    }
}
