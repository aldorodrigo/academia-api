<?php

namespace App\Filament\Resources\Scholarships\Pages;

use App\Actions\Billing\ScholarshipDecision;
use App\Filament\Resources\Scholarships\ScholarshipResource;
use App\Filament\Support\Terms;
use App\Models\Enrollment;
use App\Models\Scholarship;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageScholarships extends ManageRecords
{
    protected static string $resource = ScholarshipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request')
                ->label('Nueva beca')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', Scholarship::class)
                ->modalDescription('Queda pendiente hasta que la apruebe alguien con el permiso "Aprobar becas".')
                ->schema([
                    Select::make('enrollment_id')
                        ->label(Terms::label('student', 'Jugador'))
                        ->options(fn () => Enrollment::query()->current()->with(['student', 'group', 'season'])->get()
                            ->sortBy(fn (Enrollment $e) => $e->student->last_name)
                            ->mapWithKeys(fn (Enrollment $e) => [$e->id => "{$e->student->last_name}, {$e->student->first_name} · {$e->group->name} · {$e->season->name}"]))
                        ->searchable()
                        ->required(),
                    TextInput::make('percent')->label('Porcentaje')->suffix('%')->numeric()->minValue(1)->maxValue(100)->default(50)
                        ->helperText('100 = beca total (no se genera la cuota).')->required(),
                    Textarea::make('reason')->label('Motivo')->required(),
                    // Desde el 1º del mes en curso: así cuenta para la cuota de este mes (si todavía no se generó).
                    DatePicker::make('valid_from')->label('Desde')
                        ->default(fn () => Filament::getTenant()->today()->startOfMonth()->toDateString())
                        ->helperText('Rige para las cuotas de ese mes en adelante. Las ya generadas no cambian.')
                        ->required(),
                    DatePicker::make('valid_to')->label('Hasta')->afterOrEqual('valid_from'),
                ])
                ->action(function (array $data): void {
                    app(ScholarshipDecision::class)->request(
                        Enrollment::query()->findOrFail($data['enrollment_id']),
                        (int) $data['percent'],
                        $data['reason'],
                        CarbonImmutable::parse($data['valid_from']),
                        filled($data['valid_to'] ?? null) ? CarbonImmutable::parse($data['valid_to']) : null,
                        auth()->user(),
                    );

                    Notification::make()->success()->title('Beca cargada: queda pendiente de aprobación.')->send();
                }),
        ];
    }
}
