<?php

namespace App\Filament\Resources\DailyLogs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class DailyLogForm
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
                DatePicker::make('date')
                    ->required(),
                TextInput::make('total_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('calorie_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('protein_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('consistency_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('exercise_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('hydration_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total_calories')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total_protein_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('total_carbs_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('total_fat_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('total_fiber_g')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('water_ml')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('exercise_minutes')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('meals_logged')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('weight_kg')
                    ->numeric()
                    ->default(null),
                Textarea::make('daily_summary')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('xp_earned')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
