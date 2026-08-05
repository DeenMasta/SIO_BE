<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Application\Inventory\UseCases\ResolveMissingItemReportUseCase;
use App\Application\Inventory\UseCases\UpdateMissingItemReportInvestigationUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Inventory\MissingItemReport\ResolveMissingItemReportRequest;
use App\Http\Requests\Api\Inventory\MissingItemReport\UpdateInvestigationRequest;
use App\Http\Resources\Api\Inventory\MissingItemReportResource;
use App\Models\MissingItemReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MissingItemReportController extends Controller
{
    public function __construct(private readonly UpdateMissingItemReportInvestigationUseCase $updateInvestigation, private readonly ResolveMissingItemReportUseCase $resolveReport) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MissingItemReport::class);
        $records = MissingItemReport::query()->with(['product', 'stockItem', 'stockOut', 'stocktake', 'reportedByUser', 'resolvedByUser'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->latest('id')->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::success(MissingItemReportResource::collection($records->items()), 'Missing-item reports retrieved successfully.', meta: ['pagination' => ['current_page' => $records->currentPage(), 'per_page' => $records->perPage(), 'total' => $records->total(), 'last_page' => $records->lastPage()]]);
    }

    public function show(int $id): JsonResponse
    {
        $report = $this->detail($id);
        $this->authorize('view', $report);

        return ApiResponse::success(new MissingItemReportResource($report), 'Missing-item report retrieved successfully.');
    }

    public function investigate(UpdateInvestigationRequest $request, int $id): JsonResponse
    {
        $report = MissingItemReport::query()->findOrFail($id);
        $this->authorize('investigate', $report);
        $result = $this->updateInvestigation->execute([...$request->validated(), 'report_id' => $report->id, 'updated_by' => (int) $request->user()->id]);

        return ApiResponse::success(new MissingItemReportResource($result), 'Investigation notes saved.');
    }

    public function resolve(ResolveMissingItemReportRequest $request, int $id): JsonResponse
    {
        $report = MissingItemReport::query()->findOrFail($id);
        $this->authorize('resolve', $report);
        $result = $this->resolveReport->execute([...$request->validated(), 'report_id' => $report->id, 'resolved_by' => (int) $request->user()->id]);

        return ApiResponse::success(new MissingItemReportResource($result), 'Missing-item report resolved and fully audited.');
    }

    private function detail(int $id): MissingItemReport
    {
        return MissingItemReport::query()->with(['product', 'stockItem', 'stockOut', 'stocktake', 'reportedByUser', 'resolvedByUser'])->findOrFail($id);
    }
}
