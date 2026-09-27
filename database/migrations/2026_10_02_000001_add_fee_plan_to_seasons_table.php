<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temporadas por disciplina, vigentes por fechas (puede haber varias a la vez),
 * con plan de cobro propio. Las cuotas quedan relacionadas con su temporada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->string('kind')->default('anual')->after('name');
            // Null = sin plan de cobro todavía (no genera cuotas).
            $table->string('fee_frequency')->nullable()->after('ends_on');
            $table->string('daily_basis')->nullable()->after('fee_frequency');
            $table->string('daily_grouping')->nullable()->after('daily_basis');
            // Días desde el inicio del período hasta el vencimiento.
            $table->unsignedSmallInteger('due_days')->default(9)->after('daily_grouping');
            // Todas las cuotas de la temporada al inscribir (si no, al empezar cada período).
            $table->boolean('issue_upfront')->default(false)->after('due_days');
            $table->string('mid_period')->default('completo')->after('issue_upfront');
            $table->index(['organization_id', 'starts_on', 'ends_on']);
        });

        Schema::create('program_season', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->primary(['program_id', 'season_id']);
        });

        Schema::table('enrollments', function (Blueprint $table) {
            // Lo que eligió quien inscribió para el período en curso (null = lo del plan).
            $table->string('mid_period')->nullable()->after('ended_on');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('season_id')->nullable()->after('group_id')->constrained()->nullOnDelete();
            $table->date('period_start')->nullable()->after('period');
            $table->date('period_end')->nullable()->after('period_start');
            // Cobro por día agrupado: cantidad de días × monto por día.
            $table->unsignedInteger('quantity')->nullable()->after('base_amount');
            $table->unsignedBigInteger('unit_amount')->nullable()->after('quantity');
            $table->index(['organization_id', 'season_id']);
        });

        $this->migrateData();

        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('is_current');
        });
    }

    /**
     * Las temporadas existentes quedan anuales, con cuota mensual por período y todas las
     * disciplinas; las cuotas, con su temporada y su período; las claves "per:Y-m" pasan a
     * "per:Y-m-d" para no volver a emitir el mes en curso.
     */
    private function migrateData(): void
    {
        // La cuota ya no es solo mensual (puede ser quincenal, semanal o por día).
        DB::table('fee_concepts')->where('code', 'monthly_fee')->where('name', 'Cuota mensual')->update(['name' => 'Cuota']);

        foreach (DB::table('seasons')->get() as $season) {
            $billing = json_decode((string) DB::table('organizations')->where('id', $season->organization_id)->value('billing'), true) ?: [];

            DB::table('seasons')->where('id', $season->id)->update([
                'kind' => 'anual',
                'fee_frequency' => 'mensual',
                'due_days' => max(0, (int) ($billing['due_day'] ?? 10) - 1),
            ]);

            DB::table('program_season')->insertOrIgnore(
                DB::table('programs')->where('organization_id', $season->organization_id)->pluck('id')
                    ->map(fn ($programId) => ['program_id' => $programId, 'season_id' => $season->id])
                    ->all(),
            );
        }

        DB::table('charges')->orderBy('id')->each(function ($charge) {
            $changes = [];

            if ($charge->enrollment_id !== null) {
                $changes['season_id'] = DB::table('enrollments')->where('id', $charge->enrollment_id)->value('season_id');
            }

            if ($charge->period !== null) {
                $start = Carbon::parse($charge->period);
                $changes['period_start'] = $start->toDateString();
                $changes['period_end'] = $start->copy()->endOfMonth()->toDateString();
            }

            if ($charge->unique_key !== null && preg_match('/:per:(\d{4}-\d{2})$/', $charge->unique_key, $match) === 1) {
                $changes['unique_key'] = substr($charge->unique_key, 0, -7).$match[1].'-01';
            }

            if ($changes !== []) {
                DB::table('charges')->where('id', $charge->id)->update($changes);
            }
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->boolean('is_current')->default(false);
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'season_id']);
            $table->dropConstrainedForeignId('season_id');
            $table->dropColumn(['period_start', 'period_end', 'quantity', 'unit_amount']);
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('mid_period');
        });

        Schema::dropIfExists('program_season');

        Schema::table('seasons', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'starts_on', 'ends_on']);
            $table->dropColumn(['kind', 'fee_frequency', 'daily_basis', 'daily_grouping', 'due_days', 'issue_upfront', 'mid_period']);
        });
    }
};
