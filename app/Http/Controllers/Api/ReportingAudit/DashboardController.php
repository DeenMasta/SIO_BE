<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\ReportingAudit\Dashboard\UseCases\GetDashboardSummaryUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly GetDashboardSummaryUseCase $dashboardSummary)
    {
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->dashboardSummary->execute(), 'Dashboard summary retrieved successfully.');
    }
}
