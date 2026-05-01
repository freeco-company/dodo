<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 朵朵食物資料庫初始 seed — 約 80 個常見台灣食物 + 少量飲品 + 集團 FP 商品。
 *
 * 為什麼有這個 seeder：圖鑑（PokedexController）改成「顯示全部食物」之後，
 * 若 food_database 是空的，圖鑑就完全空白。這個 seeder 給每個新環境
 * 一份合理的「初始物種」清單，等真實 OCR / API 資料回填會自然合併。
 *
 * 使用 updateOrCreate by name_zh 確保 idempotent（重跑安全）。
 */
class FoodDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::FOODS as $food) {
            DB::table('food_database')->updateOrInsert(
                ['name_zh' => $food['name_zh']],
                array_merge($food, [
                    'verified' => true,
                    'source' => 'seed_v1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );
        }
    }

    /**
     * Format: name_zh / name_en / category / element / serving (g) / kcal / P / C / F / fiber / sodium / sugar
     * Categories: protein / carb / veggie / fruit / dairy / drink / snack / fp_product
     * Elements:  protein / carb / veggie / fat / sweet / drink / neutral
     */
    private const FOODS = [
        // ===== 蛋白質 (protein) =====
        ['name_zh' => '雞胸肉', 'name_en' => 'chicken breast', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 165, 'protein_g' => 31, 'carbs_g' => 0, 'fat_g' => 3.6, 'fiber_g' => 0, 'sodium_mg' => 74, 'sugar_g' => 0],
        ['name_zh' => '雞腿肉', 'name_en' => 'chicken thigh', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 209, 'protein_g' => 26, 'carbs_g' => 0, 'fat_g' => 11, 'fiber_g' => 0, 'sodium_mg' => 88, 'sugar_g' => 0],
        ['name_zh' => '雞蛋', 'name_en' => 'egg', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 50, 'calories' => 78, 'protein_g' => 6, 'carbs_g' => 0.6, 'fat_g' => 5, 'fiber_g' => 0, 'sodium_mg' => 62, 'sugar_g' => 0.6],
        ['name_zh' => '水煮蛋', 'name_en' => 'boiled egg', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 50, 'calories' => 78, 'protein_g' => 6, 'carbs_g' => 0.6, 'fat_g' => 5, 'fiber_g' => 0, 'sodium_mg' => 62, 'sugar_g' => 0.6],
        ['name_zh' => '荷包蛋', 'name_en' => 'fried egg', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 60, 'calories' => 90, 'protein_g' => 6, 'carbs_g' => 0.4, 'fat_g' => 7, 'fiber_g' => 0, 'sodium_mg' => 95, 'sugar_g' => 0.4],
        ['name_zh' => '鮭魚', 'name_en' => 'salmon', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 208, 'protein_g' => 22, 'carbs_g' => 0, 'fat_g' => 13, 'fiber_g' => 0, 'sodium_mg' => 59, 'sugar_g' => 0],
        ['name_zh' => '鯖魚', 'name_en' => 'mackerel', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 205, 'protein_g' => 19, 'carbs_g' => 0, 'fat_g' => 14, 'fiber_g' => 0, 'sodium_mg' => 90, 'sugar_g' => 0],
        ['name_zh' => '鱸魚', 'name_en' => 'sea bass', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 124, 'protein_g' => 23, 'carbs_g' => 0, 'fat_g' => 3, 'fiber_g' => 0, 'sodium_mg' => 80, 'sugar_g' => 0],
        ['name_zh' => '蝦', 'name_en' => 'shrimp', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 99, 'protein_g' => 24, 'carbs_g' => 0, 'fat_g' => 0.3, 'fiber_g' => 0, 'sodium_mg' => 111, 'sugar_g' => 0],
        ['name_zh' => '豆腐', 'name_en' => 'tofu', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 76, 'protein_g' => 8, 'carbs_g' => 1.9, 'fat_g' => 4.8, 'fiber_g' => 0.3, 'sodium_mg' => 7, 'sugar_g' => 0.5],
        ['name_zh' => '嫩豆腐', 'name_en' => 'silken tofu', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 55, 'protein_g' => 5, 'carbs_g' => 2, 'fat_g' => 3, 'fiber_g' => 0.2, 'sodium_mg' => 5, 'sugar_g' => 0.5],
        ['name_zh' => '無糖豆漿', 'name_en' => 'unsweetened soy milk', 'category' => 'drink', 'element' => 'drink', 'serving_weight_g' => 240, 'calories' => 80, 'protein_g' => 7, 'carbs_g' => 4, 'fat_g' => 4, 'fiber_g' => 0.5, 'sodium_mg' => 80, 'sugar_g' => 1],
        ['name_zh' => '牛肉', 'name_en' => 'beef', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 250, 'protein_g' => 26, 'carbs_g' => 0, 'fat_g' => 17, 'fiber_g' => 0, 'sodium_mg' => 72, 'sugar_g' => 0],
        ['name_zh' => '豬里肌', 'name_en' => 'pork loin', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 100, 'calories' => 143, 'protein_g' => 26, 'carbs_g' => 0, 'fat_g' => 4, 'fiber_g' => 0, 'sodium_mg' => 60, 'sugar_g' => 0],
        ['name_zh' => '優格', 'name_en' => 'yogurt', 'category' => 'dairy', 'element' => 'protein', 'serving_weight_g' => 150, 'calories' => 90, 'protein_g' => 9, 'carbs_g' => 12, 'fat_g' => 0, 'fiber_g' => 0, 'sodium_mg' => 60, 'sugar_g' => 12],
        ['name_zh' => '希臘優格', 'name_en' => 'greek yogurt', 'category' => 'dairy', 'element' => 'protein', 'serving_weight_g' => 150, 'calories' => 90, 'protein_g' => 15, 'carbs_g' => 6, 'fat_g' => 0, 'fiber_g' => 0, 'sodium_mg' => 50, 'sugar_g' => 6],

        // ===== 主食 (carb) =====
        ['name_zh' => '白飯', 'name_en' => 'white rice', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 150, 'calories' => 195, 'protein_g' => 4, 'carbs_g' => 43, 'fat_g' => 0.4, 'fiber_g' => 0.6, 'sodium_mg' => 0, 'sugar_g' => 0],
        ['name_zh' => '糙米飯', 'name_en' => 'brown rice', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 150, 'calories' => 165, 'protein_g' => 4, 'carbs_g' => 35, 'fat_g' => 1.5, 'fiber_g' => 2.5, 'sodium_mg' => 5, 'sugar_g' => 0.5],
        ['name_zh' => '五穀飯', 'name_en' => 'multigrain rice', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 150, 'calories' => 175, 'protein_g' => 5, 'carbs_g' => 36, 'fat_g' => 1.5, 'fiber_g' => 3.5, 'sodium_mg' => 5, 'sugar_g' => 0.5],
        ['name_zh' => '蒸地瓜', 'name_en' => 'steamed sweet potato', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 150, 'calories' => 130, 'protein_g' => 2, 'carbs_g' => 30, 'fat_g' => 0.2, 'fiber_g' => 4.5, 'sodium_mg' => 50, 'sugar_g' => 9],
        ['name_zh' => '南瓜', 'name_en' => 'pumpkin', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 150, 'calories' => 39, 'protein_g' => 1.5, 'carbs_g' => 9, 'fat_g' => 0.1, 'fiber_g' => 1.5, 'sodium_mg' => 1, 'sugar_g' => 4],
        ['name_zh' => '馬鈴薯', 'name_en' => 'potato', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 150, 'calories' => 116, 'protein_g' => 3, 'carbs_g' => 26, 'fat_g' => 0.2, 'fiber_g' => 3, 'sodium_mg' => 9, 'sugar_g' => 1.5],
        ['name_zh' => '蕎麥麵', 'name_en' => 'soba noodles', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 200, 'calories' => 200, 'protein_g' => 7, 'carbs_g' => 41, 'fat_g' => 1, 'fiber_g' => 4, 'sodium_mg' => 360, 'sugar_g' => 1],
        ['name_zh' => '義大利麵', 'name_en' => 'pasta', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 200, 'calories' => 220, 'protein_g' => 8, 'carbs_g' => 43, 'fat_g' => 1.3, 'fiber_g' => 2.5, 'sodium_mg' => 6, 'sugar_g' => 1],
        ['name_zh' => '吐司', 'name_en' => 'toast', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 30, 'calories' => 80, 'protein_g' => 3, 'carbs_g' => 15, 'fat_g' => 1, 'fiber_g' => 0.6, 'sodium_mg' => 130, 'sugar_g' => 1.5],
        ['name_zh' => '全麥吐司', 'name_en' => 'whole wheat toast', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 30, 'calories' => 80, 'protein_g' => 4, 'carbs_g' => 14, 'fat_g' => 1, 'fiber_g' => 2, 'sodium_mg' => 135, 'sugar_g' => 1.5],
        ['name_zh' => '燕麥粥', 'name_en' => 'oatmeal', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 250, 'calories' => 158, 'protein_g' => 6, 'carbs_g' => 28, 'fat_g' => 3, 'fiber_g' => 4, 'sodium_mg' => 7, 'sugar_g' => 1],
        ['name_zh' => '玉米', 'name_en' => 'corn', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 100, 'calories' => 86, 'protein_g' => 3.3, 'carbs_g' => 19, 'fat_g' => 1.2, 'fiber_g' => 2.4, 'sodium_mg' => 15, 'sugar_g' => 6.3],

        // ===== 蔬菜 (veggie) =====
        ['name_zh' => '花椰菜', 'name_en' => 'broccoli', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 34, 'protein_g' => 2.8, 'carbs_g' => 7, 'fat_g' => 0.4, 'fiber_g' => 2.6, 'sodium_mg' => 33, 'sugar_g' => 1.7],
        ['name_zh' => '青花菜', 'name_en' => 'broccoli', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 34, 'protein_g' => 2.8, 'carbs_g' => 7, 'fat_g' => 0.4, 'fiber_g' => 2.6, 'sodium_mg' => 33, 'sugar_g' => 1.7],
        ['name_zh' => '高麗菜', 'name_en' => 'cabbage', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 25, 'protein_g' => 1.3, 'carbs_g' => 6, 'fat_g' => 0.1, 'fiber_g' => 2.5, 'sodium_mg' => 18, 'sugar_g' => 3.2],
        ['name_zh' => '空心菜', 'name_en' => 'water spinach', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 19, 'protein_g' => 2.6, 'carbs_g' => 3, 'fat_g' => 0.2, 'fiber_g' => 2.1, 'sodium_mg' => 113, 'sugar_g' => 1],
        ['name_zh' => '菠菜', 'name_en' => 'spinach', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 23, 'protein_g' => 2.9, 'carbs_g' => 3.6, 'fat_g' => 0.4, 'fiber_g' => 2.2, 'sodium_mg' => 79, 'sugar_g' => 0.4],
        ['name_zh' => '小黃瓜', 'name_en' => 'cucumber', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 16, 'protein_g' => 0.7, 'carbs_g' => 3.6, 'fat_g' => 0.1, 'fiber_g' => 0.5, 'sodium_mg' => 2, 'sugar_g' => 1.7],
        ['name_zh' => '番茄', 'name_en' => 'tomato', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 18, 'protein_g' => 0.9, 'carbs_g' => 3.9, 'fat_g' => 0.2, 'fiber_g' => 1.2, 'sodium_mg' => 5, 'sugar_g' => 2.6],
        ['name_zh' => '紅蘿蔔', 'name_en' => 'carrot', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 41, 'protein_g' => 0.9, 'carbs_g' => 10, 'fat_g' => 0.2, 'fiber_g' => 2.8, 'sodium_mg' => 69, 'sugar_g' => 4.7],
        ['name_zh' => '玉米筍', 'name_en' => 'baby corn', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 26, 'protein_g' => 2.5, 'carbs_g' => 5, 'fat_g' => 0.5, 'fiber_g' => 2.7, 'sodium_mg' => 47, 'sugar_g' => 2],
        ['name_zh' => '香菇', 'name_en' => 'shiitake mushroom', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 34, 'protein_g' => 2.2, 'carbs_g' => 7, 'fat_g' => 0.5, 'fiber_g' => 2.5, 'sodium_mg' => 9, 'sugar_g' => 2.4],
        ['name_zh' => '金針菇', 'name_en' => 'enoki mushroom', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 37, 'protein_g' => 2.7, 'carbs_g' => 7, 'fat_g' => 0.3, 'fiber_g' => 2.7, 'sodium_mg' => 3, 'sugar_g' => 0.2],
        ['name_zh' => '茄子', 'name_en' => 'eggplant', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 25, 'protein_g' => 1, 'carbs_g' => 6, 'fat_g' => 0.2, 'fiber_g' => 3, 'sodium_mg' => 2, 'sugar_g' => 3.5],
        ['name_zh' => '青椒', 'name_en' => 'green bell pepper', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 20, 'protein_g' => 0.9, 'carbs_g' => 4.6, 'fat_g' => 0.2, 'fiber_g' => 1.7, 'sodium_mg' => 3, 'sugar_g' => 2.4],
        ['name_zh' => '甜椒', 'name_en' => 'red bell pepper', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 31, 'protein_g' => 1, 'carbs_g' => 6, 'fat_g' => 0.3, 'fiber_g' => 2.1, 'sodium_mg' => 4, 'sugar_g' => 4.2],
        ['name_zh' => '生菜沙拉', 'name_en' => 'salad', 'category' => 'veggie', 'element' => 'veggie', 'serving_weight_g' => 100, 'calories' => 18, 'protein_g' => 1.4, 'carbs_g' => 3.3, 'fat_g' => 0.2, 'fiber_g' => 2, 'sodium_mg' => 30, 'sugar_g' => 1],

        // ===== 水果 (fruit) =====
        ['name_zh' => '蘋果', 'name_en' => 'apple', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 150, 'calories' => 78, 'protein_g' => 0.4, 'carbs_g' => 21, 'fat_g' => 0.3, 'fiber_g' => 3.6, 'sodium_mg' => 1.5, 'sugar_g' => 16],
        ['name_zh' => '香蕉', 'name_en' => 'banana', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 100, 'calories' => 89, 'protein_g' => 1.1, 'carbs_g' => 23, 'fat_g' => 0.3, 'fiber_g' => 2.6, 'sodium_mg' => 1, 'sugar_g' => 12],
        ['name_zh' => '芭樂', 'name_en' => 'guava', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 150, 'calories' => 102, 'protein_g' => 4, 'carbs_g' => 21, 'fat_g' => 1.5, 'fiber_g' => 8, 'sodium_mg' => 3, 'sugar_g' => 13],
        ['name_zh' => '奇異果', 'name_en' => 'kiwi', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 100, 'calories' => 61, 'protein_g' => 1.1, 'carbs_g' => 14.7, 'fat_g' => 0.5, 'fiber_g' => 3, 'sodium_mg' => 3, 'sugar_g' => 9],
        ['name_zh' => '葡萄', 'name_en' => 'grapes', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 100, 'calories' => 69, 'protein_g' => 0.7, 'carbs_g' => 18, 'fat_g' => 0.2, 'fiber_g' => 0.9, 'sodium_mg' => 2, 'sugar_g' => 16],
        ['name_zh' => '橘子', 'name_en' => 'orange', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 130, 'calories' => 60, 'protein_g' => 1.2, 'carbs_g' => 15, 'fat_g' => 0.2, 'fiber_g' => 3, 'sodium_mg' => 0, 'sugar_g' => 12],
        ['name_zh' => '柳橙', 'name_en' => 'orange', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 130, 'calories' => 60, 'protein_g' => 1.2, 'carbs_g' => 15, 'fat_g' => 0.2, 'fiber_g' => 3, 'sodium_mg' => 0, 'sugar_g' => 12],
        ['name_zh' => '木瓜', 'name_en' => 'papaya', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 150, 'calories' => 65, 'protein_g' => 1, 'carbs_g' => 16, 'fat_g' => 0.3, 'fiber_g' => 2.6, 'sodium_mg' => 12, 'sugar_g' => 12],
        ['name_zh' => '西瓜', 'name_en' => 'watermelon', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 200, 'calories' => 60, 'protein_g' => 1.2, 'carbs_g' => 15, 'fat_g' => 0.3, 'fiber_g' => 0.8, 'sodium_mg' => 2, 'sugar_g' => 12],
        ['name_zh' => '草莓', 'name_en' => 'strawberry', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 100, 'calories' => 32, 'protein_g' => 0.7, 'carbs_g' => 7.7, 'fat_g' => 0.3, 'fiber_g' => 2, 'sodium_mg' => 1, 'sugar_g' => 4.9],
        ['name_zh' => '藍莓', 'name_en' => 'blueberry', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 100, 'calories' => 57, 'protein_g' => 0.7, 'carbs_g' => 14.5, 'fat_g' => 0.3, 'fiber_g' => 2.4, 'sodium_mg' => 1, 'sugar_g' => 10],
        ['name_zh' => '芒果', 'name_en' => 'mango', 'category' => 'fruit', 'element' => 'sweet', 'serving_weight_g' => 150, 'calories' => 90, 'protein_g' => 1.4, 'carbs_g' => 22, 'fat_g' => 0.6, 'fiber_g' => 2.6, 'sodium_mg' => 1, 'sugar_g' => 20],

        // ===== 飲品 (drink) =====
        ['name_zh' => '黑咖啡', 'name_en' => 'black coffee', 'category' => 'drink', 'element' => 'drink', 'serving_weight_g' => 240, 'calories' => 2, 'protein_g' => 0.3, 'carbs_g' => 0, 'fat_g' => 0, 'fiber_g' => 0, 'sodium_mg' => 5, 'sugar_g' => 0],
        ['name_zh' => '無糖綠茶', 'name_en' => 'unsweetened green tea', 'category' => 'drink', 'element' => 'drink', 'serving_weight_g' => 500, 'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0, 'fiber_g' => 0, 'sodium_mg' => 0, 'sugar_g' => 0],
        ['name_zh' => '無糖紅茶', 'name_en' => 'unsweetened black tea', 'category' => 'drink', 'element' => 'drink', 'serving_weight_g' => 500, 'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0, 'fiber_g' => 0, 'sodium_mg' => 0, 'sugar_g' => 0],
        ['name_zh' => '低脂牛奶', 'name_en' => 'low-fat milk', 'category' => 'dairy', 'element' => 'drink', 'serving_weight_g' => 240, 'calories' => 100, 'protein_g' => 8, 'carbs_g' => 12, 'fat_g' => 2.4, 'fiber_g' => 0, 'sodium_mg' => 100, 'sugar_g' => 12],
        ['name_zh' => '全脂牛奶', 'name_en' => 'whole milk', 'category' => 'dairy', 'element' => 'drink', 'serving_weight_g' => 240, 'calories' => 150, 'protein_g' => 8, 'carbs_g' => 12, 'fat_g' => 8, 'fiber_g' => 0, 'sodium_mg' => 100, 'sugar_g' => 12],
        ['name_zh' => '氣泡水', 'name_en' => 'sparkling water', 'category' => 'drink', 'element' => 'drink', 'serving_weight_g' => 500, 'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0, 'fiber_g' => 0, 'sodium_mg' => 30, 'sugar_g' => 0],

        // ===== 常見台灣餐 (mixed) =====
        ['name_zh' => '雞肉飯', 'name_en' => 'chicken rice', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 350, 'calories' => 480, 'protein_g' => 25, 'carbs_g' => 70, 'fat_g' => 10, 'fiber_g' => 2, 'sodium_mg' => 800, 'sugar_g' => 3],
        ['name_zh' => '滷肉飯', 'name_en' => 'braised pork rice', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 300, 'calories' => 480, 'protein_g' => 14, 'carbs_g' => 60, 'fat_g' => 18, 'fiber_g' => 1.5, 'sodium_mg' => 1000, 'sugar_g' => 5],
        ['name_zh' => '牛肉麵', 'name_en' => 'beef noodle soup', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 600, 'calories' => 600, 'protein_g' => 35, 'carbs_g' => 75, 'fat_g' => 18, 'fiber_g' => 4, 'sodium_mg' => 2000, 'sugar_g' => 5],
        ['name_zh' => '陽春麵', 'name_en' => 'plain noodle soup', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 400, 'calories' => 350, 'protein_g' => 10, 'carbs_g' => 65, 'fat_g' => 5, 'fiber_g' => 3, 'sodium_mg' => 1300, 'sugar_g' => 2],
        ['name_zh' => '魯味', 'name_en' => 'taiwanese braised', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 250, 'calories' => 280, 'protein_g' => 18, 'carbs_g' => 18, 'fat_g' => 14, 'fiber_g' => 4, 'sodium_mg' => 1100, 'sugar_g' => 3],
        ['name_zh' => '便當', 'name_en' => 'lunch box', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 500, 'calories' => 750, 'protein_g' => 30, 'carbs_g' => 90, 'fat_g' => 25, 'fiber_g' => 5, 'sodium_mg' => 1200, 'sugar_g' => 6],
        ['name_zh' => '水餃', 'name_en' => 'dumplings', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 200, 'calories' => 380, 'protein_g' => 14, 'carbs_g' => 45, 'fat_g' => 14, 'fiber_g' => 2, 'sodium_mg' => 800, 'sugar_g' => 2],
        ['name_zh' => '小籠包', 'name_en' => 'xiaolongbao', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 200, 'calories' => 360, 'protein_g' => 14, 'carbs_g' => 42, 'fat_g' => 14, 'fiber_g' => 1.5, 'sodium_mg' => 700, 'sugar_g' => 2],
        ['name_zh' => '蘿蔔糕', 'name_en' => 'turnip cake', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 150, 'calories' => 220, 'protein_g' => 4, 'carbs_g' => 30, 'fat_g' => 9, 'fiber_g' => 1.5, 'sodium_mg' => 600, 'sugar_g' => 2],
        ['name_zh' => '飯糰', 'name_en' => 'rice ball', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 200, 'calories' => 360, 'protein_g' => 10, 'carbs_g' => 60, 'fat_g' => 8, 'fiber_g' => 2, 'sodium_mg' => 700, 'sugar_g' => 2],
        ['name_zh' => '饅頭', 'name_en' => 'mantou', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 100, 'calories' => 230, 'protein_g' => 7, 'carbs_g' => 47, 'fat_g' => 1, 'fiber_g' => 1.5, 'sodium_mg' => 230, 'sugar_g' => 3],
        ['name_zh' => '蛋餅', 'name_en' => 'egg pancake', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 150, 'calories' => 280, 'protein_g' => 11, 'carbs_g' => 28, 'fat_g' => 14, 'fiber_g' => 1, 'sodium_mg' => 600, 'sugar_g' => 2],
        ['name_zh' => '三明治', 'name_en' => 'sandwich', 'category' => 'carb', 'element' => 'carb', 'serving_weight_g' => 200, 'calories' => 380, 'protein_g' => 17, 'carbs_g' => 40, 'fat_g' => 16, 'fiber_g' => 2.5, 'sodium_mg' => 750, 'sugar_g' => 5],
        ['name_zh' => '生菜雞肉捲', 'name_en' => 'lettuce chicken wrap', 'category' => 'protein', 'element' => 'protein', 'serving_weight_g' => 250, 'calories' => 320, 'protein_g' => 30, 'carbs_g' => 18, 'fat_g' => 14, 'fiber_g' => 3, 'sodium_mg' => 600, 'sugar_g' => 3],

        // ===== 點心 (snack) =====
        ['name_zh' => '無糖燕麥棒', 'name_en' => 'unsweetened oat bar', 'category' => 'snack', 'element' => 'carb', 'serving_weight_g' => 30, 'calories' => 110, 'protein_g' => 3, 'carbs_g' => 18, 'fat_g' => 3, 'fiber_g' => 2, 'sodium_mg' => 50, 'sugar_g' => 5],
        ['name_zh' => '黑巧克力', 'name_en' => 'dark chocolate', 'category' => 'snack', 'element' => 'sweet', 'serving_weight_g' => 25, 'calories' => 130, 'protein_g' => 2, 'carbs_g' => 10, 'fat_g' => 9, 'fiber_g' => 2.5, 'sodium_mg' => 6, 'sugar_g' => 6],
        ['name_zh' => '原味堅果', 'name_en' => 'mixed nuts', 'category' => 'snack', 'element' => 'fat', 'serving_weight_g' => 30, 'calories' => 180, 'protein_g' => 5, 'carbs_g' => 6, 'fat_g' => 16, 'fiber_g' => 3, 'sodium_mg' => 1, 'sugar_g' => 1],
        ['name_zh' => '酪梨', 'name_en' => 'avocado', 'category' => 'fruit', 'element' => 'fat', 'serving_weight_g' => 100, 'calories' => 160, 'protein_g' => 2, 'carbs_g' => 9, 'fat_g' => 15, 'fiber_g' => 7, 'sodium_mg' => 7, 'sugar_g' => 0.7],
    ];
}
