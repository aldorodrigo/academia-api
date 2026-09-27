<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

/**
 * Canchas del club (para reprogramar una clase).
 */
class VenueController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Venue::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
