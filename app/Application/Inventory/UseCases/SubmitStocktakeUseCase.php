<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\AuditLogger;
use App\Application\Support\DocumentNumberGenerator;
use App\Application\Support\UserNotificationService;
use App\Domain\InventoryCore\Enums\MissingItemReportStatus;
use App\Domain\InventoryCore\Enums\StocktakeStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\MissingItemReport;
use App\Models\Stocktake;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitStocktakeUseCase implements UseCase
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $auditLogger,
        private readonly UserNotificationService $notifications,
        private readonly LowStockAlertService $lowStock,
    ) {}

    public function execute(mixed $payload = null): Stocktake
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): Stocktake {
            $stocktake = Stocktake::query()->with(['lines.product', 'lines.items'])->lockForUpdate()->findOrFail($data['stocktake_id']);
            if ($stocktake->status !== StocktakeStatus::Counting) {
                throw ValidationException::withMessages(['stocktake' => ['Only a stocktake in COUNTING status can be submitted.']]);
            }

            $beforeLowStockSnapshot = $this->lowStock->snapshotForProducts($stocktake->lines->pluck('product_id')->all());

            $submittedLines = collect($data['lines'])->keyBy(fn (array $line) => (int) $line['line_id']);
            if ($submittedLines->count() !== $stocktake->lines->count() || $stocktake->lines->contains(fn ($line) => ! $submittedLines->has($line->id))) {
                throw ValidationException::withMessages(['lines' => ['A count is required for every stocktake line.']]);
            }

            $shortages = 0;
            $shortageProductIds = [];
            foreach ($stocktake->lines as $line) {
                $input = $submittedLines->get($line->id);
                $countedQty = (int) $input['counted_qty'];
                $isSerialized = $line->items->isNotEmpty();

                if ($isSerialized) {
                    $countedIds = array_map('intval', $input['counted_stock_item_ids'] ?? []);
                    if (count($countedIds) !== count(array_unique($countedIds)) || count($countedIds) !== $countedQty) {
                        throw ValidationException::withMessages(['lines' => ["Serialized line {$line->id} needs one unique registered serial for each counted unit."]]);
                    }
                    $validIds = $line->items->pluck('stock_item_id')->map(fn ($id) => (int) $id)->all();
                    if (array_diff($countedIds, $validIds) !== []) {
                        throw ValidationException::withMessages(['lines' => ["A serial on line {$line->id} was not in this stocktake snapshot."]]);
                    }
                    $missingIds = array_values(array_diff($validIds, $countedIds));
                    $line->items()->update(['is_counted' => false]);
                    $line->items()->whereIn('stock_item_id', $countedIds)->update(['is_counted' => true]);
                }

                $variance = $countedQty - (int) $line->expected_qty;
                $line->update(['counted_qty' => $countedQty, 'variance_qty' => $variance, 'remarks' => $input['remarks'] ?? null]);
                if ($variance >= 0) {
                    continue;
                }

                $shortages += abs($variance);
                $shortageProductIds[] = (int) $line->product_id;
                if ($isSerialized) {
                    $line->items->whereIn('stock_item_id', $missingIds)->each(function ($item) use ($stocktake, $line, $data): void {
                        MissingItemReport::query()->create([
                            'report_number' => $this->numbers->generateMissingItemReportNumber(),
                            'stocktake_id' => $stocktake->id,
                            'stocktake_line_id' => $line->id,
                            'product_id' => $line->product_id,
                            'stock_item_id' => $item->stock_item_id,
                            'missing_qty' => 1,
                            'status' => MissingItemReportStatus::Open,
                            'reported_by' => $data['submitted_by'],
                        ]);
                    });
                } else {
                    MissingItemReport::query()->create([
                        'report_number' => $this->numbers->generateMissingItemReportNumber(),
                        'stocktake_id' => $stocktake->id,
                        'stocktake_line_id' => $line->id,
                        'product_id' => $line->product_id,
                        'missing_qty' => abs($variance),
                        'status' => MissingItemReportStatus::Open,
                        'reported_by' => $data['submitted_by'],
                    ]);
                }
            }

            $stocktake->update(['status' => StocktakeStatus::Submitted, 'submitted_by' => $data['submitted_by'], 'submitted_at' => now()]);
            $this->auditLogger->log((int) $data['submitted_by'], 'StockManagement', 'Stocktake', (int) $stocktake->id, AuditAction::Post, newValues: ['shortage_qty' => $shortages]);
            if ($shortages > 0) {
                $this->lowStock->notifyStatusTransitions($beforeLowStockSnapshot, array_values(array_unique($shortageProductIds)), (int) $data['submitted_by']);
                $this->notifications->notifyAllActiveUsers('stocktake.shortage-reported', 'Stocktake shortages need investigation', "{$shortages} unit(s) were missing in stocktake {$stocktake->stocktake_number}.", ['stocktake_id' => $stocktake->id, 'shortage_qty' => $shortages], (int) $data['submitted_by'], 'warning');
            }

            return $stocktake->fresh(['lines.product', 'lines.items', 'missingReports.product', 'missingReports.stockItem', 'createdByUser', 'submittedByUser']);
        });
    }
}
