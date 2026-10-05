<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AgendaController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CashBoxController;
use App\Http\Controllers\Api\V1\CashDepositController;
use App\Http\Controllers\Api\V1\ClassController;
use App\Http\Controllers\Api\V1\ClassResponseController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CurrentOrganizationController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\EnrollmentRequestController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\Lessons\BookingController;
use App\Http\Controllers\Api\V1\Lessons\LessonController;
use App\Http\Controllers\Api\V1\Lessons\LessonProfileController;
use App\Http\Controllers\Api\V1\Lessons\TeacherController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationSettingsController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PaymentReportController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\Setup\GroupController as SetupGroupController;
use App\Http\Controllers\Api\V1\Setup\InstructorController as SetupInstructorController;
use App\Http\Controllers\Api\V1\Setup\ProgramController as SetupProgramController;
use App\Http\Controllers\Api\V1\Setup\SeasonController as SetupSeasonController;
use App\Http\Controllers\Api\V1\Setup\SiteController as SetupSiteController;
use App\Http\Controllers\Api\V1\StudentAttendanceController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\StudentRegistrationController;
use App\Http\Controllers\Api\V1\TerminologyController;
use App\Http\Controllers\Api\V1\VenueController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use App\Support\Onboarding\StepDrafts;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/token', [AuthTokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.token.store');

    // Registro abierto: cuenta sin organizaciones, con el celular o el correo.
    Route::post('auth/register', [RegisterController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('auth.register');

    // "Olvidé mi contraseña" con código por WhatsApp o correo.
    Route::post('auth/password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:5,1')
        ->name('auth.password.forgot');
    Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1')
        ->name('auth.password.reset');

    // Límite por invitación y un tope por IP más alto (AppServiceProvider): varias familias en el mismo wifi.
    Route::middleware('throttle:invitations')->group(function () {
        Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
    });

    // Botones del push "¿Lo llevás?" (link firmado, sin token).
    Route::post('class-responses/{class}/{user}', ClassResponseController::class)
        ->whereNumber(['class', 'user'])
        ->middleware(['signed', 'throttle:30,1'])
        ->name('class-responses');

    Route::middleware('auth:sanctum')->group(function () {
        Route::delete('auth/token', [AuthTokenController::class, 'destroy'])->name('auth.token.destroy');
        Route::get('me', [MeController::class, 'show'])->name('me');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');

        // Código de la cuenta (WhatsApp o correo) y alta del club (sin organización activa).
        Route::post('auth/verify', [RegisterController::class, 'verify'])->middleware('throttle:10,1')->name('auth.verify');
        Route::post('auth/verify/resend', [RegisterController::class, 'resend'])->middleware('throttle:3,1')->name('auth.verify.resend');
        Route::get('onboarding/templates', [OnboardingController::class, 'templates'])->name('onboarding.templates');
        Route::get('organizations/slug', [OrganizationController::class, 'slug'])->middleware('throttle:60,1')->name('organizations.slug');
        Route::post('organizations', [OrganizationController::class, 'store'])->middleware('throttle:5,1')->name('organizations.store');

        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::delete('devices/{token}', [DeviceController::class, 'destroy'])->where('token', '.+')->name('devices.destroy');

        Route::middleware('organization')->group(function () {
            Route::get('organization', CurrentOrganizationController::class)->name('organization.show');

            Route::get('students', [StudentController::class, 'index'])->name('students.index');
            Route::get('students/{student}', [StudentController::class, 'show'])->whereNumber('student')->name('students.show');

            Route::get('account', [AccountController::class, 'index'])->name('account.index');
            Route::get('students/{student}/account', [AccountController::class, 'show'])->whereNumber('student')->name('students.account');

            // Comprobantes de transferencia: el tutor los informa, quien valida los aprueba o rechaza.
            Route::post('payment-reports', [PaymentReportController::class, 'store'])->middleware('throttle:10,1')->name('payment-reports.store');
            Route::delete('payment-reports/{report}', [PaymentReportController::class, 'destroy'])->whereNumber('report')->name('payment-reports.destroy');
            Route::get('payment-reports', [PaymentReportController::class, 'index'])->name('payment-reports.index');
            Route::post('payment-reports/{report}/approve', [PaymentReportController::class, 'approve'])->whereNumber('report')->name('payment-reports.approve');
            Route::post('payment-reports/{report}/reject', [PaymentReportController::class, 'reject'])->whereNumber('report')->name('payment-reports.reject');

            // Bajas y condonación (docs/PLAN_BAJAS.md).
            Route::get('dropout-reports', [WithdrawalController::class, 'dropoutReports'])->name('dropout-reports.index');
            Route::get('staff/students/{student}', [WithdrawalController::class, 'student'])->whereNumber('student')->name('staff.students.show');
            Route::post('enrollments/{enrollment}/withdraw', [WithdrawalController::class, 'withdraw'])->whereNumber('enrollment')->name('enrollments.withdraw');
            Route::delete('enrollments/{enrollment}/dropout', [WithdrawalController::class, 'dismissDropout'])->whereNumber('enrollment')->name('enrollments.dropout.dismiss');
            Route::post('charges/waive', [WithdrawalController::class, 'waive'])->name('charges.waive');
            Route::post('charges/{charge}/unwaive', [WithdrawalController::class, 'unwaive'])->whereNumber('charge')->name('charges.unwaive');
            Route::post('students/{student}/leaving', [WithdrawalController::class, 'leaving'])->whereNumber('student')->name('students.leaving');
            Route::delete('students/{student}/leaving', [WithdrawalController::class, 'cancelLeaving'])->whereNumber('student')->name('students.leaving.cancel');
            // Inscripción desde la app: el tutor la pide (entra ya, pendiente) y quien tiene permiso la confirma o rechaza.
            // "Cargar alumno" (quien puede crear alumnos): alta directa e invitación del tutor.
            Route::post('students', StudentRegistrationController::class)->middleware('throttle:30,1')->name('students.store');
            Route::get('enrollment-requests/options', [EnrollmentRequestController::class, 'options'])->name('enrollment-requests.options');
            Route::post('enrollment-requests', [EnrollmentRequestController::class, 'store'])->middleware('throttle:10,1')->name('enrollment-requests.store');
            Route::get('enrollment-requests', [EnrollmentRequestController::class, 'index'])->name('enrollment-requests.index');
            Route::delete('enrollment-requests/{id}', [EnrollmentRequestController::class, 'destroy'])->whereNumber('id')->name('enrollment-requests.destroy');
            Route::get('enrollment-requests/review', [EnrollmentRequestController::class, 'review'])->name('enrollment-requests.review');
            Route::post('enrollment-requests/{id}/approve', [EnrollmentRequestController::class, 'approve'])->whereNumber('id')->name('enrollment-requests.approve');
            Route::post('enrollment-requests/{id}/reject', [EnrollmentRequestController::class, 'reject'])->whereNumber('id')->name('enrollment-requests.reject');
            // Cobro en efectivo desde la app: entra en la caja de quien cobra hasta que la deposita.
            Route::get('collections/students', [CollectionController::class, 'students'])->name('collections.students');
            Route::get('collections/students/{student}', [CollectionController::class, 'show'])->whereNumber('student')->name('collections.show');
            Route::post('collections', [CollectionController::class, 'store'])->middleware('throttle:30,1')->name('collections.store');
            Route::post('collections/transfers', [CollectionController::class, 'transfer'])->middleware('throttle:20,1')->name('collections.transfers');
            Route::get('me/cash-box', [CashBoxController::class, 'show'])->name('cash-box.show');
            Route::post('me/cash-box/deposits', [CashBoxController::class, 'deposit'])->middleware('throttle:10,1')->name('cash-box.deposits.store');
            Route::delete('me/cash-box/deposits/{deposit}', [CashBoxController::class, 'withdraw'])->whereNumber('deposit')->name('cash-box.deposits.destroy');
            Route::get('cash-boxes', [CashDepositController::class, 'index'])->name('cash-boxes.index');
            Route::post('cash-deposits/{deposit}/confirm', [CashDepositController::class, 'confirm'])->whereNumber('deposit')->name('cash-deposits.confirm');
            Route::post('cash-deposits/{deposit}/reject', [CashDepositController::class, 'reject'])->whereNumber('deposit')->name('cash-deposits.reject');

            Route::get('reports/balance', [ReportController::class, 'balance'])->name('reports.balance');
            Route::get('reports/balances', [ReportController::class, 'balances'])->name('reports.balances');
            // Asistencia (técnico).
            Route::get('classes', [ClassController::class, 'index'])->name('classes.index');
            Route::get('classes/{class}', [ClassController::class, 'show'])->whereNumber('class')->name('classes.show');
            Route::put('classes/{class}/attendance', [ClassController::class, 'attendance'])->whereNumber('class')->name('classes.attendance');
            Route::post('classes/{class}/suspension', [ClassController::class, 'suspend'])->whereNumber('class')->name('classes.suspend');
            Route::delete('classes/{class}/suspension', [ClassController::class, 'resume'])->whereNumber('class')->name('classes.resume');
            Route::post('classes/{class}/reschedule', [ClassController::class, 'reschedule'])->whereNumber('class')->name('classes.reschedule');
            Route::delete('classes/{class}/reschedule', [ClassController::class, 'cancelReschedule'])->whereNumber('class')->name('classes.reschedule.cancel');
            Route::get('venues', [VenueController::class, 'index'])->name('venues.index');
            Route::get('me/notification-settings', [NotificationSettingsController::class, 'show'])->name('notification-settings.show');
            Route::put('me/notification-settings', [NotificationSettingsController::class, 'update'])->name('notification-settings.update');
            Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
            Route::get('groups/{group}', [GroupController::class, 'show'])->whereNumber('group')->name('groups.show');
            // "Dejó de venir": el técnico avisa; la baja la decide el club en el panel.
            Route::post('groups/{group}/students/{student}/dropout', [GroupController::class, 'reportDropout'])
                ->whereNumber(['group', 'student'])->name('groups.dropout');
            Route::delete('groups/{group}/students/{student}/dropout', [GroupController::class, 'cancelDropout'])
                ->whereNumber(['group', 'student'])->name('groups.dropout.cancel');

            // Próxima clase y asistencia (tutor).
            Route::get('agenda', [AgendaController::class, 'index'])->name('agenda');
            Route::put('classes/{class}/students/{student}/response', [AgendaController::class, 'respond'])
                ->whereNumber(['class', 'student'])->name('classes.response');
            Route::get('students/{student}/attendance', [StudentAttendanceController::class, 'show'])->whereNumber('student')->name('students.attendance');
            Route::put('students/{student}/reminders', [StudentAttendanceController::class, 'reminders'])->whereNumber('student')->name('students.reminders');

            Route::get('reports/delinquents', [ReportController::class, 'delinquents'])->name('reports.delinquents');

            // Clases particulares (alumno adulto o tutor).
            Route::get('lessons/teachers', [LessonController::class, 'teachers'])->name('lessons.teachers');
            Route::get('lessons/teachers/{teacher}/slots', [LessonController::class, 'slots'])->whereNumber('teacher')->name('lessons.slots');
            Route::post('lessons/packs/{pack}/buy', [LessonController::class, 'buy'])->whereNumber('pack')->name('lessons.packs.buy');
            Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
            Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:30,1')->name('bookings.store');
            Route::delete('bookings/{booking}', [BookingController::class, 'destroy'])->whereNumber('booking')->name('bookings.destroy');

            // Clases particulares (profesor).
            Route::get('me/lesson-profile', [LessonProfileController::class, 'show'])->name('lesson-profile.show');
            Route::put('me/lesson-profile', [LessonProfileController::class, 'update'])->name('lesson-profile.update');
            Route::get('teacher/bookings', [TeacherController::class, 'bookings'])->name('teacher.bookings');
            Route::put('teacher/bookings/{booking}/attendance', [TeacherController::class, 'attendance'])->whereNumber('booking')->name('teacher.bookings.attendance');
            Route::delete('teacher/bookings/{booking}', [TeacherController::class, 'cancel'])->whereNumber('booking')->name('teacher.bookings.cancel');
            Route::post('teacher/payments', [TeacherController::class, 'collect'])->name('teacher.payments');
            Route::get('teacher/students', [TeacherController::class, 'students'])->name('teacher.students');
            Route::post('teacher/students/{student}/packs', [TeacherController::class, 'sellPack'])->whereNumber('student')->name('teacher.students.packs');
            Route::post('teacher/packs/{pack}/extend', [TeacherController::class, 'extend'])->whereNumber('pack')->name('teacher.packs.extend');

            // Guía "Primeros pasos" (administrador).
            Route::middleware('configure')->group(function () {
                Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
                Route::put('onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
                Route::put('onboarding/steps/{key}', [OnboardingController::class, 'skip'])->name('onboarding.skip');
                // Borrador del paso ("Se guarda solo"): por ahora, categorías y horarios.
                Route::get('onboarding/steps/{key}/draft', [OnboardingController::class, 'draft'])->whereIn('key', StepDrafts::KEYS)->name('onboarding.draft');
                Route::put('onboarding/steps/{key}/draft', [OnboardingController::class, 'saveDraft'])->whereIn('key', StepDrafts::KEYS)->middleware('throttle:120,1')->name('onboarding.draft.save');
                Route::delete('onboarding/steps/{key}/draft', [OnboardingController::class, 'forgetDraft'])->whereIn('key', StepDrafts::KEYS)->name('onboarding.draft.forget');
                Route::put('organization/terminology', [TerminologyController::class, 'update'])->name('organization.terminology');

                Route::prefix('setup')->name('setup.')->group(function () {
                    Route::get('programs', [SetupProgramController::class, 'index'])->name('programs.index');
                    Route::post('programs', [SetupProgramController::class, 'store'])->name('programs.store');
                    Route::put('programs/{program}', [SetupProgramController::class, 'update'])->whereNumber('program')->name('programs.update');
                    Route::delete('programs/{program}', [SetupProgramController::class, 'destroy'])->whereNumber('program')->name('programs.destroy');

                    Route::get('groups', [SetupGroupController::class, 'index'])->name('groups.index');
                    Route::post('groups/suggestions', [SetupGroupController::class, 'suggestions'])->name('groups.suggestions');
                    Route::post('groups', [SetupGroupController::class, 'store'])->name('groups.store');
                    Route::put('groups/{group}', [SetupGroupController::class, 'update'])->whereNumber('group')->name('groups.update');
                    Route::delete('groups/{group}', [SetupGroupController::class, 'destroy'])->whereNumber('group')->name('groups.destroy');
                    Route::post('venues', [SetupGroupController::class, 'storeVenue'])->name('venues.store');
                    Route::post('schedules/conflicts', [SetupGroupController::class, 'conflicts'])->name('schedules.conflicts');
                    Route::get('sites', [SetupSiteController::class, 'index'])->name('sites.index');
                    Route::post('sites', [SetupSiteController::class, 'store'])->name('sites.store');
                    Route::post('sites/{site}/spaces', [SetupSiteController::class, 'addSpace'])->whereNumber('site')->name('sites.spaces');

                    Route::get('seasons', [SetupSeasonController::class, 'index'])->name('seasons.index');
                    Route::get('seasons/new', [SetupSeasonController::class, 'create'])->name('seasons.create');
                    Route::post('seasons/preview', [SetupSeasonController::class, 'preview'])->name('seasons.preview');
                    Route::post('seasons', [SetupSeasonController::class, 'store'])->name('seasons.store');

                    Route::get('instructors', [SetupInstructorController::class, 'index'])->name('instructors.index');
                    Route::post('instructors', [SetupInstructorController::class, 'store'])->middleware('throttle:30,1')->name('instructors.store');
                    Route::put('instructors/me', [SetupInstructorController::class, 'me'])->name('instructors.me');
                    Route::put('instructors/{user}', [SetupInstructorController::class, 'update'])->whereNumber('user')->name('instructors.update');
                    Route::post('invitations/{invitation}/resend', [SetupInstructorController::class, 'resend'])->whereNumber('invitation')->name('invitations.resend');
                    Route::delete('invitations/{invitation}', [SetupInstructorController::class, 'revoke'])->whereNumber('invitation')->name('invitations.revoke');
                });
            });
        });
    });
});
