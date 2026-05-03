<?php

use App\Models\FoodCorrection;
use App\Models\Meal;
use App\Models\MealDish;
use App\Models\User;
use App\Services\AiServiceClient;
use App\Services\MealCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * SPEC-photo-ai-correction-v2 PR #1 — service-level coverage of:
 *   applyDishCorrection / addManualDish / removeDish / refineDishViaAi /
 *   userCalibrationFor / recalcMealTotals
 *
 * Endpoint coverage lives in PR #2; here we test pure service behaviour.
 */
beforeEach(function () {
    config()->set('services.meal_ai_service.base_url', 'https://ai.test');
    config()->set('services.meal_ai_service.shared_secret', 'secret');
    config()->set('services.meal_ai_service.timeout', 5);
});

function makeMealWithDishes(int $dishCount = 2): array
{
    $user = User::factory()->create(['pandora_user_uuid' => 'uuid-correction-'.uniqid()]);
    $meal = Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => now()->toDateString(),
        'meal_type' => 'lunch',
        'food_name' => '便當',
        'recognized_via' => 'photo',
        'photo_url' => 'https://x.test/photo.jpg',
        'calories' => 0,
        'carbs_g' => 0,
        'protein_g' => 0,
        'fat_g' => 0,
    ]);

    $dishes = [];
    foreach (range(0, $dishCount - 1) as $i) {
        $dishes[] = $meal->dishes()->create([
            'food_name' => $i === 0 ? '白飯' : '雞腿',
            'food_key' => $i === 0 ? 'rice_white' : 'chicken_thigh',
            'portion_multiplier' => 1.00,
            'kcal' => $i === 0 ? 320 : 280,
            'carb_g' => $i === 0 ? 70 : 0,
            'protein_g' => $i === 0 ? 6 : 35,
            'fat_g' => $i === 0 ? 0.5 : 15,
            'confidence' => 0.92,
            'source' => MealDish::SOURCE_AI_INITIAL,
            'display_order' => $i,
        ]);
    }
    app(MealCorrectionService::class)->recalcMealTotals($meal);

    return [$user, $meal->refresh(), $dishes];
}

it('recalcMealTotals sums dishes into meal aggregate columns', function () {
    [, $meal] = makeMealWithDishes(2);

    expect($meal->calories)->toBe(600);
    expect((float) $meal->carbs_g)->toBe(70.0);
    expect((float) $meal->protein_g)->toBe(41.0);
    expect((float) $meal->fat_g)->toBe(15.5);
});

it('applyDishCorrection swap food logs FOOD_SWAP and clears confidence', function () {
    [$user, $meal, $dishes] = makeMealWithDishes(2);
    $svc = app(MealCorrectionService::class);

    $updated = $svc->applyDishCorrection($dishes[0], $user, [
        'food_key' => 'rice_brown',
        'food_name' => '糙米',
        'kcal' => 280,
        'carb_g' => 60,
        'protein_g' => 8,
        'fat_g' => 1.5,
    ]);

    expect($updated->food_key)->toBe('rice_brown');
    expect($updated->confidence)->toBeNull();
    expect($updated->source)->toBe(MealDish::SOURCE_USER_SWAPPED);
    expect(FoodCorrection::where('user_id', $user->id)->where('correction_type', FoodCorrection::TYPE_FOOD_SWAP)->count())->toBe(1);
    expect($meal->fresh()->calories)->toBe(560);
});

it('applyDishCorrection portion change logs PORTION_CHANGE', function () {
    [$user, , $dishes] = makeMealWithDishes(2);
    $svc = app(MealCorrectionService::class);

    $svc->applyDishCorrection($dishes[0], $user, [
        'portion_multiplier' => 1.5,
        'kcal' => 480,
        'carb_g' => 105,
        'protein_g' => 9,
        'fat_g' => 0.75,
    ]);

    expect(FoodCorrection::where('user_id', $user->id)->where('correction_type', FoodCorrection::TYPE_PORTION_CHANGE)->count())->toBe(1);
});

it('applyDishCorrection both food + portion logs both correction rows', function () {
    [$user, , $dishes] = makeMealWithDishes(2);
    $svc = app(MealCorrectionService::class);

    $svc->applyDishCorrection($dishes[0], $user, [
        'food_key' => 'rice_brown',
        'portion_multiplier' => 0.75,
        'kcal' => 210,
        'carb_g' => 45,
        'protein_g' => 6,
        'fat_g' => 1,
    ]);

    expect(FoodCorrection::where('user_id', $user->id)->count())->toBe(2);
});

