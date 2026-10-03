<?php

namespace App\Http\Controllers\Api\V1\Lessons;

use App\Actions\Lessons\AvailableSlots;
use App\Actions\Lessons\LessonAccess;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\AvailabilitySlot;
use App\Models\LessonPack;
use App\Models\LessonProfile;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de clases particulares del profesor: precio, duración, paquetes y disponibilidad.
 */
class LessonProfileController extends Controller
{
    public function show(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        $this->authorizeTeacher($request->user(), $organization);

        return response()->json(['data' => $this->payload(LessonProfile::for($request->user(), $organization), $organization)]);
    }

    public function update(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        $user = $request->user();
        $this->authorizeTeacher($user, $organization);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:180'],
            'single_price' => ['required', 'integer', 'min:1'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'days_ahead' => ['required', 'integer', 'min:1', 'max:90'],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('organization_id', $organization->id)],
            'packs' => ['present', 'array', 'max:10'],
            'packs.*.id' => ['nullable', 'integer'],
            'packs.*.classes' => ['required', 'integer', 'min:1', 'max:100'],
            'packs.*.price' => ['required', 'integer', 'min:1'],
            'packs.*.valid_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'availability' => ['present', 'array', 'max:50'],
            'availability.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'availability.*.starts_at' => ['required', 'date_format:H:i'],
            'availability.*.ends_at' => ['required', 'date_format:H:i'],
        ], [
            'single_price.min' => 'Poné el precio de la clase suelta.',
            'packs.*.price.min' => 'Poné el precio de cada paquete.',
            'packs.*.valid_days.*' => 'La validez de un paquete va de 1 a 365 días.',
            'duration_minutes.*' => 'La clase dura entre 30 minutos y 3 horas.',
        ]);

        $this->validateAvailability($data['availability'], (bool) $data['enabled']);

        DB::transaction(function () use ($data, $user, $organization) {
            $profile = LessonProfile::for($user, $organization);
            $profile->fill(collect($data)->only(['enabled', 'duration_minutes', 'single_price', 'min_notice_minutes', 'days_ahead', 'money_account_id'])->all());
            $profile->save();

            // Los paquetes que no vienen dejan de ofrecerse. Los vendidos guardan su cantidad, precio
            // y validez, así que cambiar la oferta no los afecta.
            $kept = [];
            foreach ($data['packs'] as $pack) {
                $attributes = ['classes' => $pack['classes'], 'price' => $pack['price'], 'valid_days' => $pack['valid_days'] ?? null, 'is_active' => true];
                $existing = isset($pack['id']) ? LessonPack::query()->where('user_id', $user->id)->find($pack['id']) : null;

                $existing !== null
                    ? $existing->update($attributes)
                    : $existing = LessonPack::query()->create([...$attributes, 'organization_id' => $organization->id, 'user_id' => $user->id]);
                $kept[] = $existing->id;
            }
            LessonPack::query()->where('user_id', $user->id)->whereNotIn('id', $kept)->update(['is_active' => false]);

            AvailabilitySlot::query()->where('user_id', $user->id)->delete();
            foreach ($data['availability'] as $range) {
                AvailabilitySlot::query()->create([...$range, 'organization_id' => $organization->id, 'user_id' => $user->id]);
            }
        });

        return response()->json(['data' => $this->payload(LessonProfile::for($user, $organization), $organization)]);
    }

    /**
     * Instructor de la organización, o ya tiene perfil (lo puede seguir editando).
     */
    private function authorizeTeacher(User $user, Organization $organization): void
    {
        LessonAccess::ensureEnabled($organization);

        abort_unless(
            $user->hasCurrentRole($organization, OrganizationRole::Instructor) || LessonProfile::for($user, $organization)->exists,
            403,
            'Las clases particulares son para los profesores.',
        );
    }

    /**
     * @param  list<array{weekday: int, starts_at: string, ends_at: string}>  $ranges
     */
    private function validateAvailability(array $ranges, bool $enabled): void
    {
        $days = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

        foreach (collect($ranges)->groupBy('weekday') as $weekday => $day) {
            $spans = $day->map(fn (array $range) => [AvailableSlots::minutes($range['starts_at']), AvailableSlots::minutes($range['ends_at'])])
                ->sortBy(0)->values();

            foreach ($spans as $i => [$from, $to]) {
                if ($to <= $from) {
                    throw ValidationException::withMessages(['availability' => "El {$days[$weekday - 1]}, una franja termina antes de empezar."]);
                }
                if ($i > 0 && $from < $spans[$i - 1][1]) {
                    throw ValidationException::withMessages(['availability' => "El {$days[$weekday - 1]} hay franjas que se superponen."]);
                }
            }
        }

        if ($enabled && $ranges === []) {
            throw ValidationException::withMessages(['availability' => 'Cargá al menos una franja de disponibilidad.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(LessonProfile $profile, Organization $organization): array
    {
        $packs = LessonPack::query()->where('user_id', $profile->user_id)->where('is_active', true)->orderBy('classes')->get();
        $availability = AvailabilitySlot::query()->where('user_id', $profile->user_id)->orderBy('weekday')->orderBy('starts_at')->get();
        $accounts = MoneyAccount::query()->where('is_active', true)->orderBy('id')->get(['id', 'name']);

        return [
            'enabled' => (bool) $profile->enabled,
            'duration_minutes' => (int) $profile->duration_minutes,
            'single_price' => (int) $profile->single_price,
            'min_notice_minutes' => (int) $profile->min_notice_minutes,
            'days_ahead' => (int) $profile->days_ahead,
            'money_account_id' => $profile->money_account_id ?? $accounts->first()?->id,
            'money_accounts' => $accounts->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name])->values(),
            'packs' => $packs->map(fn (LessonPack $pack) => LessonController::offer($pack))->values(),
            'availability' => $availability->map(fn (AvailabilitySlot $slot) => [
                'weekday' => $slot->weekday,
                'starts_at' => substr($slot->starts_at, 0, 5),
                'ends_at' => substr($slot->ends_at, 0, 5),
            ])->values(),
        ];
    }
}
