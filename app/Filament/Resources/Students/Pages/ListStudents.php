<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Actions\SpreadsheetImportAction;
use App\Filament\Imports\StudentImporter;
use App\Filament\Resources\Students\StudentResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SpreadsheetImportAction::make()
                ->label('Importar Excel')
                ->importer(StudentImporter::class)
                // El import corre en cola: se le pasa la organización explícitamente.
                ->options(fn () => ['organization_id' => Filament::getTenant()?->getKey()])
                ->authorize('create', StudentResource::getModel()),
            CreateAction::make(),
        ];
    }
}
