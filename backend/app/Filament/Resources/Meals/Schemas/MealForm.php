<?php

namespace App\Filament\Resources\Meals\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MealForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('legacy_id')
                    ->default(null),
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Select::make('daily_log_id')
                    ->relationship('dailyLog', 'id')
                    ->default(null),
                DatePicker::make('date')
                    ->required(),
                TextInput::make('meal_type')
                    ->required(),
                TextInput::make('photo_url')
                    ->url()
                    ->default(null),
                TextInput::make('food_name')
                    ->default(null),
                Textarea::make('food_components')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('matched_food_ids')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('serving_weight_g')
                    ->numeric()
                    ->default(null),
                TextInput::make('calories')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('protein_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('carbs_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('fat_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('fiber_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('sodium_mg')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('sugar_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('meal_score')
                    ->numeric()
                    ->default(null),
                Textarea::make('coach_response')
                    ->default(null)
                    ->columnSpanFull(),
                Toggle::make('user_corrected')
                    ->required(),
                Textarea::make('correction_data')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('ai_confidence')
                    ->numeric()
                    ->default(null),
                Textarea::make('ai_raw_response')
                    ->default(null)
                    ->columnSpanFull(),
            ]);
    }
}
