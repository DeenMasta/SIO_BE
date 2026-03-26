<?php

namespace App\Http\Middleware;

use App\Application\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            return ApiResponse::error('Your account is inactive.', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
