<?php

namespace App\Application\QcOutbound\QcTransactions\UseCases;

use App\Application\Contracts\Repositories\QcTransactionRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListQcTransactionsUseCase implements UseCase
{
    public function __construct(private readonly QcTransactionRepository $qcTransactions)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $perPage = is_array($payload) ? (int) ($payload['per_page'] ?? 15) : 15;

        return $this->qcTransactions->paginate($perPage > 0 ? $perPage : 15);
    }
}
