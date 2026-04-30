<?php

namespace App\Filament\Resources\KnowledgeArticles\Pages;

use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeArticle extends CreateRecord
{
    protected static string $resource = KnowledgeArticleResource::class;
}
