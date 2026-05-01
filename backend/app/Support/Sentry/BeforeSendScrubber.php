<?php

namespace App\Support\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry before_send hook — 砍掉送上去前的 PII。
 *
 * 集團 ADR-007 §2.3 + Apple privacy nutrition：
 * 不能讓 email / phone / password / OAuth tokens / apple_id / line_id
 * 出現在 Sentry 上 (3rd-party 服務 + 集團政策不允許)。
 *
 * 這個 hook 對 request data / breadcrumbs / extra / contexts 都掃一遍。
 */
class BeforeSendScrubber
{
    /**
     * 黑名單 keys（小寫比對；substring match）。
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'email',
        'phone',
        'apple_id',
        'line_id',
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'token',          // catches *token*, refresh_token, access_token, csrf_token, ...
        'secret',
        'identity_token', // Sign in with Apple
        'id_token',
    ];

    public static function scrub(Event $event, ?EventHint $hint = null): ?Event
    {
        // 1. Request data
        $request = $event->getRequest();
        if (! empty($request)) {
            $event->setRequest(self::scrubArray($request));
        }

        // 2. Breadcrumbs
        $crumbs = $event->getBreadcrumbs();
        foreach ($crumbs as $crumb) {
            $data = $crumb->getMetadata();
            if (! empty($data)) {
                $crumb->withMetadata('data', self::scrubArray($data));
            }
        }

        // 3. Extra / tags / contexts
        $extra = $event->getExtra();
        if (! empty($extra)) {
            $event->setExtra(self::scrubArray($extra));
        }

        // contexts: Sentry\Event has getContexts() but no bulk setContexts()
        // in 4.x — individual setContext($key, $value) is the API. Iterate
        // to scrub each entry; safe no-op when contexts is empty.
        foreach ($event->getContexts() as $key => $value) {
            if (is_array($value)) {
                $event->setContext($key, self::scrubArray($value));
            }
        }

        // 4. user 區段 — 只保留 id (uuid)，砍 email / username / ip
        $user = $event->getUser();
        if ($user !== null) {
            $event->setUser(\Sentry\UserDataBag::createFromArray([
                'id' => $user->getId(),
            ]));
        }

        return $event;
    }

    /**
     * 遞迴 scrub array — 命中黑名單 key 就把值換成 [Filtered]。
     *
     * @param  array<string, mixed>  $arr
     * @return array<string, mixed>
     */
    private static function scrubArray(array $arr): array
    {
        foreach ($arr as $key => $value) {
            $lower = is_string($key) ? strtolower($key) : '';
            $hit = false;
            foreach (self::SENSITIVE_KEYS as $needle) {
                if ($lower !== '' && str_contains($lower, $needle)) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $arr[$key] = '[Filtered]';

                continue;
            }
            if (is_array($value)) {
                $arr[$key] = self::scrubArray($value);
            }
        }

        return $arr;
    }
}
