<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HIBP Pwned Passwords k-anonymity rule with 24h cache + graceful degrade.
 *
 * Why we don't use Laravel's built-in `Password::uncompromised()`:
 *   - 它每次都打 api.pwnedpasswords.com，registration 高峰時會被 throttle
 *   - 如果 HIBP 掛掉就直接擋註冊（差體驗 + 影響 launch），這裡改成 log + skip
 *
 * 實作：
 *   1. SHA-1(password) 取前 5 chars 當 prefix
 *   2. Cache::remember('hibp:'.$prefix, 86400, fn() => GET /range/$prefix)
 *   3. 比對 suffix；命中且 count >= threshold → fail
 *   4. HIBP API down → log warning + 通過（fail-open，不擋註冊）
 *
 * @see https://haveibeenpwned.com/API/v3#PwnedPasswords
 */
class HibpUncompromisedPassword implements ValidationRule
{
    public function __construct(
        private int $threshold = 1,
        private int $cacheTtlSeconds = 86400,
        private int $httpTimeoutSeconds = 3,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // env kill-switch：CI / local / E2E 可關掉避免外網依賴
        if (! config('app.hibp_enabled', env('HIBP_CHECK_ENABLED', true))) {
            return;
        }

        if (! is_string($value) || $value === '') {
            return; // nullable / required 由其他 rule 處理
        }

        $sha1 = strtoupper(sha1($value));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $body = Cache::remember(
                "hibp:{$prefix}",
                $this->cacheTtlSeconds,
                fn () => Http::timeout($this->httpTimeoutSeconds)
                    ->withHeaders(['Add-Padding' => 'true'])
                    ->get("https://api.pwnedpasswords.com/range/{$prefix}")
                    ->throw()
                    ->body()
            );
        } catch (\Throwable $e) {
            // Fail-open — HIBP down 不該擋註冊。Log 給 ops 觀察。
            Log::warning('HIBP API unavailable, skipping breach check', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! is_string($body) || $body === '') {
            return;
        }

        $count = $this->findMatchCount($body, $suffix);
        if ($count >= $this->threshold) {
            $fail('validation.password.uncompromised')->translate([
                'attribute' => $attribute,
            ]);
        }
    }

    /**
     * Parse HIBP response (line-per-suffix `SUFFIX:COUNT` CRLF) and return
     * the count for our suffix, or 0 if not present.
     */
    private function findMatchCount(string $body, string $suffix): int
    {
        $lines = preg_split('/\r\n|\n/', $body) ?: [];
        foreach ($lines as $line) {
            $parts = explode(':', trim($line), 2);
            if (count($parts) !== 2) {
                continue;
            }
            if (strcasecmp($parts[0], $suffix) === 0) {
                return (int) $parts[1];
            }
        }

        return 0;
    }
}
