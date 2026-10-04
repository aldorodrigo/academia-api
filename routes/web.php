<?php

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
