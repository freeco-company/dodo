<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Seed `count` consecutive past `DailyLog` rows for `$user`, ending
 * `$startBackDays` ago (default 1 = yesterday). Used by gamification + streak
 * hook tests. Lives here so file-level helpers don't disturb Pest's `$this`
 * binding inside `it(...)` closures.
 */
function seedStreakDays(\App\Models\User $user, int $count, int $startBackDays = 1): void
{
    for ($i = 0; $i < $count; $i++) {
        \App\Models\DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => \Carbon\Carbon::today()->subDays($startBackDays + $i)->toDateString(),
        ]);
    }
}

/**
 * Look up the correct-choice index for a seeded question card. Returns null if
 * the card or a correct choice can't be found in the seeded `question_decks`
 * config. Used by gamification card-answer hook tests.
 */
function correctChoiceIdxForCard(string $cardId): ?int
{
    $config = app(\App\Services\AppConfigService::class);
    $decks = $config->get('question_decks') ?? [];
    foreach ($decks['cards'] ?? [] as $card) {
        if (($card['id'] ?? null) === $cardId) {
            foreach ($card['choices'] ?? [] as $idx => $choice) {
                if (! empty($choice['correct'])) {
                    return $idx;
                }
            }
        }
    }

    return null;
}
