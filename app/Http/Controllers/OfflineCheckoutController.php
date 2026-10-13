<?php

namespace App\Http\Controllers;

use App\Support\OfflineCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineCheckoutController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json(['csrfToken' => csrf_token(), 'active' => app(OfflineCheckout::class)->active() !== null]);
    }

    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate(['deviceId' => 'required|uuid', 'id' => 'required|uuid']);

        return response()->json(app(OfflineCheckout::class)->prepare($data['deviceId'], $data['id']));
    }

    public function sync(Request $request): JsonResponse
    {
        return response()->json(app(OfflineCheckout::class)->sync($request->all()));
    }

    public function finish(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => 'required|uuid', 'deviceId' => 'required|uuid', 'lastSequence' => 'required|integer|min:0|max:1000000']);
        app(OfflineCheckout::class)->finish($data['id'], $data['deviceId'], (int) $data['lastSequence']);

        return response()->json(['finished' => true]);
    }
}
