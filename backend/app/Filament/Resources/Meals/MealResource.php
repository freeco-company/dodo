<?php

namespace App\Filament\Resources\Meals;

use App\Filament\Resources\Meals\Pages\CreateMeal;
use App\Filament\Resources\Meals\Pages\EditMeal;
use App\Filament\Resources\Meals\Pages\ListMeals;
use App\Filament\Resources\Meals\Schemas\MealForm;
use App\Filament\Resources\Meals\Tables\MealsTable;
use App\Models\Meal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class MealResource extends Resource
{
    protected static ?string $model = Meal::class;

    protected static ?string $navigationLabel = '餐食記錄';

    protected static ?string $modelLabel = '餐食';

    protected static ?string $pluralModelLabel = '餐食';

    protected static string|UnitEnum|null $navigationGroup = '飲食紀錄';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cake';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return MealForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MealsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeals::route('/'),
            'create' => CreateMeal::route('/create'),
            'edit' => EditMeal::route('/{record}/edit'),
        ];
    }
}
