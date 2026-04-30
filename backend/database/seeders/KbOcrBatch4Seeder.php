<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

class KbOcrBatch4Seeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $articles = [
            [
                'slug' => 'ramen-cutting-version',
                'title' => '減脂期拉麵怎麼吃？',
                'category' => 'cutting',
                'tags' => ['日式', '麵食', '減脂期', '外食'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '避開豚骨/濃厚/辣味/炸物，選醬油/味噌/鹽味 + 蔬菜蛋白質，麵一拳頭、不喝湯。',
                'body' => "❌ 減脂期避開：\n- 豚骨 / 濃厚 / 辣味拉麵\n- 唐揚雞 / 可樂餅 / 炸豬排\n\n⭕ 減脂期可以選：\n- 醬油 / 味噌 / 鹽味拉麵（清湯底）\n- 配蔬菜 + 蛋白質（溏心蛋 / 燙青菜 / 海帶）\n\n2 個關鍵：麵份量一拳頭、不喝湯。",
                'dodo_voice_body' => "減脂期想吃日式拉麵？朵朵教妳挑 🍜\n\n❌ 避開區\n• 豚骨 / 濃厚 / 辣味拉麵（湯底油重）\n• 唐揚雞 / 可樂餅 / 炸豬排（炸物配菜）\n\n⭕ OK 區\n• 醬油 / 味噌 / 鹽味拉麵\n• 配蔬菜 + 蛋白質（溏心蛋、燙青菜、海帶）\n\n💡 兩個鐵則\n• 麵 = 一拳頭份量\n• 不喝湯（湯油超多！）\n\n清湯系 + 控份量 = 拉麵也能吃 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610701371968847946.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'sarcopenia-prevention',
                'title' => '什麼是肌少症？怎麼預防',
                'category' => 'lifestyle',
                'tags' => ['肌少症', '老化', '運動'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '40 歲後肌肉量與大腿肌力逐年下降，肌少症會增加跌倒、骨折、代謝症候群風險。',
                'body' => "肌少症 = 隨年齡增加，肌肉量與肌力逐漸下降，影響日常生活。\n\n風險：\n- 跌倒 / 骨折\n- 代謝症候群\n- 心血管疾病\n- 失能 / 死亡率上升\n\n40 歲後肌肉量與大腿肌力曲線都向下，但大腿肌力下降更陡峭。\n\n預防：趕快運動 + 補蛋白。阻力訓練是黃金解。",
                'dodo_voice_body' => "「肌少症」聽起來離妳很遠？朵朵告訴妳 — 40 歲就開始了 ⚠️\n\n肌肉量 ↓ 大腿肌力 ↓↓（下降更快）\n\n為什麼要在意？\n• 跌倒 / 骨折\n• 代謝症候群\n• 心血管疾病\n• 失能風險\n\n預防方式：\n💪 阻力訓練（不只有氧）\n🥚 補蛋白（每餐都有蛋白質）\n\n年輕時練的肌肉，是老後的保險 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610743578712342887.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'inactive-lifestyle-4th-killer',
                'title' => '運動不足 = 全球第 4 大致死因素',
                'category' => 'lifestyle',
                'tags' => ['運動', 'WHO', '健康風險'],
                'audience' => ['retail', 'franchisee'],
                'summary' => 'WHO：每年 6% 死亡與身體活動不足有關。台灣只有 33.6% 規律運動人口。',
                'body' => "WHO 指出：每年 6% 死亡率與身體活動不足有關，運動不足已是全球第 4 大致死因素。\n2019 年台灣規律運動人口比率僅 33.6%。\n\n規律運動定義：每週至少 3 次 / 每次至少 30 分鐘 / 心跳 130 下或會喘會流汗。\n\n久坐風險：心血管疾病、糖尿病、肥胖風險加倍；增加大腸癌、高血壓、骨質疏鬆、脂質失調、憂鬱、焦慮風險。",
                'dodo_voice_body' => "WHO 認證的事實 — 不運動真的會出事 😱\n\n• 全球每年 6% 死亡 = 運動不足造成\n• 已是全球第 4 大致死因素\n• 台灣規律運動率只有 33.6%（2019）\n\n📏 規律運動定義（不高，達標即可）：\n每週 3 次 × 30 分鐘 × 心跳 130 / 會喘會流汗\n\n久坐的代價（不只是胖）：\n• 心血管 / 糖尿病 / 肥胖風險加倍\n• 大腸癌、高血壓、骨鬆、憂鬱、焦慮 全部 +\n\n從今天起每週 3 × 30，朵朵陪妳 💪",
                'reading_time_seconds' => 90,
                'source_image' => '610743578763460904.jpg',
                'source_attribution' => 'WHO / JEROSSE 婕樂纖',
            ],
            [
                'slug' => 'fat-burning-exercise-intensity',
                'title' => '看懂燃脂最佳運動強度',
                'category' => 'lifestyle',
                'tags' => ['運動', '燃脂', '科學'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '低強度燒脂肪、高強度燒醣類；時間越長脂肪比例越高。中強度有氧 = 燃脂甜蜜點。',
                'body' => "運動燃脂科學：\n- 低強度（瑜伽、散步）：能量主要來源 = 脂肪\n- 中強度（騎車、游泳）：能量 = 脂肪 + 醣類\n- 高強度（跳繩、跑步）：能量主要來源 = 醣類\n\n原則：強度越高，醣類比例越高；時間越長，脂肪比例越高。\n\n燃脂甜蜜點 = 中強度有氧 + 持續 30 分鐘以上。",
                'dodo_voice_body' => "「我運動半小時，燒到的是脂肪還是糖？」朵朵幫妳拆 🔬\n\n🟢 低強度（瑜伽 / 散步）\n→ 主燒脂肪\n\n🟡 中強度（騎車 / 游泳）\n→ 脂肪 + 醣類各半\n\n🔴 高強度（跳繩 / 跑步）\n→ 主燒醣類\n\n💡 兩個原則\n• 強度↑ → 醣類比例↑\n• 時間↑ → 脂肪比例↑\n\n燃脂甜蜜點：\n中強度有氧 + 30 分鐘以上 ✨",
                'reading_time_seconds' => 75,
                'source_image' => '610744084344078399.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'aerobic-vs-anaerobic-exercise',
                'title' => '無氧 vs 有氧運動 — 差在哪？',
                'category' => 'qna',
                'tags' => ['運動', '比較', '增肌'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '無氧短時間高強度、增肌長代謝；有氧長時間低強度、燒脂但代謝下降。',
                'body' => "對比：\n- 持續時間：無氧較短 / 有氧較長\n- 運動強度：無氧較高 / 有氧較低\n- 燃燒脂肪：兩者都可以\n- 肌肉量、基礎代謝率：無氧上升 / 有氧下降\n\n類型：\n- 無氧：重量訓練、徒手肌力、TRX、舉重、短跑\n- 有氧：快走、慢跑、騎自行車、游泳、有氧舞蹈、拳擊、飛輪\n\n建議：兩者搭配，無氧增肌 + 有氧燒脂。",
                'dodo_voice_body' => "減脂該做有氧還是無氧？朵朵幫妳對比 💪\n\n📊 |  | 無氧 | 有氧 |\n• 時間：短 / 長\n• 強度：高 / 低\n• 燒脂：✓ / ✓\n• 肌肉量：↑ / ↓ (！)\n\n等等，有氧會掉肌肉？\n→ 對，純有氧長期做基礎代謝會降\n\n🏋️ 無氧：重訓、TRX、舉重、短跑\n🏃 有氧：快走、慢跑、騎車、游泳、飛輪\n\n💡 黃金搭配：\n無氧增肌 + 有氧燒脂（兩者都做）\n\n光跑步不重訓，越減越難減 ✨",
                'reading_time_seconds' => 90,
                'source_image' => '610744084427964474.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'resistance-training-equipment-types',
                'title' => '阻力訓練器材有哪幾種？',
                'category' => 'qna',
                'tags' => ['運動', '阻力訓練', '器材'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '7 種阻力訓練選擇：機械、自身體重、啞鈴、槓鈴、彈力帶、藥球、日常用品。',
                'body' => "阻力訓練器材分類：\n1. 固定式機械：穩定獨立訓練目標肌群（如下肢股四頭肌）\n2. 自身體重：伏地挺身、深蹲\n3. 啞鈴：較短金屬棒，兩側負重\n4. 槓鈴：較長金屬棒，可裝槓片\n5. 彈力帶：顏色越深阻力越大\n6. 藥球：彈性 + 重量\n7. 日常用品：裝水或沙子的水壺\n\n沒有藉口不訓練 — 在家用日常用品也行。",
                'dodo_voice_body' => "「阻力訓練要去健身房才能做嗎？」朵朵說 — 不用 ✨\n\n7 種選擇任妳挑：\n\n🏋️ 健身房：\n1. 固定式機械（穩定）\n2. 啞鈴（基礎）\n3. 槓鈴（進階）\n4. 藥球\n\n🏠 在家也行：\n5. 自身體重（伏地挺身、深蹲）\n6. 彈力帶（顏色深 = 阻力大）\n7. 日常用品（裝水的水壺也算）\n\n沒器材不是藉口，先深蹲 20 下再說 💪",
                'reading_time_seconds' => 60,
                'source_image' => '610744301106495528.jpg',
                'source_attribution' => 'JEROSSE 婕樂纖營養師分享',
            ],
            [
                'slug' => 'why-resistance-training',
                'title' => '什麼是阻力訓練？為什麼比有氧更重要',
                'category' => 'qna',
                'tags' => ['運動', '阻力訓練', '增肌'],
                'audience' => ['retail', 'franchisee'],
                'summary' => '阻力訓練比有氧更能刺激肌肉生長 — 損傷後修復就是肌肉量與力量的成長機制。',
                'body' => "阻力訓練 = 用不同方式（彈力帶、負重等）訓練肌肉。\n\n相比有氧：\n- 更能刺激肌肉生長\n- 讓肌肉受到輕微損傷後進行修復\n- 修復過程 = 增加肌肉量與肌肉力量\n\n所以想增肌 / 維持基礎代謝 / 預防肌少症 → 阻力訓練優先於有氧。",
                'dodo_voice_body' => "為什麼朵朵一直推阻力訓練？因為機制不一樣 🔬\n\n阻力訓練的科學：\n1️⃣ 用負重 / 彈力帶刺激肌肉\n2️⃣ 肌肉受到輕微損傷\n3️⃣ 身體進行修復\n4️⃣ → 肌肉量↑ + 力量↑\n\n（這就是「練肌肉」真正的機制）\n\n有氧運動主要燒脂 + 心肺，但不會像阻力訓練那樣建肌。\n\n想要的不只是瘦，是「漂亮的瘦」？\n→ 加阻力訓練 ✨",
                'reading_time_seconds' => 60,
                'source_image' => '610744301140050263.jpg',
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
