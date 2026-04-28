<?php

namespace App\Filament\Resources\Food;

use App\Filament\Resources\Food\Pages\CreateFood;
use App\Filament\Resources\Food\Pages\EditFood;
use App\Filament\Resources\Food\Pages\ListFood;
use App\Filament\Resources\Food\Schemas\FoodForm;
use App\Filament\Resources\Food\Tables\FoodTable;
use App\Models\Food;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class FoodResource extends Resource
{
    protected static ?string $model = Food::class;

    protected static ?string $navigationLabel = '食物資料庫';

    protected static ?string $modelLabel = '食物';

    protected static ?string $pluralModelLabel = '食物';

    protected static string|UnitEnum|null $navigationGroup = '食物資料';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return FoodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FoodTable::configure($table);
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
            'index' => ListFood::route('/'),
            'create' => CreateFood::route('/create'),
            'edit' => EditFood::route('/{record}/edit'),
        ];
    }
}
