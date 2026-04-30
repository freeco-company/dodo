<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

/**
 * 知識庫種子內容 — Phase 5 v1 (2026-04-30)。
 *
 * 8 篇手寫文章 cover 主要 category，給 App 端有東西可顯示。
 * Phase 5c OCR pipeline 會把 storage/seed/nutrition_kb/raw/ 160 張影像
 * 補進來；本 seeder 只是 demo / fallback。
 *
 * 朵朵語氣：妳 / 朋友 / 不寫您 / 會員。
 */
class KnowledgeArticleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $articles = [
            [
                'slug' => 'protein-basics',
                'title' => '蛋白質：每天到底要吃多少？',
                'category' => 'protein',
                'tags' => ['基礎', '減脂期', '維持期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '一般成人每公斤體重 1.0-1.6g 蛋白質，減脂期可拉高到 1.6-2.0g。',
                'body' => "蛋白質建議攝取量：\n- 一般成人：1.0-1.2g/kg\n- 規律運動：1.2-1.6g/kg\n- 減脂期：1.6-2.0g/kg\n- 老年人：建議 1.2g/kg 以上以維持肌肉量",
                'dodo_voice_body' => "蛋白質要吃多少，朵朵教妳算 ✨\n\n看一下自己的體重 × 一個倍數：\n• 一般生活：×1.0-1.2 g\n• 有運動：×1.2-1.6 g\n• 減脂中：×1.6-2.0 g\n\n例如妳 60 kg 在減脂，目標就是 96-120 g 蛋白質一天。\n\n小提醒：蛋白質要分散在三餐吃，一次塞太多吸收不完哦～",
                'reading_time_seconds' => 90,
            ],
            [
                'slug' => 'fiber-25g-rule',
                'title' => '纖維 25g 是怎麼來的？',
                'category' => 'fiber',
                'tags' => ['基礎'],
                'audience' => ['retail'],
                'summary' => '台灣國健署建議成人每日 25-35g 膳食纖維，多數人吃不到一半。',
                'body' => "膳食纖維每日建議量為 25-35g。台灣成人實際攝取平均約 14g，僅達建議量一半。",
                'dodo_voice_body' => "纖維是腸道的好朋友！\n\n每天目標 25-35 g，但大多數朋友只吃到一半左右…\n\n簡單補充法：\n🥬 一個蘋果 ≈ 4g\n🥦 一碗綠花椰 ≈ 5g\n🍠 半條地瓜 ≈ 3g\n\n餐餐配個蔬菜，妳就贏一半了 🌿",
                'reading_time_seconds' => 60,
            ],
            [
                'slug' => 'water-rule-of-thumb',
                'title' => '一天該喝多少水？',
                'category' => 'water',
                'tags' => ['基礎', '生活'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '基本公式：體重 (kg) × 30-40 ml；運動 / 高溫天再加 500ml。',
                'body' => "水分需求估算公式：體重(kg) × 30-40 ml。例：60kg 成人約需 1800-2400 ml。",
                'dodo_voice_body' => "妳今天喝水了嗎？\n\n簡單公式：體重 × 30-40 ml\n例如 60 kg 朋友 → 1800-2400 ml\n\n運動 / 流汗天記得多 500 ml～\n\n小撇步：早晨起床先一杯，飯前一杯，運動時別等渴了才喝 ✨",
                'reading_time_seconds' => 50,
            ],
            [
                'slug' => 'sugar-hidden-traps',
                'title' => '隱形糖陷阱：這些食物糖比你想像多',
                'category' => 'myth_busting',
                'tags' => ['減脂期', '飲品'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '優格、果汁、燕麥棒、素肉乾、即食粥...這些「健康食品」糖量都不低。',
                'body' => "高糖陷阱食品：\n- 風味優格 (200ml)：12-18g 糖\n- 100% 純果汁 (250ml)：22-28g 糖\n- 燕麥棒：8-15g 糖\n- 素肉乾：醃料含糖 5-12g/100g\n- 即食粥：澱粉 + 額外糖，升糖快",
                'dodo_voice_body' => "這些「看起來健康」的東西其實糖很高，朵朵幫妳整理 🔍\n\n• 風味優格 (200ml) — 12-18 g 糖\n• 純果汁 — 比可樂只低一點\n• 燕麥棒 — 是甜點不是早餐\n• 素肉乾 — 醃料藏糖\n• 即食粥 — 升糖比白飯快\n\n標籤上「碳水化合物 - 糖」那一欄看一下，妳會嚇一跳！",
                'reading_time_seconds' => 75,
            ],
            [
                'slug' => 'convenience-store-protein-picks',
                'title' => '便利店蛋白選物清單',
                'category' => 'product_match',
                'tags' => ['便利商店', '減脂期', '維持期'],
                'audience' => ['retail'],
                'summary' => '7-11 / 全家裡這些東西蛋白質高、熱量適中，可以閉著眼睛拿。',
                'body' => "便利店高蛋白選擇：\n- 茶葉蛋 1 顆 ≈ 7g 蛋白\n- 沙拉雞胸 130g ≈ 25-30g\n- 無糖豆漿 450ml ≈ 12-15g\n- 希臘優格 100g ≈ 9-10g\n- 鮪魚飯糰 ≈ 10-15g",
                'dodo_voice_body' => "便利店其實藏了好多高蛋白寶藏 ✨\n\n隨手可拿：\n🥚 茶葉蛋 — 7 g 蛋白 / 顆\n🐔 沙拉雞胸 — 25-30 g (主餐等級)\n🥛 無糖豆漿 - 12-15 g\n🥄 希臘優格 — 9-10 g\n🍙 鮪魚飯糰 — 10-15 g\n\n下次卡關不知吃啥，閉著眼從這幾樣裡挑 💪",
                'reading_time_seconds' => 80,
            ],
            [
                'slug' => 'cutting-vs-maintenance',
                'title' => '減脂期 vs 維持期，吃法差在哪？',
                'category' => 'cutting',
                'tags' => ['減脂期', '維持期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '減脂熱量赤字 -300~500 kcal、蛋白拉高、碳水降但不歸零。維持期回 TDEE。',
                'body' => "減脂期：總熱量 - 300~500 kcal、蛋白 1.6-2.0g/kg、碳水降 25%、油脂保留。\n維持期：回 TDEE、蛋白 1.2-1.4g/kg、碳水油脂正常。",
                'dodo_voice_body' => "減脂跟維持要怎麼分？朵朵簡單畫給妳：\n\n🔥 減脂期：\n• 熱量比平常少 300-500\n• 蛋白拉到 1.6-2.0 倍體重\n• 碳水降一點不歸零（會崩潰）\n• 油脂留著，飽足感救星\n\n☀️ 維持期：\n• 熱量回到 TDEE\n• 蛋白 1.2-1.4 倍\n• 碳水油脂正常吃\n\n減脂太久身體會抗議。每 12 週給自己 2 週維持期，朵朵推薦 ✨",
                'reading_time_seconds' => 100,
            ],
            [
                'slug' => 'meal-timing-myth',
                'title' => '晚上 8 點後不能吃？真的假的',
                'category' => 'myth_busting',
                'tags' => ['謬誤'],
                'audience' => ['retail'],
                'summary' => '研究顯示「總熱量」比「進食時間」影響大。但晚餐重質、避免高糖宵夜還是有道理。',
                'body' => "近期 meta-analysis 顯示，總熱量是體重變化主因。進食時間影響相對較小。但夜間進食對睡眠 / 血糖波動仍有影響。",
                'dodo_voice_body' => "「8 點後不能吃」是真的嗎？\n\n答案是：沒那麼絕對。\n\n• 總熱量 > 進食時間（這是主軸）\n• 但晚餐吃高糖高油 → 隔天精神差、血糖飄\n\n朵朵建議：\n如果妳晚回家肚子餓，別硬餓，吃個蛋白 + 蔬菜的小份就好。\n硬撐到睡覺反而更容易半夜亂吃 🌙",
                'reading_time_seconds' => 70,
            ],
            [
                'slug' => 'breakfast-protein-match',
                'title' => '早餐蛋白吃夠，整天比較不餓',
                'category' => 'meal_timing',
                'tags' => ['基礎', '減脂期'],
                'audience' => ['retail'],
                'summary' => '早餐蛋白 25-30g 可降低中午暴食機率。研究實證效應。',
                'body' => "高蛋白早餐 (25-30g 蛋白) 與低蛋白早餐相比，整日總熱量攝取平均低 200-300 kcal。",
                'dodo_voice_body' => "早餐吃對蛋白，整天會輕鬆很多 ✨\n\n研究證實：\n早餐 25-30 g 蛋白 → 中午暴食機率下降 → 整天少吃 200-300 卡\n\n簡單做法：\n• 兩顆蛋 + 一杯豆漿 ≈ 28g\n• 希臘優格 + 雞胸三明治 ≈ 35g\n• 無糖豆漿 + 茶葉蛋 ×2 ≈ 26g\n\n試一週，妳會回來謝朵朵 💪",
                'reading_time_seconds' => 65,
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
