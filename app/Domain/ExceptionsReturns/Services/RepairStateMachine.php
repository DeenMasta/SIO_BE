<?php

namespace App\Domain\ExceptionsReturns\Services;

use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use Illuminate\Validation\ValidationException;

/**
 * RepairStateMachine enforces strict state transitions for repairs.
 *
 * State Diagram:
 *   OPEN → IN_PROGRESS → COMPLETED
 *       ↓
 *   CANCELLED (from OPEN or IN_PROGRESS)
 */
class RepairStateMachine
{
    /**
     * Transition map: current_status_value => [allowed_next_status_values]
     */
    private const array TRANSITIONS = [
        'OPEN' => ['IN_PROGRESS', 'CANCELLED', 'COMPLETED'],
        'IN_PROGRESS' => ['COMPLETED', 'CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    /**
     * Check if a transition is allowed.
     */
    public static function isAllowed(RepairStatus $currentStatus, RepairStatus $newStatus): bool
    {
        $currentValue = $currentStatus->value;
        $newValue = $newStatus->value;
        $allowed = self::TRANSITIONS[$currentValue] ?? [];

        return in_array($newValue, $allowed, true);
    }

    /**
     * Get allowed transitions from current status.
     *
     * @return array<RepairStatus>
     */
    public static function allowedTransitions(RepairStatus $currentStatus): array
    {
        $currentValue = $currentStatus->value;
        $allowedValues = self::TRANSITIONS[$currentValue] ?? [];

        return array_map(fn ($value) => RepairStatus::from($value), $allowedValues);
    }

    /**
     * Validate transition or throw ValidationException.
     */
    public static function validateTransition(RepairStatus $currentStatus, RepairStatus $newStatus): void
    {
        if (! self::isAllowed($currentStatus, $newStatus)) {
            throw ValidationException::withMessages([
                'repair_status' => [
                    'Invalid repair status transition from '.$currentStatus->value.' to '.$newStatus->value.'.',
                ],
            ]);
        }
    }
}
