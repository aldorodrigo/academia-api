<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Landing pública de Tuku (tukuha.app): presentación, precios y contacto.
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('site.home', [
            'freeUntil' => Carbon::parse(config('tuku.free_until')),
            'plans' => config('tuku.plans'),
        ]);
    }
}
