<?php

namespace App\Http\Controllers\Api\PurchasingInbound;

use App\Application\Contracts\Repositories\StockInRepository;
use App\Application\PurchasingInbound\StockIn\UseCases\ListStockInsUseCase;
use App\Application\PurchasingInbound\StockIn\UseCases\PostStockInUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\DocumentNumberGenerator;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PurchasingInbound\StockIn\PostStockInRequest;
use App\Http\Resources\Api\PurchasingInbound\StockInResource;
use App\Models\StockIn;
use App\Models\StockItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockInController extends Controller
{
    public function __construct(
        private readonly ListStockInsUseCase $listStockIns,
        private readonly PostStockInUseCase $postStockIn,
        private readonly StockInRepository $stockIns,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockIn::class);

        $stockIns = $this->listStockIns->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            StockInResource::collection($stockIns->items()),
            'Stock in records retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $stockIns->currentPage(),
                    'per_page' => $stockIns->perPage(),
                    'total' => $stockIns->total(),
                    'last_page' => $stockIns->lastPage(),
                ],
            ],
        );
    }

    public function store(PostStockInRequest $request): JsonResponse
    {
        $this->authorize('create', StockIn::class);

        $payload = $request->validated();
        $payload['stock_in_pic_id'] = (int) $request->user()->id;
        $payload['stock_in_number'] = trim((string) ($payload['stock_in_number'] ?? '')) !== ''
            ? trim((string) $payload['stock_in_number'])
            : $this->documentNumberGenerator->generateStockInNumber();

        $stockIn = $this->postStockIn->execute($payload);

        return ApiResponse::success(new StockInResource($stockIn), 'Stock in received successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $stockIn = $this->stockIns->findOrFail($id);
        $this->authorize('view', $stockIn);

        return ApiResponse::success(new StockInResource($stockIn), 'Stock in retrieved successfully.');
    }

    /**
     * Returns all PENDING QC stock items that belong to the given stock-in session.
     * Used by the QC module to auto-populate the QC checklist.
     */
    public function pendingQcItems(int $id): JsonResponse
    {
        $stockIn = $this->stockIns->findOrFail($id);
        $this->authorize('view', $stockIn);

        $items = StockItem::query()
            ->with(['product.conditions', 'product.accessories'])
            ->whereHas('stockInLine', fn ($q) => $q->where('stock_in_id', $id))
            ->where('qc_status', StockItemQcStatus::Pending->value)
            ->orderBy('id')
            ->get();

        $mapped = $items->map(fn (StockItem $item): array => [
            'id'             => $item->id,
            'serial_number'  => $item->serial_number,
            'product_id'     => $item->product_id,
            'product_name'   => $item->product?->product_name,
            'qc_status'      => $item->qc_status?->value,
            'conditions'     => ($item->product?->conditions ?? collect())
                ->map(fn ($c): string => (string) $c->condition_name)
                ->filter(fn (string $v): bool => $v !== '')
                ->values()
                ->toArray(),
            'accessories'    => ($item->product?->accessories ?? collect())
                ->map(fn ($a): string => (string) $a->accessory_name)
                ->filter(fn (string $v): bool => $v !== '')
                ->values()
                ->toArray(),
        ]);

        return ApiResponse::success($mapped, 'Pending QC items for stock-in retrieved successfully.');
    }
}
