<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceInboxItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'telegram_invoice_message_id',
        'file_disk',
        'file_path',
        'original_file_name',
        'mime_type',
        'file_size',
        'file_hash',
        'telegram_file_id',
        'telegram_file_unique_id',
        'download_status',
        'parse_status',
        'readability_status',
        'ocr_text',
        'extracted_json',
        'invoice_number',
        'customer_name',
        'invoice_date',
        'matched_sale_order_id',
        'matched_stock_out_id',
        'reviewed_by',
        'confidence_score',
        'match_status',
        'is_duplicate',
        'duplicate_reason',
        'duplicate_of_inbox_item_id',
        'review_notes',
        'match_notes',
        'last_downloaded_at',
        'matched_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'extracted_json' => 'array',
            'invoice_date' => 'date',
            'confidence_score' => 'decimal:2',
            'is_duplicate' => 'boolean',
            'last_downloaded_at' => 'datetime',
            'matched_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function telegramMessage(): BelongsTo
    {
        return $this->belongsTo(TelegramInvoiceMessage::class, 'telegram_invoice_message_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_inbox_item_id');
    }
}
