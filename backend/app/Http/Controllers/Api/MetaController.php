<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Static catalog endpoints translated from ai-game/src/services/{usage,outfits,lore}.ts.
 * Pure constants — no DB access. Inline by design.
 */
class MetaController extends Controller
{
    public function limits(): JsonResponse
    {
        return response()->json([
            'free' =>    ['scans' => 2,  'chats' => 10,  'daily_score' => false, 'weekly_report' => false],
            'monthly' => ['scans' => 10, 'chats' => 100, 'daily_score' => true,  'weekly_report' => true],
            'yearly' =>  ['scans' => 15, 'chats' => 150, 'daily_score' => true,  'weekly_report' => true],
            'vip' =>     ['scans' => 30, 'chats' => 300, 'daily_score' => true,  'weekly_report' => true],
        ]);
    }

    public function outfits(): JsonResponse
    {
        return response()->json([
            ['key' => 'none',         'name' => '基本造型', 'description' => '原本的樣子，最耐看', 'emoji' => '🫧', 'unlock_type' => 'default',     'unlock_hint' => '一開始就有'],
            ['key' => 'scarf',        'name' => '溫暖圍巾', 'description' => '玫瑰色圍巾，冬天感',  'emoji' => '🧣', 'unlock_type' => 'level',       'unlock_value' => 5,  'unlock_hint' => 'LV.5 解鎖'],
            ['key' => 'glasses',      'name' => '圓框眼鏡', 'description' => '文青氣質',           'emoji' => '👓', 'unlock_type' => 'level',       'unlock_value' => 8,  'unlock_hint' => 'LV.8 解鎖'],
            ['key' => 'headphones',   'name' => '玫瑰耳機', 'description' => '音樂愛好者',         'emoji' => '🎧', 'unlock_type' => 'level',       'unlock_value' => 12, 'unlock_hint' => 'LV.12 解鎖'],
            ['key' => 'straw_hat',    'name' => '草帽',     'description' => '夏日陽光',           'emoji' => '👒', 'unlock_type' => 'streak',      'unlock_value' => 7,  'unlock_hint' => '連續 7 天達標'],
            ['key' => 'chef_hat',     'name' => '主廚帽',   'description' => '料理高手',           'emoji' => '👨‍🍳', 'unlock_type' => 'achievement', 'unlock_value' => 'foodie_10',   'unlock_hint' => '收集 10 種食物'],
            ['key' => 'angel_wings',  'name' => '天使翅膀', 'description' => '療癒系代表',         'emoji' => '👼', 'unlock_type' => 'level',       'unlock_value' => 20, 'unlock_hint' => 'LV.20 解鎖'],
            ['key' => 'devil_horns',  'name' => '小惡魔角', 'description' => '貪吃的小惡魔',       'emoji' => '😈', 'unlock_type' => 'achievement', 'unlock_value' => 'perfect_day', 'unlock_hint' => '單日 80 分以上'],
            ['key' => 'halo',         'name' => '光環',     'description' => '完美主義者',         'emoji' => '😇', 'unlock_type' => 'achievement', 'unlock_value' => 'perfect_week','unlock_hint' => '累積 7 個完美日'],
            ['key' => 'fp_crown',     'name' => 'FP 皇冠',   'description' => '只有 FP 永久會員戴得起', 'emoji' => '👑', 'unlock_type' => 'fp_lifetime', 'unlock_hint' => '加入 FP 網站會員解鎖', 'fp_exclusive' => true],
            ['key' => 'fp_chef',      'name' => 'FP 主廚裝', 'description' => '婕樂纖營養師同款',    'emoji' => '🧑‍🍳', 'unlock_type' => 'fp_lifetime', 'unlock_hint' => '加入 FP 網站會員解鎖', 'fp_exclusive' => true],
            ['key' => 'fp_starry',    'name' => 'FP 星空袍', 'description' => '限定款、閃閃發亮',    'emoji' => '🌟', 'unlock_type' => 'fp_lifetime', 'unlock_hint' => '加入 FP 網站會員解鎖', 'fp_exclusive' => true],
        ]);
    }

