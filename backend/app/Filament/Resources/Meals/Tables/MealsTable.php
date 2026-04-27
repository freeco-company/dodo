<?php

namespace App\Filament\Resources\Meals\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legacy_id')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->searchable(),
                TextColumn::make('dailyLog.id')
                    ->searchable(),
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('meal_type')
                    ->searchable(),
                TextColumn::make('photo_url')
                    ->searchable(),
                TextColumn::make('food_name')
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
                TextColumn::make('meal_score')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('user_corrected')
                    ->boolean(),
                TextColumn::make('ai_confidence')
                    ->numeric()
                    ->sortable(),
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
