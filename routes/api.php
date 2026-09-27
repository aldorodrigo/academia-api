<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CurrentOrganizationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\StudentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/token', [AuthTokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.token.store');

    Route::middleware('throttle:10,1')->group(function () {
        Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::delete('auth/token', [AuthTokenController::class, 'destroy'])->name('auth.token.destroy');
        Route::get('me', MeController::class)->name('me');

        Route::middleware('organization')->group(function () {
            Route::get('organization', CurrentOrganizationController::class)->name('organization.show');

            Route::get('students', [StudentController::class, 'index'])->name('students.index');
            Route::get('students/{student}', [StudentController::class, 'show'])->whereNumber('student')->name('students.show');

            Route::get('account', [AccountController::class, 'index'])->name('account.index');
            Route::get('students/{student}/account', [AccountController::class, 'show'])->whereNumber('student')->name('students.account');
        });
    });
});
