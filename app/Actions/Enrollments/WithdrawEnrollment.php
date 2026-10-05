<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Invitation;
use App\Models\Student;
use App\Models\User;
use App\Notifications\StudentWithdrawn;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dar de baja una inscripción (panel, quien puede editar inscripciones), con fecha y motivo.
 *
 * La deuda queda como histórica: las cuotas impagas, también la del período en curso, siguen
 * pendientes hasta que se pagan, se anulan o se condonan (WaiveCharges). Solo se anulan solas las
 * cuotas futuras sin pagos (VoidFutureCharges, desde el modelo). Si el técnico había avisado que
 * dejó de venir, el aviso se cierra.
 *
 * Quien da la baja elige si le avisa a la familia: el mensaje viene prellenado, amable y con las
 * puertas abiertas, y se puede cambiar. Antes de mandarlo se ve a quién le llega y por dónde
 * (`noticeReach`): a los tutores con cuenta les queda en "Avisos" de la app, y además push si tienen
 * la app instalada y correo si tienen uno para copias; a los que no tienen cuenta, por WhatsApp a mano.
 */
class WithdrawEnrollment
{
    /**
     * @return array{pending_count: int, pending_amount: int, future_count: int} lo que muestra el modal antes de confirmar
     */
    public function preview(Enrollment $enrollment): array
    {
        $today = $enrollment->organization->today()->toDateString();
        $charges = Charge::query()->notVoided()
            ->where('enrollment_id', $enrollment->id)
            ->with(['allocations.payment', 'organization', 'season'])
            ->get();

        $future = $charges->filter(fn (Charge $c) => $c->period_start !== null && $c->period_start->toDateString() > $today && $c->paidAmount() === 0);
        $pending = $charges->diff($future)->filter(fn (Charge $c) => $c->pendingAmount() > 0);

        return [
            'pending_count' => $pending->count(),
            'pending_amount' => (int) $pending->sum(fn (Charge $c) => $c->pendingAmount()),
            'future_count' => $future->count(),
        ];
    }

    /**
     * Mensaje sugerido para la familia.
     */
    public static function defaultNotice(Enrollment $enrollment): string
    {
        $enrollment->loadMissing(['student', 'organization']);

        return "Hola, te contamos que registramos la baja de {$enrollment->student->first_name} en {$enrollment->organization->name}. "
            .'¡Gracias por todo este tiempo compartido! Las puertas siempre van a estar abiertas: '
            .'cuando quieran volver, escribinos y los esperamos con mucho gusto.';
    }

