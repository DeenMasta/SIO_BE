<?php

namespace App\Http\Resources\Api\Integrations;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelegramInvoiceMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'telegram_update_id' => $this->telegram_update_id,
            'telegram_chat_id' => $this->telegram_chat_id,
            'telegram_chat_title' => $this->telegram_chat_title,
            'telegram_message_id' => $this->telegram_message_id,
            'telegram_user_id' => $this->telegram_user_id,
            'telegram_username' => $this->telegram_username,
            'telegram_first_name' => $this->telegram_first_name,
            'telegram_last_name' => $this->telegram_last_name,
            'message_text' => $this->message_text,
            'caption' => $this->caption,
            'message_date' => $this->message_date,
            'received_at' => $this->received_at,
        ];
    }
}
