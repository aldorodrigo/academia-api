<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollments\EnrollmentRequestAccess;
use App\Actions\Enrollments\ReviewEnrollmentRequest;
use App\Actions\Enrollments\SubmitEnrollmentRequest;
use App\Enums\EnrollmentRequestStatus;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\MidPeriod;
use App\Filament\Support\Terms;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EnrollmentRequestResource;
use App\Http\Resources\Api\V1\ReviewEnrollmentRequestResource;
use App\Models\EnrollmentRequest;
use App\Models\Group;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inscripción desde la app: el tutor pide lugar para un hijo (o retira el pedido) y quien tiene permiso
 * aprueba o rechaza.
 */
class EnrollmentRequestController extends Controller
{
    private const WITH = ['user', 'season', 'group.program', 'student.medicalRecord', 'student.guardians', 'organization', 'reviewedBy'];

    public function __construct(private CurrentOrganization $current) {}

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate(['birth_date' => ['nullable', 'date']]);

        return response()->json(['data' => EnrollmentRequestAccess::options(
            isset($data['birth_date']) ? CarbonImmutable::parse($data['birth_date']) : null,
        )]);
    }

    public function store(Request $request, SubmitEnrollmentRequest $submit): JsonResponse
    {
        $today = $this->current->get()->today();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before:'.$today->toDateString(), 'after:'.$today->subYears(100)->toDateString()],
            'document' => ['required', 'string', 'max:20', 'regex:/^[\w.\-]+$/u'],
            'relationship' => ['nullable', Rule::enum(GuardianRelationship::class)],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'season_id' => ['required', 'integer'],
            'group_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'medical' => ['nullable', 'array'],
            'medical.*' => ['nullable', 'string', 'max:500'],
        ], [
            'first_name.required' => 'Ingresá el nombre.',
            'last_name.required' => 'Ingresá el apellido.',
            'birth_date.required' => 'Ingresá la fecha de nacimiento.',
            'birth_date.before' => 'La fecha de nacimiento tiene que ser pasada.',
            'birth_date.after' => 'Revisá el año de nacimiento.',
            'document.required' => 'Ingresá el número de documento.',
            'document.regex' => 'Ingresá el número de documento, sin espacios.',
            'season_id.required' => 'Elegí la temporada.',
            'group_id.required' => 'Elegí '.Vocabulary::the($this->current->get()->term('group')).'.',
        ]);

        $enrollmentRequest = $submit->handle($this->current->get(), $request->user(), $data);

        return (new EnrollmentRequestResource($enrollmentRequest->load(self::WITH)))->response()->setStatusCode(201);
    }

    /**
     * Las del usuario: pendientes y las no aprobadas de los últimos 30 días.
     */
    public function index(Request $request): JsonResponse
    {
        $requests = EnrollmentRequest::query()
            ->where('user_id', $request->user()->id)
            ->where(fn ($query) => $query
                ->where('status', EnrollmentRequestStatus::Pending)
                ->orWhere(fn ($query) => $query
                    ->where('status', EnrollmentRequestStatus::Rejected)
                    ->where('reviewed_at', '>=', now()->subDays(30))))
            ->with(self::WITH)
            ->latest()
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => EnrollmentRequestResource::collection($requests)->toArray($request)]);
    }

    public function destroy(Request $request, int $id, ReviewEnrollmentRequest $review): Response
    {
        $enrollmentRequest = EnrollmentRequest::query()->where('user_id', $request->user()->id)->find($id);

        abort_if($enrollmentRequest === null, 404, 'No encontramos esta solicitud.');

        $review->cancel($enrollmentRequest);

        return response()->noContent();
    }

    public function review(Request $request): JsonResponse
    {
        $this->authorizeReview($request);
        $request->validate(['status' => ['nullable', Rule::in(['pendiente', 'todos'])]]);

        $requests = EnrollmentRequestAccess::reviewable($request->user())
            ->when($request->input('status', 'pendiente') === 'pendiente', fn ($query) => $query->pending()->oldest())
            ->when($request->input('status') === 'todos', fn ($query) => $query->latest()->limit(50))
            ->with(self::WITH)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => ReviewEnrollmentRequestResource::collection($requests)->toArray($request)]);
    }

    public function approve(Request $request, int $id, ReviewEnrollmentRequest $review): ReviewEnrollmentRequestResource
    {
        $this->authorizeReview($request);
        $data = $request->validate([
            'group_id' => ['nullable', 'integer'],
            'mid_period' => ['nullable', Rule::enum(MidPeriod::class)],
            'over_capacity' => ['nullable', 'boolean'],
        ]);

        $group = null;
        if (isset($data['group_id'])) {
            $group = Group::query()->find($data['group_id']);

            if ($group === null || ! EnrollmentRequestAccess::canReviewGroup($request->user(), $group)) {
                throw ValidationException::withMessages(['group_id' => 'Elegí '.Terms::gendered('group', 'categoría', 'uno', 'una').' de '.Terms::the('group', 'categoría', plural: true).' que podés confirmar.']);
            }
        }

        $enrollmentRequest = $review->approve(
            $this->find($request, $id),
            $request->user(),
            $group,
            isset($data['mid_period']) ? MidPeriod::from($data['mid_period']) : null,
            (bool) ($data['over_capacity'] ?? false),
        );

        return new ReviewEnrollmentRequestResource($enrollmentRequest->load(self::WITH));
    }

    public function reject(Request $request, int $id, ReviewEnrollmentRequest $review): ReviewEnrollmentRequestResource
    {
        $this->authorizeReview($request);
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Contale a la familia por qué no la aprobás.'],
        );

        $enrollmentRequest = $review->reject($this->find($request, $id), $request->user(), $data['reason']);

        return new ReviewEnrollmentRequestResource($enrollmentRequest->load(self::WITH));
    }

    /**
     * Una que puede confirmar (404 para las de categorías ajenas).
     */
    private function find(Request $request, int $id): EnrollmentRequest
    {
        $enrollmentRequest = EnrollmentRequestAccess::reviewable($request->user())->find($id);

        abort_if($enrollmentRequest === null, 404, 'No encontramos esta solicitud.');

        return $enrollmentRequest;
    }

    private function authorizeReview(Request $request): void
    {
        abort_unless(
            EnrollmentRequestAccess::canReviewAny($request->user()),
            403,
            'No tenés permiso para confirmar inscripciones.',
        );
    }
}
