<?php

namespace App\Application\Contracts\Repositories;

use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PurchaseOrderRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function findOrFail(int $id): PurchaseOrder;

    public function createWithLines(array $data): PurchaseOrder;

    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder;

    public function delete(PurchaseOrder $purchaseOrder): void;
}
