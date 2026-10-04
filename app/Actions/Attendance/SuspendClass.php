<?php

namespace App\Actions\Attendance;

use App\Actions\Billing\WaiveSuspendedClass;
use App\Enums\ClassStatus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Notifications\ClassSuspended;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Suspende una clase (lluvia, cancha ocupada…) y avisa por push a los tutores
 * del grupo, sin importar si pidieron el aviso de los días de clase.
 */
class SuspendClass
{
    public function __construct(private WaiveSuspendedClass $waiver) {}

    /**
     * @param  bool  $waiveCharge  "No cobrar esta clase" (solo si la temporada cobra por día de entrenamiento)
     */
    public function handle(ClassSession $session, string $reason, ?User $user, bool $waiveCharge = false): void
    {
        $waive = $waiveCharge && $session->canWaiveCharge();

        $session->update([
            'status' => ClassStatus::Suspended,
            'suspension_reason' => trim($reason),
            'suspended_by' => $user?->id,
            'charge_waived' => $waive,
        ]);

        if ($waive) {
            $this->waiver->apply($session, $user);
        }

        $recipients = self::usersInChargeOf($session->students())
            ->reject(fn (User $recipient) => $recipient->is($user));

        Notification::send($recipients, new ClassSuspended($session));
    }

    public function resume(ClassSession $session, ?User $user = null): void
    {
        $waived = $session->charge_waived;

        $session->update(['status' => ClassStatus::Scheduled, 'suspension_reason' => null, 'suspended_by' => null, 'charge_waived' => false]);

        // Con la clase ya programada, las cuotas reemitidas vuelven a contar ese día.
        if ($waived) {
            $this->waiver->undo($session, $user);
        }
    }

    /**
     * Usuarios a cargo de los alumnos: tutores con cuenta y alumnos adultos.
     *
     * @param  Collection<int, Student>  $students
     * @return Collection<int, User>
     */
    public static function usersInChargeOf(Collection $students): Collection
    {
        $ids = $students->pluck('id');

        return User::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('id', Student::query()->whereIn('id', $ids)->whereNotNull('user_id')->select('user_id'))
                ->orWhereHas('guardians', fn (Builder $guardians) => $guardians
                    ->whereHas('students', fn (Builder $students) => $students->whereIn('students.id', $ids))))
            ->get();
    }
}
