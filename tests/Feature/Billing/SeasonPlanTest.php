<?php

use App\Actions\Billing\GenerateSeasonCharges;
use App\Actions\Billing\RegisterPayment;
use App\Actions\Billing\SeasonPeriods;
use App\Actions\Billing\VoidCharge;
use App\Actions\Enrollments\TransferSeason;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentMethod;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Scholarship;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

beforeEach(function () {
    $this->travelTo('2027-01-06 12:00:00');
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->padel = Program::factory()->for($this->jakare)->create(['name' => 'Pádel']);
    $this->sub10 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $this->padelKids = Group::factory()->for($this->padel)->create(['name' => 'Pádel Kids', 'organization_id' => $this->jakare->id]);
    // Entrena lunes, miércoles y viernes.
    foreach ([1, 3, 5] as $weekday) {
        Schedule::factory()->create(['group_id' => $this->sub10->id, 'weekday' => $weekday, 'starts_at' => '17:00', 'ends_at' => '18:30']);
    }
    $this->monthly = FeeConcept::monthlyFee($this->jakare);

    $this->family = Family::factory()->for($this->jakare)->create();
    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'family_id' => $this->family->id]);
});

function season(array $attributes, array $programs = [], int $amount = 150000): Season
{
    $season = Season::factory()->for(test()->jakare)->create($attributes);
    $season->programs()->sync($programs);
    Tariff::factory()->create(['fee_concept_id' => test()->monthly->id, 'season_id' => $season->id, 'amount' => $amount, 'valid_from' => $season->starts_on]);

    return $season;
}

function enroll(Season $season, ?Group $group = null, array $attributes = []): Enrollment
{
    return Enrollment::factory()->create([
        'student_id' => test()->mateo->id,
        'group_id' => ($group ?? test()->sub10)->id,
        'season_id' => $season->id,
        'enrolled_on' => $season->starts_on,
        ...$attributes,
    ]);
}

function periods(Season $season, ?Group $group = null): array
{
    return app(SeasonPeriods::class)->for($season, $group)
        ->map(fn ($p) => [$p->start->format('m-d'), $p->end->format('m-d'), $p->dueOn->format('m-d'), $p->quantity])
        ->all();
}

describe('períodos', function () {
    it('mensual: meses calendario recortados a la temporada, vence a los días del plan', function () {
        $season = Season::factory()->for($this->jakare)->make(['starts_on' => '2027-01-15', 'ends_on' => '2027-03-20', 'fee_frequency' => 'mensual', 'due_days' => 9]);

        expect(periods($season))->toBe([
            ['01-15', '01-31', '01-15', null],
            ['02-01', '02-28', '02-10', null],
            ['03-01', '03-20', '03-10', null],
        ]);
    });

    it('quincenal: del 1 al 15 y del 16 a fin de mes', function () {
        $season = Season::factory()->for($this->jakare)->make(['starts_on' => '2027-02-01', 'ends_on' => '2027-02-28', 'fee_frequency' => 'quincenal', 'due_days' => 3]);

        expect(periods($season))->toBe([
            ['02-01', '02-15', '02-04', null],
            ['02-16', '02-28', '02-19', null],
        ]);
    });

    it('semanal: de lunes a domingo, la primera recortada', function () {
        $season = Season::factory()->for($this->jakare)->make(['starts_on' => '2027-01-06', 'ends_on' => '2027-01-17', 'fee_frequency' => 'semanal', 'due_days' => 3]);

        expect(periods($season))->toBe([
            ['01-06', '01-10', '01-07', null],
            ['01-11', '01-17', '01-14', null],
        ]);
    });

    it('por día de entrenamiento: cuenta los días con horario y agrupa por día, semana o mes', function () {
        $base = ['starts_on' => '2027-01-04', 'ends_on' => '2027-01-31', 'fee_frequency' => 'diaria', 'daily_basis' => 'entrenamiento', 'due_days' => 0];

        $byWeek = Season::factory()->for($this->jakare)->make([...$base, 'daily_grouping' => 'semana']);
        $byMonth = Season::factory()->for($this->jakare)->make([...$base, 'daily_grouping' => 'mes']);
        $byDay = Season::factory()->for($this->jakare)->make([...$base, 'daily_grouping' => 'dia', 'ends_on' => '2027-01-10']);

        expect(collect(periods($byWeek, $this->sub10))->pluck(3)->all())->toBe([3, 3, 3, 3])
            ->and(periods($byMonth, $this->sub10))->toBe([['01-04', '01-31', '01-04', 12]])
            ->and(collect(periods($byDay, $this->sub10))->pluck(0)->all())->toBe(['01-04', '01-06', '01-08']);
    });
});

