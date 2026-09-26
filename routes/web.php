<?php

use Illuminate\Support\Facades\Route;

// Por ahora no hay sitio público: la raíz lleva al panel.
Route::redirect('/', '/admin');
