<?php

namespace App\Application\PurchasingInbound\StockIn\UseCases;

use App\Application\Contracts\UseCase;
use App\Models\QcDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListQcDocumentsUseCase implements UseCase
{
    /**
     * @return LengthAwarePaginator<QcDocument>
     */
    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $data = (array) $payload;
        $perPage = (int) ($data['per_page'] ?? 15);

        return QcDocument::query()
            ->with(['pic'])
            ->withCount('checks')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
