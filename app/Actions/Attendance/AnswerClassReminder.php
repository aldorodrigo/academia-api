<?php

namespace App\Actions\Attendance;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Notifications\ClassReminder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;

/**
 * "Sí, va" / "No va" desde un link firmado del aviso de clase (botón del push o página del correo): la clase,
 * el usuario y los alumnos vienen en el link, sin sesión.
 */
class AnswerClassReminder
{
    public function __construct(private CurrentOrganization $current, private RespondToClass $respond) {}

    /**
     * Clase, usuario y alumnos del link (404 si no existen o ya no están a cargo del usuario).
     *
     * @return array{0: ClassSession, 1: User, 2: Collection<int, Student>}
     */
    public function resolve(int $class, int $user, string $students): array
    {
        $session = ClassSession::query()->withoutGlobalScopes()->with('organization')->find($class);
        $user = User::query()->find($user);
        abort_if($session === null || $user === null, 404, 'No encontramos esta clase.');

        $ids = array_filter(array_map('intval', explode(',', $students)));
        $students = $this->current->run($session->organization, fn () => Student::query()->inChargeOf($user)->whereKey($ids)->get());
        abort_if($students->isEmpty(), 404, 'No encontramos a este alumno.');

        return [$session, $user, $students];
    }

    /**
     * Guarda la respuesta y devuelve el mensaje para mostrar ("Listo: avisaste que Mateo va.").
     */
    public function handle(int $class, int $user, string $students, bool $going): string
    {
        [$session, $user, $students] = $this->resolve($class, $user, $students);

        $this->current->run($session->organization, function () use ($session, $user, $students, $going) {
            foreach ($students as $student) {
                $this->respond->handle($session, $student, $going, $user);
            }
        });

        return 'Listo: avisaste que '.ClassReminder::names($students->pluck('first_name')->all()).' '.self::verb($students->count(), $going).'.';
    }

    /**
     * "va", "van", "no va", "no van".
     */
    public static function verb(int $count, bool $going): string
    {
        return ($going ? '' : 'no ').($count > 1 ? 'van' : 'va');
    }
}