describe('emisión', function () {
    it('al empezar cada período: al inscribir crea solo el período en curso y el generador sigue', function () {
        $season = season(['starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'fee_frequency' => 'mensual']);
        enroll($season);

        expect(Charge::query()->pluck('description')->all())->toBe(['Cuota enero 2027']);

        $this->travelTo('2027-02-01 12:00:00');
        $summary = app(GenerateSeasonCharges::class)->handle($this->jakare);

        expect($summary)->toMatchArray(['created' => 1, 'existing' => 1])
            ->and(app(GenerateSeasonCharges::class)->handle($this->jakare)['created'])->toBe(0);
        $february = Charge::query()->latest('id')->first();
        expect($february->season_id)->toBe($season->id)
            ->and($february->period_start->toDateString())->toBe('2027-02-01')
            ->and($february->period_end->toDateString())->toBe('2027-02-28')
            ->and($february->due_on->toDateString())->toBe('2027-02-10');
    });

    it('todas juntas al inscribir: crea la temporada una sola vez y las futuras son próximas', function () {
        $season = season(['starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'fee_frequency' => 'mensual', 'issue_upfront' => true]);
        enroll($season);
        app(GenerateSeasonCharges::class)->handle($this->jakare);

        $charges = Charge::query()->orderBy('period_start')->get();
        expect($charges)->toHaveCount(12)
            ->and($charges->first()->isUpcoming())->toBeFalse()
            ->and($charges->last()->isUpcoming())->toBeTrue();
    });

    it('a mitad de período: proporcional, completo o desde el próximo', function (string $mode, array $expected) {
        $season = season(['starts_on' => '2027-01-01', 'ends_on' => '2027-02-28', 'fee_frequency' => 'mensual', 'issue_upfront' => true], amount: 310000);
        // Se inscribe el 22 de enero: faltan 10 de 31 días.
        enroll($season, attributes: ['enrolled_on' => '2027-01-22', 'mid_period' => $mode]);

        expect(Charge::query()->orderBy('period_start')->get()->map(fn (Charge $c) => [$c->description, $c->base_amount])->all())->toBe($expected);

        // La del período en curso vence a los días del plan desde la inscripción, no antes.
        if ($mode !== 'proximo') {
            expect(Charge::query()->orderBy('period_start')->first()->due_on->toDateString())->toBe('2027-01-31');
        }
    })->with([
        'proporcional' => ['proporcional', [['Cuota enero 2027 (proporcional)', 100000], ['Cuota febrero 2027', 310000]]],
        'completo' => ['completo', [['Cuota enero 2027', 310000], ['Cuota febrero 2027', 310000]]],
        'próximo' => ['proximo', [['Cuota febrero 2027', 310000]]],
    ]);

    it('por día agrupado por semana: cantidad × monto por día', function () {
        $season = season(['starts_on' => '2027-01-04', 'ends_on' => '2027-01-17', 'fee_frequency' => 'diaria', 'daily_basis' => 'entrenamiento', 'daily_grouping' => 'semana', 'due_days' => 3, 'issue_upfront' => true], amount: 20000);
        enroll($season);

        $first = Charge::query()->orderBy('period_start')->first();
        expect(Charge::query()->count())->toBe(2)
            ->and($first->description)->toBe('Cuota semana 4–10 ene (3 entrenamientos)')
            ->and([$first->quantity, $first->unit_amount, $first->base_amount])->toBe([3, 20000, 60000]);
    });

    it('por clase asistida no genera hasta que exista Asistencia', function () {
        $season = season(['starts_on' => '2027-01-04', 'ends_on' => '2027-01-17', 'fee_frequency' => 'diaria', 'daily_basis' => 'asistencia', 'daily_grouping' => 'semana', 'issue_upfront' => true], amount: 20000);
        enroll($season);

        expect(Charge::query()->count())->toBe(0);
    });

    it('un jugador en dos temporadas a la vez (anual de fútbol y colonia de pádel)', function () {
        $anual = season(['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'fee_frequency' => 'mensual'], [$this->futbol->id]);
        $colonia = season(['name' => 'Colonia', 'starts_on' => '2027-01-04', 'ends_on' => '2027-01-17', 'fee_frequency' => 'semanal', 'due_days' => 2], [$this->padel->id], 80000);
        enroll($anual);
        enroll($colonia, $this->padelKids);

        expect(Enrollment::query()->billable()->count())->toBe(2)
            ->and(Charge::query()->with('season')->get()->map(fn (Charge $c) => [$c->season->name, $c->base_amount])->sortBy(0)->values()->all())
            ->toBe([['2027', 150000], ['Colonia', 80000]])
            ->and(Season::query()->active()->forProgram($this->padel)->pluck('name')->all())->toBe(['Colonia']);
    });

    it('las claves viejas por mes no se vuelven a emitir', function () {
        $season = season(['starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $enrollment = enroll($season);
        Charge::factory()->create([
            'student_id' => $this->mateo->id, 'enrollment_id' => $enrollment->id, 'season_id' => $season->id,
            'unique_key' => "enr:{$enrollment->id}:con:{$this->monthly->id}:per:2027-01-01",
        ]);
        $season->update(['fee_frequency' => 'mensual']);

        expect(app(GenerateSeasonCharges::class)->handle($this->jakare))->toMatchArray(['created' => 0, 'existing' => 1]);
    });
});

describe('baja, suspensión y becas', function () {
    beforeEach(function () {
        $this->season = season(['starts_on' => '2027-01-01', 'ends_on' => '2027-06-30', 'fee_frequency' => 'mensual', 'issue_upfront' => true]);
        $this->enrollment = enroll($this->season);
    });

    it('la baja anula las futuras sin pagar y deja las pagadas y la del período en curso', function () {
        $march = Charge::query()->whereDate('period_start', '2027-03-01')->sole();
        $cash = MoneyAccount::query()->first();
        app(RegisterPayment::class)->handle($this->family, $cash, 50000, PaymentMethod::Cash, CarbonImmutable::parse('2027-01-06'), allocations: [$march->id => 50000]);

        $this->enrollment->update(['status' => EnrollmentStatus::Withdrawn]);

        $voided = Charge::query()->whereNotNull('voided_at')->orderBy('period_start')->get();
        expect($voided->map(fn (Charge $c) => $c->period_start->format('m'))->all())->toBe(['02', '04', '05', '06'])
            ->and($voided->first()->void_reason)->toBe('Baja de la inscripción')
            ->and($voided->first()->unique_key)->toBeNull();

        // Al reactivarla se reemiten las que faltan.
        $this->enrollment->update(['status' => EnrollmentStatus::Active]);

        expect(Charge::query()->whereNull('voided_at')->count())->toBe(6);
    });

    it('anular con "volver a emitir" rehace la cuota con la beca aprobada', function () {
        $february = Charge::query()->whereDate('period_start', '2027-02-01')->sole();
        Scholarship::factory()->approved()->create(['enrollment_id' => $this->enrollment->id, 'percent' => 50, 'valid_from' => '2027-02-01']);

        app(VoidCharge::class)->handle($february, 'Beca aprobada', User::factory()->create(), reissue: true);

        $new = Charge::query()->whereDate('period_start', '2027-02-01')->whereNull('voided_at')->sole();
        expect($new->final_amount)->toBe(75000)
            ->and($february->fresh()->unique_key)->toBeNull();
    });
});

describe('pase de temporada', function () {
    it('pasa solo las disciplinas de la temporada nueva y crea las cuotas en segundo plano, con aviso', function () {
        $old = season(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'], [$this->futbol->id]);
        enroll($old);
        enroll($old, $this->padelKids);
        $new = season(['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'fee_frequency' => 'mensual', 'issue_upfront' => true], [$this->futbol->id]);
        $user = User::factory()->create();

        $rows = app(TransferSeason::class)->candidates($old, $new);
        $created = app(TransferSeason::class)->handle($old, $new, $rows->all(), $user);

        expect($rows)->toHaveCount(1)
            ->and($created)->toBe(1)
            ->and(Charge::query()->where('season_id', $new->id)->count())->toBe(12)
            ->and(DatabaseNotification::query()->sole()->data['title'])->toBe('Cuotas creadas')
            ->and(DatabaseNotification::query()->sole()->data['body'])->toBe('Se crearon 12 cuotas para 1 inscripciones.');
    });
});

describe('estado de cuenta', function () {
    it('separa lo que hay que pagar ahora de las próximas cuotas', function () {
        $season = season(['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-03-31', 'fee_frequency' => 'mensual', 'issue_upfront' => true]);
        enroll($season);
        $tutor = memberOf($this->jakare);
        Guardian::factory()->for($this->jakare)->create(['user_id' => $tutor->id, 'family_id' => $this->family->id])->students()->attach($this->mateo);

        $response = $this->actingAs($tutor, 'sanctum')->getJson('/api/v1/account', ['X-Organization' => 'jakare'])->assertOk();

        // La inscripción a una temporada que todavía no empezó también es próxima.
        $next = season(['name' => '2028', 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31']);
        Tariff::factory()->create(['fee_concept_id' => FeeConcept::enrollmentFee($this->jakare)->id, 'season_id' => $next->id, 'amount' => 100000, 'valid_from' => '2028-01-01']);
        enroll($next);

        $response = $this->actingAs($tutor, 'sanctum')->getJson('/api/v1/account', ['X-Organization' => 'jakare'])->assertOk();

        expect($response->json('data'))->toMatchArray(['balance' => 550000, 'due_now' => 150000, 'upcoming' => 400000])
            ->and($response->json('data.students.0'))->toMatchArray(['due_now' => 150000, 'upcoming' => 400000])
            ->and(collect($response->json('data.charges'))->where('is_upcoming', true)->count())->toBe(3)
            ->and(collect($response->json('data.charges'))->firstWhere('period_start', '2027-01-01'))->toMatchArray([
                'season' => ['id' => $season->id, 'name' => '2027'],
                'period_start' => '2027-01-01',
                'period_end' => '2027-01-31',
                'quantity' => null,
                'is_upcoming' => false,
            ]);
    });
});
