<?php

namespace App\Http\Controllers\Api\IdentityAccess;

use App\Application\IdentityAccess\Users\UseCases\ActivateUserUseCase;
use App\Application\IdentityAccess\Users\UseCases\CreateUserUseCase;
use App\Application\IdentityAccess\Users\UseCases\DeactivateUserUseCase;
use App\Application\IdentityAccess\Users\UseCases\ListUsersUseCase;
use App\Application\IdentityAccess\Users\UseCases\UpdateUserUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IdentityAccess\User\StoreUserRequest;
use App\Http\Requests\Api\IdentityAccess\User\UpdateUserRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function __construct(
        private readonly ListUsersUseCase $listUsers,
        private readonly CreateUserUseCase $createUser,
        private readonly UpdateUserUseCase $updateUser,
        private readonly ActivateUserUseCase $activateUser,
        private readonly DeactivateUserUseCase $deactivateUser,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->listUsers->execute([
            'per_page' => (int) $request->integer('per_page', 15),
            'q' => $request->query('q'),
            'role' => $request->query('role'),
            'status' => $request->query('status'),
        ]);

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
        $this->authorize('create', User::class);

        $user = $this->createUser->execute($request->validated());

        return ApiResponse::success(new UserResource($user), 'User created successfully.', 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $updated = $this->updateUser->execute([
            'user' => $user,
            ...$request->validated(),
        ]);

        return ApiResponse::success(new UserResource($updated), 'User updated successfully.');
    }

    public function activate(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $updated = $this->activateUser->execute($user);

        return ApiResponse::success(new UserResource($updated), 'User activated successfully.');
    }

    public function deactivate(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        if ((int) $this->currentUser()->id === (int) $user->id) {
            return ApiResponse::error('You cannot deactivate your current session account.', 422);
        }

        $updated = $this->deactivateUser->execute($user);
        $user->tokens()->delete();

        return ApiResponse::success(new UserResource($updated), 'User deactivated successfully.');
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
