<?php

namespace App\Console\Commands;

use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Domain\ReportingAudit\Enums\AuditAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReplaceDispatchedProductAndRemoveWrongInbound extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inventory:replace-dispatch-and-remove-wrong-inbound
        {sale_order_line_id : The fulfilled sale_order_lines.id to replace}
        {stock_out_line_id : The linked stock_out_lines.id to replace}
        {wrong_stock_in_line_id : The non-serialized stock_in_lines.id to remove}
        {wrong_purchase_order_line_id : The matching purchase_order_lines.id to remove}
        {replacement_stock_item_id : The QC-passed serialized stock_items.id to dispatch}
        {--performed-by= : Optional users.id for the audit entry; defaults to the original stock-out performer}
        {--yes : Skip the confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Replace a wrong non-serialized dispatch with an existing serialized item, then remove the wrong stock-in and PO lines';

    public function __construct(
        private readonly StockBalanceUpdater $stockBalanceUpdater,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $data = [
            'sale_order_line_id' => (int) $this->argument('sale_order_line_id'),
            'stock_out_line_id' => (int) $this->argument('stock_out_line_id'),
            'wrong_stock_in_line_id' => (int) $this->argument('wrong_stock_in_line_id'),
            'wrong_purchase_order_line_id' => (int) $this->argument('wrong_purchase_order_line_id'),
            'replacement_stock_item_id' => (int) $this->argument('replacement_stock_item_id'),
            'performed_by' => $this->option('performed-by') !== null
                ? (int) $this->option('performed-by')
                : null,
        ];

        if (in_array(0, [
            $data['sale_order_line_id'],
            $data['stock_out_line_id'],
            $data['wrong_stock_in_line_id'],
            $data['wrong_purchase_order_line_id'],
            $data['replacement_stock_item_id'],
        ], true)) {
            $this->error('All five IDs must be positive integers.');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm(
            'This rewrites the fulfilled Sales Order/Stock Out and permanently removes the selected Stock In and PO lines. Continue?',
            false,
        )) {
            $this->warn('Correction aborted.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(fn (): array => $this->replace($data));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Product dispatch corrected and wrong inbound records removed successfully.');
        $this->table(
            ['Field', 'Value'],
            [
                ['wrong_product_id', (string) $result['wrong_product_id']],
                ['replacement_product_id', (string) $result['replacement_product_id']],
                ['sale_order_line_id', (string) $result['sale_order_line_id']],
                ['stock_out_line_id', (string) $result['stock_out_line_id']],
                ['stock_out_line_item_id', (string) $result['stock_out_line_item_id']],
                ['replacement_stock_item_id', (string) $result['replacement_stock_item_id']],
                ['deleted_stock_in_line_id', (string) $result['deleted_stock_in_line_id']],
                ['deleted_purchase_order_line_id', (string) $result['deleted_purchase_order_line_id']],
                ['purchase_order_status', (string) $result['purchase_order_status']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     sale_order_line_id:int,
     *     stock_out_line_id:int,
     *     wrong_stock_in_line_id:int,
     *     wrong_purchase_order_line_id:int,
     *     replacement_stock_item_id:int,
     *     performed_by:?int
     * }  $data
     * @return array<string, int|string>
     */
    private function replace(array $data): array
    {
        $now = now();

        $saleOrderLine = DB::table('sale_order_lines')
            ->where('id', $data['sale_order_line_id'])
            ->lockForUpdate()
            ->first();
        $stockOutLine = DB::table('stock_out_lines')
            ->where('id', $data['stock_out_line_id'])
            ->lockForUpdate()
            ->first();
        $wrongStockInLine = DB::table('stock_in_lines')
            ->where('id', $data['wrong_stock_in_line_id'])
            ->lockForUpdate()
            ->first();
        $wrongPurchaseOrderLine = DB::table('purchase_order_lines')
            ->where('id', $data['wrong_purchase_order_line_id'])
            ->lockForUpdate()
            ->first();
        $replacementStockItem = DB::table('stock_items')
            ->where('id', $data['replacement_stock_item_id'])
            ->lockForUpdate()
            ->first();

        if ($saleOrderLine === null || $stockOutLine === null || $wrongStockInLine === null || $wrongPurchaseOrderLine === null || $replacementStockItem === null) {
            throw new RuntimeException('One or more selected records no longer exist.');
        }

        $wrongProductId = (int) $wrongStockInLine->product_id;
        $replacementProductId = (int) $replacementStockItem->product_id;

        if ((int) $saleOrderLine->product_id !== $wrongProductId
            || (int) $stockOutLine->product_id !== $wrongProductId
            || (int) $stockOutLine->sale_order_line_id !== (int) $saleOrderLine->id) {
            throw new RuntimeException('The Sales Order and Stock Out lines do not both point to the selected wrong product.');
        }

        if ((int) $wrongStockInLine->purchase_order_line_id !== (int) $wrongPurchaseOrderLine->id
            || (int) $wrongPurchaseOrderLine->product_id !== $wrongProductId) {
            throw new RuntimeException('The selected Stock In line is not linked to the selected wrong PO line.');
        }

        if ((int) $wrongStockInLine->received_qty !== (int) $wrongPurchaseOrderLine->received_qty) {
            throw new RuntimeException('The wrong PO line has receipts beyond the selected Stock In line and cannot be removed safely.');
        }

        if ((int) $stockOutLine->qty !== (int) $saleOrderLine->fulfilled_qty || (int) $stockOutLine->qty !== (int) $saleOrderLine->ordered_qty) {
            throw new RuntimeException('This command only supports a fully fulfilled one-line Sales Order dispatch.');
        }

        if ((int) $stockOutLine->is_extra !== 0 || (int) $stockOutLine->settled_qty !== 0 || (int) $stockOutLine->reversed_qty !== 0) {
            throw new RuntimeException('Extra, settled, or reversed Stock Out lines cannot be corrected with this command.');
        }

        if (DB::table('stock_out_lines')->where('sale_order_line_id', (int) $saleOrderLine->id)->count() !== 1) {
            throw new RuntimeException('The Sales Order line is linked to multiple Stock Out lines and cannot be corrected with this command.');
        }

        $wrongProduct = DB::table('products')->where('id', $wrongProductId)->first(['id', 'requires_serial_number']);
        $replacementProduct = DB::table('products')->where('id', $replacementProductId)->first(['id', 'requires_serial_number']);

        if ($wrongProduct === null || $replacementProduct === null
            || (bool) $wrongProduct->requires_serial_number
            || ! (bool) $replacementProduct->requires_serial_number
            || $wrongProductId === $replacementProductId) {
            throw new RuntimeException('This command requires a wrong non-serialized product and a different replacement serialized product.');
        }

        if (DB::table('stock_items')->where('stock_in_line_id', (int) $wrongStockInLine->id)->exists()) {
            throw new RuntimeException('The wrong Stock In line has serialized stock items and cannot be removed with this command.');
        }

        if (DB::table('stock_out_line_items')->where('stock_out_line_id', (int) $stockOutLine->id)->exists()) {
            throw new RuntimeException('The wrong Stock Out line already has serialized item links.');
        }

        if ($replacementStockItem->qc_status !== 'PASSED'
            || $replacementStockItem->current_status !== 'IN_STOCK'
            || ! (bool) $replacementStockItem->is_available
            || DB::table('stock_out_line_items')->where('stock_item_id', (int) $replacementStockItem->id)->exists()) {
            throw new RuntimeException('The replacement stock item must be QC-passed, available, in stock, and unused.');
        }

        if (DB::table('customer_return_lines')->where('original_stock_out_line_id', (int) $stockOutLine->id)->exists()
            || DB::table('customer_exchange_lines')->where('sale_order_line_id', (int) $saleOrderLine->id)->orWhere('replacement_stock_out_line_id', (int) $stockOutLine->id)->exists()) {
            throw new RuntimeException('The selected Sales Order or Stock Out line has a return or exchange and cannot be corrected this way.');
        }

        $outboundMovements = DB::table('stock_movements')
            ->where('reference_table', 'stock_out_lines')
            ->where('reference_id', (int) $stockOutLine->id)
            ->where('product_id', $wrongProductId)
            ->whereNull('stock_item_id')
            ->where('movement_type', 'STOCK_OUT')
            ->lockForUpdate()
            ->get();
        $inboundMovements = DB::table('stock_movements')
            ->where('reference_table', 'stock_in_lines')
            ->where('reference_id', (int) $wrongStockInLine->id)
            ->where('product_id', $wrongProductId)
            ->whereNull('stock_item_id')
            ->where('movement_type', 'STOCK_IN')
            ->lockForUpdate()
            ->get();

        if ($outboundMovements->count() !== 1 || (int) $outboundMovements->first()->qty_out !== (int) $stockOutLine->qty) {
            throw new RuntimeException('The wrong Stock Out line must have exactly one matching non-serialized outbound movement.');
        }

        if ($inboundMovements->count() !== 1 || (int) $inboundMovements->first()->qty_in !== (int) $wrongStockInLine->received_qty) {
            throw new RuntimeException('The wrong Stock In line must have exactly one matching non-serialized inbound movement.');
        }

        $performedBy = $data['performed_by'] ?? (int) $outboundMovements->first()->performed_by;
        if ($performedBy <= 0 || ! DB::table('users')->where('id', $performedBy)->exists()) {
            throw new RuntimeException('The supplied performed-by user does not exist.');
        }

        $stockOutLineItemId = DB::table('stock_out_line_items')->insertGetId([
            'stock_out_line_id' => (int) $stockOutLine->id,
            'stock_item_id' => (int) $replacementStockItem->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sale_order_lines')->where('id', (int) $saleOrderLine->id)->update([
            'product_id' => $replacementProductId,
            'updated_at' => $now,
        ]);
        DB::table('stock_out_lines')->where('id', (int) $stockOutLine->id)->update([
            'product_id' => $replacementProductId,
            'updated_at' => $now,
        ]);
        DB::table('stock_items')->where('id', (int) $replacementStockItem->id)->update([
            'current_status' => 'DELIVERED',
            'is_available' => false,
            'last_movement_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('stock_movements')->where('id', (int) $outboundMovements->first()->id)->update([
            'product_id' => $replacementProductId,
            'stock_item_id' => (int) $replacementStockItem->id,
            'reference_table' => 'stock_out_line_items',
            'reference_id' => $stockOutLineItemId,
            'updated_at' => $now,
        ]);

        DB::table('stock_movements')->where('id', (int) $inboundMovements->first()->id)->delete();
        DB::table('stock_in_lines')->where('id', (int) $wrongStockInLine->id)->delete();
        DB::table('purchase_order_lines')->where('id', (int) $wrongPurchaseOrderLine->id)->delete();

        $remainingLines = DB::table('purchase_order_lines')
            ->where('purchase_order_id', (int) $wrongPurchaseOrderLine->purchase_order_id)
            ->lockForUpdate()
            ->get(['ordered_qty', 'received_qty']);

        $purchaseOrderStatus = $remainingLines->isNotEmpty() && $remainingLines->every(
            static fn (object $line): bool => (int) $line->received_qty >= (int) $line->ordered_qty,
        )
            ? 'COMPLETED'
            : ($remainingLines->contains(static fn (object $line): bool => (int) $line->received_qty > 0) ? 'PARTIAL' : 'ISSUED');

        DB::table('purchase_orders')->where('id', (int) $wrongPurchaseOrderLine->purchase_order_id)->update([
            'status' => $purchaseOrderStatus,
            'updated_at' => $now,
        ]);

        $this->stockBalanceUpdater->recomputeForProducts([$wrongProductId, $replacementProductId]);
        $this->auditLogger->log(
            userId: $performedBy,
            moduleName: 'Inventory',
            entityName: 'ProductDispatchCorrection',
            entityId: (int) $stockOutLine->id,
            action: AuditAction::Update,
            oldValues: [
                'wrong_product_id' => $wrongProductId,
                'sale_order_line_id' => (int) $saleOrderLine->id,
                'stock_out_line_id' => (int) $stockOutLine->id,
                'stock_in_line_id' => (int) $wrongStockInLine->id,
                'purchase_order_line_id' => (int) $wrongPurchaseOrderLine->id,
            ],
            newValues: [
                'replacement_product_id' => $replacementProductId,
                'replacement_stock_item_id' => (int) $replacementStockItem->id,
                'stock_out_line_item_id' => $stockOutLineItemId,
                'deleted_stock_in_line_id' => (int) $wrongStockInLine->id,
                'deleted_purchase_order_line_id' => (int) $wrongPurchaseOrderLine->id,
            ],
        );

        return [
            'wrong_product_id' => $wrongProductId,
            'replacement_product_id' => $replacementProductId,
            'sale_order_line_id' => (int) $saleOrderLine->id,
            'stock_out_line_id' => (int) $stockOutLine->id,
            'stock_out_line_item_id' => $stockOutLineItemId,
            'replacement_stock_item_id' => (int) $replacementStockItem->id,
            'deleted_stock_in_line_id' => (int) $wrongStockInLine->id,
            'deleted_purchase_order_line_id' => (int) $wrongPurchaseOrderLine->id,
            'purchase_order_status' => $purchaseOrderStatus,
        ];
    }
}
