<?php

use App\Http\Controllers\ClassReminderPageController;
use App\Http\Controllers\EmailConfirmationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportDownloadController;
use Illuminate\Support\Facades\Route;

// Landing pública de Tuku (tukuha.app).
Route::get('/', LandingController::class)->name('landing');

// Recibo de pago en PDF: link firmado y temporal (app y panel), sin sesión.
Route::get('recibos/{payment}', ReceiptController::class)
    ->whereNumber('payment')
    ->middleware('signed')
    ->name('receipts.show');

// Comprobante de transferencia informado por el tutor: link firmado y temporal (app y panel).
Route::get('comprobantes-de-pago/{report}', PaymentProofController::class)
    ->whereNumber('report')
    ->middleware('signed')
    ->name('payment-proofs.show');

// Informes en PDF o Excel: link firmado y temporal (app y panel).
Route::get('informes/{report}.{format}', ReportDownloadController::class)
    ->whereIn('report', ['balance', 'saldos', 'morosos'])
    ->whereIn('format', ['pdf', 'xlsx'])
    ->middleware('signed')
    ->name('reports.download');

// "Confirmar mi correo": correo opcional de una cuenta con celular (link firmado de los correos).
Route::get('correo/confirmar/{user}/{hash}', EmailConfirmationController::class)
    ->whereNumber('user')
    ->middleware('throttle:10,1')
    ->name('email.confirm');

// "Sí, va" / "No va" del aviso de clase por correo: el link abre la página y la respuesta se guarda con su botón.
Route::get('clases/{class}/respuesta/{user}', [ClassReminderPageController::class, 'show'])
    ->whereNumber(['class', 'user'])
    ->middleware('throttle:30,1')
    ->name('class-reminder.show');
Route::post('clases/{class}/respuesta/{user}', [ClassReminderPageController::class, 'store'])
    ->whereNumber(['class', 'user'])
    ->middleware('throttle:30,1')
    ->name('class-reminder.store');
