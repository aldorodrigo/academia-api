<?php

namespace App\Http\Controllers\Api\V1\Lessons;

use App\Actions\Lessons\AvailableSlots;
use App\Actions\Lessons\BuyPack;
use App\Actions\Lessons\LessonAccess;
use App\Enums\BookingStatus;
use App\Enums\ClassPackStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BookingResource;
use App\Http\Resources\Api\V1\ClassPackResource;
use App\Models\Booking;
use App\Models\ClassPack;
use App\Models\LessonPack;
use App\Models\LessonProfile;
use App\Models\Student;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clases particulares del lado del alumno adulto o del tutor: profesores, horas libres y paquetes.
 */
class LessonController extends Controller
{
    /** Días en que se sigue mostrando un paquete vencido o terminado. */
    public const RECENT_PACK_DAYS = 30;

    public function teachers(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        LessonAccess::ensureEnabled($organization);

        $students = Student::query()->inChargeOf($request->user())->orderBy('birth_date')->get();
        $profiles = LessonProfile::query()->enabled()->with(['user', 'packs'])->get()
            ->sortBy(fn (LessonProfile $profile) => $profile->user?->name)
            ->values();

        return response()->json([
            'data' => $profiles->map(fn (LessonProfile $profile) => [
                'id' => $profile->user_id,
                'name' => $profile->user?->name,
                'photo_url' => null,
                'duration_minutes' => $profile->duration_minutes,
                'single_price' => $profile->single_price,
                'packs' => $profile->packs->map(fn (LessonPack $pack) => self::offer($pack))->values(),
                'students' => $students->map(fn (Student $student) => [
                    'student' => ['id' => $student->id, 'first_name' => $student->first_name, 'full_name' => $student->full_name],
                    'pack' => ($pack = self::currentPack($student, $profile->user_id, $organization->today())) === null ? null : new ClassPackResource($pack),
                    'next_booking' => ($next = Booking::query()
                        ->where('student_id', $student->id)
                        ->where('user_id', $profile->user_id)
                        ->where('status', BookingStatus::Confirmed)
                        ->where('date', '>=', $organization->today()->toDateString())
                        ->orderBy('date')->orderBy('starts_at')
                        ->with(['student', 'teacher', 'charge', 'organization'])
                        ->first()) === null ? null : new BookingResource($next),
                ])->values(),
            ])->values(),
        ]);
    }

    public function slots(Request $request, int $teacher, CurrentOrganization $current, AvailableSlots $slots): JsonResponse
    {
        $organization = $current->get();
        LessonAccess::ensureEnabled($organization);
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        $profile = LessonProfile::query()->enabled()->where('user_id', $teacher)->first();
        abort_if($profile === null, 404, 'Este profesor no tiene clases particulares.');

        $today = $organization->today();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from'), $organization->timezone) : $today;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to'), $organization->timezone) : $today->addDays($profile->days_ahead);

        return response()->json(['data' => [
            'duration_minutes' => $profile->duration_minutes,
            'days' => $slots->for($profile, $organization, $from, $to),
        ]]);
    }

    public function buy(Request $request, int $pack, CurrentOrganization $current, BuyPack $buy): JsonResponse
    {
        LessonAccess::ensureEnabled($current->get());
        $data = $request->validate(['student_id' => ['required', 'integer']]);

        $student = Student::query()->inChargeOf($request->user())->find($data['student_id']);
        $offer = LessonPack::query()->where('is_active', true)->find($pack);
        abort_if($student === null || $offer === null, 404, 'No encontramos ese paquete.');
        abort_unless(LessonProfile::query()->enabled()->where('user_id', $offer->user_id)->exists(), 404, 'No encontramos ese paquete.');

        $classPack = $buy->handle($offer, $student, $request->user());

        return (new ClassPackResource($classPack->load('charge')))->response()->setStatusCode(201);
    }

    /**
     * El paquete que se muestra: el activo o pendiente; si no, el último vencido o terminado reciente.
     */
    public static function currentPack(Student $student, int $teacherId, CarbonImmutable $today): ?ClassPack
    {
        $packs = ClassPack::query()
            ->where('student_id', $student->id)
            ->where('user_id', $teacherId)
            ->with('charge.allocations.payment')
            ->orderByDesc('id')
            ->get();

        return $packs->first(fn (ClassPack $pack) => $pack->status === ClassPackStatus::Active)
            ?? $packs->first(fn (ClassPack $pack) => $pack->status === ClassPackStatus::PendingPayment)
            ?? $packs->first(fn (ClassPack $pack) => $pack->updated_at !== null
                && $pack->updated_at->gte($today->subDays(self::RECENT_PACK_DAYS)));
    }

    /**
     * @return array{id: int, classes: int, price: int, valid_days: ?int}
     */
    public static function offer(LessonPack $pack): array
    {
        return ['id' => $pack->id, 'classes' => $pack->classes, 'price' => $pack->price, 'valid_days' => $pack->valid_days];
    }
}
