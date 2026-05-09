<?php

namespace App\Domain\ExceptionsReturns\Services;

use App\Domain\ExceptionsReturns\Enums\RepairFlow;
use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use Illuminate\Validation\ValidationException;

class RepairStateMachine
{
    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private const array TRANSITIONS = [
        RepairFlow::Internal->value => [
            RepairStatus::Open->value => [
                RepairStatus::InProgress->value,
                RepairStatus::Cancelled->value,
                RepairStatus::Completed->value,
            ],
            RepairStatus::InProgress->value => [
                RepairStatus::Completed->value,
                RepairStatus::Cancelled->value,
            ],
            RepairStatus::ReadyToReturn->value => [],
            RepairStatus::Completed->value => [],
            RepairStatus::ReturnedToCustomer->value => [],
            RepairStatus::Cancelled->value => [],
        ],
        RepairFlow::Customer->value => [
            RepairStatus::Open->value => [
                RepairStatus::InProgress->value,
                RepairStatus::Cancelled->value,
                RepairStatus::ReadyToReturn->value,
            ],
            RepairStatus::InProgress->value => [
                RepairStatus::ReadyToReturn->value,
                RepairStatus::Cancelled->value,
            ],
            RepairStatus::ReadyToReturn->value => [
                RepairStatus::ReturnedToCustomer->value,
                RepairStatus::Cancelled->value,
            ],
            RepairStatus::Completed->value => [],
            RepairStatus::ReturnedToCustomer->value => [],
            RepairStatus::Cancelled->value => [],
        ],
    ];

    public static function isAllowed(RepairFlow $flow, RepairStatus $currentStatus, RepairStatus $newStatus): bool
    {
        $allowed = self::TRANSITIONS[$flow->value][$currentStatus->value] ?? [];

        return in_array($newStatus->value, $allowed, true);
    }

    /**
     * @return array<RepairStatus>
     */
    public static function allowedTransitions(RepairFlow $flow, RepairStatus $currentStatus): array
    {
        $allowedValues = self::TRANSITIONS[$flow->value][$currentStatus->value] ?? [];

        return array_map(fn (string $value): RepairStatus => RepairStatus::from($value), $allowedValues);
    }

    public static function validateTransition(RepairFlow $flow, RepairStatus $currentStatus, RepairStatus $newStatus): void
    {
        if (! self::isAllowed($flow, $currentStatus, $newStatus)) {
            throw ValidationException::withMessages([
                'repair_status' => [
                    'Invalid repair status transition for '.$flow->value.' repair from '.$currentStatus->value.' to '.$newStatus->value.'.',
                ],
            ]);
        }
    }
}
