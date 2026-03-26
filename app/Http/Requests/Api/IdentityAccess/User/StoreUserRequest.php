<?php

namespace App\Http\Requests\Api\IdentityAccess\User;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Domain\IdentityAccess\Enums\UserStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }

    protected function allowedFields(): array
    {
        return ['name', 'email', 'password', 'role', 'status'];
    }
}
