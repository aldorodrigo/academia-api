<?php

use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

// Por ahora no hay sitio público: la raíz lleva al panel.
Route::redirect('/', '/admin');

// Recibo de pago en PDF: link firmado y temporal (app y panel), sin sesión.
Route::get('recibos/{payment}', ReceiptController::class)
    ->whereNumber('payment')
    ->middleware('signed')
    ->name('receipts.show');
