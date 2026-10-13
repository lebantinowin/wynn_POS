<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OwnerAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('login', 'login.store')) {
            if (! $request->user() || (int) $request->user()->getAuthIdentifier() !== User::OWNER_ID) {
                Auth::logout();
                throw new AuthenticationException('Please sign in.', ['web'], route('login'));
            }
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
