<?php

namespace App\Filament\Resources\KnowledgeArticles;

use App\Filament\Resources\KnowledgeArticles\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticles\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticles\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticles\Schemas\KnowledgeArticleForm;
use App\Filament\Resources\KnowledgeArticles\Tables\KnowledgeArticlesTable;
use App\Models\KnowledgeArticle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Filament admin resource for the營養知識庫.
 *
 * v1: manual CRUD（admin / 加盟者寫文章 + 朵朵語氣改寫）。
 * Phase 5c: OCR pipeline 會 batch-create draft entries from
 * storage/seed/nutrition_kb/raw/，admin 在這頁 review + publish。
 */
class KnowledgeArticleResource extends Resource
{
    protected static ?string $model = KnowledgeArticle::class;

    protected static ?string $navigationLabel = '知識庫';

    protected static ?string $modelLabel = '知識文章';

    protected static ?string $pluralModelLabel = '知識文章';

    protected static string|UnitEnum|null $navigationGroup = '內容';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return KnowledgeArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeArticlesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeArticles::route('/'),
            'create' => CreateKnowledgeArticle::route('/create'),
            'edit' => EditKnowledgeArticle::route('/{record}/edit'),
        ];
    }
}
