<?php

namespace App\Filament\Resources\DailyLogs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DailyLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legacy_id')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->searchable(),
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('total_score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('calorie_score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('protein_score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('consistency_score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('exercise_score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('hydration_score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_calories')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_protein_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_carbs_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_fat_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_fiber_g')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('water_ml')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('exercise_minutes')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('meals_logged')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('weight_kg')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('xp_earned')
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
