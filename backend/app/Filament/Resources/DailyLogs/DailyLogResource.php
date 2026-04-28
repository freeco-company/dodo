<?php

namespace App\Filament\Resources\DailyLogs;

use App\Filament\Resources\DailyLogs\Pages\CreateDailyLog;
use App\Filament\Resources\DailyLogs\Pages\EditDailyLog;
use App\Filament\Resources\DailyLogs\Pages\ListDailyLogs;
use App\Filament\Resources\DailyLogs\Schemas\DailyLogForm;
use App\Filament\Resources\DailyLogs\Tables\DailyLogsTable;
use App\Models\DailyLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class DailyLogResource extends Resource
{
    protected static ?string $model = DailyLog::class;

    protected static ?string $navigationLabel = '每日彙總';

    protected static ?string $modelLabel = '每日紀錄';

    protected static ?string $pluralModelLabel = '每日紀錄';

    protected static string|UnitEnum|null $navigationGroup = '飲食紀錄';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return DailyLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DailyLogsTable::configure($table);
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
            'index' => ListDailyLogs::route('/'),
            'create' => CreateDailyLog::route('/create'),
            'edit' => EditDailyLog::route('/{record}/edit'),
        ];
    }
}
