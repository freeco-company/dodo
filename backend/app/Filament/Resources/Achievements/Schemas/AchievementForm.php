<?php

namespace App\Filament\Resources\Achievements\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AchievementForm
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
                TextInput::make('achievement_key')
                    ->required(),
                TextInput::make('achievement_name')
                    ->required(),
                DateTimePicker::make('unlocked_at')
                    ->required(),
            ]);
    }
}
