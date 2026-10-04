<?php

namespace App\Support\Dashboard;

use App\Enums\EnrollmentStatus;
use App\Enums\ExpenseStatus;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Números del Escritorio: consultas livianas (sumas y conteos en SQL) en vez de los informes
 * completos, que recorren familia por familia. Vencido = sin pagar después del vencimiento más
 * los días de gracia, la misma regla que Charge::status().
 */
class Metrics
{
    private CarbonImmutable $today;

    public function __construct(private Organization $organization)
    {
        $this->today = $organization->today();
    }

    public static function for(Organization $organization): self
    {
        return new self($organization);
    }

    /**
     * Cobranza del mes: lo cobrado, lo que vence en el mes y lo que falta, y lo vencido.
     *
     * @return array{collected: int, due: int, due_pending: int, overdue: int, overdue_families: int}
     */
    public function collection(): array
    {
        [$start, $end] = [$this->today->startOfMonth()->toDateString(), $this->today->endOfMonth()->toDateString()];

        $month = $this->charges()->whereBetween('charges.due_on', [$start, $end])
            ->selectRaw('COALESCE(SUM(charges.final_amount), 0) as due, COALESCE(SUM(GREATEST(charges.final_amount - COALESCE(paid.total, 0), 0)), 0) as pending')
            ->first();

        $overdue = $this->overdue()
            ->selectRaw('COALESCE(SUM(charges.final_amount - COALESCE(paid.total, 0)), 0) as total, COUNT(DISTINCT students.family_id) as families')
            ->first();

        return [
            'collected' => (int) DB::table('payments')
                ->where('organization_id', $this->organization->id)
                ->whereNull('voided_at')
                ->whereBetween('received_on', [$start, $end])
                ->sum('amount'),
            'due' => (int) $month->due,
            'due_pending' => (int) $month->pending,
            'overdue' => (int) $overdue->total,
            'overdue_families' => (int) $overdue->families,
        ];
    }

    /**
     * Lo que vence en los próximos 7 días y no está pagado.
     *
     * @return array{amount: int, count: int}
     */
    public function dueThisWeek(): array
    {
        $row = $this->charges()
            ->whereBetween('charges.due_on', [$this->today->toDateString(), $this->today->addDays(7)->toDateString()])
            ->whereRaw('charges.final_amount > COALESCE(paid.total, 0)')
            ->selectRaw('COALESCE(SUM(charges.final_amount - COALESCE(paid.total, 0)), 0) as amount, COUNT(*) as count')
            ->first();

        return ['amount' => (int) $row->amount, 'count' => (int) $row->count];
    }

    /**
     * Las familias con más deuda vencida, con un celular para escribirles.
     *
     * @return list<array{family_id: int, family: string, amount: int, oldest_due_on: string, phone: ?string}>
     */
    public function topDelinquents(int $limit = 5): array
    {
        $rows = $this->overdue()
            ->join('families', 'families.id', '=', 'students.family_id')
            ->groupBy('students.family_id', 'families.name')
            ->selectRaw('students.family_id, families.name, SUM(charges.final_amount - COALESCE(paid.total, 0)) as amount, MIN(charges.due_on) as oldest')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get();

        $phones = DB::table('guardians')
            ->whereIn('family_id', $rows->pluck('family_id'))
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['family_id', 'phone'])
            ->unique('family_id')
            ->pluck('phone', 'family_id');

