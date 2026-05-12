<?php

namespace App\Jobs\Integrations;

use App\Application\Support\UserNotificationService;
use App\Models\InvoiceInboxItem;
use App\Services\Integrations\Telegram\TelegramInvoiceMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchTelegramInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $invoiceInboxItemId,
    ) {
    }

    public function handle(
        TelegramInvoiceMatcher $matcher,
        UserNotificationService $notifications,
    ): void {
        $item = InvoiceInboxItem::query()
            ->with('telegramMessage')
            ->find($this->invoiceInboxItemId);

        if (! $item || ! in_array((string) $item->parse_status, ['parsed', 'needs_review'], true)) {
            return;
        }

        $result = $matcher->match($item);

        if ($item->parse_status === 'needs_review' && $result['match_status'] !== 'matched') {
            $result['match_status'] = 'pending';
            $result['matched_sale_order_id'] = null;
            $result['matched_stock_out_id'] = null;
            $result['match_notes'] = 'Matching deferred until manual review completes.';
        }

        $item->forceFill([
            'match_status' => $result['match_status'],
            'matched_sale_order_id' => $result['matched_sale_order_id'],
            'matched_stock_out_id' => $result['matched_stock_out_id'],
            'match_notes' => $result['match_notes'],
            'matched_at' => $result['match_status'] === 'matched' ? now() : null,
        ])->save();

        if ($result['match_status'] === 'matched') {
            $notifications->notifyAllActiveUsers(
                eventType: 'telegram-invoice.matched',
                title: 'Telegram invoice matched',
                message: sprintf('Invoice inbox item %d was matched to an operational record.', $item->id),
                data: [
                    'invoice_inbox_item_id' => $item->id,
                    'invoice_number' => $item->invoice_number,
                    'matched_sale_order_id' => $result['matched_sale_order_id'],
                    'matched_stock_out_id' => $result['matched_stock_out_id'],
                ],
                level: 'success',
            );

            return;
        }

        if ($result['match_status'] === 'pending') {
            return;
        }

        if (in_array($result['match_status'], ['multi_match', 'unmatched'], true)) {
            $notifications->notifyAllActiveUsers(
                eventType: 'telegram-invoice.match-review',
                title: 'Telegram invoice match needs review',
                message: sprintf('Invoice inbox item %d could not be matched automatically.', $item->id),
                data: [
                    'invoice_inbox_item_id' => $item->id,
                    'invoice_number' => $item->invoice_number,
                    'match_status' => $result['match_status'],
                ],
                level: 'warning',
            );
        }
    }
}
