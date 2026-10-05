<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollments\EnrollmentRequestAccess;
use App\Actions\Invitations\CreateInvitation;
use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentStatus;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\MidPeriod;
use App\Exceptions\ImportRowException;
use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Invitation;
use App\Models\Student;
use App\Support\Phone;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Cargar alumno" desde la app (quien puede crear alumnos, ej. el admin): alta directa con `RegisterStudent`,
 * igual que "Nuevo jugador" del panel, y la invitación del tutor para mandar por WhatsApp (link `wa.me`).
 */
class StudentRegistrationController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, RegisterStudent $register, CreateInvitation $invitations): JsonResponse
    {
        abort_unless($request->user()->can('create', Student::class), 403, 'No tenés permiso para cargar alumnos.');

        $organization = $current->get();
        $today = $organization->today();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before:'.$today->toDateString(), 'after:'.$today->subYears(100)->toDateString()],
            'document' => ['required', 'string', 'max:20', 'regex:/^[\w.\-]+$/u'],
            'season_id' => ['required', 'integer'],
            'group_id' => ['required', 'integer'],
            'mid_period' => ['nullable', Rule::enum(MidPeriod::class)],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'guardian.first_name' => ['required', 'string', 'max:100'],
            'guardian.last_name' => ['nullable', 'string', 'max:100'],
            'guardian.phone' => ['required', 'string', 'max:30'],
            'guardian.email' => ['nullable', 'email', 'max:255'],
            'guardian.relationship' => ['nullable', Rule::enum(GuardianRelationship::class)],
        ], [
            'first_name.required' => 'Ingresá el nombre.',
            'last_name.required' => 'Ingresá el apellido.',
            'birth_date.required' => 'Ingresá la fecha de nacimiento.',
            'birth_date.before' => 'La fecha de nacimiento tiene que ser pasada.',
            'document.required' => 'Ingresá el número de documento.',
            'document.regex' => 'Ingresá el número de documento, sin espacios.',
            'guardian.first_name.required' => 'Ingresá el nombre '.Vocabulary::of($organization->term('guardian')).'.',
            'guardian.phone.required' => 'Ingresá el celular '.Vocabulary::of($organization->term('guardian')).'.',
            'guardian.email.email' => 'El correo '.Vocabulary::of($organization->term('guardian')).' no es válido.',
        ]);

        if (Phone::mobile($data['guardian']['phone']) === null) {
            throw ValidationException::withMessages(['guardian.phone' => 'Ingresá un celular válido.']);
        }

        [$season, $group] = EnrollmentRequestAccess::place((int) $data['season_id'], (int) $data['group_id']);

        try {
            $student = $register->handle(
                $organization,
                [
                    'first_name' => trim($data['first_name']),
                    'last_name' => trim($data['last_name']),
                    'document' => trim($data['document']),
                    'birth_date' => CarbonImmutable::parse($data['birth_date']),
                    'gender' => $data['gender'] ?? null,
                ],
                $group,
                $season,
                EnrollmentStatus::Active,
                [[...$data['guardian'], 'invite' => false]],
                $request->user(),
                mustBeNew: true,
                midPeriod: isset($data['mid_period']) ? MidPeriod::from($data['mid_period']) : null,
            );
        } catch (ImportRowException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }

        $guardian = $current->run($organization, fn () => Guardian::query()
            ->where('phone', Phone::normalize($data['guardian']['phone']))
            ->whereHas('students', fn ($students) => $students->whereKey($student->id))
            ->first());

        // El tutor que todavía no usa la app recibe la invitación (por correo si tiene; por WhatsApp la manda quien carga).
        $invitation = null;
        if ($guardian !== null && ! $guardian->hasAccount()) {
            [$created, $token] = $invitations->forGuardian($guardian, $request->user());
            $invitation = [
                'link' => Invitation::urlFor($token),
                'whatsapp_url' => $created->whatsappUrl($token),
                'expires_on' => $created->expires_at->setTimezone($organization->timezone)->toDateString(),
            ];
        }

        return response()->json(['data' => [
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'place' => "{$group->name} · {$group->program->name} ({$season->name})",
            ],
            'guardian' => $guardian === null ? null : [
                'name' => $guardian->full_name,
                'has_account' => $guardian->hasAccount(),
            ],
            'invitation' => $invitation,
        ]], Response::HTTP_CREATED);
    }
}
