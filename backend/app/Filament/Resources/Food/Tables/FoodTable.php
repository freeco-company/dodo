<?php

namespace App\Filament\Resources\Food\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FoodTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legacy_id')
                    ->searchable(),
                TextColumn::make('name_zh')
                    ->searchable(),
                TextColumn::make('name_en')
                    ->searchable(),
                TextColumn::make('category')
                    ->searchable(),
                TextColumn::make('brand')
                    ->searchable(),
                TextColumn::make('element')
                    ->searchable(),
                TextColumn::make('serving_description')
                    ->searchable(),
                TextColumn::make('serving_weight_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('calories')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('protein_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('carbs_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('fat_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('fiber_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sodium_mg')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sugar_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('source')
                    ->searchable(),
                IconColumn::make('verified')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
