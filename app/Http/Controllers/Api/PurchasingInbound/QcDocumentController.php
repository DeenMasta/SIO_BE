<?php

namespace App\Http\Controllers\Api\PurchasingInbound;

use App\Application\PurchasingInbound\StockIn\UseCases\ListQcDocumentsUseCase;
use App\Application\PurchasingInbound\StockIn\UseCases\PostQcDocumentUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Application\Support\DocumentNumberGenerator;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PurchasingInbound\StockIn\PostQcDocumentRequest;
use App\Http\Requests\Api\PurchasingInbound\StockIn\UpdateQcDocumentRequest;
use App\Http\Resources\Api\PurchasingInbound\QcDocumentResource;
use App\Models\QcCheck;
use App\Models\QcDocument;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QcDocumentController extends Controller
{
    public function __construct(
        private readonly ListQcDocumentsUseCase $listQcDocuments,
        private readonly PostQcDocumentUseCase $postQcDocument,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        // For now, no strict policy enforcement on list
        $documents = $this->listQcDocuments->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            QcDocumentResource::collection($documents->items()),
            'QC Documents retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $documents->currentPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'last_page' => $documents->lastPage(),
                ],
            ],
        );
    }

    public function store(PostQcDocumentRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['pic_id'] = (int) $request->user()->id;
        $payload['document_number'] = trim((string) ($payload['document_number'] ?? '')) !== ''
            ? trim((string) $payload['document_number'])
            : $this->documentNumberGenerator->generateQcDocumentNumber();

        $qcDocument = $this->postQcDocument->execute($payload);

        return ApiResponse::success(new QcDocumentResource($qcDocument), 'QC Document created successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $document = QcDocument::query()
            ->with(['checks.stockItem.product', 'pic'])
            ->findOrFail($id);

        return ApiResponse::success(new QcDocumentResource($document), 'QC Document retrieved successfully.');
    }

    public function update(UpdateQcDocumentRequest $request, int $id): JsonResponse
    {
        $document = QcDocument::query()->findOrFail($id);
        $payload = $request->validated();
        $lines = array_values((array) ($payload['lines'] ?? []));
        $performedBy = (int) $request->user()->id;

        $oldValues = [
            'date' => $document->date?->format('Y-m-d'),
            'remarks' => $document->remarks,
        ];

        DB::transaction(function () use ($document, $payload, $lines, $performedBy): void {
            $hasChanges = false;

            $document->update([
                'date' => (string) ($payload['date'] ?? $document->date?->format('Y-m-d')),
                'remarks' => isset($payload['remarks']) && trim((string) $payload['remarks']) !== ''
                    ? trim((string) $payload['remarks'])
                    : null,
            ]);

            if ($document->wasChanged(['date', 'remarks'])) {
                $hasChanges = true;
            }

            $checkIds = array_map(static fn (array $line): int => (int) $line['id'], $lines);

            $checks = QcCheck::query()
                ->where('qc_document_id', $document->id)
                ->whereIn('id', $checkIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($checks->count() !== count($checkIds)) {
                $existingIds = $checks->keys()->all();
                $missingIds = array_values(array_diff($checkIds, $existingIds));

                throw ValidationException::withMessages([
                    'lines' => ['One or more QC line IDs do not belong to this document: '.implode(', ', $missingIds)],
                ]);
            }

            $stockItemIds = $checks->pluck('stock_item_id')->all();

            $stockItems = StockItem::query()
                ->with(['product.conditions', 'product.accessories'])
                ->whereIn('id', $stockItemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                $check = $checks->get((int) $line['id']);
                if (! $check) {
                    continue;
                }

                $stockItem = $stockItems->get((int) $check->stock_item_id);
                if (! $stockItem) {
                    continue;
                }

                $conditionOptions = $this->extractConditionOptions($stockItem);
                $accessoryOptions = $this->extractAccessoryOptions($stockItem);

                $checkedConditions = $this->filterCheckedValues(
                    (array) ($line['checked_conditions'] ?? []),
                    $conditionOptions,
                );

                $checkedAccessories = $this->filterCheckedValues(
                    (array) ($line['checked_accessories'] ?? []),
                    $accessoryOptions,
                );

                $result = $this->calculateResultStatus(
                    count($checkedConditions),
                    count($checkedAccessories),
                    count($conditionOptions),
                    count($accessoryOptions),
                );

                $lineRemarks = isset($line['remarks']) && trim((string) $line['remarks']) !== ''
                    ? trim((string) $line['remarks'])
                    : null;

                $hadLineChanges =
                    $check->result !== $result
                    || ! $this->sameStringArray((array) $check->checked_conditions, $checkedConditions)
                    || ! $this->sameStringArray((array) $check->checked_accessories, $checkedAccessories)
                    || trim((string) ($check->remarks ?? '')) !== trim((string) ($lineRemarks ?? ''));

                $check->update([
                    'result' => $result->value,
                    'checked_at' => now(),
                    'checked_conditions' => $checkedConditions,
                    'checked_accessories' => $checkedAccessories,
                    'remarks' => $lineRemarks,
                ]);

                if ($hadLineChanges) {
                    $hasChanges = true;
                }

                $stockItem->qc_status = $result;
                if ($result === StockItemQcStatus::Passed) {
                    $stockItem->is_available = true;
                } else {
                    $stockItem->is_available = false;
                }

                $stockItem->save();

                $movementType = $result === StockItemQcStatus::Passed
                    ? MovementType::QcPass
                    : MovementType::QcFail;

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id'        => $stockItem->product_id,
                    'stock_item_id'     => $stockItem->id,
                    'movement_type'     => $movementType,
                    'reference_table'   => 'qc_items',
                    'reference_id'      => (int) $check->id,
                    'qty_in'            => 0,
                    'qty_out'           => 0,
                    'from_status'       => $stockItem->current_status->value,
                    'to_status'         => $stockItem->current_status->value,
                    'performed_by'      => $performedBy,
                    'remarks'           => $lineRemarks,
                ]);
            }

            if ($hasChanges && ! $document->wasChanged(['date', 'remarks'])) {
                $document->touch();
            }
        });

        $this->auditLogger->log(
            userId: $performedBy,
            moduleName: 'PurchasingInbound',
            entityName: 'QcDocument',
            entityId: (int) $document->id,
            action: AuditAction::Update,
            oldValues: $oldValues,
            newValues: [
                'date' => (string) ($payload['date'] ?? $document->date?->format('Y-m-d')),
                'remarks' => isset($payload['remarks']) ? trim((string) $payload['remarks']) : $document->remarks,
                'total_lines' => count($lines),
            ],
        );

        $updatedDocument = QcDocument::query()
            ->with(['checks.stockItem.product', 'pic'])
            ->findOrFail($document->id);

        return ApiResponse::success(new QcDocumentResource($updatedDocument), 'QC Document updated successfully.');
    }

    /**
     * @return array<int, string>
     */
    private function extractConditionOptions(StockItem $stockItem): array
    {
        $product = $stockItem->product;
        if (! $product) {
            return [];
        }

        $values = $product->conditions
            ->map(fn ($item): string => trim((string) $item->condition_name))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();

        return array_values(array_unique($values));
    }

    /**
     * @return array<int, string>
     */
    private function extractAccessoryOptions(StockItem $stockItem): array
    {
        $product = $stockItem->product;
        if (! $product) {
            return [];
        }

        $values = $product->accessories
            ->map(fn ($item): string => trim((string) $item->accessory_name))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();

        return array_values(array_unique($values));
    }

    /**
     * @param  array<int, mixed>  $checked
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    private function filterCheckedValues(array $checked, array $options): array
    {
        if ($options === []) {
            return [];
        }

        $normalizedChecked = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $checked,
        ), static fn (string $value): bool => $value !== '')));

        return array_values(array_intersect($normalizedChecked, $options));
    }

    private function calculateResultStatus(
        int $checkedConditionCount,
        int $checkedAccessoryCount,
        int $totalConditionCount,
        int $totalAccessoryCount,
    ): StockItemQcStatus {
        $totalChecked = $checkedConditionCount + $checkedAccessoryCount;
        $totalPossible = $totalConditionCount + $totalAccessoryCount;

        if ($totalPossible === 0) {
            return StockItemQcStatus::Passed;
        }

        if ($totalChecked === $totalPossible) {
            return StockItemQcStatus::Passed;
        }

        if ($totalChecked > 0) {
            return StockItemQcStatus::Partial;
        }

        return StockItemQcStatus::Failed;
    }

    /**
     * @param  array<int, mixed>  $left
     * @param  array<int, mixed>  $right
     */
    private function sameStringArray(array $left, array $right): bool
    {
        $normalizedLeft = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $left,
        ), static fn (string $value): bool => $value !== '')));

        $normalizedRight = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $right,
        ), static fn (string $value): bool => $value !== '')));

        sort($normalizedLeft);
        sort($normalizedRight);

        return $normalizedLeft === $normalizedRight;
    }
}