it('addManualDish appends a dish + logs ADD_MISSING + recalcs totals', function () {
    [$user, $meal] = makeMealWithDishes(2);
    $svc = app(MealCorrectionService::class);

    $newDish = $svc->addManualDish($meal, $user, [
        'food_name' => '滷蛋',
        'food_key' => 'egg_braised',
        'portion_multiplier' => 1.0,
        'kcal' => 80,
        'carb_g' => 1,
        'protein_g' => 6,
        'fat_g' => 5,
    ]);

    expect($newDish->source)->toBe(MealDish::SOURCE_USER_MANUAL);
    expect($newDish->display_order)->toBe(2);
    expect($newDish->confidence)->toBeNull();
    expect(FoodCorrection::where('user_id', $user->id)->where('correction_type', FoodCorrection::TYPE_ADD_MISSING)->count())->toBe(1);
    expect($meal->fresh()->calories)->toBe(680);
});

it('removeDish deletes + logs REMOVE + recalcs totals', function () {
    [$user, $meal, $dishes] = makeMealWithDishes(2);
    $svc = app(MealCorrectionService::class);

    $svc->removeDish($dishes[1], $user);

    expect(MealDish::find($dishes[1]->id))->toBeNull();
    expect(FoodCorrection::where('user_id', $user->id)->where('correction_type', FoodCorrection::TYPE_REMOVE)->count())->toBe(1);
    expect($meal->fresh()->calories)->toBe(320);
});

it('refineDishViaAi updates dish from ai-service response', function () {
    [$user, , $dishes] = makeMealWithDishes(2);
    Http::fake([
        'ai.test/v1/vision/refine' => Http::response([
            'dishes' => [
                [
                    'food_name' => '糙米',
                    'food_key' => 'rice_brown',
                    'portion_multiplier' => 1.0,
                    'kcal' => 270,
                    'carb_g' => 58,
                    'protein_g' => 8,
                    'fat_g' => 1.6,
                    'confidence' => 0.88,
                ],
                [
                    'food_name' => '雞腿',
                    'food_key' => 'chicken_thigh',
                    'portion_multiplier' => 1.0,
                    'kcal' => 280,
                    'carb_g' => 0,
                    'protein_g' => 35,
                    'fat_g' => 15,
                    'confidence' => 0.92,
                ],
            ],
        ], 200),
    ]);
    $svc = app(MealCorrectionService::class);

    $updated = $svc->refineDishViaAi($dishes[0], $user, ['new_food_key' => 'rice_brown']);

    expect($updated->food_key)->toBe('rice_brown');
    expect($updated->source)->toBe(MealDish::SOURCE_AI_REFINED);
    expect($updated->kcal)->toBe(270);
    expect(FoodCorrection::where('user_id', $user->id)->where('correction_type', FoodCorrection::TYPE_AI_REFINE)->count())->toBe(1);
});

it('refineDishViaAi soft-fails when ai-service is down (no exception, no mutation)', function () {
    [$user, , $dishes] = makeMealWithDishes(2);
    Http::fake([
        'ai.test/v1/vision/refine' => Http::response('boom', 500),
    ]);
    $svc = app(MealCorrectionService::class);

    $result = $svc->refineDishViaAi($dishes[0], $user, ['new_food_key' => 'rice_brown']);

    expect($result->food_key)->toBe('rice_white');
    expect($result->source)->toBe(MealDish::SOURCE_AI_INITIAL);
});

it('userCalibrationFor returns null when sample count < 3', function () {
    [$user, , $dishes] = makeMealWithDishes(2);
    $svc = app(MealCorrectionService::class);
    $svc->applyDishCorrection($dishes[0], $user, [
        'portion_multiplier' => 1.5, 'kcal' => 480, 'carb_g' => 105, 'protein_g' => 9, 'fat_g' => 0.75,
    ]);

    expect($svc->userCalibrationFor($user, 'rice_white'))->toBeNull();
});

it('userCalibrationFor returns averaged bias when sample count >= 3', function () {
    [$user, $meal] = makeMealWithDishes(1);
    $svc = app(MealCorrectionService::class);

    foreach ([1.5, 1.5, 1.25] as $newPortion) {
        $dish = $meal->dishes()->first();
        $dish->update(['portion_multiplier' => 1.00]);
        $svc->applyDishCorrection($dish->fresh(), $user, [
            'portion_multiplier' => $newPortion,
            'kcal' => (int) ($newPortion * 320),
            'carb_g' => $newPortion * 70,
            'protein_g' => $newPortion * 6,
            'fat_g' => $newPortion * 0.5,
        ]);
    }

    $calibration = $svc->userCalibrationFor($user, 'rice_white');

    expect($calibration)->not->toBeNull();
    expect($calibration['sample_count'])->toBe(3);
    expect($calibration['portion_bias'])->toBeGreaterThan(0);
});
