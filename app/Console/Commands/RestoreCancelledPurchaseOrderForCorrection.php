<?php

namespace App\Console\Commands;

use App\Application\Support\AuditLogger;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RestoreCancelledPurchaseOrderForCorrection extends Command
{
    /**
     * @var string
     */
    protected $signature = 'purchase-orders:restore-cancelled-for-correction
                            {purchase_order_id : The CANCELLED purchase_orders.id to restore}
                            {--performed-by= : Optional users.id for the audit entry}
                            {--dry-run : Show the status that would be restored without saving}
                            {--yes : Skip the confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Restore a cancelled PO to the status implied by its recorded receipts so it can be corrected safely.';

    public function __construct(private readonly AuditLogger $auditLogger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $purchaseOrderId = (int) $this->argument('purchase_order_id');
        $performedBy = $this->option('performed-by') !== null
            ? (int) $this->option('performed-by')
            : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($purchaseOrderId < 1) {
            $this->error('purchase_order_id must be a positive integer.');

            return self::FAILURE;
        }

        if ($performedBy !== null && $performedBy < 1) {
            $this->error('--performed-by must be a positive users.id.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->option('yes') && ! $this->confirm(
            'This reopens only the selected CANCELLED PO. Posted receipts and stock items are not changed. Continue?',
            false,
        )) {
            $this->warn('Restore aborted.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(fn (): array => $this->restore(
                $purchaseOrderId,
                $performedBy,
                $dryRun,
            ));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'No records changed.' : 'Cancelled purchase order restored for correction.');
        $this->table(
            ['Field', 'Value'],
            [
                ['purchase_order_id', (string) $result['purchase_order_id']],
                ['po_number', $result['po_number']],
                ['status', $result['status']],
                ['line_count', (string) $result['line_count']],
                ['received_line_count', (string) $result['received_line_count']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Restore only the PO lifecycle state. It intentionally never changes
     * purchase_order_lines, stock_in_lines, stock_items, or movements.
     *
     * @return array{
     *     purchase_order_id:int,
     *     po_number:string,
     *     status:string,
     *     line_count:int,
     *     received_line_count:int
     * }
     */
    private function restore(int $purchaseOrderId, ?int $performedBy, bool $dryRun): array
    {
        /** @var PurchaseOrder $purchaseOrder */
        $purchaseOrder = PurchaseOrder::query()
            ->with('lines')
            ->lockForUpdate()
            ->findOrFail($purchaseOrderId);

        if ($purchaseOrder->status !== PurchaseOrderStatus::Cancelled) {
            throw new RuntimeException(sprintf(
                'PO %s is %s, not CANCELLED. Only cancelled POs can be restored with this command.',
                $purchaseOrder->po_number,
                $purchaseOrder->status?->value ?? 'UNKNOWN',
            ));
        }

        if ($purchaseOrder->lines->isEmpty()) {
            throw new RuntimeException(sprintf('PO %s has no lines and cannot be restored.', $purchaseOrder->po_number));
        }

        $status = $this->statusImpliedByReceipts($purchaseOrder);
        $receivedLineCount = $purchaseOrder->lines
            ->filter(static fn (PurchaseOrderLine $line): bool => (int) $line->received_qty > 0)
            ->count();

        if (! $dryRun) {
            $purchaseOrder->update(['status' => $status]);
            $this->auditLogger->log(
                userId: $performedBy,
                moduleName: 'PurchasingInbound',
                entityName: 'PurchaseOrder',
                entityId: (int) $purchaseOrder->id,
                action: AuditAction::Update,
                oldValues: ['status' => PurchaseOrderStatus::Cancelled->value],
                newValues: [
                    'status' => $status->value,
                    'transition' => 'restore-for-correction',
                    'reason' => 'Restored cancelled PO without changing receipt or stock records.',
                ],
            );
        }

        return [
            'purchase_order_id' => (int) $purchaseOrder->id,
            'po_number' => $purchaseOrder->po_number,
            'status' => $status->value,
            'line_count' => $purchaseOrder->lines->count(),
            'received_line_count' => $receivedLineCount,
        ];
    }

    private function statusImpliedByReceipts(PurchaseOrder $purchaseOrder): PurchaseOrderStatus
    {
        if ($purchaseOrder->lines->every(
            static fn (PurchaseOrderLine $line): bool => (int) $line->received_qty >= (int) $line->ordered_qty,
        )) {
            return PurchaseOrderStatus::Completed;
        }

        return $purchaseOrder->lines->contains(
            static fn (PurchaseOrderLine $line): bool => (int) $line->received_qty > 0,
        )
            ? PurchaseOrderStatus::Partial
            : PurchaseOrderStatus::Issued;
    }
}
