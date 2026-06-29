<?php

namespace App\Jobs\Integrations;

use App\Application\Support\UserNotificationService;
use App\Models\InvoiceInboxItem;
use App\Services\Integrations\Telegram\TelegramInvoiceCustomerSync;
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
        TelegramInvoiceCustomerSync $customerSync,
        UserNotificationService $notifications,
        \App\Application\Support\DocumentNumberGenerator $documentNumberGenerator,
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

            $customer = $customerSync->syncFromParsedDetails(
                $result['customer_name'],
                $result['customer_phone'] ?? null,
                $result['customer_address'] ?? null
            );

            // Attempt to create Sale Order if customer exists and items were parsed
            if ($customer && !empty($result['items'])) {
                $lines = [];
                $unmatchedCodes = [];
                $allMatched = true;

                foreach ($result['items'] as $code) {
                    // Check if it's a package
                    $package = \App\Models\Package::query()->with('products')->where('package_code', $code)->first();
                    
                    if ($package) {
                        foreach ($package->products as $product) {
                            $lines[] = [
                                'product_id' => $product->id,
                                'ordered_qty' => $product->pivot->quantity ?? 1,
                                'unit_price' => $product->selling_price,
                                'is_free' => false,
                                'remarks' => 'From Package: ' . $package->package_name,
                            ];
                        }
                        continue;
                    }

                    // Check if it's a product
                    $product = \App\Models\Product::query()->where('product_code', $code)->first();
                    if ($product) {
                        $lines[] = [
                            'product_id' => $product->id,
                            'ordered_qty' => 1,
                            'unit_price' => $product->selling_price,
                            'is_free' => false,
                            'remarks' => null,
                        ];
                        continue;
                    }

                    // Code not found
                    $allMatched = false;
                    $unmatchedCodes[] = $code;
                }

                if (!$allMatched) {
                    throw new \Exception('Failed to match items to a Product or Package: ' . implode(', ', $unmatchedCodes));
                }

                if (!empty($lines)) {
                    $payload = [
                        'customer_id' => $customer->id,
                        'so_number' => $documentNumberGenerator->generateSaleOrderNumber(),
                        'so_date' => $result['invoice_date'] ?? now()->format('Y-m-d'),
                        'invoice_number' => $result['invoice_number'],
                        'status' => \App\Domain\SalesOutbound\Enums\SaleOrderStatus::Draft,
                        'remarks' => 'Auto-generated from parsed Telegram invoice.',
                        'created_by' => \App\Models\User::query()->first()?->id ?? 1,
                        'lines' => $lines,
                    ];

                    // create sale order without requiring authenticated user since it's a background job
                    // we'll set created_by to null or a system user if needed, or pass 0.
                    // Let's use the SaleOrderRepository to bypass UseCase which might assume active user.
                    $saleOrderRepo = app(\App\Application\Contracts\Repositories\SaleOrderRepository::class);
                    $saleOrder = $saleOrderRepo->createWithLines($payload);

                    // Update item with matched sale order ID
                    $item->forceFill(['matched_sale_order_id' => $saleOrder->id])->save();
                }
            }

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
