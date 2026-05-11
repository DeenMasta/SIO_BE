<?php

namespace App\Application\Support;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Domain\IdentityAccess\Enums\UserStatus;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;

class UserNotificationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyAllActiveUsers(
        string $eventType,
        string $title,
        string $message,
        array $data = [],
        ?int $exceptUserId = null,
        string $level = 'info',
    ): void {
        $recipientIds = User::query()
            ->where('status', UserStatus::Active->value)
            ->when($exceptUserId !== null, fn ($query) => $query->where('id', '!=', $exceptUserId))
            ->pluck('id')
            ->all();

        $this->sendToUserIds($recipientIds, $eventType, $title, $message, $data, $level);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyAdmins(
        string $eventType,
        string $title,
        string $message,
        array $data = [],
        ?int $exceptUserId = null,
        string $level = 'info',
    ): void {
        $recipientIds = User::query()
            ->where('status', UserStatus::Active->value)
            ->where('role', UserRole::Admin->value)
            ->when($exceptUserId !== null, fn ($query) => $query->where('id', '!=', $exceptUserId))
            ->pluck('id')
            ->all();

        $this->sendToUserIds($recipientIds, $eventType, $title, $message, $data, $level);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyUser(
        int $userId,
        string $eventType,
        string $title,
        string $message,
        array $data = [],
        string $level = 'info',
    ): void {
        $this->sendToUserIds([$userId], $eventType, $title, $message, $data, $level);
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @param  array<string, mixed>  $data
     */
    private function sendToUserIds(
        array $userIds,
        string $eventType,
        string $title,
        string $message,
        array $data,
        string $level,
    ): void {
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));

        if ($recipientIds === []) {
            return;
        }

        $dispatch = function () use ($recipientIds, $eventType, $title, $message, $data, $level): void {
            $payload = [
                'event_type' => $eventType,
                'title' => $title,
                'message' => $message,
                'level' => $level,
                'data' => $data,
            ];

            User::query()
                ->whereIn('id', $recipientIds)
                ->get()
                ->each(fn (User $user) => $user->notify(new SystemNotification($payload)));
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}
