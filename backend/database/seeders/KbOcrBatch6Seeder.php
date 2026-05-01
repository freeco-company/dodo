<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

class KbOcrBatch6Seeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $articles = [
            [
                'slug' => 'street-food-noodle-stand-menu',
                'title' => '小吃攤／麵攤怎麼點（菜單版）',
                'category' => 'lifestyle',
                'tags' => ['小吃攤', '麵攤', '外食', '點餐'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '湯麵 / 乾麵首選黃麵、白麵、粄條、米粉、米苔目（不淋油蔥）；飯類選白飯（避滷肉飯）；湯首選蛋花湯；小菜選豆干、豬頭肉、滷蛋、皮蛋豆腐、燙青菜（不淋滷汁）。',
                'body' => "🍜 湯麵 / 乾麵\n首選：黃麵、白麵、粄條、米粉、米苔目\n⚠️ 請麵攤不淋油蔥\n\n🍚 飯類\n首選：白飯（小碗）\n避開：滷肉飯（油脂高）\n\n🥣 湯類\n首選：蛋花湯\n避開：貢丸湯、餛飩湯、肉羹湯（加工品）\n\n🥬 小菜（請不淋滷汁）\n首選：豆干、豬頭肉、滷蛋、皮蛋豆腐、燙青菜\n避開：油豆腐、豬皮、豬頭皮（高油脂）\n\n🚫 飲料\n避開：啤酒、含糖飲料\n選礦泉水或無糖茶",
                'dodo_voice_body' => "去小吃攤不知道點什麼？朵朵幫妳列好菜單 📋\n\n🍜 湯麵 / 乾麵 ✅\n黃麵 / 白麵 / 粄條 / 米粉 / 米苔目\n💡 要說「不要淋油蔥」\n\n🍚 飯類\n✅ 白飯（小碗）\n❌ 滷肉飯（一勺滷汁等於一匙油）\n\n🥣 湯類\n✅ 蛋花湯\n❌ 貢丸 / 餛飩 / 肉羹（都是加工品）\n\n🥬 小菜（記得「不淋滷汁」）\n✅ 豆干、豬頭肉、滷蛋、皮蛋豆腐、燙青菜\n❌ 油豆腐、豬皮、豬頭皮\n\n🚫 飲料：礦泉水 > 無糖茶 > 千萬不要啤酒\n\n會點 = 一餐控在 500 卡內 ✨",
                'reading_time_seconds' => 90,
                'source_image' => '610849144328159594.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'street-food-vegetable-picks',
                'title' => '小吃攤蔬菜這樣點',
                'category' => 'lifestyle',
                'tags' => ['小吃攤', '蔬菜', '外食'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '燙青菜、海帶、小黃瓜、滷苦瓜、炒豆芽菜、滷蘿蔔、燙花椰菜、龍鬚菜、番茄蔬菜湯都是好選擇。',
                'body' => "小吃攤 / 麵攤蔬菜安全清單：\n\n🥬 燙青菜（請不淋滷汁，可加蒜末）\n🌊 海帶\n🥒 小黃瓜（涼拌少油）\n🥗 滷苦瓜\n🌱 炒豆芽菜\n🥕 滷蘿蔔\n🥦 燙花椰菜\n🌿 龍鬚菜\n🍅 番茄蔬菜湯\n\n💡 蔬菜目標：每餐至少一份（拳頭大）\n💡 加工醃漬類（酸菜、菜圃、醃蘿蔔）跳過 → 鈉太高",
                'dodo_voice_body' => "小吃攤的蔬菜 9 選 — 朵朵點名 ✋\n\n🥬 燙青菜（記得不淋滷汁、加蒜末超香）\n🌊 海帶（補碘）\n🥒 小黃瓜（消水腫）\n🥗 滷苦瓜（降火）\n🌱 炒豆芽菜（少油請廚師輕炒）\n🥕 滷蘿蔔\n🥦 燙花椰菜\n🌿 龍鬚菜\n🍅 番茄蔬菜湯（蔬菜湯裡 CP 值最高）\n\n💡 一餐至少一份蔬菜（拳頭大）\n⚠️ 跳過：酸菜、菜圃、醃蘿蔔（鈉爆表）✨",
                'reading_time_seconds' => 60,
                'source_image' => '610849144378753588.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'street-food-protein-picks',
                'title' => '小吃攤蛋白質這樣點',
                'category' => 'lifestyle',
                'tags' => ['小吃攤', '蛋白質', '外食'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '肝連肉、嘴邊肉、豬肝、滷蛋、荷包蛋、豆干、皮蛋豆腐（醬少）、素雞、涼拌干絲都是優質蛋白選擇。',
                'body' => "小吃攤 / 麵攤蛋白質清單：\n\n🥩 肉類（去油脂優選）\n- 肝連肉（瘦）\n- 嘴邊肉\n- 豬肝（鐵質高）\n\n🍳 蛋類\n- 滷蛋\n- 荷包蛋（請少油）\n\n🫛 豆製品\n- 豆干（富含蛋白低油）\n- 皮蛋豆腐（醬料少放）\n- 素雞\n- 涼拌干絲（少油）\n\n💡 一餐目標：兩拳頭蛋白質\n💡 避開：油豆腐、百頁豆腐（油炸過、熱量爆表）",
                'dodo_voice_body' => "小吃攤蛋白質清單 — 朵朵幫妳挑 9 樣 💪\n\n🥩 瘦肉類\n• 肝連肉\n• 嘴邊肉\n• 豬肝（順便補鐵）\n\n🍳 蛋類\n• 滷蛋\n• 荷包蛋（記得跟老闆說「少油」）\n\n🫛 豆製品（CP 值之王）\n• 豆干\n• 皮蛋豆腐（醬料少放、不加醬油膏）\n• 素雞\n• 涼拌干絲\n\n💡 一餐 2 拳頭蛋白質才夠養肌\n⚠️ 油豆腐、百頁豆腐 = 炸過的 → 熱量翻倍 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610849144429085001.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'street-food-starch-picks',
                'title' => '小吃攤澱粉這樣點',
                'category' => 'lifestyle',
                'tags' => ['小吃攤', '澱粉', '外食'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '雞肉飯、鴨肉飯、清湯麵、清燉牛肉麵、鍋燒烏龍麵、5-6 顆水餃。飯點小碗滷汁少量、麵點湯麵吃半碗不喝湯、避開加工品醃漬物。',
                'body' => "小吃攤 / 麵攤澱粉首選：\n\n🍚 飯類\n- 雞肉飯（瘦）\n- 鴨肉飯（瘦）\n→ 一律點小碗、滷汁要求少量\n\n🍜 麵類\n- 清湯麵\n- 清燉牛肉麵\n- 鍋燒烏龍麵\n→ 吃半碗 + 不喝湯（湯裡都是油 + 鈉）\n\n🥟 餃子類\n- 水餃 5-6 顆（蛋白質 + 澱粉）\n\n💡 小叮嚀：\n✓ 飯 → 點小碗、滷汁少量\n✓ 麵 → 點湯麵、吃半碗、不喝湯\n✗ 避開加工品（貢丸、油豆腐）、醃漬物（酸菜、菜圃、醃蘿蔔）",
                'dodo_voice_body' => "小吃攤澱粉怎麼選不胖？朵朵筆記 📝\n\n🍚 飯（一律點小碗）\n✅ 雞肉飯、鴨肉飯（瘦肉）\n💡 跟老闆說「滷汁少量」\n\n🍜 麵（湯麵首選）\n✅ 清湯麵、清燉牛肉麵、鍋燒烏龍麵\n💡 三大守則：\n• 點湯麵（不點乾麵）\n• 吃半碗就好\n• 不喝湯（湯 = 油 + 鈉）\n\n🥟 想吃水餃？\n5-6 顆剛好（蛋白質 + 澱粉一次拿）\n\n⚠️ 紅線清單\n❌ 加工品：貢丸、油豆腐\n❌ 醃漬物：酸菜、菜圃、醃蘿蔔（鈉爆表）✨",
                'reading_time_seconds' => 75,
                'source_image' => '610849144579555363.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'hotpot-ingredients-traffic-light',
                'title' => '火鍋料聰明吃（紅黃綠燈）',
                'category' => 'lifestyle',
                'tags' => ['火鍋', '加工品', '熱量'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '紅燈盡量不吃：油條 148 / 炸豆皮 95 / 百頁豆腐 91 / 麻吉燒 60 kcal。黃燈適量挑選：甜不辣 32 / 鑫鑫腸 28 / 蟹肉棒 22 / 蛋餃 21 kcal。綠燈熱量較低：魚板 10 / 魚卵捲 8 / 蒟蒻卷 2 kcal。',
                'body' => "🔴 紅燈 — 盡量不吃\n- 油條（27g）148 kcal\n- 炸豆皮（20g）95 kcal\n- 百頁豆腐（40g）91 kcal\n- 麻吉燒（1 顆）60 kcal\n\n🟡 黃燈 — 適量挑選\n- 甜不辣（1 條）32 kcal\n- 鑫鑫腸（1 條）28 kcal\n- 蟹肉棒（1 條）22 kcal\n- 蛋餃（1 個）21 kcal\n\n🟢 綠燈 — 熱量較低\n- 魚板（1 片）10 kcal\n- 魚卵捲（1 片）8 kcal\n- 蒟蒻卷（1 個）2 kcal\n\n💡 加工火鍋料鈉含量也偏高，綠燈也別吃過量。\n💡 真正的火鍋主角：肉片（去油涮）+ 大量蔬菜 + 菇類 + 豆腐（非百頁）。",
                'dodo_voice_body' => "火鍋料紅黃綠燈 — 朵朵幫妳分等級 🚦\n\n🔴 紅燈（高熱量陷阱）\n油條 148｜炸豆皮 95｜百頁豆腐 91｜麻吉燒 60 kcal\n→ 「百頁豆腐」是炸過的，不是普通豆腐 ⚠️\n\n🟡 黃燈（最多挑 1-2 樣）\n甜不辣 32｜鑫鑫腸 28｜蟹肉棒 22｜蛋餃 21 kcal\n\n🟢 綠燈（也別吃太多）\n魚板 10｜魚卵捲 8｜蒟蒻卷 2 kcal\n\n💡 真正主角：\n✓ 肉片（去油涮）\n✓ 大量蔬菜 + 菇類\n✓ 板豆腐（不是百頁！）\n\n⚠️ 加工料鈉都高，綠燈也不能無限吃 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610853354436558920.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'ramen-during-fat-loss',
                'title' => '體態管理期拉麵怎麼吃',
                'category' => 'lifestyle',
                'tags' => ['拉麵', '日式', '體態管理'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '避開豚骨 / 濃厚 / 辣味湯底與唐揚雞 / 可樂餅 / 炸豬排配菜，改點醬油 / 味噌 / 鹽味湯底加蔬菜 + 蛋白質配菜。麵吃一拳頭、不喝湯。',
                'body' => "❌ 體態管理期不選\n- 湯底：豚骨、濃厚系、辣味系\n- 配菜：唐揚雞、可樂餅、炸豬排\n→ 一碗熱量輕易破 1000 kcal\n\n⭕ 推薦選擇\n- 湯底：醬油、味噌、鹽味（清湯系）\n- 配菜：蔬菜（玉米筍、青蔥、海苔、筍乾）+ 蛋白質（叉燒去油、溏心蛋、豆腐）\n\n💡 兩大守則：\n1. 麵只吃一拳頭量\n2. 湯不喝（鈉爆表 + 油浮在表面）\n\n💡 可以加：豆芽、青蔥、辣椒粉提味（不增熱量）",
                'dodo_voice_body' => "體態管理期想吃拉麵？朵朵告訴妳怎麼吃 🍜\n\n❌ 紅線\n湯底：豚骨 / 濃厚 / 辣味系\n配菜：唐揚雞 / 可樂餅 / 炸豬排\n→ 一碗 1000+ kcal，半天熱量沒了\n\n⭕ 綠燈組合\n湯底：醬油 / 味噌 / 鹽味（清湯系）\n配菜：玉米筍、青蔥、海苔、筍乾、叉燒（去油邊）、溏心蛋、豆腐\n\n💡 兩大鐵律：\n1️⃣ 麵只吃一拳頭量\n2️⃣ 湯一口都不喝（油浮在表面 + 鈉量爆表）\n\n💡 想加味道：豆芽、青蔥、辣椒粉（零熱量加分）✨",
                'reading_time_seconds' => 75,
                'source_image' => '610855845584437596.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'ramen-flavor-calorie-comparison',
                'title' => '拉麵口味熱量比較',
                'category' => 'lifestyle',
                'tags' => ['拉麵', '熱量', '日式'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '同樣一碗拉麵，湯底差很多：豚骨拉麵（豬骨+雞骨）約 800 大卡、鹽味拉麵（雞骨熬）約 600 大卡、味噌拉麵 約 600 大卡、醬油拉麵 約 500 大卡。',
                'body' => "拉麵湯底熱量排行（一碗約）：\n\n🔴 豚骨拉麵（豬骨 + 雞骨）約 800 kcal\n→ 油脂濃稠，湯不喝\n\n🟡 鹽味拉麵（雞骨熬）約 600 kcal\n🟡 味噌拉麵 約 600 kcal\n\n🟢 醬油拉麵 約 500 kcal\n→ 體態管理期首選\n\n💡 同一家店、同樣份量，光換湯底就差 300 kcal（≈ 1 碗白飯）。\n💡 配料另算：叉燒、半熟蛋、奶油 corn 都會再加分。",
                'dodo_voice_body' => "同一碗拉麵差 300 卡？看湯底！朵朵列表 📊\n\n🔴 豚骨（豬骨+雞骨熬）≈ 800 kcal\n→ 湯太濃太油，一口都不喝\n\n🟡 鹽味（雞骨熬）≈ 600 kcal\n🟡 味噌 ≈ 600 kcal\n\n🟢 醬油 ≈ 500 kcal\n→ 體態管理期首選湯底 ✨\n\n💡 換湯底差 300 kcal ≈ 一碗白飯欸\n💡 別忘了配料也算錢：\n• 叉燒（油邊去掉）\n• 半熟蛋 +70\n• 奶油玉米 +100\n\n會選 = 偶爾吃也不怕 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610855845651022140.jpg',
                'source_attribution' => '三立新聞網（SETN）健康報導',
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