    /**
     * Quienes reciben el aviso: los tutores con la app y el alumno adulto, si tiene cuenta.
     *
     * @return Collection<int, User>
     */
    public static function noticeRecipients(Student $student): Collection
    {
        $student->loadMissing(['guardians.user', 'user']);

        return $student->guardians
            ->map(fn (Guardian $guardian) => $guardian->user)
            ->push($student->user)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * A quién le llega el aviso y por dónde, de verdad: cada tutor (y el alumno adulto con cuenta) con
     * sus canales. `app`: tiene cuenta (le queda en "Avisos"); `push`: tiene la app instalada con
     * notificaciones; `mail`: tiene un correo para copias. Sin cuenta no le llega nada: si tiene
     * celular, `whatsapp_phone` para mandárselo por WhatsApp a mano.
     *
     * @return list<array{name: string, user_id: ?int, channels: list<string>, phone: ?string, whatsapp_phone: ?string}>
     */
    public static function noticeReach(Student $student): array
    {
        $student->loadMissing(['guardians.user.deviceTokens', 'user.deviceTokens']);
        $seen = [];
        $reach = [];

        foreach ($student->guardians as $guardian) {
            $user = $guardian->user;
            if ($user !== null && isset($seen[$user->id])) {
                continue;
            }
            if ($user !== null) {
                $seen[$user->id] = true;
            }

            $mobile = $user === null ? Phone::mobile($guardian->phone) : null;
            $reach[] = [
                'name' => $guardian->full_name ?: (string) $user?->name,
                'user_id' => $user?->id,
                'channels' => $user === null ? [] : self::channelsOf($user),
                'phone' => $user === null ? Phone::display($guardian->phone) : null,
                'whatsapp_phone' => $mobile === null ? null : Phone::digits($mobile),
            ];
        }

        if ($student->user !== null && ! isset($seen[$student->user->id])) {
            $reach[] = [
                'name' => $student->user->name,
                'user_id' => $student->user->id,
                'channels' => self::channelsOf($student->user),
                'phone' => null,
                'whatsapp_phone' => null,
            ];
        }

        return $reach;
    }

    /**
     * Por dónde le llega un aviso a una cuenta (lo mismo que decide `PushNotification::via()`).
     *
     * @return list<string>
     */
    public static function channelsOf(User $user): array
    {
        $tokens = $user->relationLoaded('deviceTokens') ? $user->deviceTokens->isNotEmpty() : $user->deviceTokens()->exists();

        return array_values(array_filter([
            'app',
            $tokens ? 'push' : null,
            $user->mailableEmail() !== null ? 'mail' : null,
        ]));
    }

    /**
     * "A Laura Benítez le llega en la app y por correo." / "Pedro Benítez no tiene la app: …".
     *
     * @param  array{name: string, channels: list<string>, whatsapp_phone: ?string}  $person
     */
    public static function describeReach(array $person): string
    {
        if ($person['channels'] === []) {
            return "{$person['name']} no tiene la app: no le llega. "
                .($person['whatsapp_phone'] !== null ? 'Podés mandárselo por WhatsApp.' : 'Avisale por otro medio.');
        }

        $words = array_map(fn (string $channel) => match ($channel) {
            'app' => 'en la app',
            'push' => 'como notificación en el celular',
            'mail' => 'por correo',
            default => $channel,
        }, $person['channels']);
        $last = array_pop($words);

        return "A {$person['name']} le llega ".($words === [] ? $last : implode(', ', $words)." y {$last}").'.';
    }

    /**
     * Link de WhatsApp con el mensaje ya escrito, para un tutor sin la app.
     */
    public static function whatsappUrl(string $whatsappPhone, string $message): string
    {
        return Invitation::whatsappLink('+'.$whatsappPhone, trim($message));
    }

    /**
     * Avisa a la familia (mensaje ya revisado por quien da la baja). Devuelve a cuántos les llegó.
     * El registro de actividad guarda por dónde le llegó a cada uno.
     */
    public function notify(Enrollment $enrollment, string $message, ?User $by): int
    {
        if (blank(trim($message))) {
            throw ValidationException::withMessages(['message' => 'Escribí el mensaje para la familia.']);
        }

        $recipients = self::noticeRecipients($enrollment->student);
        $reach = collect(self::noticeReach($enrollment->student));
        $notification = new StudentWithdrawn("Baja de {$enrollment->student->first_name}", trim($message));
        $recipients->each(fn (User $user) => $user->notify($notification));

        activity('academic')->performedOn($enrollment)->causedBy($by)
            ->withProperties([
                'student_id' => $enrollment->student_id,
                'recipients' => $recipients->count(),
                'channels' => $reach->map(fn (array $person) => ['name' => $person['name'], 'channels' => $person['channels']])->values()->all(),
                'message' => trim($message),
            ])
            ->log('Aviso de baja a la familia');

        return $recipients->count();
    }

    public function handle(Enrollment $enrollment, CarbonInterface|string $on, string $reason, ?User $by, ?string $notice = null): Enrollment
    {
        if ($enrollment->isWithdrawn()) {
            throw ValidationException::withMessages(['ended_on' => 'La inscripción ya está dada de baja.']);
        }

        if ($enrollment->isFinished()) {
            throw ValidationException::withMessages(['ended_on' => 'La temporada ya terminó: la inscripción quedó finalizada.']);
        }

        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['withdrawal_reason' => 'Indicá el motivo de la baja.']);
        }

        if ($notice !== null && blank(trim($notice))) {
            throw ValidationException::withMessages(['message' => 'Escribí el mensaje para la familia.']);
        }

        $on = CarbonImmutable::parse($on)->toDateString();
        $today = $enrollment->organization->today()->toDateString();

        if ($on > $today) {
            throw ValidationException::withMessages(['ended_on' => 'La fecha de baja no puede ser posterior a hoy.']);
        }

        if ($enrollment->enrolled_on !== null && $on < $enrollment->enrolled_on->toDateString()) {
            throw ValidationException::withMessages(['ended_on' => 'La fecha de baja no puede ser anterior a la inscripción ('.$enrollment->enrolled_on->format('d/m/Y').').']);
        }

        return DB::transaction(function () use ($enrollment, $on, $reason, $by, $notice) {
            $previous = $enrollment->status;

            $enrollment->update([
                'status' => EnrollmentStatus::Withdrawn,
                'ended_on' => $on,
                'withdrawal_reason' => trim($reason),
                'withdrawn_by' => $by?->id,
            ]);

            activity('academic')->performedOn($enrollment)->causedBy($by)
                ->withProperties(['student_id' => $enrollment->student_id, 'from' => $previous->value, 'ended_on' => $on, 'reason' => trim($reason)])
                ->log('Baja de la inscripción');

            if ($notice !== null) {
                $this->notify($enrollment, $notice, $by);
            }

            return $enrollment;
        });
    }
}
