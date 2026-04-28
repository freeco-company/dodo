<?php

namespace Tests\Feature\Api;

use App\Models\Meal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Frontend↔Backend contract: 401 unauth gap-fill.
 *
 * Audited 49 api() call sites in frontend/public/app.js against
 * routes/api.php. Existing test files cover the alignment + meal +
 * card + bootstrap + me + paywall surfaces. This file fills the
 * remaining auth-protected endpoints with a 401 smoke check so any
 * future route refactor that drops auth middleware is caught.
 *
 * Tenant isolation for meal listing is already covered in MealTest.
 * The cross-user write regression guard for /meals/{id}/correct is
 * here so a future controller refactor that loses the ownership
 * check fails loudly.
 *
 * Class style (PHPUnit) on purpose — keeps phpstan baseline clean
 * (Pest functional dataset style trips method.notFound noise).
 */
class FrontendContractAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function unauthGetEndpoints(): array
    {
        return [
            'me' => ['/api/me'],
            'meals index' => ['/api/meals'],
            'meals show' => ['/api/meals/1'],
            'checkin goals' => ['/api/checkin/goals'],
            'journey' => ['/api/journey'],
            'interact gift status' => ['/api/interact/gift/status'],
            'cards stamina' => ['/api/cards/stamina'],
            'cards collection' => ['/api/cards/collection'],
            'foods search' => ['/api/foods/search?q=apple'],
            'quests today' => ['/api/quests/today'],
            'meta limits' => ['/api/meta/limits'],
            'meta outfits' => ['/api/meta/outfits'],
            'lore spirits' => ['/api/lore/spirits'],
            'referrals me' => ['/api/referrals/me'],
            'chat starters' => ['/api/chat/starters'],
            'island store' => ['/api/island/store/forest'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unauthPostEndpoints(): array
    {
        return [
            'logout' => ['/api/auth/logout'],
            'meals store' => ['/api/meals'],
            'checkin water' => ['/api/checkin/water'],
            'checkin water set' => ['/api/checkin/water/set'],
            'checkin exercise' => ['/api/checkin/exercise'],
            'checkin exercise set' => ['/api/checkin/exercise/set'],
            'checkin weight' => ['/api/checkin/weight'],
            'journey advance' => ['/api/journey/advance'],
            'interact pet' => ['/api/interact/pet'],
            'interact gift' => ['/api/interact/gift'],
            'shield refill' => ['/api/shield/refill'],
            'shield use' => ['/api/shield/use'],
            'cards draw' => ['/api/cards/draw'],
            'cards answer' => ['/api/cards/answer'],
            'island consume-visit' => ['/api/island/consume-visit'],
            'outfits equip' => ['/api/outfits/equip'],
            'referrals redeem' => ['/api/referrals/redeem'],
            'account delete-request' => ['/api/account/delete-request'],
            'account restore' => ['/api/account/restore'],
            'rating-prompt event' => ['/api/rating-prompt/event'],
            'analytics track' => ['/api/analytics/track'],
            'push register' => ['/api/push/register'],
            'push unregister' => ['/api/push/unregister'],
            'tier redeem' => ['/api/tier/redeem'],
            'subscribe mock' => ['/api/subscribe/mock'],
            'iap verify' => ['/api/iap/verify'],
            'meals scan' => ['/api/meals/scan'],
            'meals text' => ['/api/meals/text'],
            'chat message' => ['/api/chat/message'],
            'me franchise-cta-silence' => ['/api/me/franchise-cta-silence'],
        ];
    }

    #[DataProvider('unauthGetEndpoints')]
    public function test_unauthenticated_get_returns_401(string $path): void
    {
        $this->getJson($path)->assertUnauthorized();
    }

    #[DataProvider('unauthPostEndpoints')]
    public function test_unauthenticated_post_returns_401(string $path): void
    {
        $this->postJson($path, [])->assertUnauthorized();
    }

    public function test_unauthenticated_delete_meal_returns_401(): void
    {
        $this->deleteJson('/api/meals/1')->assertUnauthorized();
    }

    public function test_unauthenticated_correct_meal_returns_401(): void
    {
        $this->putJson('/api/meals/1/correct', ['food_name' => 'x'])
            ->assertUnauthorized();
    }

    /**
     * Tenant isolation regression guard for the cross-user write path.
     * If a future refactor drops the ownership check on the meal
     * correction endpoint, this test fails — preventing leak of one
     * tenant's meal mutation surface to another logged-in user.
     */
    public function test_correcting_another_user_meal_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $meal = Meal::factory()->for($owner)->create();

        $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/meals/{$meal->id}/correct", ['food_name' => 'hijack'])
            ->assertStatus(403);
    }
}
