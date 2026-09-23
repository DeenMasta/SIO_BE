<?php

namespace App\Console\Commands;

use App\Application\Support\AuditLogger;
use App\Domain\ReportingAudit\Enums\AuditAction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CorrectSaleOrderDispatchedQuantity extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sales-orders:correct-dispatched-quantity
        {so_number : The sales order number, for example SO-20260918-0005}
        {product_code : The product code on the line to correct, for example AT-P80}
        {ordered_qty : The corrected ordered quantity; it must equal the dispatched quantity}
        {--performed-by= : Optional users.id to record in the audit log; defaults to the order creator}
        {--yes : Skip the confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Lower an over-ordered, already-dispatched sales-order line and mark the order fulfilled';

    public function __construct(private readonly AuditLogger $auditLogger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $data = [
            'so_number' => trim((string) $this->argument('so_number')),
            'product_code' => trim((string) $this->argument('product_code')),
            'ordered_qty' => (int) $this->argument('ordered_qty'),
            'performed_by' => $this->option('performed-by') !== null
                ? (int) $this->option('performed-by')
                : null,
        ];

        if ($data['so_number'] === '' || $data['product_code'] === '' || $data['ordered_qty'] <= 0) {
            $this->error('Sales order number, product code, and a positive ordered quantity are required.');

            return self::FAILURE;
        }

        try {
            $preview = $this->inspect($data);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Field', 'Current', 'Corrected'],
            [
                ['Sales order', $preview['so_number'], $preview['so_number']],
                ['Product', $preview['product_code'], $preview['product_code']],
                ['Ordered quantity', (string) $preview['old_ordered_qty'], (string) $data['ordered_qty']],
                ['Fulfilled quantity', (string) $preview['fulfilled_qty'], (string) $preview['fulfilled_qty']],
                ['Line subtotal', $preview['old_subtotal'], $preview['new_subtotal']],
                ['Sales order status', $preview['old_status'], 'FULFILLED'],
            ],
        );

        if (! $this->option('yes') && ! $this->confirm(
            'Apply this sales-order correction? It does not create, reverse, or alter stock movements.',
            false,
        )) {
            $this->warn('Correction aborted.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(fn (): array => $this->correct($data));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Sales order %s is now FULFILLED. Line %d was corrected from %d to %d.',
            $result['so_number'],
            $result['sale_order_line_id'],
            $result['old_ordered_qty'],
            $result['ordered_qty'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{so_number:string, product_code:string, ordered_qty:int, performed_by:?int}  $data
     * @return array{so_number:string, product_code:string, old_ordered_qty:int, fulfilled_qty:int, old_subtotal:string, new_subtotal:string, old_status:string}
     */
    private function inspect(array $data): array
    {
        $records = $this->findLine($data, false);
        $this->validateCorrection($records['sale_order'], $records['line'], $records['all_lines'], $data['ordered_qty']);

        return [
            'so_number' => (string) $records['sale_order']->so_number,
            'product_code' => (string) $records['line']->product_code,
            'old_ordered_qty' => (int) $records['line']->ordered_qty,
            'fulfilled_qty' => (int) $records['line']->fulfilled_qty,
            'old_subtotal' => number_format((float) $records['line']->subtotal, 2, '.', ''),
            'new_subtotal' => $this->subtotal($data['ordered_qty'], (string) $records['line']->unit_price),
            'old_status' => (string) $records['sale_order']->status,
        ];
    }

    /**
     * @param  array{so_number:string, product_code:string, ordered_qty:int, performed_by:?int}  $data
     * @return array{so_number:string, sale_order_line_id:int, old_ordered_qty:int, ordered_qty:int}
     */
    private function correct(array $data): array
    {
        $records = $this->findLine($data, true);
        $saleOrder = $records['sale_order'];
        $line = $records['line'];
        $this->validateCorrection($saleOrder, $line, $records['all_lines'], $data['ordered_qty']);

        $performedBy = $data['performed_by'] ?? (int) $saleOrder->created_by;
        if ($performedBy <= 0 || ! DB::table('users')->where('id', $performedBy)->exists()) {
            throw new RuntimeException('The audit user does not exist. Supply a valid --performed-by user ID.');
        }

        $now = now();
        $oldOrderedQty = (int) $line->ordered_qty;
        $newSubtotal = $this->subtotal($data['ordered_qty'], (string) $line->unit_price);

        DB::table('sale_order_lines')->where('id', (int) $line->id)->update([
            'ordered_qty' => $data['ordered_qty'],
            'subtotal' => $newSubtotal,
            'updated_at' => $now,
        ]);
        DB::table('sale_orders')->where('id', (int) $saleOrder->id)->update([
            'status' => 'FULFILLED',
            'updated_at' => $now,
        ]);

        $this->auditLogger->log(
            userId: $performedBy,
            moduleName: 'SalesOutbound',
            entityName: 'SaleOrderDispatchedQuantityCorrection',
            entityId: (int) $saleOrder->id,
            action: AuditAction::Update,
            oldValues: [
                'so_number' => (string) $saleOrder->so_number,
                'sale_order_line_id' => (int) $line->id,
                'product_code' => (string) $line->product_code,
                'ordered_qty' => $oldOrderedQty,
                'fulfilled_qty' => (int) $line->fulfilled_qty,
                'subtotal' => (string) $line->subtotal,
                'status' => (string) $saleOrder->status,
            ],
            newValues: [
                'ordered_qty' => $data['ordered_qty'],
                'fulfilled_qty' => (int) $line->fulfilled_qty,
                'subtotal' => $newSubtotal,
                'status' => 'FULFILLED',
                'reason' => 'Corrected ordered quantity to match an already-dispatched quantity; no stock records changed.',
            ],
        );

        return [
            'so_number' => (string) $saleOrder->so_number,
            'sale_order_line_id' => (int) $line->id,
            'old_ordered_qty' => $oldOrderedQty,
            'ordered_qty' => $data['ordered_qty'],
        ];
    }

    /**
     * @param  array{so_number:string, product_code:string, ordered_qty:int, performed_by:?int}  $data
     * @return array{sale_order:object, line:object, all_lines:Collection<int, object>}
     */
    private function findLine(array $data, bool $lock): array
    {
        $saleOrderQuery = DB::table('sale_orders')->where('so_number', $data['so_number']);
        $saleOrder = $lock ? $saleOrderQuery->lockForUpdate()->first() : $saleOrderQuery->first();

        if ($saleOrder === null) {
            throw new RuntimeException(sprintf('Sales order %s was not found.', $data['so_number']));
        }

        $lineQuery = DB::table('sale_order_lines')
            ->join('products', 'products.id', '=', 'sale_order_lines.product_id')
            ->where('sale_order_lines.sale_order_id', (int) $saleOrder->id)
            ->where('products.product_code', $data['product_code'])
            ->select('sale_order_lines.*', 'products.product_code');
        $lines = $lock ? $lineQuery->lockForUpdate()->get() : $lineQuery->get();

        if ($lines->count() !== 1) {
            throw new RuntimeException($lines->isEmpty()
                ? sprintf('Product %s does not appear on sales order %s.', $data['product_code'], $data['so_number'])
                : sprintf('Product %s appears more than once on sales order %s; use a unique product line before correcting it.', $data['product_code'], $data['so_number']));
        }

        $allLinesQuery = DB::table('sale_order_lines')->where('sale_order_id', (int) $saleOrder->id);
        $allLines = $lock ? $allLinesQuery->lockForUpdate()->get() : $allLinesQuery->get();

        return ['sale_order' => $saleOrder, 'line' => $lines->first(), 'all_lines' => $allLines];
    }

    /**
     * @param  Collection<int, object>  $allLines
     */
    private function validateCorrection(object $saleOrder, object $line, $allLines, int $orderedQty): void
    {
        if (! in_array((string) $saleOrder->status, ['CONFIRMED', 'FULFILLED'], true)) {
            throw new RuntimeException('Only CONFIRMED or FULFILLED sales orders can be corrected.');
        }

        if ((int) $line->fulfilled_qty !== $orderedQty) {
            throw new RuntimeException(sprintf(
                'Corrected ordered quantity must exactly equal the fulfilled quantity (%d).',
                (int) $line->fulfilled_qty,
            ));
        }

        if ((int) $line->ordered_qty <= $orderedQty) {
            throw new RuntimeException(sprintf(
                'This command only lowers an over-ordered line. Current ordered quantity is %d.',
                (int) $line->ordered_qty,
            ));
        }

        $otherUnfulfilledLine = $allLines->first(function (object $saleOrderLine) use ($line): bool {
            return (int) $saleOrderLine->id !== (int) $line->id
                && (int) $saleOrderLine->fulfilled_qty < (int) $saleOrderLine->ordered_qty;
        });

        if ($otherUnfulfilledLine !== null) {
            throw new RuntimeException(sprintf(
                'Sales order cannot be marked FULFILLED because line %d is still only %d of %d fulfilled.',
                (int) $otherUnfulfilledLine->id,
                (int) $otherUnfulfilledLine->fulfilled_qty,
                (int) $otherUnfulfilledLine->ordered_qty,
            ));
        }
    }

    private function subtotal(int $quantity, string $unitPrice): string
    {
        return number_format($quantity * (float) $unitPrice, 2, '.', '');
    }
}
