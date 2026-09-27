<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Filament\Support\EnrollmentForm;
use App\Models\Enrollment;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Otra disciplina o nueva temporada. Se abre directo con ?action=enroll.
            Action::make('enroll')
                ->label('Inscribir')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->modalHeading(fn () => "Inscribir a {$this->getStudent()->full_name}")
                ->schema(fn () => EnrollmentForm::fields($this->getStudent()))
                ->modalSubmitActionLabel('Inscribir')
                ->authorize('create', Enrollment::class)
                ->action(function (array $data): void {
                    $this->getStudent()->enrollments()->create($data);

                    Notification::make()->success()->title('Inscripción guardada.')->send();

                    // Recarga la ficha con la pestaña Inscripciones al día.
                    $this->redirect(static::getUrl(['record' => $this->getStudent()]));
                }),
            DeleteAction::make(),
        ];
    }

    private function getStudent(): Student
    {
        /** @var Student */
        return $this->getRecord();
    }
}
