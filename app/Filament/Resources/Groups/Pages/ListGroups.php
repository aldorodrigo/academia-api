<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Schemas\GroupForm;
use App\Filament\Support\Terms;
use App\Models\Program;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Las disciplinas no tienen menú propio: se agregan acá o desde el formulario.
            Action::make('newProgram')
                ->label(Terms::gendered('program', 'Disciplina', 'Nuevo', 'Nueva').' '.Terms::singular('program', 'Disciplina'))
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray')
                ->schema(GroupForm::programFields())
                ->authorize('create', GroupResource::getModel())
                ->action(function (array $data): void {
                    Program::query()->create($data);

                    Notification::make()->success()->title(Terms::label('program', 'Disciplina').' '.Terms::gendered('program', 'Disciplina', 'creado', 'creada').'.')->send();
                }),
            CreateAction::make(),
        ];
    }
}
