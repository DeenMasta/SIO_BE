<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'event_type' => $this->data['event_type'] ?? null,
            'title' => $this->data['title'] ?? null,
            'message' => $this->data['message'] ?? null,
            'level' => $this->data['level'] ?? 'info',
            'data' => $this->data['data'] ?? [],
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
