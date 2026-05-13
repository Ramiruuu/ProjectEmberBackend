<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthenticate extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        if ($request->is('api/*')) {
            return null;
        }

        return parent::redirectTo($request);
    }

    protected function unauthenticated($request, array $guards)
    {
        if ($request->is('api/*')) {
            abort(response()->json(['message' => 'Unauthenticated.'], 401));
        }

        parent::unauthenticated($request, $guards);
    }
}