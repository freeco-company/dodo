<?php

namespace App\Filament\Resources\KnowledgeArticles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class KnowledgeArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('title')->searchable()->limit(40),
                BadgeColumn::make('category')
                    ->colors([
                        'primary' => ['protein', 'fiber', 'water'],
                        'success' => ['cutting', 'maintenance'],
                        'warning' => ['qna', 'myth_busting'],
                        'gray' => 'other',
                    ]),
                TextColumn::make('audience')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : '')
                    ->badge(),
                IconColumn::make('published_at')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye')
                    ->falseIcon('heroicon-o-pencil')
                    ->trueColor('success')
                    ->label('已發布'),
                TextColumn::make('view_count')->numeric()->sortable()->toggleable(),
                TextColumn::make('saved_count')->numeric()->sortable()->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')->options([
                    'protein' => '蛋白質', 'carb' => '碳水', 'fiber' => '纖維', 'fat' => '油脂',
                    'water' => '水分', 'micronutrient' => '微量元素', 'product_match' => '產品搭配',
                    'meal_timing' => '餐次安排', 'cutting' => '減脂期', 'maintenance' => '維持期',
                    'qna' => '常見 Q&A', 'myth_busting' => '謬誤澄清', 'lifestyle' => '生活作息', 'other' => '其他',
                ]),
                TernaryFilter::make('published_at')
                    ->label('發布狀態')
                    ->placeholder('全部')
                    ->trueLabel('已發布')
                    ->falseLabel('草稿'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