        return $rows->map(fn (object $row) => [
            'family_id' => (int) $row->family_id,
            'family' => $row->name,
            'amount' => (int) $row->amount,
            'oldest_due_on' => (string) $row->oldest,
            'phone' => $phones[$row->family_id] ?? null,
        ])->values()->all();
    }

    /**
     * Alumnos con inscripción vigente o próxima, altas y bajas del mes y cada grupo con su cupo.
     *
     * @return array{active: int, new: int, withdrawn: int, full: int, groups: list<array{id: int, name: string, program: string, count: int, capacity: ?int}>}
     */
    public function students(): array
    {
        [$start, $end] = [$this->today->startOfMonth()->toDateString(), $this->today->endOfMonth()->toDateString()];
        $current = $this->currentEnrollments();

        $groups = DB::table('groups')
            ->join('programs', 'programs.id', '=', 'groups.program_id')
            ->where('groups.organization_id', $this->organization->id)
            ->where('groups.is_active', true)
            ->leftJoinSub(
                (clone $current)->groupBy('enrollments.group_id')->selectRaw('enrollments.group_id, COUNT(DISTINCT enrollments.student_id) as total'),
                'enrolled', 'enrolled.group_id', '=', 'groups.id',
            )
            ->orderBy('programs.name')->orderBy('groups.min_age')->orderBy('groups.name')
            ->get(['groups.id', 'groups.name', 'programs.name as program', 'groups.capacity', DB::raw('COALESCE(enrolled.total, 0) as total')])
            ->map(fn (object $group) => [
                'id' => (int) $group->id,
                'name' => $group->name,
                'program' => $group->program,
                'count' => (int) $group->total,
                'capacity' => $group->capacity === null ? null : (int) $group->capacity,
            ]);

        return [
            'active' => (int) (clone $current)->distinct()->count('enrollments.student_id'),
            'new' => (int) DB::table('enrollments')
                ->where('organization_id', $this->organization->id)
                ->whereBetween('enrolled_on', [$start, $end])
                ->distinct()->count('student_id'),
            'withdrawn' => (int) DB::table('enrollments')
                ->where('organization_id', $this->organization->id)
                ->where('status', EnrollmentStatus::Withdrawn->value)
                ->whereBetween('ended_on', [$start, $end])
                ->distinct()->count('student_id'),
            'full' => $groups->filter(fn (array $group) => $group['capacity'] !== null && $group['count'] >= $group['capacity'])->count(),
            'groups' => $groups->values()->all(),
        ];
    }

    /**
     * Cumpleaños de los alumnos activos en los próximos 7 días (hoy incluido).
     *
     * @return list<array{name: string, date: CarbonImmutable, age: int}>
     */
    public function birthdays(int $days = 7): array
    {
        $ids = $this->currentEnrollments()->distinct()->pluck('enrollments.student_id');

        return Student::query()->whereKey($ids)->get(['id', 'first_name', 'last_name', 'birth_date'])
            ->map(function (Student $student) use ($days) {
                $birth = $student->birth_date;
                $on = fn (int $year) => $this->today->setDate($year, $birth->month, $birth->month === 2 && $birth->day === 29 && ! checkdate(2, 29, $year) ? 28 : $birth->day);
                $birthday = $on($this->today->year)->lt($this->today) ? $on($this->today->year + 1) : $on($this->today->year);

                return $birthday->diffInDays($this->today, true) < $days ? [
                    'name' => $student->full_name,
                    'date' => $birthday,
                    'age' => $birthday->year - $student->birth_date->year,
                ] : null;
            })
            ->filter()
            ->sortBy('date')
            ->values()
            ->all();
    }

    /**
     * Saldo de cada cuenta activa.
     *
     * @return Collection<int, array{id: int, name: string, balance: int}>
     */
    public function accounts(): Collection
    {
        return MoneyAccount::query()
            ->where('organization_id', $this->organization->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name, 'balance' => $account->balance()]);
    }

    /**
     * Lo que está esperando a alguien. Cada clave cuenta una cosa.
     *
     * @return array{invitations: int, expired_invitations: int, groups_without_instructor: int, groups_without_schedule: int, guardians_without_app: int, pending_expenses: int, pending_enrollments: int}
     */
    public function pending(): array
    {
        $org = $this->organization->id;
        $invitations = fn () => DB::table('invitations')->where('organization_id', $org)->whereNull('accepted_at')->whereNull('revoked_at');
        $groups = fn () => DB::table('groups')->where('organization_id', $org)->where('is_active', true);

        return [
            'invitations' => $invitations()->where('expires_at', '>', now())->count(),
            'expired_invitations' => $invitations()->where('expires_at', '<=', now())->count(),
            'groups_without_instructor' => $groups()->whereNotExists(fn (Builder $query) => $query
                ->from('group_instructor')->whereColumn('group_instructor.group_id', 'groups.id'))->count(),
            'groups_without_schedule' => $groups()->whereNotExists(fn (Builder $query) => $query
                ->from('schedules')->whereColumn('schedules.group_id', 'groups.id'))->count(),
            'guardians_without_app' => DB::table('guardians')->where('organization_id', $org)->whereNull('user_id')
                ->where(fn (Builder $query) => $query->whereNotNull('phone')->orWhereNotNull('email'))->count(),
            'pending_expenses' => DB::table('expenses')->where('organization_id', $org)
                ->where('status', ExpenseStatus::Pending->value)->count(),
            'pending_enrollments' => DB::table('enrollments')->where('organization_id', $org)
                ->where('status', EnrollmentStatus::Pending->value)->count(),
        ];
    }

    /**
     * Cuotas sin anular con lo pagado (imputaciones de pagos no anulados).
     */
    private function charges(): Builder
    {
        $paid = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNull('payments.voided_at')
            ->where('payments.organization_id', $this->organization->id)
            ->groupBy('payment_allocations.charge_id')
            ->selectRaw('payment_allocations.charge_id, SUM(payment_allocations.amount + payment_allocations.early_payment_discount) as total');

        return DB::table('charges')
            ->where('charges.organization_id', $this->organization->id)
            ->whereNull('charges.voided_at')
            ->leftJoinSub($paid, 'paid', 'paid.charge_id', '=', 'charges.id');
    }

    /**
     * Cuotas vencidas sin pagar, con el alumno (para agrupar por familia).
     */
    private function overdue(): Builder
    {
        $graceDays = (int) $this->organization->billing('grace_days');

        return $this->charges()
            ->join('students', 'students.id', '=', 'charges.student_id')
            ->where('charges.due_on', '<', $this->today->subDays($graceDays)->toDateString())
            ->whereRaw('charges.final_amount > COALESCE(paid.total, 0)');
    }

    /**
     * Inscripciones activas o becadas de temporadas que no terminaron.
     */
    private function currentEnrollments(): Builder
    {
        return DB::table('enrollments')
            ->join('seasons', 'seasons.id', '=', 'enrollments.season_id')
            ->where('enrollments.organization_id', $this->organization->id)
            ->whereIn('enrollments.status', [EnrollmentStatus::Active->value, EnrollmentStatus::Scholarship->value])
            ->where('seasons.ends_on', '>=', $this->today->toDateString());
    }
}
