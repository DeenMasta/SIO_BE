<?php

namespace App\Application\Contracts\Repositories;

use App\Models\SaleOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SaleOrderRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function findOrFail(int $id): SaleOrder;

    public function createWithLines(array $data): SaleOrder;

    public function update(SaleOrder $so, array $data): SaleOrder;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function appendLines(SaleOrder $so, array $lines): SaleOrder;

    public function delete(SaleOrder $so): void;
}
