<?php

namespace App\Jobs\Integrations;

use App\Application\Support\UserNotificationService;
use App\Models\InvoiceInboxItem;
use App\Services\Integrations\Telegram\TelegramInvoicePdfParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ParseTelegramInvoicePdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $invoiceInboxItemId,
    ) {
    }

    public function handle(
        TelegramInvoicePdfParser $parser,
        UserNotificationService $notifications,
    ): void {
        $item = InvoiceInboxItem::query()
            ->with('telegramMessage')
            ->find($this->invoiceInboxItemId);

        if (! $item || trim((string) $item->file_path) === '') {
            return;
        }

        $absolutePath = Storage::disk((string) $item->file_disk)->path((string) $item->file_path);

        try {
            $item->forceFill([
                'parse_status' => 'processing',
                'match_status' => 'pending',
            ])->save();

            $result = $parser->parse(
                $absolutePath,
                $item->telegramMessage?->caption,
                $item->original_file_name,
            );

            $item->forceFill([
                'parse_status' => $result['parse_status'],
                'readability_status' => $result['readability_status'],
                'ocr_text' => $result['ocr_text'],
                'invoice_number' => $result['invoice_number'],
                'customer_name' => $result['customer_name'],
                'invoice_date' => $result['invoice_date'],
                'confidence_score' => $result['confidence_score'],
                'review_notes' => $result['review_notes'],
                'extracted_json' => $result['extracted_json'],
                'match_status' => 'pending',
                'match_notes' => null,
                'matched_sale_order_id' => null,
                'matched_stock_out_id' => null,
                'matched_at' => null,
            ])->save();

            MatchTelegramInvoiceJob::dispatch($item->id);

            if ($result['parse_status'] === 'needs_review') {
                $notifications->notifyAllActiveUsers(
                    eventType: 'telegram-invoice.needs-review',
                    title: 'Telegram invoice needs review',
                    message: sprintf(
                        'Invoice inbox item %d requires manual review.',
                        $item->id
                    ),
                    data: [
                        'invoice_inbox_item_id' => $item->id,
                        'invoice_number' => $result['invoice_number'],
                        'file_name' => $item->original_file_name,
                    ],
                    level: 'warning',
                );
            }
        } catch (\Throwable $exception) {
            $item->forceFill([
                'parse_status' => 'failed',
                'review_notes' => $exception->getMessage(),
            ])->save();

            $notifications->notifyAllActiveUsers(
                eventType: 'telegram-invoice.parse-failed',
                title: 'Telegram invoice parse failed',
                message: sprintf(
                    'Invoice inbox item %d could not be parsed.',
                    $item->id
                ),
                data: [
                    'invoice_inbox_item_id' => $item->id,
                    'file_name' => $item->original_file_name,
                ],
                level: 'warning',
            );

            throw $exception;
        }
    }
}
