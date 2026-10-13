<?php

namespace App\Http\Middleware;

use App\Support\PosStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PosTransaction
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('login', 'login.store', 'logout')) {
            return $next($request);
        }

        return PosStore::atomic(fn (): Response => $next($request));
    }
}
