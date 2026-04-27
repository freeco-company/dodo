<?php

namespace App\Filament\Resources\Meals\Pages;

use App\Filament\Resources\Meals\MealResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMeals extends ListRecords
{
    protected static string $resource = MealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
