<?php

namespace App\Services\Integrations\Telegram;

use App\Models\InvoiceInboxItem;
use App\Models\SaleOrder;
use App\Models\StockOut;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class TelegramInvoiceMatcher
{
    /**
     * @return array{
     *   match_status:string,
     *   matched_sale_order_id:?int,
     *   matched_stock_out_id:?int,
     *   match_notes:?string
     * }
     */
    public function match(InvoiceInboxItem $item): array
    {
        $invoiceNumber = $this->normalize((string) ($item->invoice_number ?? ''));
        $searchText = $this->buildSearchText($item);

        if ($invoiceNumber !== null) {
            $saleOrders = $this->matchSaleOrdersByInvoiceNumber($invoiceNumber);
            $stockOuts = $this->matchStockOutsByInvoiceNumber($invoiceNumber);

            if ($saleOrders->count() === 1 && $stockOuts->count() <= 1) {
                /** @var SaleOrder $saleOrder */
                $saleOrder = $saleOrders->first();
                $stockOut = $stockOuts->count() === 1
                    ? $stockOuts->first()
                    : $this->findStockOutForSaleOrder($saleOrder->id, $invoiceNumber);

                return [
                    'match_status' => 'matched',
                    'matched_sale_order_id' => $saleOrder->id,
                    'matched_stock_out_id' => $stockOut?->id,
                    'match_notes' => 'Matched by exact invoice number.',
                ];
            }

            if ($saleOrders->count() === 0 && $stockOuts->count() === 1) {
                /** @var StockOut $stockOut */
                $stockOut = $stockOuts->first();

                return [
                    'match_status' => 'matched',
                    'matched_sale_order_id' => $stockOut->sale_order_id ? (int) $stockOut->sale_order_id : null,
                    'matched_stock_out_id' => $stockOut->id,
                    'match_notes' => 'Matched by exact invoice number on stock out.',
                ];
            }

            if ($saleOrders->count() > 1 || $stockOuts->count() > 1) {
                return [
                    'match_status' => 'multi_match',
                    'matched_sale_order_id' => null,
                    'matched_stock_out_id' => null,
                    'match_notes' => 'Multiple records share the same invoice number. Manual review required.',
                ];
            }
        }

        $saleOrderNumber = $this->extractDocumentNumber($searchText, '/\bSO[-\/]?[A-Z0-9][A-Z0-9\/\-_\.]{2,}\b/i');
        if ($saleOrderNumber !== null) {
            $saleOrders = SaleOrder::query()
                ->whereRaw('UPPER(so_number) = ?', [strtoupper($saleOrderNumber)])
                ->get();

            if ($saleOrders->count() === 1) {
                /** @var SaleOrder $saleOrder */
                $saleOrder = $saleOrders->first();

                return [
                    'match_status' => 'matched',
                    'matched_sale_order_id' => $saleOrder->id,
                    'matched_stock_out_id' => null,
                    'match_notes' => 'Matched by sales order number found in invoice content.',
                ];
            }
        }

        $stockOutNumber = $this->extractDocumentNumber($searchText, '/\bSTO?[-\/]?[A-Z0-9][A-Z0-9\/\-_\.]{2,}\b/i');
        if ($stockOutNumber !== null) {
            $stockOuts = StockOut::query()
                ->whereRaw('UPPER(stock_out_number) = ?', [strtoupper($stockOutNumber)])
                ->get();

            if ($stockOuts->count() === 1) {
                /** @var StockOut $stockOut */
                $stockOut = $stockOuts->first();

                return [
                    'match_status' => 'matched',
                    'matched_sale_order_id' => $stockOut->sale_order_id ? (int) $stockOut->sale_order_id : null,
                    'matched_stock_out_id' => $stockOut->id,
                    'match_notes' => 'Matched by stock out number found in invoice content.',
                ];
            }
        }

        return [
            'match_status' => $invoiceNumber === null ? 'pending' : 'unmatched',
            'matched_sale_order_id' => null,
            'matched_stock_out_id' => null,
            'match_notes' => $invoiceNumber === null
                ? 'Invoice number not available yet.'
                : 'No sale order or stock out matched the extracted invoice number.',
        ];
    }

    /**
     * @return Collection<int, SaleOrder>
     */
    private function matchSaleOrdersByInvoiceNumber(string $invoiceNumber): Collection
    {
        return SaleOrder::query()
            ->whereRaw('UPPER(invoice_number) = ?', [strtoupper($invoiceNumber)])
            ->get();
    }

    /**
     * @return Collection<int, StockOut>
     */
    private function matchStockOutsByInvoiceNumber(string $invoiceNumber): Collection
    {
        if (! Schema::hasColumn('stock_out', 'invoice_number')) {
            return collect();
        }

        return StockOut::query()
            ->whereRaw('UPPER(invoice_number) = ?', [strtoupper($invoiceNumber)])
            ->get();
    }

    private function findStockOutForSaleOrder(int $saleOrderId, string $invoiceNumber): ?StockOut
    {
        $query = StockOut::query()->where('sale_order_id', $saleOrderId);

        if (Schema::hasColumn('stock_out', 'invoice_number')) {
            $query->whereRaw('UPPER(invoice_number) = ?', [strtoupper($invoiceNumber)]);
        }

        return $query->orderByDesc('id')->first();
    }

    private function buildSearchText(InvoiceInboxItem $item): string
    {
        return trim(implode("\n", array_filter([
            (string) ($item->ocr_text ?? ''),
            (string) ($item->telegramMessage?->caption ?? ''),
            (string) ($item->original_file_name ?? ''),
        ], static fn (string $value): bool => trim($value) !== '')));
    }

    private function normalize(string $value): ?string
    {
        $normalized = strtoupper(trim($value));
        $normalized = trim($normalized, " \t\n\r\0\x0B:;,.#");

        return $normalized !== '' ? $normalized : null;
    }

    private function extractDocumentNumber(string $text, string $pattern): ?string
    {
        if ($text === '') {
            return null;
        }

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        return $this->normalize((string) ($matches[0] ?? ''));
    }
}
