<?php

namespace App\Http\Controllers\Api;

use App\Application\Support\ApiResponse;
use App\Domain\IdentityAccess\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Resources\Api\UserResource;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            return ApiResponse::error('Invalid credentials.', Response::HTTP_UNAUTHORIZED);
        }

        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            return ApiResponse::error('Your account is inactive.', Response::HTTP_FORBIDDEN);
        }

        $abilities = $user->role === UserRole::Admin ? ['admin-access', 'staff-access'] : ['staff-access'];
        $token = $user->createToken($request->input('device_name', 'api-token'), $abilities)->plainTextToken;

        return ApiResponse::success([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => UserResource::make($user),
        ], 'Login successful.');
    }

    public function logout()
    {
        $user = request()->user();
        $token = $user?->currentAccessToken();

        if ($user && $token) {
            $user->tokens()->whereKey($token->id)->delete();
        }

        return ApiResponse::success(null, 'Logout successful.');
    }

    public function me()
    {
        return ApiResponse::success(UserResource::make(request()->user()), 'User profile retrieved.');
    }
}
