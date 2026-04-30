<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

/**
 * Phase 5c — Batch 2 OCR-extracted articles (6 篇).
 *
 * 2026-05-01 — 繼續 read storage/seed/nutrition_kb/raw/ 影像
 * 結構化 + 朵朵語氣改寫。Source = JEROSSE 婕樂纖營養師群組分享 +
 * 民視新聞台 + Rachel Nutrition (Burd et al. 2009 圖) + 亞洲大學附設醫院。
 */
class KbOcrBatch2Seeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $articles = [
            [
                'slug' => 'kuruma-cake-calorie-comparison',
                'title' => '車輪餅熱量大比拚 — 一顆逼近一碗飯',
                'category' => 'qna',
                'tags' => ['點心', '夜市', '比較', '隱形熱量'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '車輪餅一顆 185-265 大卡，相當於一碗飯。減重期一週吃一次就好。',
                'body' => "甜口味：奶油 265 / 紅豆 240 / 巧克力 250 / 地瓜 255 大卡\n鹹口味：菜脯 185 / 鮪魚玉米 240 / 玉米起司 238 / 肉鬆 255 大卡\n\n一顆熱量逼近一碗飯（約 280 大卡）。減重期間控制頻率，一週吃一次就好。",
                'dodo_voice_body' => "下班買兩顆車輪餅當點心？朵朵幫妳算帳 🥮\n\n甜口味\n• 奶油 265 / 紅豆 240 / 巧克力 250 / 地瓜 255\n\n鹹口味\n• 菜脯 185 / 鮪魚玉米 240 / 玉米起司 238 / 肉鬆 255\n\n（單位：大卡 / 顆）\n\n一顆 ≈ 一碗飯熱量 😱\n\n減脂期 → 一週一次當享受就好，配無糖茶慢慢吃 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610717217646182605.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'fruit-gi-classification',
                'title' => '水果 GI 值紅綠燈 — 一份約一拳頭',
                'category' => 'qna',
                'tags' => ['水果', 'GI', '減脂期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '常見水果按 GI 值分三級。高 GI 像西瓜榴槤荔枝；低 GI 像蘋果芭樂奇異果。',
                'body' => "🔴 GI > 70（升糖快）：西瓜、榴槤、荔枝、龍眼\n🟡 GI 55-70（中等）：葡萄、鳳梨、香蕉、草莓、桃子、芒果、木瓜\n🟢 GI < 55（升糖慢）：番茄、芭樂、奇異果、櫻桃、葡萄柚、柳橙、梨子、蘋果\n\n即使選低 GI 仍要避免過量。建議一天 2 份為上限，每次一拳頭份量。\n資料來源：亞洲大學附設醫院",
                'dodo_voice_body' => "水果不是吃越多越好喔～朵朵幫妳排紅綠燈 🍎\n\n🔴 GI > 70（升糖快）\n西瓜 / 榴槤 / 荔枝 / 龍眼\n\n🟡 GI 55-70（中等）\n葡萄 / 鳳梨 / 香蕉 / 草莓 / 桃子 / 芒果 / 木瓜\n\n🟢 GI < 55（升糖慢）\n番茄 / 芭樂 / 奇異果 / 櫻桃 / 葡萄柚 / 柳橙 / 梨子 / 蘋果\n\n減脂期挑綠燈那欄優先 ✨\n但記得 — 即使低 GI 吃多一樣會胖。\n\n📏 一份 = 一拳頭，一天 2 份就好",
                'reading_time_seconds' => 90,
                'source_image' => '610731969096974500.jpg',
                'source_attribution' => '亞洲大學附設醫院',
            ],
            [
                'slug' => 'exercise-deficit-blood-pressure',
                'title' => '每週運動不到 1 小時 = 高血壓風險',
                'category' => 'lifestyle',
                'tags' => ['運動', '心血管', '健康風險'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '研究顯示每週運動不到 60 分鐘者，血壓上升 30-40 毫米，風險如高血壓患者。',
                'body' => "新聞研究指出，每週運動不到 60 分鐘的人，身體狀態接近高血壓患者：\n- 血壓上升 30-40 毫米汞柱\n- 心血管負擔增加\n\n目標：每週中等強度運動 150 分鐘（每週 5 天 × 30 分鐘），或高強度 75 分鐘。",
                'dodo_voice_body' => "妳上週運動了多久？朵朵不是要逼妳，但這個數據真的有點驚 😬\n\n📊 研究：每週運動 < 60 分鐘的人\n→ 血壓上升 30-40 毫米汞柱\n→ 風險接近高血壓患者\n\n但好消息是：\n門檻其實不高 ✨\n\n每週 150 分鐘中等強度（5 天 × 30 分鐘）\n= 健走、騎車、家事都算\n\n從今天先走 30 分鐘起步吧 💪",
                'reading_time_seconds' => 60,
                'source_image' => '610743578662535347.jpg',
                'source_attribution' => '民視新聞台',
            ],
            [
                'slug' => 'post-workout-protein-foods',
                'title' => '運動後吃什麼補蛋白？',
                'category' => 'product_match',
                'tags' => ['運動', '蛋白質', '補給'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '運動後盡快補蛋白：無糖豆漿、溫泉蛋、茶葉蛋、雞胸肉、蛋白飲品。',
                'body' => "運動後 30 分鐘是肌肉合成黃金期，建議盡快補充：\n- 無糖豆漿（植物蛋白 + 低熱量）\n- 溫泉蛋 / 茶葉蛋（完全蛋白 + 方便取得）\n- 雞胸肉（高蛋白低脂）\n- 蛋白飲品 / 高蛋白奶茶\n\n運動後越快吃，肌肉合成效率越好。不吃反而會肌肉流失。",
                'dodo_voice_body' => "剛運動完肚子餓？朵朵推薦這幾個 💪\n\n🥛 無糖豆漿（植物蛋白 + 低熱量）\n🍳 溫泉蛋 / 茶葉蛋（蛋白完整 + 便利商店有）\n🍗 雞胸肉（高蛋白低脂）\n🍵 蛋白奶茶 / 蛋白飲（速效）\n\n關鍵：30 分鐘內補上\n\n「運動完不吃會瘦更快」是錯的！\n不吃 = 肌肉分解 → 基礎代謝下降 → 反而難瘦 😱\n\n吃對才是關鍵 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610744780884541735.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'muscle-protein-synthesis-curve',
                'title' => '肌肉蛋白合成 vs 分解 — 為什麼不能空腹太久',
                'category' => 'qna',
                'tags' => ['蛋白質', '增肌', '科學'],
                'audience' => ['retail', 'franchisee'],
                'summary' => 'Burd 2009 研究：進食啟動合成，空腹則啟動分解。長時間不吃 = 肌肉流失。',
                'body' => "Burd et al. (2009) 經典研究：\n- 阻力訓練後 + 進食 → 啟動肌肉蛋白合成（曲線上升）\n- 空腹太久 → 肌肉蛋白分解（流失）\n\n結論：每隔 3-5 小時補一次蛋白，避免長時間空腹，肌肉才能持續合成。減脂不等於餓肚子。",
                'dodo_voice_body' => "為什麼朵朵一直叫妳「不要餓太久」？因為這張圖 📊\n\nBurd 2009 研究：\n• 進食 + 阻力訓練 → 肌肉合成（曲線↑）\n• 空腹太久 → 肌肉分解（流失）\n\n白話翻譯：\n餓肚子 ≠ 瘦得好\n餓肚子 = 肌肉先掉，基礎代謝跟著掉，越減越難減\n\n建議節奏：\n每 3-5 小時補一次蛋白質\n（早餐 / 午餐 / 點心 / 晚餐）\n\n減脂是吃對，不是不吃 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610745212527968675.jpg',
                'source_attribution' => 'Rachel Nutrition / Burd et al. 2009',
            ],
            [
                'slug' => 'low-gi-selection-6-tips',
                'title' => '低 GI 食物挑選 6 重點',
                'category' => 'qna',
                'tags' => ['GI', '減脂期', '食材'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '未加工 / 高纖 / 短烹調 / 原型 / 含酸 / 簡單烹調 — 6 個面向挑低 GI 食物。',
                'body' => "低 GI 食物挑選 6 個面向：\n1. 未加工精緻化：燕麥 > 燕麥奶\n2. 膳食纖維高：地瓜 > 白飯\n3. 烹調時間短：白飯 > 粥（煮越久 GI 越高）\n4. 原型食材：新鮮水果 > 奇異果汁\n5. 含酸量高：芭樂 > 西瓜\n6. 烹調方式簡單：清蒸 > 油炸\n\n注意：低 GI 吃多一樣會胖。控量 + 選對食物雙管齊下。",
                'dodo_voice_body' => "想吃得健康但不知道怎麼挑？朵朵 6 招給妳 ✨\n\n1️⃣ 未加工 — 燕麥 > 燕麥奶\n2️⃣ 高纖 — 地瓜 > 白飯\n3️⃣ 短烹調 — 白飯 > 粥（煮越久 GI 越高！）\n4️⃣ 原型 — 新鮮水果 > 奇異果汁\n5️⃣ 含酸 — 芭樂 > 西瓜\n6️⃣ 簡單烹調 — 清蒸 > 油炸\n\n⚠️ 重要提醒：\n低 GI 食物吃多一樣會胖！\n\n挑對食物 + 控制份量 = 雙管齊下才有效 💪",
                'reading_time_seconds' => 90,
                'source_image' => '610745464723865860.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
        ];

        foreach ($articles as $a) {
            KnowledgeArticle::updateOrCreate(
                ['slug' => $a['slug']],
                array_merge($a, ['published_at' => $now]),
            );
        }
    }
}
