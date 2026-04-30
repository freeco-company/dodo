<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

class KbOcrBatch5Seeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $articles = [
            [
                'slug' => 'post-workout-protein-timing',
                'title' => '運動後一定要喝高蛋白嗎？',
                'category' => 'qna',
                'tags' => ['蛋白質', '運動', '時機'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '看運動種類、時間、體態與目標決定。中高強度 30 分以上 + 體脂 < 30%，30 分內補蛋白；強度時間不足又想減脂者，補了反而會增重。',
                'body' => "決定要不要補蛋白的 2 個情境：\n\n⭕ 中高強度運動 > 30 分鐘 + 體脂 < 30%\n→ 30 分鐘內補充蛋白質\n\n❌ 運動強度 / 時間不足 + 想減脂\n→ 吃東西反而會增加體重\n\n💡 全天蛋白質攝取不足 → 影響肌肉合成。\n每餐請吃足夠 2 拳頭蛋白質。",
                'dodo_voice_body' => "「運動後一定要立刻喝高蛋白？」朵朵說 — 看情況 🤔\n\n⭕ 該補的人：\n• 中高強度運動 > 30 分鐘\n• 體脂 < 30%\n→ 30 分鐘內補蛋白\n\n❌ 不該急著補的人：\n• 運動強度時間不足\n• 想減脂\n→ 補了反而增重 😱\n\n💡 但每天總量不能少：\n每餐 2 拳頭蛋白質才夠養肌肉 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610744780766314744.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'exercise-intensity-talk-test',
                'title' => '怎麼判斷運動強度？說話測試法',
                'category' => 'lifestyle',
                'tags' => ['運動', '強度', '心率'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '不用穿戴裝置也能判斷：能輕鬆說話 = 輕度（50-60% 心率）、可說話無法唱歌 = 中度（60-70%）、說話困難 = 高強度（70-85%）。',
                'body' => "🟢 輕度（50-60% 最大心率）\n- 說話測試：能輕鬆說話\n- 例：伸展、散步、做家事\n\n🟡 中度（60-70%）\n- 說話測試：尚可說話、無法唱歌\n- 例：快走、跳舞、游泳\n\n🔴 高強度（70-85%）\n- 說話測試：說話困難\n- 例：重訓、快跑、間歇訓練\n\n燃脂建議從中度開始，搭配高強度間歇 + 阻力訓練。",
                'dodo_voice_body' => "沒有穿戴裝置怎麼知道強度？朵朵教妳「說話測試」🗣️\n\n🟢 輕度（心率 50-60%）\n→ 能輕鬆說話\n→ 伸展、散步、做家事\n\n🟡 中度（心率 60-70%）\n→ 尚可說話、無法唱歌\n→ 快走、跳舞、游泳\n\n🔴 高強度（心率 70-85%）\n→ 說話困難\n→ 重訓、快跑、間歇\n\n💡 想燃脂：中度 30 分起跳\n💡 想增肌：加進高強度區 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610744780783092051.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'protein-importance-and-bcaa',
                'title' => '蛋白質為什麼重要？認識 BCAA',
                'category' => 'qna',
                'tags' => ['蛋白質', 'BCAA', '氨基酸'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '蛋白質 = 肌肉原料 + 增加飽足感。人體無法製造 9 種必需氨基酸，BCAA（白胺酸/異白胺酸/纈胺酸）對肌肉合成尤其關鍵。',
                'body' => "Q：蛋白質重要嗎？\n\nA：蛋白質是 →\n1. 肌肉主要的原料\n2. 增加飽足感\n\n人體無法製造 9 種必需氨基酸，必需要日常蛋白質補充。\n\n其中支鏈氨基酸（BCAA）對肌肉合成相當重要，包含：\n- 白胺酸 (Leucine)\n- 異白胺酸 (Isoleucine)\n- 纈胺酸 (Valine)\n\nBCAA 的功用：\n✓ 防止肌肉流失\n✓ 增加肌肉合成\n✓ 延緩運動時疲勞產生",
                'dodo_voice_body' => "為什麼朵朵一直叫妳吃蛋白質？2 個關鍵作用 💪\n\n1️⃣ 肌肉的原料\n2️⃣ 增加飽足感（不易餓）\n\n而且妳的身體無法自己製造 9 種必需氨基酸 — 一定要從食物來。\n\n其中最重要的是 BCAA（支鏈氨基酸）：\n• 白胺酸 Leucine\n• 異白胺酸 Isoleucine\n• 纈胺酸 Valine\n\n✨ BCAA 的三大功能：\n✓ 防止肌肉流失\n✓ 增加肌肉合成\n✓ 延緩運動疲勞\n\n減脂期肌肉沒掉光，BCAA 立大功 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610745212427829512.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'low-gi-foods',
                'title' => '什麼是低升糖（低 GI）飲食',
                'category' => 'qna',
                'tags' => ['低 GI', '血糖', '飲食原則'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '低 GI = 含醣（糖）量低、纖維量高、消化緩慢的食物，例：糙米、全麥、芭樂、綠色蔬菜。',
                'body' => "低 GI 飲食 = 糙米、全麥食物、芭樂、綠色蔬菜等含醣（糖）量低、纖維含量高、消化速度緩慢的食物。\n\n常見低 GI 選擇：\n- 全穀：糙米、燕麥、全麥麵包\n- 蔬菜：花椰菜、深綠葉菜、番茄、菇類\n- 水果：芭樂、蘋果、莓果\n- 豆類：黑豆、毛豆、紅豆（無糖）\n- 根莖類：地瓜、南瓜（適量）",
                'dodo_voice_body' => "「低 GI」到底是什麼？朵朵翻譯給妳 🥗\n\n低 GI = 三個特徵：\n✓ 含醣（糖）量低\n✓ 纖維含量高\n✓ 消化速度慢\n\n常見低 GI 食物：\n🌾 糙米、燕麥、全麥麵包\n🥦 花椰菜、深綠葉菜、番茄、菇類\n🍎 芭樂、蘋果、莓果\n🫘 黑豆、毛豆\n🍠 地瓜、南瓜（適量）\n\n吃對 GI 值，血糖穩 → 不易餓 → 不囤脂 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610745464773411065.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'high-gi-foods',
                'title' => '什麼是高升糖（高 GI）飲食',
                'category' => 'qna',
                'tags' => ['高 GI', '血糖', '飲食原則'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '高 GI = 白飯、白吐司、鬆餅、蛋糕、西瓜、芒果、荔枝等含醣量高或消化吸收快的水果與澱粉類。',
                'body' => "高 GI 飲食 = 白飯、白吐司、鬆餅、蛋糕等含醣（糖）量高或消化吸收快的水果及澱粉類食物。\n\n常見高 GI 食物：\n🥖 麵包：白吐司、可頌、菠蘿、奶油麵包\n🍰 甜點：蛋糕、鬆餅、餅乾\n🍚 主食：白飯、白麵、白粥、饅頭\n🍉 水果：西瓜、芒果、荔枝、龍眼\n🥤 含糖飲料 / 果汁\n\n💡 不是不能吃，但減脂期需控制份量 + 搭配蛋白質 / 纖維。",
                'dodo_voice_body' => "高 GI 食物清單 — 朵朵幫妳列好 📋\n\n🥖 麵包類\n白吐司 / 可頌 / 菠蘿 / 奶油麵包\n\n🍰 甜點類\n蛋糕 / 鬆餅 / 餅乾\n\n🍚 主食類\n白飯 / 白麵 / 白粥 / 饅頭\n\n🍉 水果類\n西瓜 / 芒果 / 荔枝 / 龍眼\n\n🥤 飲料類\n含糖飲料 / 果汁\n\n💡 不是禁吃，是要會搭：\n• 控份量\n• 搭配蛋白質 + 纖維\n• 餐後散步 10 分鐘\n血糖才不會大起大落 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610745464824529068.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'gi-blood-sugar-impact',
                'title' => '不同 GI 值食物對血糖的影響',
                'category' => 'lifestyle',
                'tags' => ['GI', '血糖', '胰島素'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '低 GI → 血糖平穩、延長飽足、預防疾病；高 GI → 血糖大起大落、易形成體脂肪、肥胖糖尿三高。',
                'body' => "低 GI 食物（如燕麥）：\n✓ 胰島素分泌穩定\n✓ 血糖不易波動\n✓ 延長飽足感、情緒穩定、預防疾病\n\n高 GI 食物（如含糖奶昔）：\n✗ 血糖波動大\n✗ 易形成體脂肪\n✗ 肥胖、糖尿病、三高\n✗ 爆飲暴食、想睡覺、容易餓\n\n結論：選擇低 GI = 不只是體重管理，更是長期慢性病預防。",
                'dodo_voice_body' => "為什麼朵朵一直推低 GI？看血糖曲線就懂 📈\n\n🟢 低 GI（燕麥、糙米）\n血糖：平穩波浪線\n結果：\n✓ 胰島素穩定\n✓ 飽足久、情緒穩\n✓ 預防慢性病\n\n🔴 高 GI（含糖飲、白吐司）\n血糖：大上大下雲霄飛車\n結果：\n✗ 血糖大波動\n✗ 易囤體脂肪\n✗ 肥胖 / 糖尿 / 三高\n✗ 爆食、犯睏、剛吃完又餓\n\n💡 不是少吃，是吃「對的」\n血糖穩 = 體重穩 = 心情穩 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610745464841306403.jpg',
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
