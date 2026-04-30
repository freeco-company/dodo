<?php

namespace App\Filament\Resources\KnowledgeArticles\Pages;

use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeArticle extends EditRecord
{
    protected static string $resource = KnowledgeArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
