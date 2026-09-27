<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Enrollments\TransferSeason;
use App\Enums\EnrollmentStatus;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Support\Terms;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Season;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Pase de temporada: elegir origen y destino, revisar la categoría sugerida de cada
 * jugador y reinscribir a todos de una vez.
 */
class SeasonTransfer extends Page
{
    protected static string $resource = EnrollmentResource::class;

    protected static ?string $title = 'Pase de temporada';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('create', Enrollment::class) ?? false;
    }

    public function mount(): void
    {
        $to = Season::currentOrNull();
        $from = Season::query()
            ->when($to, fn ($query) => $query->whereKeyNot($to->id)->where('starts_on', '<', $to->starts_on))
            ->orderByDesc('starts_on')
            ->first();

        $this->form->fill([
            'from_season_id' => $from?->id,
            'to_season_id' => $to?->id,
            'rows' => $this->candidates($from?->id, $to?->id),
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $reload = fn (Get $get, Set $set) => $set('rows', $this->candidates($get('from_season_id'), $get('to_season_id')));
        $seasons = fn () => Season::query()->orderByDesc('starts_on')->pluck('name', 'id');

        return $schema->components([
            Grid::make(2)->schema([
                Select::make('from_season_id')->label('Desde')->options($seasons)->required()->live()->afterStateUpdated($reload),
                Select::make('to_season_id')->label('Hacia')->options($seasons)->required()->different('from_season_id')
                    ->live()->afterStateUpdated($reload)
                    ->validationMessages(['different' => 'Elegí una temporada distinta.']),
            ]),
            Repeater::make('rows')
                ->label(fn (Get $get) => count($get('rows') ?? []) === 0
                    ? 'No hay jugadores para pasar en esa temporada.'
                    : 'Revisá la categoría de cada uno; se sugiere por edad.')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->table([
                    TableColumn::make(Terms::label('student', 'Jugador')),
                    TableColumn::make('Venía en'),
                    TableColumn::make('Pasa a'),
                    TableColumn::make('Estado'),
                    TableColumn::make('Reinscribir'),
                ])
                ->schema([
                    Hidden::make('enrollment_id'),
                    TextInput::make('player')->disabled()->dehydrated(),
                    TextInput::make('from_group')->disabled()->dehydrated()
                        ->hint(fn (Get $get) => $get('already') ? 'Ya reinscripto' : null),
                    Select::make('group_id')
                        ->options(fn () => Group::query()->with('program')->where('is_active', true)->orderBy('name')->get()
                            ->mapWithKeys(fn (Group $group) => [$group->id => "{$group->name} · {$group->program->name}"]))
                        ->required(),
                    Select::make('status')
                        ->options(collect(TransferSeason::TRANSFERABLE)->mapWithKeys(fn (EnrollmentStatus $s) => [$s->value => $s->label()]))
                        ->required(),
                    Toggle::make('include'),
                    Hidden::make('already'),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('transfer')
                ->footer([
                    Actions::make([
                        Action::make('transfer')
                            ->label(fn () => 'Reinscribir '.collect($this->data['rows'] ?? [])->where('include', true)->count().' jugadores')
                            ->submit('transfer'),
                    ]),
                ]),
        ]);
    }

    public function transfer(): void
    {
        $data = $this->form->getState();
        $rows = array_values($data['rows'] ?? []);

        $created = app(TransferSeason::class)->handle(
            Season::query()->findOrFail($data['from_season_id']),
            Season::query()->findOrFail($data['to_season_id']),
            $rows,
        );
        $skipped = collect($rows)->where('include', false)->where('already', false)->count();

        Notification::make()
            ->success()
            ->title("Reinscriptos: {$created}.".($skipped > 0 ? " Quedaron sin reinscribir: {$skipped}." : ''))
            ->send();

        $this->redirect(EnrollmentResource::getUrl());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function candidates(mixed $fromId, mixed $toId): array
    {
        $from = filled($fromId) ? Season::query()->find($fromId) : null;
        $to = filled($toId) ? Season::query()->find($toId) : null;

        if ($from === null || $to === null || $from->is($to)) {
            return [];
        }

        return app(TransferSeason::class)->candidates($from, $to)->all();
    }
}
