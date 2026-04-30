<?php

namespace App\Filament\Resources\KnowledgeArticles\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class KnowledgeArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(200)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, $set, $get) {
                    if (empty($get('slug')) && ! empty($state)) {
                        $set('slug', Str::slug($state));
                    }
                }),

            TextInput::make('slug')
                ->required()
                ->maxLength(100)
                ->unique(ignoreRecord: true)
                ->helperText('URL-safe identifier (lowercase, dash-separated)'),

            Select::make('category')
                ->options([
                    'protein' => '蛋白質',
                    'carb' => '碳水',
                    'fiber' => '纖維',
                    'fat' => '油脂',
                    'water' => '水分',
                    'micronutrient' => '微量元素',
                    'product_match' => '產品搭配',
                    'meal_timing' => '餐次安排',
                    'cutting' => '減脂期',
                    'maintenance' => '維持期',
                    'qna' => '常見 Q&A',
                    'myth_busting' => '謬誤澄清',
                    'lifestyle' => '生活作息',
                    'other' => '其他',
                ])
                ->required(),

            Select::make('audience')
                ->multiple()
                ->options([
                    'retail' => '零售客戶',
                    'franchisee' => '加盟者',
                ])
                ->helperText('哪些 TA 看得到。retail = 一般 App 用戶；franchisee = 加盟者後台'),

            Textarea::make('summary')
                ->maxLength(500)
                ->rows(2)
                ->helperText('一句話摘要 (用在 list / push)'),

            Textarea::make('body')
                ->required()
                ->rows(8)
                ->helperText('內容 markdown / 純文字。此為原始營養師專業語氣版本'),

            Textarea::make('dodo_voice_body')
                ->rows(6)
                ->helperText('朵朵語氣改寫版（妳/朋友, 不用您/會員）。給 App 端展示用'),

            TextInput::make('reading_time_seconds')
                ->numeric()
                ->minValue(0)
                ->maxValue(3600)
                ->suffix('秒')
                ->default(60),

            TextInput::make('source_image')
                ->maxLength(200)
                ->helperText('storage/seed/nutrition_kb/raw/ 內檔名 (追溯用)'),

            TextInput::make('source_attribution')
                ->maxLength(200)
                ->default('專業營養師群組分享'),

            DateTimePicker::make('published_at')
                ->helperText('留空 = 草稿；填日期 = 該時間後自動上線'),
        ]);
    }
}
