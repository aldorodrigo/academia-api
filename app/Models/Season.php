<?php

namespace App\Models;

use App\Enums\DailyBasis;
use App\Enums\DailyGrouping;
use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Enums\SeasonKind;
use App\Enums\SeasonStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Temporada de una o varias disciplinas. Vigente según sus fechas: puede haber varias
 * a la vez (la anual de fútbol, la de pádel, una colonia de vacaciones).
 *
 * Plan de cobro: frecuencia de la cuota (null = sin plan, no genera cuotas), vencimiento,
 * si las cuotas se crean todas al inscribir y qué se cobra al inscribir a mitad de período.
 */
#[Fillable(['organization_id', 'name', 'kind', 'starts_on', 'ends_on', 'fee_frequency', 'daily_basis', 'daily_grouping', 'due_days', 'issue_upfront', 'mid_period'])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = ['kind' => 'anual', 'due_days' => 9, 'issue_upfront' => false, 'mid_period' => 'completo'];

    protected function casts(): array
    {
        return [
            'kind' => SeasonKind::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'fee_frequency' => FeeFrequency::class,
            'daily_basis' => DailyBasis::class,
            'daily_grouping' => DailyGrouping::class,
            'due_days' => 'integer',
            'issue_upfront' => 'boolean',
            'mid_period' => MidPeriod::class,
        ];
    }

    /**
     * Hoy en la fecha local de la organización activa.
     */
    public static function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()?->today() ?? CarbonImmutable::today();
    }

    /**
     * Vigentes en una fecha (por defecto, hoy).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query, ?CarbonInterface $on = null): void
    {
        $on = ($on ?? self::today())->toDateString();

        $query->whereDate('starts_on', '<=', $on)->whereDate('ends_on', '>=', $on);
    }

    /**
     * Vigentes o próximas (no terminaron).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query, ?CarbonInterface $on = null): void
    {
        $query->whereDate('ends_on', '>=', ($on ?? self::today())->toDateString());
    }

    /**
     * Temporadas de una disciplina (una temporada sin disciplinas vale para todas).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forProgram(Builder $query, Program|int $program): void
    {
        $id = $program instanceof Program ? $program->id : $program;

        $query->where(fn (Builder $query) => $query
            ->whereHas('programs', fn (Builder $programs) => $programs->whereKey($id))
            ->orWhereDoesntHave('programs'));
    }

    /**
     * Temporada que se sugiere para una disciplina: la vigente más nueva o, si no hay, la próxima.
     */
    public static function defaultFor(Program|int|null $program = null): ?self
    {
        $query = fn () => static::query()->when($program !== null, fn (Builder $query) => $query->forProgram($program));

        return $query()->active()->orderByDesc('starts_on')->first()
            ?? $query()->open()->orderBy('starts_on')->first();
    }

    public function status(?CarbonInterface $on = null): SeasonStatus
    {
        $on = CarbonImmutable::instance($on ?? self::today())->startOfDay();

        return match (true) {
            $on->lt($this->starts_on) => SeasonStatus::Upcoming,
            $on->gt($this->ends_on) => SeasonStatus::Finished,
            default => SeasonStatus::Active,
        };
    }

    public function hasEnded(?CarbonInterface $on = null): bool
    {
        return $this->status($on) === SeasonStatus::Finished;
    }

    /**
     * Tiene plan de cobro (genera cuotas).
     */
    public function hasFeePlan(): bool
    {
        return $this->fee_frequency !== null;
    }

    /**
     * El cobro depende de la asistencia (todavía no hay módulo): no genera cuotas.
     */
    public function chargesByAttendance(): bool
    {
        return $this->fee_frequency === FeeFrequency::Daily && $this->daily_basis === DailyBasis::Attendance;
    }

    /**
     * La cuota se crea al terminar el período (por clase asistida o por clase dictada).
     */
    public function chargesAfterPeriod(): bool
    {
        return $this->fee_frequency === FeeFrequency::Daily
            && in_array($this->daily_basis, [DailyBasis::Attendance, DailyBasis::Taught], true);
    }

    /**
     * @return BelongsToMany<Program, $this>
     */
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return HasMany<Tariff, $this>
     */
    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    /**
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }
}
