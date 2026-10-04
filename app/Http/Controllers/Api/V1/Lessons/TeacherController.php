<?php

namespace App\Http\Controllers\Api\V1\Lessons;

use App\Actions\Lessons\BuyPack;
use App\Actions\Lessons\CancelBooking;
use App\Actions\Lessons\CollectLessonPayment;
use App\Actions\Lessons\ExtendClassPack;
use App\Actions\Lessons\LessonAccess;
use App\Actions\Lessons\MarkBooking;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BookingResource;
use App\Http\Resources\Api\V1\ClassPackResource;
use App\Models\Booking;
use App\Models\Charge;
use App\Models\ClassPack;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\LessonPack;
use App\Models\LessonProfile;
use App\Models\Student;
use App\Support\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Clases particulares del lado del profesor: agenda, marcar, cobrar, alumnos y paquetes.
 */
class TeacherController extends Controller
{
    /** Máximo de días de la agenda en una consulta. */
    public const MAX_RANGE_DAYS = 31;

    private const BOOKING_RELATIONS = ['student', 'teacher', 'charge.allocations.payment', 'classPack.charge.allocations.payment', 'organization'];

    public function bookings(Request $request, CurrentOrganization $current): AnonymousResourceCollection
    {
        $profile = $this->profile($request, $current);
        $organization = $current->get();
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')) : $organization->today();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')) : $from;
        $to = $to->min($from->addDays(self::MAX_RANGE_DAYS - 1));

        $bookings = Booking::query()
            ->where('user_id', $profile->user_id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Attended, BookingStatus::Absent])
            ->with(self::BOOKING_RELATIONS)
            ->orderBy('date')
            ->orderBy('starts_at')
            ->get();

        return BookingResource::collection($bookings->map(fn (Booking $booking) => (new BookingResource($booking))->forTeacher()));
    }

    public function attendance(Request $request, int $booking, CurrentOrganization $current, MarkBooking $mark): BookingResource
    {
        $profile = $this->profile($request, $current);
        $data = $request->validate(['attended' => ['required', 'boolean']]);

        $booking = $this->booking($profile, $booking);
        $mark->handle($booking, (bool) $data['attended'], $request->user());

        return (new BookingResource($booking->fresh(self::BOOKING_RELATIONS)))->forTeacher();
    }

    public function cancel(Request $request, int $booking, CurrentOrganization $current, CancelBooking $cancel): BookingResource
    {
        $profile = $this->profile($request, $current);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $booking = $this->booking($profile, $booking);
        $cancel->handle($booking, $request->user(), byTeacher: true, reason: $data['reason'] ?? null);

        return (new BookingResource($booking->fresh(self::BOOKING_RELATIONS)))->forTeacher();
    }

    public function collect(Request $request, CurrentOrganization $current, CollectLessonPayment $collect): JsonResponse
    {
        $profile = $this->profile($request, $current);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::Transfer->value])],
            'booking_id' => ['nullable', 'integer'],
            'class_pack_id' => ['nullable', 'integer'],
        ], ['amount.min' => 'Poné un monto mayor a 0.']);

        $student = $this->student($profile, $data['student_id']);
        $booking = isset($data['booking_id']) ? $this->booking($profile, $data['booking_id']) : null;
        $pack = isset($data['class_pack_id']) ? ClassPack::query()->where('user_id', $profile->user_id)->find($data['class_pack_id']) : null;

        if (($booking !== null && $booking->student_id !== $student->id) || (isset($data['class_pack_id']) && ($pack === null || $pack->student_id !== $student->id))) {
            throw ValidationException::withMessages(['student_id' => 'La reserva o el paquete no es de este alumno.']);
        }

        $payment = $collect->handle($profile, $student, (int) $data['amount'], PaymentMethod::from($data['method']), $request->user(), $booking, $pack);
        $applied = (int) $payment->allocations->sum('amount');
        $credit = $payment->credit();

        return response()->json(['data' => [
            'receipt_number' => $payment->receiptLabel(),
            'amount' => $payment->amount,
            'applied' => $applied,
            'credit' => $credit,
            'message' => 'Cobrado '.Money::pyg($payment->amount)->format().'.'
                .($credit > 0 ? ' Quedan '.Money::pyg($credit)->format().' a favor.' : ''),
            'booking' => $booking === null ? null : (new BookingResource($booking->fresh(self::BOOKING_RELATIONS)))->forTeacher(),
            'pack' => $pack === null ? null : new ClassPackResource($pack->fresh('charge.allocations.payment')),
        ]], 201);
    }

    public function students(Request $request, CurrentOrganization $current): JsonResponse
    {
        $profile = $this->profile($request, $current);
        $organization = $current->get();
        $concept = FeeConcept::privateLesson($organization);

        $ids = Booking::query()->where('user_id', $profile->user_id)->pluck('student_id')
            ->merge(ClassPack::query()->where('user_id', $profile->user_id)->pluck('student_id'))
            ->unique();

        $students = Student::query()->whereIn('id', $ids)->orderBy('first_name')->orderBy('last_name')->get();

        return response()->json(['data' => $students->map(function (Student $student) use ($profile, $organization, $concept) {
            $pack = LessonController::currentPack($student, $profile->user_id, $organization->today());
            $debt = Charge::query()
                ->where('student_id', $student->id)
                ->where('fee_concept_id', $concept->id)
                ->whereNull('voided_at')
                ->where(fn ($query) => $query
                    ->whereHas('bookings', fn ($bookings) => $bookings->where('user_id', $profile->user_id))
                    ->orWhereHas('classPacks', fn ($packs) => $packs->where('user_id', $profile->user_id)))
                ->with('allocations.payment')
                ->get()
                ->sum(fn (Charge $charge) => $charge->pendingAmount());

            return [
                'student' => ['id' => $student->id, 'first_name' => $student->first_name, 'full_name' => $student->full_name],
                'pack' => $pack === null ? null : new ClassPackResource($pack),
                'debt' => (int) $debt,
                'credit' => $student->family_id === null ? 0 : (Family::query()->find($student->family_id)?->credit() ?? 0),
                'last_booking' => Booking::query()->where('user_id', $profile->user_id)->where('student_id', $student->id)
                    ->whereNotIn('status', [BookingStatus::CancelledByStudent, BookingStatus::CancelledByTeacher])
                    ->max('date'),
            ];
        })->values()]);
    }

    public function sellPack(Request $request, int $student, CurrentOrganization $current, BuyPack $buy): JsonResponse
    {
        $profile = $this->profile($request, $current);
        $data = $request->validate(['lesson_pack_id' => ['required', 'integer']]);

        $student = $this->student($profile, $student);
        $offer = LessonPack::query()->where('user_id', $profile->user_id)->where('is_active', true)->find($data['lesson_pack_id']);
        abort_if($offer === null, 404, 'No encontramos ese paquete.');

        $pack = $buy->handle($offer, $student, $request->user());

        return (new ClassPackResource($pack->load('charge.allocations.payment')))->response()->setStatusCode(201);
    }

    public function extend(Request $request, int $pack, CurrentOrganization $current, ExtendClassPack $extend): ClassPackResource
    {
        $profile = $this->profile($request, $current);
        $data = $request->validate(['expires_on' => ['required', 'date_format:Y-m-d']]);

        $pack = ClassPack::query()->where('user_id', $profile->user_id)->find($pack);
        abort_if($pack === null, 404, 'No encontramos ese paquete.');

        $extend->handle($pack, CarbonImmutable::parse($data['expires_on'], $current->get()->timezone)->startOfDay());

        return new ClassPackResource($pack->fresh('charge.allocations.payment'));
    }

    /**
     * El perfil del profesor (aunque haya dejado de recibir reservas, sigue con su agenda).
     */
    private function profile(Request $request, CurrentOrganization $current): LessonProfile
    {
        $organization = $current->get();
        LessonAccess::ensureEnabled($organization);

        $profile = LessonProfile::for($request->user(), $organization);
        abort_unless($profile->exists, 403, 'No tenés clases particulares.');

        return $profile;
    }

    private function booking(LessonProfile $profile, int $id): Booking
    {
        $booking = Booking::query()->where('user_id', $profile->user_id)->with(self::BOOKING_RELATIONS)->find($id);
        abort_if($booking === null, 404, 'No encontramos esta reserva.');

        return $booking;
    }

    /**
     * Solo alumnos con reservas o paquetes con el profesor.
     */
    private function student(LessonProfile $profile, int $id): Student
    {
        $student = Student::query()->find($id);

        if ($student === null || ! LessonAccess::isStudentOf($profile->user, $student)) {
            throw ValidationException::withMessages(['student_id' => 'Solo podés cobrar o vender a tus alumnos.']);
        }

        return $student;
    }
}
