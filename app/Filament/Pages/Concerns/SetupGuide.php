<?php

namespace App\Filament\Pages\Concerns;

use App\Actions\Academic\CreatePrograms;
use App\Actions\Academic\SaveGroups;
use App\Actions\Onboarding\ManageInstructors;
use App\Enums\GroupCriterion;
use App\Filament\Actions\ShowInvitationLinkAction;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Seasons\SeasonResource;
use App\Filament\Support\ContactField;
use App\Filament\Support\Terms;
use App\Filament\Support\VenueField;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Venue;
use App\Support\Onboarding\Checklist;
use App\Support\Onboarding\Team;
use App\Support\Onboarding\Templates;
use App\Support\Scheduling\ScheduleConflicts;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Guía "Primeros pasos" dentro del Escritorio (la misma que en la app): la lista de pasos con su
 * estado y un panel lateral para cada uno. El progreso sale de los datos (Checklist). Al
 * completarla desaparece; "Seguir después" la achica a una barra.
 */
trait SetupGuide
{
    /**
     * Solo el administrador configura el club (y el super admin, para dar soporte).
     */
    public static function canConfigure(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();

        return $user !== null && $tenant instanceof Organization
            && ($user->is_super_admin || $user->isOrganizationAdmin($tenant));
    }

    /**
     * @return array{steps: list<array<string, mixed>>, done: int, total: int, next: ?string, completed: bool, dismissed: bool}
     */
    public static function checklist(): array
    {
        return Checklist::for(Filament::getTenant())->toArray();
    }

    /**
     * Cómo se ve la guía: completa ('full'), achicada ('compact') o nada (null: completa o sin permiso).
     */
    public static function guideMode(): ?string
    {
        if (! self::canConfigure()) {
            return null;
        }

        $checklist = self::checklist();

        return match (true) {
            $checklist['completed'] => null,
            $checklist['dismissed'] => 'compact',
            default => 'full',
        };
    }

    public function dismissGuideAction(): Action
    {
        return Action::make('dismissGuide')
            ->label('Seguir después')
            ->color('gray')
            ->link()
            ->action(function () {
                $this->tenant()->forceFill(['onboarding_dismissed_at' => now()])->save();
                Notification::make()->title('La retomás desde acá cuando quieras.')->send();
            });
    }

