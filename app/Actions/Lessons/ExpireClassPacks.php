<?php

namespace App\Actions\Lessons;

use App\Enums\BookingStatus;
use App\Enums\ClassPackStatus;
use App\Models\ClassPack;
use App\Models\Organization;
use App\Notifications\PackExpiring;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Notification;

/**
 * Corre una vez por día (packs:expire): vence los paquetes activos cuyo `expires_on` ya pasó
 * (las clases sin usar se pierden; el cargo no se toca) y avisa 7 días y 1 día antes a los que
 * todavía tienen clases sin reservar. Idempotente por `expiry_notified`.
 */
class ExpireClassPacks
{
    /** Días antes del vencimiento en que se avisa, del primero al último. */
    public const WARN_DAYS = [7, 1];

    public function __construct(private CurrentOrganization $current) {}

    /**
     * @return array{expired: int, warned: int}
     */
    public function handle(Organization $organization): array
    {
        return $this->current->run($organization, function (Organization $organization) {
            $today = $organization->today();
            $expired = 0;
            $warned = 0;

            $packs = ClassPack::query()
                ->where('status', ClassPackStatus::Active)
                ->whereNotNull('expires_on')
                ->with(['student.guardians', 'teacher'])
                ->get();

            foreach ($packs as $pack) {
                // Las reservas confirmadas de días que ya pasaron cuentan: el profesor todavía las marca.
                if ($pack->expires_on->lt($today)) {
                    $pack->update(['status' => ClassPackStatus::Expired]);
                    $expired++;

                    continue;
                }

                $daysLeft = (int) $today->diffInDays($pack->expires_on);
                $due = collect(self::WARN_DAYS)->filter(fn (int $days) => $daysLeft <= $days)->min();

                if ($due === null || ($pack->expiry_notified !== null && $pack->expiry_notified <= $due)) {
                    continue;
                }

                $unreserved = $pack->remaining() - $pack->bookings()->where('status', BookingStatus::Confirmed)->count();
                $pack->update(['expiry_notified' => $due]);

                if ($unreserved > 0) {
                    Notification::send(LessonAccess::recipients($pack->student), new PackExpiring($pack, $unreserved));
                    $warned++;
                }
            }

            return ['expired' => $expired, 'warned' => $warned];
        });
    }
}
