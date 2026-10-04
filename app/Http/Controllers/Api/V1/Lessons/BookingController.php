<?php

namespace App\Http\Controllers\Api\V1\Lessons;

use App\Actions\Lessons\BookLesson;
use App\Actions\Lessons\CancelBooking;
use App\Actions\Lessons\LessonAccess;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BookingResource;
use App\Models\Booking;
use App\Models\LessonProfile;
use App\Models\Student;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reservas de los alumnos a cargo del usuario (alumno adulto o tutor).
 */
class BookingController extends Controller
{
    /** Días hacia atrás que se muestran en "Pasadas". */
    public const PAST_DAYS = 60;

    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        LessonAccess::ensureEnabled($organization);
        $request->validate(['student_id' => ['nullable', 'integer']]);

        $students = Student::query()->inChargeOf($request->user())
            ->when($request->filled('student_id'), fn ($query) => $query->whereKey($request->integer('student_id')))
            ->pluck('id');
        $now = CarbonImmutable::now($organization->timezone);

        $bookings = Booking::query()
            ->whereIn('student_id', $students)
            ->where('date', '>=', $organization->today()->subDays(self::PAST_DAYS)->toDateString())
            ->with(['student', 'teacher', 'charge.allocations.payment', 'organization'])
            ->orderBy('date')
            ->orderBy('starts_at')
            ->get();

        [$upcoming, $past] = $bookings->partition(fn (Booking $booking) => ! $booking->hasStarted($now));

        return response()->json(['data' => [
            'upcoming' => BookingResource::collection($upcoming->filter(fn (Booking $booking) => $booking->status === BookingStatus::Confirmed)->values()),
            'past' => BookingResource::collection($past->reverse()->values()),
        ]]);
    }

    public function store(Request $request, CurrentOrganization $current, BookLesson $book): JsonResponse
    {
        $organization = $current->get();
        LessonAccess::ensureEnabled($organization);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'teacher_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i'],
        ]);

        $student = Student::query()->inChargeOf($request->user())->find($data['student_id']);
        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Solo podés reservar para vos o para tus hijos.']);
        }

        $profile = LessonProfile::query()->enabled()->where('user_id', $data['teacher_id'])->first();
        abort_if($profile === null, 404, 'Este profesor no tiene clases particulares.');

        $booking = $book->handle(
            $organization,
            $profile,
            $student,
            CarbonImmutable::parse($data['date'], $organization->timezone),
            $data['starts_at'],
            $request->user(),
        );

        return (new BookingResource($booking->load(['student', 'teacher', 'charge', 'organization'])))
            ->response()->setStatusCode(201);
    }

    public function destroy(Request $request, int $booking, CurrentOrganization $current, CancelBooking $cancel): BookingResource
    {
        LessonAccess::ensureEnabled($current->get());

        $students = Student::query()->inChargeOf($request->user())->pluck('id');
        $booking = Booking::query()->whereIn('student_id', $students)->find($booking);
        abort_if($booking === null, 404, 'No encontramos esta reserva.');

        $cancel->handle($booking, $request->user(), byTeacher: false);

        return new BookingResource($booking->load(['student', 'teacher', 'charge', 'organization']));
    }
}
