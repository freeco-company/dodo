<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

class KbOcrBatch3Seeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $articles = [
            [
                'slug' => 'breakfast-toppings-processed-vs-natural',
                'title' => '早餐配料：加工 vs 天然',
                'category' => 'myth_busting',
                'tags' => ['早餐', '加工品', '發炎'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '加工品讓身體發炎、越吃越餓；低加工天然食材飽足感高、不易胖。',
                'body' => "❌ 加工品（易胖、發炎、越吃越餓）\n火腿、熱狗、培根、漢堡肉餅、薯餅、卡啦雞、油炸物、抹醬、肉鬆\n\n⭕ 低加工天然（飽足、不易胖）\n蔬菜：生菜、大番茄、小黃瓜、苜蓿芽\n蛋白質：蛋、里肌肉片、雞腿排、鮪魚、燻雞、起司片\n\n機制：加工品 → 身體發炎 → 代謝障礙 → 易胖。",
                'dodo_voice_body' => "早餐店妳怎麼點？朵朵幫妳分兩堆 🍳\n\n❌ 加工品 NG 區\n火腿 / 熱狗 / 培根 / 漢堡肉餅 / 薯餅 / 卡啦雞 / 油炸 / 肉鬆\n→ 身體發炎 → 代謝變差 → 越吃越餓\n\n⭕ 天然 OK 區\n蔬菜：生菜 / 番茄 / 小黃瓜 / 苜蓿芽\n蛋白：蛋 / 里肌肉片 / 雞腿排 / 鮪魚 / 燻雞 / 起司片\n\n換個配料，吃飽又不發胖 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610566167321116852.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'greek-yogurt-vs-style-detail',
                'title' => '希臘優格 vs 希臘式優格 — 成分大不同',
                'category' => 'myth_busting',
                'tags' => ['優格', '標籤陷阱', '加工添加'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '真希臘優格只有鮮奶 + 乳酸菌；希臘式多了增稠劑（果膠/洋菜膠）和鮮奶油。',
                'body' => "希臘優格（真）：成分 = 鮮奶、乳酸菌；熱量低、蛋白高\n希臘式優格：成分 = 鮮奶、乳酸菌、增稠劑（果膠、洋菜膠）、鮮奶油；熱量高\n\n看不懂瓶身就翻成分表：成分越短越單純越接近原版。",
                'dodo_voice_body' => "再幫妳細看一次優格成分表 🔍\n\n👑 希臘優格（真）\n• 鮮奶 + 乳酸菌\n• 熱量低、蛋白高\n\n📦 希臘式優格\n• 鮮奶 + 乳酸菌\n• ⚠️ 增稠劑（果膠、洋菜膠）\n• ⚠️ 鮮奶油\n→ 熱量高\n\n挑優格 → 成分表越短越好 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610566852250436108.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'potato-egg-salad-hidden-fat',
                'title' => '馬鈴薯泥 / 蛋沙拉 — 隱藏油脂炸彈',
                'category' => 'myth_busting',
                'tags' => ['沙拉', '隱藏油脂', '加工'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '蛋沙拉、馬鈴薯泥、洋芋沙拉看似清爽，其實藏滿美乃滋、沙拉醬、奶油。',
                'body' => "看似健康但其實是油脂炸彈：\n❌ 蛋沙拉三明治\n❌ 洋芋生菜沙拉\n❌ 通心麵蛋沙拉\n❌ 洋芋（馬鈴薯泥）\n\n隱藏元兇：美乃滋、沙拉醬、奶油 / 鮮奶油\n\n想吃沙拉選清爽油醋醬或和風醬，避開白色奶味的醬。",
                'dodo_voice_body' => "「我點沙拉應該很健康吧？」朵朵翻一下醬料 ⚠️\n\n❌ 隱藏油脂炸彈：\n• 蛋沙拉三明治\n• 洋芋生菜沙拉\n• 通心麵蛋沙拉\n• 馬鈴薯泥\n\n元兇：\n美乃滋 / 沙拉醬 / 奶油 / 鮮奶油\n\n（白色濃稠醬基本上 = 油 + 蛋黃）\n\n想吃沙拉？\n→ 選油醋醬 / 和風醬\n→ 避開「美乃滋系」白醬 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610568304503292263.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'mcdonalds-cutting-burger-chicken',
                'title' => '減脂期麥當勞怎麼點 — 漢堡 / 炸雞篇',
                'category' => 'cutting',
                'tags' => ['速食', '麥當勞', '減脂期', '外食'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '麥當勞也能減脂吃，主餐去培根 / 去炸皮，配四季沙拉，飲料無糖。',
                'body' => "減脂期麥當勞點法（漢堡 / 炸雞篇）：\n\n主餐擇一：\n- 義式烤雞沙拉\n- 吉事漢堡\n- 嫩煎雞腿堡\n- BLT 安格斯牛肉堡（去培根）\n- BLT 嫩煎雞腿堡（去培根）\n- 麥脆雞腿（去炸皮）\n\n配餐：四季沙拉（不加醬 / 和風醬）\n飲料：無糖紅茶 / 無糖綠茶 / 美式咖啡 / 可口可樂 ZERO",
                'dodo_voice_body' => "減脂期想吃麥當勞？朵朵教妳選 ✨\n\n🍔 主餐擇一\n• 義式烤雞沙拉\n• 吉事漢堡\n• 嫩煎雞腿堡\n• BLT 安格斯牛肉堡（去培根）\n• BLT 嫩煎雞腿堡（去培根）\n• 麥脆雞腿（去炸皮！）\n\n🥗 配餐\n四季沙拉（不加醬 / 和風醬）\n\n🥤 飲料\n無糖紅 / 無糖綠 / 美式 / Coke ZERO\n\n關鍵字：「去培根」「去炸皮」「無糖」\n外食也能減脂 💪",
                'reading_time_seconds' => 90,
                'source_image' => '610570084951523977.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'mcdonalds-cutting-fries-version',
                'title' => '減脂期麥當勞 — 想吃薯條版',
                'category' => 'cutting',
                'tags' => ['速食', '麥當勞', '減脂期'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '想吃薯條時的減脂組合：主餐選沙拉或去皮雞腿，配中薯（去鹽），無糖飲料。',
                'body' => "減脂期想吃薯條也行，這樣搭：\n\n主餐擇一：\n- 義式烤雞沙拉\n- 麥脆雞腿（去炸皮）\n\n配餐：中薯條（去鹽）\n飲料：無糖紅茶 / 無糖綠茶 / 美式咖啡 / 可口可樂 ZERO\n\n關鍵：薯條已經有油脂，主餐就不選炸物 + 漢堡 + 飲料無糖。",
                'dodo_voice_body' => "「但我就是想吃薯條啊...」朵朵幫妳搭 🍟\n\n要點薯條沒問題，但其他要克制：\n\n🍗 主餐擇一（避開炸物）\n• 義式烤雞沙拉\n• 麥脆雞腿（去炸皮）\n\n🍟 配餐\n中薯條（記得跟櫃檯說「去鹽」）\n\n🥤 飲料\n無糖紅 / 無糖綠 / 美式 / Coke ZERO\n\n邏輯：薯條就是油脂 quota，其他餐就要清爽 ✨\n減脂不是不能吃，是會分配 💪",
                'reading_time_seconds' => 75,
                'source_image' => '610570085001855270.jpg',
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
