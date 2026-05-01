<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('legacy_id')
                    ->default(null),
                TextInput::make('line_id')
                    ->default(null),
                TextInput::make('apple_id')
                    ->default(null),
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->default(null),
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->default(null),
                TextInput::make('avatar_color')
                    ->required()
                    ->default('peach'),
                TextInput::make('avatar_species')
                    ->required()
                    ->default('balance'),
                TextInput::make('avatar_animal')
                    ->required()
                    ->default('cat'),
                TextInput::make('daily_pet_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                DatePicker::make('last_pet_date'),
                DatePicker::make('last_gift_date'),
                Textarea::make('outfits_owned')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('equipped_outfit')
                    ->required()
                    ->default('none'),
                TextInput::make('height_cm')
                    ->numeric()
                    ->default(null),
                TextInput::make('current_weight_kg')
                    ->numeric()
                    ->default(null),
                TextInput::make('target_weight_kg')
                    ->numeric()
                    ->default(null),
                TextInput::make('start_weight_kg')
                    ->numeric()
                    ->default(null),
                DatePicker::make('birth_date'),
                TextInput::make('gender')
                    ->default(null),
                TextInput::make('activity_level')
                    ->required()
                    ->default('light'),
                Textarea::make('allergies')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('dislike_foods')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('favorite_foods')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('dietary_type')
                    ->required()
                    ->default('normal'),
                TextInput::make('level')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('xp')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('current_streak')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('longest_streak')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total_days')
                    ->required()
                    ->numeric()
                    ->default(0),
                DatePicker::make('last_active_date'),
                TextInput::make('subscription_tier')
                    ->required()
                    ->default('free'),
                DateTimePicker::make('subscription_expires_at'),
                TextInput::make('daily_calorie_target')
                    ->numeric()
                    ->default(null),
                TextInput::make('daily_protein_target_g')
                    ->numeric()
                    ->default(null),
                TextInput::make('friendship')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('streak_shields')
                    ->required()
                    ->numeric()
                    ->default(1),
                DateTimePicker::make('shield_last_refill'),
                TextInput::make('membership_tier')
                    ->required()
                    ->default('public'),
                TextInput::make('subscription_type')
                    ->required()
                    ->default('none'),
                DateTimePicker::make('subscription_expires_at_iso'),
                TextInput::make('fp_ref_code')
                    ->default(null),
                DateTimePicker::make('tier_verified_at'),
                Toggle::make('is_franchisee')
                    ->label('已加盟（FP 團隊夥伴）')
                    ->helperText('勾起 = 解鎖 fp_recipe / franchise 卡牌、FP 皇冠 / 主廚裝、FP 食物圖鑑。客服 / 業務手動勾選；母艦 webhook 整合後會自動同步。')
                    ->afterStateUpdated(fn ($state, $set) => $state ? $set('franchise_verified_at', now()->toDateTimeString()) : null)
                    ->live(),
                DateTimePicker::make('franchise_verified_at')
                    ->label('加盟驗證時間')
                    ->helperText('勾選 is_franchisee 時自動填上現在時間'),
                TextInput::make('island_visits_used')
                    ->required()
                    ->numeric()
                    ->default(0),
                DatePicker::make('island_visits_reset_at'),
                TextInput::make('journey_cycle')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('journey_day')
                    ->required()
                    ->numeric()
                    ->default(1),
                DatePicker::make('journey_last_advance_date'),
                DateTimePicker::make('journey_started_at'),
                TextInput::make('daily_water_goal_ml')
                    ->required()
                    ->numeric()
                    ->default(3000),
                DateTimePicker::make('onboarded_at'),
                DateTimePicker::make('disclaimer_ack_at'),
            ]);
    }
}
