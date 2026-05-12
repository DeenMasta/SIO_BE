<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TelegramInvoiceMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_update_id',
        'telegram_chat_id',
        'telegram_chat_title',
        'telegram_message_id',
        'telegram_user_id',
        'telegram_username',
        'telegram_first_name',
        'telegram_last_name',
        'message_text',
        'caption',
        'message_date',
        'raw_payload',
        'received_at',
        'pruned_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'message_date' => 'datetime',
            'received_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }

    public function inboxItem(): HasOne
    {
        return $this->hasOne(InvoiceInboxItem::class);
    }
}
