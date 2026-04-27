<?php

namespace App\Filament\Resources\Food\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FoodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('legacy_id')
                    ->default(null),
                TextInput::make('name_zh')
                    ->required(),
                TextInput::make('name_en')
                    ->default(null),
                TextInput::make('category')
                    ->default(null),
                TextInput::make('brand')
                    ->default(null),
                TextInput::make('element')
                    ->required()
                    ->default('neutral'),
                TextInput::make('serving_description')
                    ->default(null),
                TextInput::make('serving_weight_g')
                    ->numeric()
                    ->default(null),
                TextInput::make('calories')
                    ->numeric()
                    ->default(null),
                TextInput::make('protein_g')
                    ->numeric()
                    ->default(null),
                TextInput::make('carbs_g')
                    ->numeric()
                    ->default(null),
                TextInput::make('fat_g')
                    ->numeric()
                    ->default(null),
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
                Textarea::make('variants')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('aliases')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('source')
                    ->default(null),
                Toggle::make('verified')
                    ->required(),
            ]);
    }
}
