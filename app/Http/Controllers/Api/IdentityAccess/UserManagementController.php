<?php

namespace App\Http\Controllers\Api\IdentityAccess;

use App\Application\Support\ApiResponse;
use App\Domain\IdentityAccess\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IdentityAccess\User\StoreUserRequest;
use App\Http\Requests\Api\IdentityAccess\User\UpdateUserRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(
            UserResource::collection($users->items()),
            'Users retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage(),
                ],
            ],
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::query()->create($request->validated());

        return ApiResponse::success(new UserResource($user), 'User created successfully.', 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->fill($request->validated());
        $user->save();

        return ApiResponse::success(new UserResource($user->refresh()), 'User updated successfully.');
    }

    public function activate(User $user): JsonResponse
    {
        $user->status = UserStatus::Active;
        $user->save();

        return ApiResponse::success(new UserResource($user->refresh()), 'User activated successfully.');
    }

    public function deactivate(User $user): JsonResponse
    {
        $user->status = UserStatus::Inactive;
        $user->save();
        $user->tokens()->delete();

        return ApiResponse::success(new UserResource($user->refresh()), 'User deactivated successfully.');
    }
}
