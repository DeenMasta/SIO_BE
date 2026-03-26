<?php

namespace App\Http\Requests\Api\IdentityAccess\User;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Domain\IdentityAccess\Enums\UserStatus;
use App\Http\Requests\Api\StrictFormRequest;
use App\Models\User;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends StrictFormRequest
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
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
            'role' => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
        ];
    }

    protected function allowedFields(): array
    {
        return ['name', 'email', 'password', 'role', 'status'];
    }
}
