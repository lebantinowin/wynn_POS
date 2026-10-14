<?php

namespace App\Http\Middleware;

use App\Support\PracticeShop;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PracticeWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('pos.practice')) {
            try {
                PracticeShop::assertPrepared();
            } catch (RuntimeException $exception) {
                abort(503, $exception->getMessage());
            }
        }

        return $next($request);
    }
}
