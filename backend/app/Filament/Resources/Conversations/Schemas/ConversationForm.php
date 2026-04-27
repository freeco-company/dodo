<?php

namespace App\Filament\Resources\Conversations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ConversationForm
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
                TextInput::make('role')
                    ->required(),
                Textarea::make('content')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('scenario')
                    ->default(null),
                TextInput::make('model_used')
                    ->default(null),
                TextInput::make('tokens_used')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
