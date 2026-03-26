<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\ReportingAudit\Dashboard\UseCases\GetDashboardSummaryUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportingAudit\Dashboard\DashboardSummaryRequest;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly GetDashboardSummaryUseCase $dashboardSummary)
    {
    }

    public function index(DashboardSummaryRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->dashboardSummary->execute($request->validated()),
            'Dashboard summary retrieved successfully.'
        );
    }
}
