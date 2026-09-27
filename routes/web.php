<?php

use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportDownloadController;
use Illuminate\Support\Facades\Route;

// Por ahora no hay sitio público: la raíz lleva al panel.
Route::redirect('/', '/admin');

// Recibo de pago en PDF: link firmado y temporal (app y panel), sin sesión.
Route::get('recibos/{payment}', ReceiptController::class)
    ->whereNumber('payment')
    ->middleware('signed')
    ->name('receipts.show');

// Informes en PDF o Excel: link firmado y temporal (app y panel).
Route::get('informes/{report}.{format}', ReportDownloadController::class)
    ->whereIn('report', ['balance', 'saldos', 'morosos'])
    ->whereIn('format', ['pdf', 'xlsx'])
    ->middleware('signed')
    ->name('reports.download');