    public function resumeGuideAction(): Action
    {
        return Action::make('resumeGuide')
            ->label('Seguir')
            ->action(fn () => $this->tenant()->forceFill(['onboarding_dismissed_at' => null])->save());
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

    /** Según el género del término: g('group', 'otro', 'otra'). */
    private function g(string $key, string $masculine, string $feminine): string
    {
        return Terms::gendered($key, $key, $masculine, $feminine);
    }

    /**
     * Un técnico con dos categorías a la misma hora: aviso (se guarda igual).
     *
     * @param  list<string>  $warnings
     */
    private function warnInstructor(array $warnings): void
    {
        if ($warnings !== []) {
            Notification::make()->warning()->persistent()->title('Ojo, se superponen')->body(implode("\n", $warnings))->send();
        }
    }

    private function notifyDone(string $title): void
    {
        $checklist = self::checklist();

        Notification::make()
            ->success()
            ->title($checklist['completed'] ? '¡Todo listo! Ya configuraste lo básico.' : $title)
            ->send();

        // Al completarla se recarga: la guía desaparece del Escritorio.
        if ($checklist['completed']) {
            $this->redirect(Dashboard::getUrl());
        }
    }

    // Paso 1: ¿Qué enseñan?

    public function programsAction(): Action
    {
        return Action::make('programs')
            ->label(fn () => Program::query()->exists() ? 'Agregar' : 'Elegir')
            ->modalHeading('¿Qué enseñan?')
            ->modalDescription(fn () => 'Elegí '.$this->g('program', 'uno o más', 'una o más').'. Te sugerimos cómo se arma cada '.$this->g('program', 'uno', 'una').': por edad (Sub-8, Sub-10…) o por nivel.')
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
                    ->label(fn () => $this->g('program', 'Otros', 'Otras'))
                    ->defaultItems(0)
                    ->addActionLabel(fn () => 'Agregar '.$this->g('program', 'otro', 'otra'))
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
                ];

                return [...$state, 'groups' => self::suggestions($state), 'plan' => []];
            })
            ->steps([
                // 1. Qué categorías tienen (sin horarios).
                Step::make('list')
                    ->label(fn () => ucfirst($this->plural('group')))
                    ->description(fn () => '¿Qué '.$this->plural('group').' tienen?')
                    ->schema([
                        Text::make(fn () => 'Las familias eligen '.$this->g('group', 'el', 'la').' '.$this->term('group').' al inscribirse. Te sugerimos una lista: cambiá lo que haga falta.'),
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
                        // Por nivel no hay edades: solo el nombre.
                        Repeater::make('groups')
                            ->label('Se van a crear')
                            ->helperText(fn (Get $get) => self::byAge($get('program_id')) ? 'Edad: la que cumplen en el año de la temporada.' : null)
                            ->table(fn (Get $get) => self::byAge($get('program_id'))
                                ? [Repeater\TableColumn::make('Nombre'), Repeater\TableColumn::make('Edad desde'), Repeater\TableColumn::make('Hasta')]
                                : [Repeater\TableColumn::make('Nombre')])
                            ->schema([
                                TextInput::make('name')->required()->maxLength(255)->distinct(),
                                TextInput::make('min_age')->numeric()->minValue(3)->maxValue(99)
                                    ->visible(fn (Get $get) => self::byAge($get('../../program_id'))),
                                TextInput::make('max_age')->numeric()->minValue(3)->maxValue(99)
                                    ->visible(fn (Get $get) => self::byAge($get('../../program_id'))),
                                TextInput::make('level')->hidden()->dehydratedWhenHidden(),
                            ])
                            ->minItems(1)
                            ->addActionLabel(fn () => 'Agregar '.$this->g('group', 'otro', 'otra')),
                        TextInput::make('capacity')
                            ->label(fn () => 'Cupo por cada '.$this->g('group', 'uno', 'una').' (opcional)')
                            ->numeric()->minValue(1),
                    ])
                    // Arma la pantalla de horarios con la lista (conserva lo ya cargado).
                    ->afterValidation(function (Get $get, Set $set) {
                        $previous = collect($get('plan') ?? [])->keyBy('name');
                        $set('plan', collect($get('groups') ?? [])->map(fn (array $group) => [
                            'name' => $group['name'],
                            'slots' => $previous[$group['name']]['slots'] ?? [self::emptySlot()],
                        ])->values()->all());
                    }),
                // 2. Cuándo entrena cada una.
                Step::make('schedules')
                    ->label('Horarios')
                    ->description(fn () => '¿Cuándo entrena cada '.$this->term('group').'?')
                    ->schema([
                        Text::make(fn () => 'Elegí los días y el horario de cada '.$this->g('group', 'uno', 'una').'. Si entrenan igual, cargá '
                            .$this->g('group', 'uno', 'una').' y tocá «Copiar a '.$this->g('group', 'todos', 'todas').'».'),
                        Repeater::make('plan')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->itemLabel(fn (array $state) => ($state['name'] ?? '').(self::hasDays($state) ? '' : ' · Falta el horario'))
                            ->extraItemActions([
                                Action::make('copyToAll')
                                    ->label(fn () => 'Copiar a '.$this->g('group', 'todos', 'todas'))
                                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                                    ->action(function (array $arguments, Repeater $component): void {
                                        $items = $component->getRawState();
                                        $slots = $items[$arguments['item']]['slots'] ?? [];
                                        $component->rawState(collect($items)->map(fn (array $item) => [...$item, 'slots' => $slots])->all());
                                        Notification::make()->title('Horario copiado a '.$this->g('group', 'todos', 'todas').'.')->send();
                                    }),
                            ])
                            ->schema([
                                Hidden::make('name'),
                                Repeater::make('slots')
                                    ->hiddenLabel()
                                    ->addActionLabel('Otro horario')
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    // Con un solo horario no se borra (se dejan los días vacíos).
                                    ->deletable(fn (?array $state) => count($state ?? []) > 1)
                                    ->columns(4)
                                    ->schema([
                                        CheckboxList::make('weekdays')
                                            ->hiddenLabel()
                                            ->options(collect(Schedule::WEEKDAYS)->map(fn (string $day) => mb_substr($day, 0, 3)))
                                            ->columns(7)
                                            // Al marcar días se actualiza "Falta el horario".
                                            ->live()
                                            ->columnSpanFull(),
                                        TimePicker::make('starts_at')->label('Desde')->seconds(false)->live(onBlur: true)
                                            ->required(fn (Get $get) => filled($get('weekdays'))),
                                        TimePicker::make('ends_at')->label('Hasta')->seconds(false)->live(onBlur: true)
                                            ->required(fn (Get $get) => filled($get('weekdays')))
                                            ->after('starts_at')
                                            ->validationMessages(['after' => 'Tiene que terminar después de empezar.']),
                                        // Lugar y cancha (también se crean acá); el último usado viene sugerido.
                                        VenueField::make()->live()->columnSpan(2),
                                    ]),
                                // Aviso en la tarjeta: misma cancha, mismo día y hora que otra categoría.
                                Text::make(fn (Get $get) => new HtmlString(collect(self::itemConflicts($get))
                                    ->map(fn (string $message) => '⚠ '.e($message))->join('<br>')
                                    .'<br><span style="opacity:.75">Se puede guardar igual (por ejemplo, si comparten la cancha).</span>'))
                                    ->color('warning')
                                    ->visible(fn (Get $get) => self::itemConflicts($get) !== []),
                            ]),
                    ]),
            ])
            ->action(function (array $data, SaveGroups $save) {
                $plan = collect($data['plan'] ?? [])->keyBy('name');

                $save->create(
                    Program::query()->findOrFail($data['program_id']),
                    collect($data['groups'])->map(fn (array $group) => [
                        ...$group,
                        'min_age' => filled($group['min_age'] ?? null) ? (int) $group['min_age'] : null,
                        'max_age' => filled($group['max_age'] ?? null) ? (int) $group['max_age'] : null,
                        'capacity' => filled($data['capacity'] ?? null) ? (int) $data['capacity'] : null,
                        'schedules' => self::schedules($plan[$group['name']]['slots'] ?? []),
                    ])->values()->all(),
                );

                $missing = collect($data['groups'])->reject(fn (array $group) => self::hasDays($plan[$group['name']] ?? []))->count();
                $pending = Program::query()->whereDoesntHave('groups')->orderBy('name')->value('name');
                $this->notifyDone(match (true) {
                    $missing > 0 => "Listo. {$missing} sin horario: completalo desde ".ucfirst($this->plural('group')).'.',
                    $pending !== null => "Listo. Ahora, {$pending}.",
                    default => 'Listo. Ahora, la temporada.',
                });
            });
    }

    /**
     * Choques de los horarios de una tarjeta (contra lo guardado y las otras tarjetas).
     *
     * @return list<string>
     */
    private static function itemConflicts(Get $get): array
    {
        $slots = self::planSlots($get('../../plan') ?? []);
        $keys = collect($slots)->where('group_name', $get('name'))->pluck('key')->all();

        return collect(ScheduleConflicts::forSlots($slots))->only($keys)->flatten()->unique()->values()->all();
    }

    /**
     * Los horarios de la pantalla 2, uno por día, para revisar choques.
     *
     * @param  array<int|string, array<string, mixed>>  $plan
     * @return list<array<string, mixed>>
     */
    private static function planSlots(array $plan): array
    {
        return collect($plan)->values()->flatMap(fn (array $item, int $i) => collect(self::schedules($item['slots'] ?? []))
            ->map(fn (array $schedule, int $j) => [...$schedule, 'key' => "{$i}-{$j}", 'group_name' => $item['name'] ?? '']))
            ->values()->all();
    }

    /**
     * Un horario vacío, con la hora más común y el último lugar usado.
     *
     * @return array<string, mixed>
     */
    private static function emptySlot(): array
    {
        return [
            'weekdays' => [],
            'starts_at' => '17:00',
            'ends_at' => '18:30',
            'venue_id' => Venue::query()->latest('id')->value('id'),
        ];
    }

    /**
     * La categoría tiene al menos un día elegido.
     *
     * @param  array<string, mixed>  $item
     */
    private static function hasDays(array $item): bool
    {
        return collect($item['slots'] ?? [])->contains(fn (array $slot) => filled($slot['weekdays'] ?? []));
    }

    /**
     * Horarios del formulario → horarios de la categoría (uno por día elegido).
     *
     * @param  array<int|string, array<string, mixed>>  $slots
     * @return list<array{weekday: int, starts_at: string, ends_at: string, venue_id: ?int}>
     */
    private static function schedules(array $slots): array
    {
        return collect($slots)
            // Los que tienen días y horas (mientras se carga pueden estar a medias).
            ->filter(fn (array $slot) => filled($slot['weekdays'] ?? []) && filled($slot['starts_at'] ?? null) && filled($slot['ends_at'] ?? null))
            ->flatMap(fn (array $slot) => collect($slot['weekdays'])->sort()->map(fn ($day) => [
                'weekday' => (int) $day,
                'starts_at' => substr((string) $slot['starts_at'], 0, 5),
                'ends_at' => substr((string) $slot['ends_at'], 0, 5),
                'venue_id' => filled($slot['venue_id'] ?? null) ? (int) $slot['venue_id'] : null,
            ]))
            ->values()
            ->all();
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
                $this->warnInstructor($data['teaches'] ? ScheduleConflicts::forInstructor(auth()->user(), $data['group_ids'] ?? []) : []);
                $this->notifyDone('Guardado.');
            });
    }

    public function inviteInstructorAction(): Action
    {
        return Action::make('inviteInstructor')
            ->label(fn () => 'Invitar a '.$this->g('instructor', 'un', 'una').' '.$this->term('instructor'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(fn () => 'Invitar a '.$this->g('instructor', 'un', 'una').' '.$this->term('instructor'))
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
                    $this->warnInstructor(ScheduleConflicts::forInstructor($result['user'], $result['user']->instructedGroups()->pluck('groups.id')->all()));
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
