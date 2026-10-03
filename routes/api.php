<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AgendaController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\ClassController;
use App\Http\Controllers\Api\V1\ClassResponseController;
use App\Http\Controllers\Api\V1\CurrentOrganizationController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\Lessons\BookingController;
use App\Http\Controllers\Api\V1\Lessons\LessonController;
use App\Http\Controllers\Api\V1\Lessons\LessonProfileController;
use App\Http\Controllers\Api\V1\Lessons\TeacherController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationSettingsController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\StudentAttendanceController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\VenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/token', [AuthTokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.token.store');

    Route::middleware('throttle:10,1')->group(function () {
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
        Route::get('me', MeController::class)->name('me');

        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::delete('devices/{token}', [DeviceController::class, 'destroy'])->where('token', '.+')->name('devices.destroy');

        Route::middleware('organization')->group(function () {
            Route::get('organization', CurrentOrganizationController::class)->name('organization.show');

            Route::get('students', [StudentController::class, 'index'])->name('students.index');
            Route::get('students/{student}', [StudentController::class, 'show'])->whereNumber('student')->name('students.show');

            Route::get('account', [AccountController::class, 'index'])->name('account.index');
            Route::get('students/{student}/account', [AccountController::class, 'show'])->whereNumber('student')->name('students.account');

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
        });
    });
});
