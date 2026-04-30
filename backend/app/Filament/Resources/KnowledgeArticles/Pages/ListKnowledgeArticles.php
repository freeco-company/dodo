<?php

namespace App\Filament\Resources\KnowledgeArticles\Pages;

use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeArticles extends ListRecords
{
    protected static string $resource = KnowledgeArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
