<?php

namespace Tests\Feature\Identity;

use App\Models\CardPlay;
use App\Models\DailyLog;
use App\Models\DailyQuest;
use App\Models\JourneyAdvance;
use App\Models\Meal;
use App\Models\User;
use App\Services\CardService;
use App\Services\CheckinService;
use App\Services\JourneyService;
use App\Services\QuestService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase D Wave 2 — 確認 service 內部 query 已改成 by pandora_user_uuid。
 *
 * 三條主軸：
 *
 *   1. Reverse trait fill: 給 model 只帶 uuid 沒帶 user_id，trait 應該反向回填
 *      user_id（Wave 2 trait 擴充，因為 hasMany FK 改成 uuid 後 create 不會帶 user_id）。
 *
 *   2. User::hasMany on uuid: User → Meal/DailyLog/... relations FK 已改為
 *      pandora_user_uuid，create 透過 relation 應該照常工作。
 *
 *   3. Service reads via uuid: 既有 row 同時有 user_id + uuid 時，service 用 uuid
 *      query 拿得到。「人造一個 row 只塞 user_id 不塞 uuid」由 Wave 1 的 trait
 *      saving 補 uuid 已經保證雙欄都在，所以不在這層測 — Wave 1 的
 *      HasPandoraUserUuidTraitTest 涵蓋。
 *
 * @see ADR-007 §2.3
 */
class Wave2UuidQueriesTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        // UserObserver 在 saved 時 ensureMirror → 自動簽 pandora_user_uuid
        $user = User::factory()->create();
        $this->assertNotNull($user->pandora_user_uuid, 'UserObserver should sign uuid');

        return $user;
    }

    public function test_trait_reverse_fills_user_id_when_only_uuid_given(): void
    {
        $user = $this->makeUser();

        // 模擬 hasMany create path：只給 pandora_user_uuid
        $meal = new Meal([
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => now()->toDateString(),
            'meal_type' => 'lunch',
            'food_name' => 'test',
            'calories' => 500,
        ]);
        $meal->save();

        $this->assertSame($user->id, $meal->fresh()->user_id);
        $this->assertSame($user->pandora_user_uuid, $meal->fresh()->pandora_user_uuid);
    }

    public function test_user_hasmany_relation_uses_uuid_fk(): void
    {
        $user = $this->makeUser();

        // create via relation — Wave 2 後 FK 是 pandora_user_uuid
        $log = $user->dailyLogs()->create(['date' => now()->toDateString()]);

        $this->assertSame($user->pandora_user_uuid, $log->pandora_user_uuid);
        $this->assertSame($user->id, $log->user_id, 'trait should reverse-fill user_id');
        $this->assertTrue($user->dailyLogs()->where('id', $log->id)->exists());
    }

    public function test_card_service_reads_only_by_uuid_isolate_users(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser();

        // 兩個使用者各打 1 張卡
        CardPlay::create([
            'user_id' => $alice->id,
            'date' => now()->toDateString(),
            'card_id' => 'card-a',
            'card_type' => 'knowledge',
            'rarity' => 'common',
            'answered_at' => now(),
        ]);
        CardPlay::create([
            'user_id' => $bob->id,
            'date' => now()->toDateString(),
            'card_id' => 'card-b',
            'card_type' => 'knowledge',
            'rarity' => 'common',
            'answered_at' => now(),
        ]);

        $service = app(CardService::class);
        $aliceCollection = $service->collection($alice);
        $bobCollection = $service->collection($bob);

        $this->assertSame(1, $aliceCollection['total']);
        $this->assertSame('card-a', $aliceCollection['cards'][0]['card_id']);

        $this->assertSame(1, $bobCollection['total']);
        $this->assertSame('card-b', $bobCollection['cards'][0]['card_id']);
    }

    public function test_checkin_service_finds_existing_log_via_uuid(): void
    {
        $user = $this->makeUser();
        $today = now()->toDateString();

        // 模擬 row 寫進來時走 Wave 1 dual-write — 兩欄都有
        DailyLog::create([
            'user_id' => $user->id,
            'date' => $today,
            'water_ml' => 500,
        ]);

        // service 改成讀 uuid 後，應仍能找到並更新而非建新 row
        app(CheckinService::class)->logWater($user, 200);

        $this->assertSame(1, DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', $today)
            ->count());
        $this->assertSame(700, (int) DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', $today)
            ->value('water_ml'));
    }

    public function test_journey_service_reads_recent_advances_by_uuid(): void
    {
        $user = $this->makeUser();
        JourneyAdvance::create([
            'user_id' => $user->id,
            'cycle' => 1,
            'day' => 1,
            'reason' => 'meal_log',
        ]);

        $journey = app(JourneyService::class)->getJourney($user);
        $this->assertCount(1, $journey['recent_advances']);
        $this->assertSame('meal_log', $journey['recent_advances'][0]['reason']);
    }

    public function test_quest_service_does_not_double_create_after_uuid_read(): void
    {
        $user = $this->makeUser();
        $service = app(QuestService::class);

        // listToday 第一次會 ensureToday create，第二次該命中 uuid query 不重建
        $first = $service->listToday($user);
        $count1 = DailyQuest::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', now()->toDateString())
            ->count();
        $service->listToday($user);
        $count2 = DailyQuest::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', now()->toDateString())
            ->count();

        $this->assertGreaterThan(0, count($first['quests']));
        $this->assertSame($count1, $count2, 'ensureToday must not duplicate after uuid read');
    }

    public function test_referral_service_reads_referrals_by_uuid(): void
    {
        $referrer = $this->makeUser();
        $referee = $this->makeUser();

        // 直接 insert 一筆 referral，雙欄都填
        DB::table('referrals')->insert([
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id,
            'pandora_referrer_uuid' => $referrer->pandora_user_uuid,
            'pandora_referee_uuid' => $referee->pandora_user_uuid,
            'code' => 'TESTCODE',
            'reward_kind' => 'trial_extension_7d',
            'created_at' => now(),
        ]);

        $stats = app(ReferralService::class)->stats($referrer);
        $this->assertSame(1, $stats['invited_count']);
    }
}
