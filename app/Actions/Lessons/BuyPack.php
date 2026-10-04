<?php

namespace App\Actions\Lessons;

use App\Actions\Billing\ApplyCredit;
use App\Actions\Billing\IssueCharge;
use App\Enums\ClassPackStatus;
use App\Models\ClassPack;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\LessonPack;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compra (o venta del profesor) de un paquete: emite su cargo y queda pendiente de pago;
 * se activa cuando el cargo queda pagado (también con el saldo a favor que ya tenía).
 */
class BuyPack
{
    public function __construct(
        private IssueCharge $issue,
        private ApplyCredit $applyCredit,
    ) {}

    public function handle(LessonPack $offer, Student $student, User $by): ClassPack
    {
        if (! $offer->is_active) {
            throw ValidationException::withMessages(['pack' => 'Este paquete ya no se ofrece.']);
        }

        $pending = ClassPack::query()
            ->where('student_id', $student->id)
            ->where('user_id', $offer->user_id)
            ->where('status', ClassPackStatus::PendingPayment)
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages(['pack' => 'Ya tiene un paquete pendiente de pago con este profesor.']);
        }

        $family = Family::ensureFor($student);
        $organization = $offer->organization;
        $teacher = $offer->user;

        $pack = DB::transaction(function () use ($offer, $student, $by, $organization, $teacher) {
            $pack = ClassPack::query()->create([
                'organization_id' => $organization->id,
                'student_id' => $student->id,
                'user_id' => $offer->user_id,
                'lesson_pack_id' => $offer->id,
                'classes' => $offer->classes,
                'price' => $offer->price,
                'valid_days' => $offer->valid_days,
                'status' => ClassPackStatus::PendingPayment,
                'created_by' => $by->id,
            ]);

            $charge = $this->issue->handle([
                'organization_id' => $organization->id,
                'student_id' => $student->id,
                'fee_concept_id' => FeeConcept::privateLesson($organization)->id,
                'description' => $pack->label().($teacher ? " con {$teacher->name}" : ''),
                'base_amount' => $offer->price,
                'quantity' => $offer->classes,
                'unit_amount' => intdiv($offer->price, max(1, $offer->classes)),
                'issued_on' => $organization->today()->toDateString(),
                'due_on' => $organization->today()->toDateString(),
                'unique_key' => "pack:{$pack->id}",
                'created_by' => $by->id,
            ]);

            $pack->update(['charge_id' => $charge->id]);

            return $pack;
        });

        $this->applyCredit->forFamily($family);

        return $pack->refresh();
    }
}
