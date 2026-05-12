<?php

namespace App\Http\Resources\Api\Integrations;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceInboxItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'file_disk' => $this->file_disk,
            'file_path' => $this->file_path,
            'original_file_name' => $this->original_file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_hash' => $this->file_hash,
            'telegram_file_id' => $this->telegram_file_id,
            'telegram_file_unique_id' => $this->telegram_file_unique_id,
            'download_status' => $this->download_status,
            'parse_status' => $this->parse_status,
            'readability_status' => $this->readability_status,
            'ocr_text' => $this->ocr_text,
            'extracted_json' => $this->extracted_json,
            'invoice_number' => $this->invoice_number,
            'customer_name' => $this->customer_name,
            'invoice_date' => $this->invoice_date,
            'confidence_score' => $this->confidence_score,
            'match_status' => $this->match_status,
            'matched_sale_order_id' => $this->matched_sale_order_id,
            'matched_stock_out_id' => $this->matched_stock_out_id,
            'match_notes' => $this->match_notes,
            'matched_at' => $this->matched_at,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
            'is_duplicate' => $this->is_duplicate,
            'duplicate_reason' => $this->duplicate_reason,
            'duplicate_of_inbox_item_id' => $this->duplicate_of_inbox_item_id,
            'review_notes' => $this->review_notes,
            'last_downloaded_at' => $this->last_downloaded_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'telegram_message' => $this->whenLoaded('telegramMessage', fn (): TelegramInvoiceMessageResource => new TelegramInvoiceMessageResource($this->telegramMessage)),
        ];
    }
}
