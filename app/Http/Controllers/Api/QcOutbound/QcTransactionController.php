<?php

namespace App\Http\Controllers\Api\QcOutbound;

use App\Application\Contracts\Repositories\QcTransactionRepository;
use App\Application\QcOutbound\QcTransactions\UseCases\ListQcTransactionsUseCase;
use App\Application\QcOutbound\QcTransactions\UseCases\PostQcTransactionUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\QcOutbound\QcTransaction\StoreQcTransactionRequest;
use App\Http\Resources\Api\QcOutbound\QcTransactionResource;
use App\Models\QcTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QcTransactionController extends Controller
{
    public function __construct(
        private readonly ListQcTransactionsUseCase $listQcTransactions,
        private readonly PostQcTransactionUseCase $postQcTransaction,
        private readonly QcTransactionRepository $qcTransactions,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', QcTransaction::class);

        $records = $this->listQcTransactions->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            QcTransactionResource::collection($records->items()),
            'QC transactions retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
        );
    }

    public function store(StoreQcTransactionRequest $request): JsonResponse
    {
        $this->authorize('create', QcTransaction::class);

        $payload = $request->validated();
        $payload['qc_by'] = (int) $request->user()->id;

        $qcTransaction = $this->postQcTransaction->execute($payload);

        return ApiResponse::success(new QcTransactionResource($qcTransaction), 'QC transaction posted successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $qcTransaction = $this->qcTransactions->findOrFail($id);
        $this->authorize('view', $qcTransaction);

        return ApiResponse::success(new QcTransactionResource($qcTransaction), 'QC transaction retrieved successfully.');
    }
}
