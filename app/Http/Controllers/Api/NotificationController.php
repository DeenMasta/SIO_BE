<?php

namespace App\Http\Controllers\Api;

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\NotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $this->currentUser($request)
            ->notifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate(max((int) $request->integer('per_page', 15), 1));

        return ApiResponse::paginated(
            $notifications,
            NotificationResource::collection($notifications->items()),
            'Notifications retrieved successfully.',
            meta: [
                'unread_count' => $this->currentUser($request)->unreadNotifications()->count(),
            ],
        );
    }

    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = $this->currentUser($request)
            ->notifications()
            ->findOrFail($notificationId);

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return ApiResponse::success(
            new NotificationResource($notification->fresh()),
            'Notification marked as read successfully.',
            meta: [
                'unread_count' => $this->currentUser($request)->unreadNotifications()->count(),
            ],
        );
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->currentUser($request)
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return ApiResponse::success(
            null,
            'All notifications marked as read successfully.',
            meta: ['unread_count' => 0],
        );
    }

    public function clearAll(Request $request): JsonResponse
    {
        $this->currentUser($request)
            ->notifications()
            ->delete();

        return ApiResponse::success(
            null,
            'All notifications cleared successfully.',
            meta: ['unread_count' => 0],
        );
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