    public function spirits(): JsonResponse
    {
        // 集團統一 anchor v2（方向1 手繪棉花紙質感 / 溫柔文青風，2026-04-30 拍板）
        // 11 species 共用 across 4 App。前次 hamster / shiba / tuxedo 已退役並 migrate
        // 至 bear / dog / cat（見 2026_04_30_*_remap_avatar_animal_to_v2.php migration）。
        return response()->json([
            ['animal_key' => 'rabbit',   'zh_name' => '兔兔',     'spirit_title' => '輕盈之精靈', 'element' => '春風',       'gift' => '放下沉重的執念',           'mythology' => '春之女神 Ostara 的信使',                 'motto' => '沒關係啦～偶爾放鬆才跳得更高 🌱'],
            ['animal_key' => 'cat',      'zh_name' => '貓貓',     'spirit_title' => '直覺之精靈', 'element' => '月光',       'gift' => '相信身體比腦袋更早知道的事', 'mythology' => '埃及貓神 Bastet 的使者，也是北歐 Freyja 女神戰車的夥伴', 'motto' => '餓了就吃，飽了就停，這是月亮的古老智慧 🌙'],
            ['animal_key' => 'tiger',    'zh_name' => '虎虎',     'spirit_title' => '專注之精靈', 'element' => '山林',       'gift' => '堅定地走自己的路',         'mythology' => '東方四象之一西方白虎，秋日守護',          'motto' => '想要的就直直地走過去，不繞路 🌿'],
            ['animal_key' => 'penguin',  'zh_name' => '企鵝',     'spirit_title' => '堅毅之精靈', 'element' => '極地冰原',   'gift' => '寒冷中也能緩步前行',       'mythology' => '冰巨人 Ymir 遺留的後代',                 'motto' => '穩穩的不完美，好過完美的放棄 🐧'],
            ['animal_key' => 'bear',     'zh_name' => '熊熊',     'spirit_title' => '療癒之精靈', 'element' => '冬日壁爐',   'gift' => '允許自己被擁抱',           'mythology' => '希臘月神 Artemis 的聖獸',                'motto' => '今天就吃想吃的吧，明天我們再一起走～ 🐻'],
            ['animal_key' => 'dog',      'zh_name' => '狗狗',     'spirit_title' => '陪伴之精靈', 'element' => '夕陽散步',   'gift' => '每天一起動一下',           'mythology' => '忠犬 Garmr 的溫柔支線版本',              'motto' => '走一下啦，走 10 分鐘也好 🐕'],
            ['animal_key' => 'fox',      'zh_name' => '狐狸',     'spirit_title' => '智慧之精靈', 'element' => '晨霧',       'gift' => '識破美食陷阱',             'mythology' => '北歐詭計之神 Loki 的徒弟',               'motto' => '聰明地吃，不必委屈地餓 🦊'],
            ['animal_key' => 'dinosaur', 'zh_name' => '恐龍',     'spirit_title' => '勇氣之精靈', 'element' => '遠古岩漿',   'gift' => '做一次會被嘲笑的決定',     'mythology' => '混沌 Chaos 時代倖存者',                  'motto' => '現在開始永遠不嫌晚 🦖'],
            ['animal_key' => 'sheep',    'zh_name' => '綿羊',     'spirit_title' => '安撫之精靈', 'element' => '羊毛雲朵',   'gift' => '柔軟地對自己',             'mythology' => '希臘 Aries 金羊毛守護者',                'motto' => '今天累了沒關係，包起來軟軟的也是進步 🐑'],
            ['animal_key' => 'pig',      'zh_name' => '小豬',     'spirit_title' => '滿足之精靈', 'element' => '春日溫泉',   'gift' => '不為了別人吃飯',           'mythology' => '凱爾特 Henwen 豐收母豬的後代',           'motto' => '想吃就吃，但記得是「妳想」不是「他要」 🌸'],
            ['animal_key' => 'robot',    'zh_name' => '機器人',   'spirit_title' => '紀錄之精靈', 'element' => '齒輪節拍',   'gift' => '不靠意志，靠系統',         'mythology' => '未來世界倒回送來的小幫手',               'motto' => '記錄勝過記憶，數據會替妳撐住 ⚙️'],
        ]);
    }
}
