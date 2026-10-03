<?php

namespace App\Filament\Pages;

use App\Actions\Academic\CreatePrograms;
use App\Actions\Academic\SaveGroups;
use App\Actions\Onboarding\ManageInstructors;
use App\Enums\GroupCriterion;
use App\Filament\Actions\ShowInvitationLinkAction;
use App\Filament\Resources\Seasons\SeasonResource;
use App\Filament\Support\ContactField;
use App\Filament\Support\Terms;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Venue;
use App\Support\Onboarding\Checklist;
use App\Support\Onboarding\Team;
use App\Support\Onboarding\Templates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * "Primeros pasos": la guía del administrador (la misma que en la app). Cada paso se hace
 * en un panel lateral; el progreso sale de los datos (Checklist).
 */
class Onboarding extends Page
{
    protected string $view = 'filament.pages.onboarding';

    protected static ?string $slug = 'primeros-pasos';

    protected static ?string $title = 'Primeros pasos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = -10;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();

        return $user !== null && $tenant instanceof Organization
            && ($user->is_super_admin || $user->isOrganizationAdmin($tenant));
    }

    public static function getNavigationBadge(): ?string
    {
        $checklist = self::checklist();

        return $checklist['completed'] ? null : "{$checklist['done']}/{$checklist['total']}";
    }

    /**
     * Se abre sola (desde el Escritorio) mientras esté incompleta y no se haya cerrado.
     */
    public static function shouldOpen(): bool
    {
        $tenant = Filament::getTenant();

        // El super admin que entra a dar soporte no cae en la guía.
        if (! $tenant instanceof Organization || ! auth()->user()?->isOrganizationAdmin($tenant)) {
            return false;
        }

        $checklist = self::checklist();

        return ! $checklist['completed'] && ! $checklist['dismissed'];
    }

    /**
     * @return array{steps: list<array<string, mixed>>, done: int, total: int, next: ?string, completed: bool, dismissed: bool}
     */
    public static function checklist(): array
    {
        return Checklist::for(Filament::getTenant())->toArray();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Te llevamos paso a paso. Se guarda solo: podés dejarlo y seguir después.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('dismiss')
                ->label('Seguir después')
                ->color('gray')
                ->visible(fn () => ! self::checklist()['completed'] && ! self::checklist()['dismissed'])
                ->action(function () {
                    $this->tenant()->forceFill(['onboarding_dismissed_at' => now()])->save();
                    Notification::make()->title('La retomás desde el menú «Primeros pasos».')->send();
                    $this->redirect(Filament::getUrl());
                }),
        ];
    }

    private function tenant(): Organization
    {
        return Filament::getTenant();
    }

    private function term(string $key): string
    {
        return Terms::singular($key, $key);
    }

    private function plural(string $key): string
    {
        return Terms::plural($key, $key);
    }

    private function notifyDone(string $title): void
    {
        $checklist = self::checklist();

        Notification::make()
            ->success()
            ->title($checklist['completed'] ? '¡Todo listo! Ya configuraste lo básico.' : $title)
            ->send();

        // Al completarla se recarga para actualizar el menú (el contador desaparece).
        if ($checklist['completed']) {
            $this->redirect(static::getUrl());
        }
    }

    // Paso 1: ¿Qué enseñan?

    public function programsAction(): Action
    {
        return Action::make('programs')
            ->label(fn () => Program::query()->exists() ? 'Agregar' : 'Elegir')
            ->modalHeading('¿Qué enseñan?')
            ->modalDescription('Elegí una o más. Te sugerimos cómo se arma cada una: por edad (Sub-8, Sub-10…) o por nivel.')
            ->slideOver()
            ->schema([
                CheckboxList::make('programs')
                    ->hiddenLabel()
                    ->options(fn () => collect(Templates::programs())
                        ->reject(fn (array $program) => Program::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($program['name'])])->exists())
                        ->mapWithKeys(fn (array $program) => [$program['name'] => $program['name']]))
                    ->descriptions(fn () => collect(Templates::programs())
                        ->mapWithKeys(fn (array $program) => [$program['name'] => $program['group_criterion'] === GroupCriterion::Level->value ? 'Por nivel' : 'Por edad']))
                    ->columns(3),
                Repeater::make('custom')
                    ->label('Otras')
                    ->defaultItems(0)
                    ->addActionLabel('Agregar otra')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required()->maxLength(100),
                        Select::make('group_criterion')
                            ->label('Se arma')
                            ->options([GroupCriterion::BirthYear->value => 'Por edad', GroupCriterion::Level->value => 'Por nivel'])
                            ->default(GroupCriterion::Level->value)
                            ->selectablePlaceholder(false)
                            ->required(),
                    ]),
            ])
            ->action(function (array $data, CreatePrograms $create) {
                $templates = collect(Templates::programs())->keyBy('name');
                $create->handle([
                    ...collect($data['programs'] ?? [])->map(fn (string $name) => $templates[$name])->all(),
                    ...($data['custom'] ?? []),
                ]);
                $this->notifyDone('Listo. Ahora, '.$this->plural('group').' y horarios.');
            });
    }

    // Paso 2: categorías y horarios

    public function groupsAction(): Action
    {
        return Action::make('groups')
            ->label(fn () => Group::query()->exists() ? 'Agregar' : 'Armar')
            ->modalHeading(fn () => ucfirst($this->plural('group')).' y horarios')
            ->modalDescription(fn () => 'Las familias eligen la '.$this->term('group').' al inscribirse. Te sugerimos una lista: cambiá lo que haga falta.')
            ->slideOver()
            ->fillForm(function () {
                $program = Program::query()->whereDoesntHave('groups')->orderBy('name')->first()
                    ?? Program::query()->orderBy('name')->first();
                $ages = Templates::ages();
                $state = [
                    'program_id' => $program?->id,
                    'from' => $ages['from'],
                    'to' => $ages['to'],
                    'span' => $ages['span'],
                    'levels' => Templates::levels(),
                    'weekdays' => [],
                    'starts_at' => '17:00',
                    'ends_at' => '18:30',
                    'venue_id' => Venue::query()->count() === 1 ? Venue::query()->value('id') : null,
                ];

                return [...$state, 'groups' => self::suggestions($state)];
            })
            ->schema([
                Select::make('program_id')
                    ->label(fn () => ucfirst($this->term('program')))
                    ->options(fn () => Program::query()->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('groups', self::suggestions(self::state($get)))),
                Grid::make(3)
                    ->visible(fn (Get $get) => self::byAge($get('program_id')))
                    ->schema([
                        Select::make('from')->label('Edad desde')->options(self::ageOptions())->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('groups', self::suggestions(self::state($get)))),
                        Select::make('to')->label('Hasta')->options(self::ageOptions())->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('groups', self::suggestions(self::state($get)))),
                        Radio::make('span')->label('De a')->options([1 => '1 año', 2 => '2 años'])->inline()->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('groups', self::suggestions(self::state($get)))),
                    ]),
                TagsInput::make('levels')
                    ->label('Niveles')
                    ->visible(fn (Get $get) => ! self::byAge($get('program_id')))
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('groups', self::suggestions(self::state($get)))),
                Repeater::make('groups')
                    ->label('Se van a crear')
                    ->helperText('Por edad: la que cumplen en el año de la temporada.')
                    ->table([
                        Repeater\TableColumn::make('Nombre'),
                        Repeater\TableColumn::make('Desde'),
                        Repeater\TableColumn::make('Hasta'),
                    ])
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255)->distinct(),
                        TextInput::make('min_age')->numeric()->minValue(3)->maxValue(99),
                        TextInput::make('max_age')->numeric()->minValue(3)->maxValue(99),
                        TextInput::make('level')->hidden()->dehydratedWhenHidden(),
                    ])
                    ->minItems(1)
                    ->addActionLabel('Agregar otra'),
                Section::make('¿Qué días y a qué hora?')
                    ->description('Igual para todas. Después se cambia en cada una.')
                    ->compact()
                    ->schema([
                        CheckboxList::make('weekdays')
                            ->hiddenLabel()
                            ->options(collect(Schedule::WEEKDAYS)->map(fn (string $day) => mb_substr($day, 0, 3)))
                            ->columns(7)
                            ->required()
                            ->validationMessages(['required' => 'Elegí al menos un día.']),
                        Grid::make(2)->schema([
                            TimePicker::make('starts_at')->label('Desde')->seconds(false)->required(),
                            TimePicker::make('ends_at')->label('Hasta')->seconds(false)->required()->after('starts_at')
                                ->validationMessages(['after' => 'Tiene que terminar después de empezar.']),
                        ]),
                    ]),
                Section::make('¿Dónde entrenan?')
                    ->description('Opcional.')
                    ->compact()
                    ->schema([
                        Select::make('venue_id')
                            ->label('Lugar')
                            ->options(fn () => Venue::query()->orderBy('name')->pluck('name', 'id'))
                            ->placeholder('Otro lugar')
                            ->live()
                            ->visible(fn () => Venue::query()->exists()),
                        Grid::make(2)
                            ->visible(fn (Get $get) => blank($get('venue_id')))
                            ->schema([
                                TextInput::make('venue_name')->label('Lugar nuevo')->placeholder('Cancha del club'),
                                TextInput::make('venue_address')->label('Dirección'),
                            ]),
                        TextInput::make('capacity')->label('Cupo por cada una')->numeric()->minValue(1),
                    ]),
            ])
            ->action(function (array $data, SaveGroups $save) {
                $schedules = collect($data['weekdays'])->sort()->map(fn ($day) => [
                    'weekday' => (int) $day,
                    'starts_at' => substr($data['starts_at'], 0, 5),
                    'ends_at' => substr($data['ends_at'], 0, 5),
                ])->values()->all();

                $save->create(
                    Program::query()->findOrFail($data['program_id']),
                    collect($data['groups'])->map(fn (array $group) => [
                        ...$group,
                        'min_age' => filled($group['min_age'] ?? null) ? (int) $group['min_age'] : null,
                        'max_age' => filled($group['max_age'] ?? null) ? (int) $group['max_age'] : null,
                        'capacity' => filled($data['capacity'] ?? null) ? (int) $data['capacity'] : null,
                        'schedules' => $schedules,
                    ])->values()->all(),
                    filled($data['venue_id'] ?? null)
                        ? ['id' => (int) $data['venue_id']]
                        : ['name' => $data['venue_name'] ?? null, 'address' => $data['venue_address'] ?? null],
                );

                $pending = Program::query()->whereDoesntHave('groups')->orderBy('name')->value('name');
                $this->notifyDone($pending === null ? 'Listo. Ahora, la temporada.' : "Listo. Ahora, {$pending}.");
            });
    }

    /**
     * @return array<string, mixed>
     */
    private static function state(Get $get): array
    {
        return [
            'program_id' => $get('program_id'),
            'from' => $get('from'),
            'to' => $get('to'),
            'span' => $get('span'),
            'levels' => $get('levels'),
        ];
    }

    private static function byAge(mixed $programId): bool
    {
        return Program::query()->find($programId)?->group_criterion === GroupCriterion::BirthYear;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private static function suggestions(array $state): array
    {
        $program = Program::query()->find($state['program_id']);

        if ($program === null) {
            return [];
        }

        $existing = $program->groups()->pluck('name')->map(fn (string $name) => mb_strtolower($name));
        $from = (int) ($state['from'] ?? 5);
        $groups = $program->group_criterion === GroupCriterion::BirthYear
            ? Templates::groupsByAge($from, max($from, (int) ($state['to'] ?? 16)), max(1, (int) ($state['span'] ?? 2)))
            : Templates::groupsByLevel($state['levels'] ?? []);

        return collect($groups)->reject(fn (array $group) => $existing->contains(mb_strtolower($group['name'])))->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private static function ageOptions(): array
    {
        return collect(range(3, 18))->mapWithKeys(fn (int $age) => [$age => "{$age} años"])->all();
    }

    // Paso 3: temporada (el asistente de siempre)

    public function seasonAction(): Action
    {
        return Action::make('season')
            ->label('Abrir el asistente')
            ->url(fn () => SeasonResource::getUrl('create', ['guia' => 1]));
    }

    // Paso 4: técnicos

    public function teachingAction(): Action
    {
        return Action::make('teaching')
            ->label('Yo también doy clases')
            ->color('gray')
            ->modalHeading('Yo también doy clases')
            ->modalDescription('Tomás asistencia desde la app con tu cuenta.')
            ->fillForm(function () {
                $me = Team::instructors($this->tenant())->firstWhere('id', auth()->id());

                return ['teaches' => $me !== null, 'group_ids' => $me?->instructedGroups->pluck('id')->all() ?? []];
            })
            ->schema([
                Toggle::make('teaches')->label('Doy clases')->live(),
                CheckboxList::make('group_ids')
                    ->label('¿Cuáles?')
                    ->options(fn () => Group::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->columns(3)
                    ->visible(fn (Get $get) => $get('teaches')),
            ])
            ->action(function (array $data, ManageInstructors $manage) {
                $manage->setTeaching($this->tenant(), auth()->user(), (bool) $data['teaches'], $data['group_ids'] ?? []);
                $this->notifyDone('Guardado.');
            });
    }

    public function inviteInstructorAction(): Action
    {
        return Action::make('inviteInstructor')
            ->label(fn () => 'Invitar a un '.$this->term('instructor'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(fn () => 'Invitar a un '.$this->term('instructor'))
            ->modalDescription('Con el celular, le mandás el link por WhatsApp; con el correo, también le llega por email.')
            ->schema([
                TextInput::make('name')->label('Nombre y apellido')->required()->maxLength(255),
                ContactField::make(),
                CheckboxList::make('group_ids')
                    ->label('¿Qué da?')
                    ->options(fn () => Group::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->columns(3),
            ])
            ->action(function (array $data, ManageInstructors $manage) {
                $contact = ContactField::split($data['contact']);
                $result = $manage->invite($this->tenant(), auth()->user(), $data['name'], $contact['email'], $data['group_ids'] ?? [], $contact['phone']);

                if ($result['token'] === null) {
                    $this->notifyDone('Ya era '.$this->term('instructor').': le asignamos lo que da.');

                    return;
                }

                $this->replaceMountedAction('showLink', ['token' => $result['token'], 'name' => $data['name'], 'phone' => $contact['phone']]);
            });
    }

    public function showLinkAction(): Action
    {
        return ShowInvitationLinkAction::make();
    }

    public function skipInstructorsAction(): Action
    {
        return Action::make('skipInstructors')
            ->label('Lo hago después')
            ->link()
            ->color('gray')
            ->action(function () {
                $organization = $this->tenant();
                $organization->forceFill([
                    'onboarding_skipped' => collect($organization->onboarding_skipped ?? [])->push('instructors')->unique()->values()->all(),
                ])->save();
                $this->notifyDone('Lo dejaste para después.');
            });
    }
}
