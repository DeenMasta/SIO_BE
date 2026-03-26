<?php

namespace App\Http\Resources\Api\ReportingAudit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    private const REDACTED_VALUE = '[REDACTED]';

    private const SENSITIVE_KEY_PATTERNS = [
        'password',
        'token',
        'secret',
        'authorization',
        'api_key',
        'apikey',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => $this->user_id !== null ? (int) $this->user_id : null,
            'module_name' => (string) $this->module_name,
            'entity_name' => (string) $this->entity_name,
            'entity_id' => $this->entity_id !== null ? (int) $this->entity_id : null,
            'action' => is_object($this->action) ? (string) $this->action->value : (string) $this->action,
            'old_values' => self::redact($this->old_values),
            'new_values' => self::redact($this->new_values),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return self::redactRecursive($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function redactRecursive(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower($key) : '';

            if (self::isSensitiveKey($normalizedKey)) {
                $redacted[$key] = self::REDACTED_VALUE;
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = self::redactRecursive($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
