<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

/**
 * Phase 5c — Batch 1 OCR-extracted articles (15 篇).
 *
 * 2026-04-30 — 我（Claude）直接 read storage/seed/nutrition_kb/raw/ 影像，
 * 結構化成 KnowledgeArticle 草稿（直接 publish）。內容來自 JEROSSE 婕樂纖
 * 營養師群組分享 + 中視 / 三立新聞網 飲食報導 (fair use, 教育用途，
 * source_attribution 已標註)。
 *
 * 朵朵語氣 follow group-naming-and-voice.md（妳/朋友, never 您/會員）。
 *
 * 後續若 user 設 ANTHROPIC_API_KEY 跑 `php artisan kb:ocr-import`，
 * dedupe by source_image，本 seeder 已處理的會 skip。
 */
class KbOcrBatch1Seeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $articles = [
            [
                'slug' => 'black-sugar-ginger-tea-trap',
                'title' => '黑糖薑母茶 = 半碗飯',
                'category' => 'myth_busting',
                'tags' => ['隱形糖', '飲品', '體態管理期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '一份黑糖薑母茶碳水 30.2g，差不多是半碗白飯的份量。',
                'body' => "黑糖薑母茶看起來健康，但碳水化合物每份高達 30.2g，相當於半碗白飯。每包 16 入，每份 44g，總熱量同樣不容小覷。喝看起來「養生」的飲品時，記得看一下背面營養標。",
                'dodo_voice_body' => "「黑糖薑母茶很養生吧？」朵朵幫妳翻一下背面標籤 🔍\n\n• 每份碳水化合物 30.2g\n• 約等於半碗白飯\n• 還有黑糖額外的精緻糖\n\n冬天偶爾喝暖一下沒關係，但別把它當水喝啊朋友～",
                'reading_time_seconds' => 60,
                'source_image' => '1730859795939_0.jpg',
                'source_attribution' => '營養師群組分享 / 商品標示 (2026-04-30 OCR)',
            ],
            [
                'slug' => 'breakfast-toast-equals-rice',
                'title' => '早餐店切邊吐司 2 片 = 半碗飯',
                'category' => 'myth_busting',
                'tags' => ['早餐', '隱形碳水'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '吐司是高精緻澱粉 + 油鹽糖，2 片切邊吐司碳水量等於半碗白飯。',
                'body' => "早餐店常見的切邊吐司 2 片，碳水化合物約等於半碗白飯。吐司本身就是高精緻澱粉 + 油 + 鹽 + 糖的組合，吃太頻繁容易升糖快、餓得也快。控制頻率，偶爾吃就好。",
                'dodo_voice_body' => "妳早餐店常點的切邊吐司啊⋯\n\n2 片 = 半碗飯（碳水量）\n\n而且還包含：\n• 高精緻澱粉\n• 油 + 鹽 + 糖添加\n• 升糖快\n\n不是不能吃，但別天天當早餐 🍞 想換口味試試看蛋白質 + 全穀雜糧的組合。",
                'reading_time_seconds' => 45,
                'source_image' => '610711864557174791.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'wholewheat-toast-not-slim',
                'title' => '全麥吐司 ≠ 不會胖',
                'category' => 'myth_busting',
                'tags' => ['早餐', '標籤陷阱', '體態管理期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '市售全麥吐司多是精緻澱粉 + 油鹽糖添加物，跟一般吐司差不多。',
                'body' => "「全麥吐司」聽起來很健康，但市售品多用全麥麵粉與一般麵粉混合，仍屬於精緻澱粉。常見成分還有花生油、棕櫚油、糖漿、酵母、日常保養劑等添加物。容易吃過量、吃不飽。想吃複合碳水建議改選地瓜、南瓜、馬鈴薯、燕麥、雜糧。",
                'dodo_voice_body' => "「但我吃的是全麥吐司耶」朵朵翻給妳看 🔍\n\n• 全麥麵粉 + 一般麵粉混合（仍是精緻澱粉）\n• 還有花生油、糖漿、酵母、添加物\n• 易吃過量、吃不飽\n\n想要真正的複合碳水？\n🍠 地瓜 / 南瓜 / 馬鈴薯\n🌾 燕麥 / 雜糧飯\n\n天然全穀雜糧 才是體態管理期的好朋友 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610711864674615469.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'greek-yogurt-vs-greek-style',
                'title' => '希臘優格 vs 希臘「式」優格',
                'category' => 'myth_busting',
                'tags' => ['標籤陷阱', '飲品', '蛋白質'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '一字之差，熱量翻倍：真希臘優格 56kcal / 100g，希臘「式」優格 137kcal / 100g。',
                'body' => "包裝上「希臘優格」和「希臘式優格」差一個字，熱量差很多：\n- 希臘優格 (Greek Yogurt)：每 100g 熱量 56.4 kcal、蛋白質 10g\n- 希臘式優格 (Greek STYLE Yogurt)：每 100g 熱量 137.6 kcal、蛋白質 3.12g\n\n真希臘優格用乳清過濾的傳統做法，蛋白質高、糖低；希臘「式」優格只是模仿口感，會加奶粉、糖、增稠劑來模擬濃稠。看背面成分表分辨。",
                'dodo_voice_body' => "妳買優格時有看清楚嗎？文字陷阱來了 🚨\n\n希臘優格 vs 希臘「式」優格 一字差很多：\n\n• 希臘優格（真）：56 大卡 / 蛋白 10g\n• 希臘「式」優格：137 大卡 / 蛋白 3.12g\n\n熱量翻倍、蛋白變 1/3！\n\n真希臘優格 = 過濾乳清的古法\n希臘「式」 = 模仿口感（加奶粉/糖/增稠劑）\n\n下次拿起來先看背面成分 ✨",
                'reading_time_seconds' => 90,
                'source_image' => '610566852183326814.jpg',
                'source_attribution' => '中視新聞報導',
            ],
            [
                'slug' => 'yogurt-additive-traps',
                'title' => '希臘式優格成分標籤陷阱',
                'category' => 'myth_busting',
                'tags' => ['標籤陷阱', '加工食品'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '質立希臘式優格成分含大奶粉、洋菜粉、柑橘果膠 — 都是「模擬濃稠口感」的添加物。',
                'body' => "市售希臘式優格的「濃稠口感」很多時候不是來自真正過濾的乳清，而是加入下列添加物模擬：\n- 大奶粉：補蛋白與口感\n- 洋菜粉：植物性增稠劑\n- 柑橘果膠：天然增稠 + 風味\n買優格時看一下成分表，越短越單純越好。",
                'dodo_voice_body' => "翻過希臘式優格的成分表嗎？\n\n常見的「濃稠秘密」：\n• 大奶粉（補蛋白＋口感）\n• 洋菜粉（增稠劑）\n• 柑橘果膠（天然增稠）\n\n不是說都不能吃，而是要知道妳吃下去的是什麼 🔍\n\n挑優格的小撇步：\n成分表越短 → 越接近真食物 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '1776757501870_0.jpg',
                'source_attribution' => '商品標示 (2026-04-30 OCR)',
            ],
            [
                'slug' => 'hotpot-ingredient-traffic-light',
                'title' => '火鍋料聰明吃 — 紅綠燈分類',
                'category' => 'product_match',
                'tags' => ['火鍋', '體態管理期', '社交餐'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '油條 148kcal / 麻吉燒 60kcal / 蒟蒻卷只有 2kcal — 火鍋料熱量差超大。',
                'body' => "火鍋料熱量差距驚人，按紅綠燈分：\n\n🔴 盡量不吃：油條 148 / 炸豆皮 95 / 百頁豆腐 91 / 麻吉燒 60 kcal\n🟡 適量挑選：甜不辣 32 / 鑫鑫腸 28 / 蟹肉棒 22 / 蛋餃 21 kcal\n🟢 熱量較低：魚板 10 / 魚卵捲 8 / 蒟蒻卷 2 kcal\n\n下次涮火鍋，紅燈那欄盡量繞過。",
                'dodo_voice_body' => "火鍋好吃但料的熱量差很大，朵朵幫妳排紅綠燈 🚦\n\n🔴 盡量不吃\n• 油條 148 大卡\n• 炸豆皮 95\n• 百頁豆腐 91（其實是高油加工，不是豆腐！）\n• 麻吉燒 60\n\n🟡 適量挑選\n• 甜不辣 32 / 鑫鑫腸 28 / 蟹肉棒 22 / 蛋餃 21\n\n🟢 安心吃\n• 魚板 10 / 魚卵捲 8 / 蒟蒻卷 2\n\n下次涮鍋夾菜時想一下這張表 ✨",
                'reading_time_seconds' => 100,
                'source_image' => '610575813599298186.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'healthy-hotpot-full-guide',
                'title' => '健康吃鍋不怕胖 — 全套搭配',
                'category' => 'product_match',
                'tags' => ['火鍋', '社交餐', '體態管理期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '湯底 / 配料 / 主食 / 肉品 / 醬料 / 飲料 — 6 個維度怎麼挑。',
                'body' => "吃鍋分 6 個維度：\n- 湯底 ✓ 昆布湯 / 番茄湯 / 蔬菜湯 ✗ 沙茶 / 咖哩 / 麻辣 / 起司牛奶\n- 配料 ✓ 蔬菜盤 ✗ 火鍋料（米血、百頁豆腐、甜不辣等加工品）\n- 主食 ✓ 白飯 / 麵條 / 地瓜 / 南瓜 / 芋頭（總量一個拳頭）✗ 王子麵 / 雞絲麵 / 鍋燒意麵\n- 肉品 ✓ 低脂海鮮 / 雞肉 / 梅花牛 / 板腱牛 ✗ 五花 / 雪花 / 培根\n- 醬料 ✓ 蔥薑蒜蘿蔔泥 / 醬油 / 醋 ✗ 沙茶醬 / 芝麻醬 / 辣油\n- 飲料 ✓ 無糖茶 / 黑咖啡 / 零卡可樂 ✗ 含糖飲料 / 啤酒",
                'dodo_voice_body' => "跟朋友吃鍋怎麼挑才不會踩雷？朵朵 6 維度給妳：\n\n🍲 湯底\n✓ 昆布 / 番茄 / 蔬菜湯\n✗ 沙茶 / 咖哩 / 麻辣 / 起司牛奶\n\n🥬 配料\n✓ 蔬菜盤\n✗ 火鍋料（米血、百頁豆腐、甜不辣 都是加工品）\n\n🍚 主食\n✓ 白飯 / 麵 / 地瓜 / 南瓜 / 芋頭（總量一拳頭）\n✗ 王子麵 / 雞絲麵\n\n🥩 肉品\n✓ 海鮮 / 雞肉 / 梅花牛 / 板腱牛\n✗ 五花 / 雪花 / 培根\n\n🥄 醬料\n✓ 蔥薑蒜 / 醬油 / 醋\n✗ 沙茶 / 芝麻醬 / 辣油\n\n🥤 飲料\n✓ 無糖茶 / 黑咖啡\n✗ 含糖飲料 / 啤酒\n\n收進收藏吧～聚餐前複習一下 ✨",
                'reading_time_seconds' => 150,
                'source_image' => '610575503052767370.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'teppanyaki-cutting-version',
                'title' => '體態管理期鐵板燒怎麼吃 — 1000 → 500 大卡',
                'category' => 'cutting',
                'tags' => ['鐵板燒', '體態管理期', '外食'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '一般鐵板燒套餐 1000 大卡，調整一下能砍半到 500 大卡。',
                'body' => "一般鐵板燒：主餐 + 飯 1 碗 + 紅茶 1 杯 ≈ 1000 大卡\n體態管理版：乾煎肉類/海鮮 + 少油少鹽 + 飯半碗或不吃 ≈ 500 大卡\n\n關鍵 3 招：\n1. 主餐改乾煎，避免重醬汁\n2. 跟廚師說「少油少鹽」\n3. 飯減半或不吃，改加蔬菜",
                'dodo_voice_body' => "體態管理期還想吃鐵板燒？朵朵教妳腰斬熱量 ✨\n\n一般版：1000 大卡\n• 主餐\n• 飯 1 碗\n• 紅茶 1 杯（含糖）\n\n↓ 簡單調整 ↓\n\n體態管理版：500 大卡\n• 乾煎肉類/海鮮（避開重醬）\n• 少油少鹽（直接跟師傅說）\n• 飯半碗或不吃，改加蔬菜\n\n3 招：乾煎、少油少鹽、飯減半\n\n外食也能跟身材好朋友 💪",
                'reading_time_seconds' => 75,
                'source_image' => '610698738884935953.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'milk-vs-plant-milk-compared',
                'title' => '牛奶 vs 植物奶營養大評比',
                'category' => 'qna',
                'tags' => ['飲品', '蛋白質', '比較'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '4 種奶 (牛 / 燕麥 / 杏仁 / 豆) 各 100g 熱量、蛋白、碳水、纖維大不同。',
                'body' => "每 100g 比較：\n- 牛奶：63 kcal / 蛋白 3.0g / 碳水 4.8g / 纖維 0g\n- 燕麥奶：44 kcal / 蛋白 1.0g / 碳水 8.1g (最高) / 纖維 1.1g\n- 杏仁奶：13 kcal (最低) / 蛋白 0.4g / 碳水 0.3g (最低) / 纖維 0.3g\n- 豆奶：35 kcal / 蛋白 3.6g (最高) / 碳水 1.7g / 纖維 1.3g (最高)\n\n要蛋白選豆奶；要纖維選豆奶或燕麥；要低卡選杏仁奶但要注意蛋白也低。\n*杏仁奶以 Alpro 無糖款為例，其餘為衛福部食品營養成分資料庫",
                'dodo_voice_body' => "妳早餐配什麼奶？朵朵幫妳排排比 🥛\n\n每 100g：\n\n🐄 牛奶 — 63 大卡 / 蛋白 3.0g\n🌾 燕麥奶 — 44 大卡 / 碳水 8.1g (最高)\n🌰 杏仁奶 — 13 大卡 (最低！) / 蛋白 0.4g\n🌱 豆奶 — 35 大卡 / 蛋白 3.6g (最高) / 纖維 1.3g\n\n簡單選：\n• 想補蛋白 → 豆奶\n• 想低熱量 → 杏仁奶（但蛋白也低）\n• 想纖維 → 豆奶 or 燕麥奶\n• 喜歡濃郁 → 牛奶\n\n沒有「最好」，只有「最適合妳」 ✨",
                'reading_time_seconds' => 100,
                'source_image' => '610699248895525192.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享 / 衛福部食品營養成分資料庫',
            ],
            [
                'slug' => 'chinese-cooking-green-zone',
                'title' => '中式料理綠燈區 — 清淡原型烹調',
                'category' => 'lifestyle',
                'tags' => ['烹調', '體態管理期', '中式'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '清燉 / 蒸 / 煮 / 燙 / 涼拌 / 煙燻 / 烤 / 氣炸 / 滷 — 都是相對低油的烹調法。',
                'body' => "想吃中式但又怕熱量爆？選綠燈烹調法：清燉、蒸、煮、燙、涼拌、煙燻、烤、氣炸、滷。\n共通點：少額外油、保留食物原型、調味相對清淡。\n\n搭配蛋白質（瘦肉/海鮮/豆製品）和蔬菜，外食也能很簡單。",
                'dodo_voice_body' => "中式料理綠燈區 — 朵朵推薦的烹調法 🟢\n\n• 清燉（湯品）\n• 蒸（魚 / 蛋）\n• 煮（湯麵）\n• 燙（青菜）\n• 涼拌\n• 煙燻\n• 烤\n• 氣炸\n• 滷\n\n共通點：\n少加油、保留食物原型、調味清淡\n\n外食點菜時往這裡選，配蛋白 + 蔬菜，輕鬆不踩雷 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610699258609271248.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'chinese-cooking-red-zone',
                'title' => '中式料理紅燈區 — 油膩重口味',
                'category' => 'myth_busting',
                'tags' => ['烹調', '中式', '高熱量'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '炸 / 酥 / 爆 / 煎 / 糖醋 / 三杯 / 炒 / 紅燒 / 燴 — 都是高油高熱量烹調。',
                'body' => "中式紅燈烹調法：炸、酥、爆、煎、糖醋、三杯、炒、紅燒、燴。\n共通點：油量大、調味重、糖鹽多、加工程度高。體態管理期或在意身材時減少頻率，偶爾解饞 OK。",
                'dodo_voice_body' => "中式料理紅燈區 — 偶爾吃就好的款 🔴\n\n• 炸 / 酥（裹粉油炸，熱量翻倍）\n• 爆（重油快炒）\n• 煎（用油量多）\n• 糖醋（糖 + 油 + 醬）\n• 三杯（醬油糖油全套）\n• 炒（看用油量）\n• 紅燒（醬油 + 糖 + 油）\n• 燴（勾芡 + 油）\n\n不是不能吃，是頻率問題。\n體態管理期一週 1-2 次當解饞，其他餐回到綠燈區 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610699258945077283.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'ramen-flavor-calorie-comparison',
                'title' => '拉麵口味胖瘦 — 4 款熱量比較',
                'category' => 'qna',
                'tags' => ['日式', '麵食', '比較'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '豚骨 800 / 鹽味 600+ / 味噌 600+ / 醬油 500+ — 一碗拉麵差距 300 大卡。',
                'body' => "4 款常見拉麵每碗熱量：\n- 豚骨拉麵：豬骨 + 雞骨高湯，800 大卡\n- 鹽味拉麵：雞骨清湯，600 多大卡\n- 味噌拉麵：濃郁醬汁，600 多大卡\n- 醬油拉麵：清爽湯底，500 多大卡\n\n豚骨湯油脂最多最濃郁，醬油湯底最清爽。差 300 大卡 = 一頓主餐。",
                'dodo_voice_body' => "拉麵 4 大流派的熱量帳本 🍜\n\n🐷 豚骨拉麵 ── 800 大卡（豬骨+雞骨）\n🧂 鹽味拉麵 ── 600+ 大卡（雞骨清湯）\n🌰 味噌拉麵 ── 600+ 大卡（濃郁醬）\n🍶 醬油拉麵 ── 500+ 大卡（清湯）\n\n豚骨 vs 醬油 = 差 300 大卡（約一頓主餐）\n\n想吃日式又顧身材？\n→ 點醬油 / 鹽味，少喝湯就好 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610701371867922901.jpg',
                'source_attribution' => '三立新聞網',
            ],
            [
                'slug' => 'noodle-dry-vs-soup-calories',
                'title' => '各種麵條 乾 vs 湯 熱量比較',
                'category' => 'qna',
                'tags' => ['麵食', '比較', '體態管理期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '同一種麵，吃乾的比吃湯的熱量高 100-125 大卡（油與調味多）。',
                'body' => "4 種麵條乾 vs 湯熱量：\n- 油炸意麵：乾 475 / 湯 350 (差 125)\n- 油麵：乾 425 / 湯 325 (差 100)\n- 米粉：乾 366 / 湯 250 (差 116)\n- 冬粉：乾 357 / 湯 230 (差 127)\n\n乾麵多了拌油 / 醬汁 / 油蔥酥，熱量明顯增加。體態管理期想吃麵建議選湯麵或不喝湯。",
                'dodo_voice_body' => "麵店點餐選乾還是湯？朵朵幫妳查熱量 🍜\n\n• 油炸意麵：乾 475 / 湯 350（差 125）\n• 油麵：乾 425 / 湯 325（差 100）\n• 米粉：乾 366 / 湯 250（差 116）\n• 冬粉：乾 357 / 湯 230（差 127）\n\n同一種麵，乾的多 100-125 大卡（拌油 + 醬汁 + 油蔥酥）。\n\n體態管理期 → 選湯麵 + 少喝湯（湯油也多）\n想吃乾麵 → 偶爾就好 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610704468539080766.jpg',
                'source_attribution' => '三立新聞網',
            ],
            [
                'slug' => 'noodle-4-tips-for-cutting',
                'title' => '體重管理吃麵 4 招不發胖',
                'category' => 'cutting',
                'tags' => ['麵食', '體態管理期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '挑對麵條 / 吃粗不吃細 / 吃湯不吃乾 / 搭配小菜 — 4 招吃麵不卡關。',
                'body' => "4 招吃麵不發胖：\n1. 挑對麵條：避開油炸麵\n2. 吃粗不吃細：粗麵表面積小，沾附油脂與調味少\n3. 吃湯不吃乾：清湯麵取代乾麵\n4. 搭配小菜：加點青菜與蛋白質，營養更均衡",
                'dodo_voice_body' => "體態管理期不戒麵也行～朵朵 4 招給妳 🍜\n\n1️⃣ **挑對麵條** — 避開油炸麵\n2️⃣ **吃粗不吃細** — 粗麵表面積小，吸的油與醬料少\n3️⃣ **吃湯不吃乾** — 清湯取代拌油的乾麵\n4️⃣ **搭配小菜** — 加青菜 + 蛋白質，營養更平衡\n\n4 招收進口袋。下次點麵時想一下，妳就贏了 💪",
                'reading_time_seconds' => 60,
                'source_image' => '610704468723630187.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'sandwich-460-vs-320-cals',
                'title' => '選錯三明治多吃好幾卡',
                'category' => 'cutting',
                'tags' => ['早餐', '加工品'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '油煎吐司三明治 460 大卡 vs 簡單烹調三明治 320 大卡 — 差 140 大卡。',
                'body' => "同樣是三明治差很大：\n❌ 油煎吐司三明治：460 大卡。熱量高、加工品、易胖 + 發炎\n⭕ 簡單烹調三明治：320 大卡。天然配料 + 簡單烹調\n\n關鍵在「吐司怎麼處理」+ 「夾的料是不是加工品」。早餐店選原味吐司 + 蛋 + 蔬菜 + 番茄 + 起司，不要油煎吐司。",
                'dodo_voice_body' => "早餐三明治踩雷沒？朵朵翻給妳看 🥪\n\n❌ 油煎吐司三明治 — 460 大卡\n• 油煎 = 額外吸油\n• 加工肉品（火腿/培根）\n• 易胖 + 容易誘發發炎\n\n⭕ 簡單烹調三明治 — 320 大卡\n• 天然配料（蛋 / 番茄 / 生菜）\n• 簡單烹調（不油煎）\n\n差 140 大卡 = 一杯拿鐵。\n\n早餐店點時記得：「吐司不要油煎喔～」 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610566167321116851.jpg',
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
