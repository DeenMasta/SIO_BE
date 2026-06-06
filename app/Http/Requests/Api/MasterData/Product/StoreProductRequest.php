<?php

namespace App\Http\Requests\Api\MasterData\Product;

use App\Domain\MasterData\Enums\ProductType;
use App\Domain\MasterData\Enums\RecordStatus;
use App\Http\Requests\Api\StrictFormRequest;
use App\Models\ProductCondition;
use Illuminate\Validation\Rule;

class StoreProductRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Product::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:products,product_code'],
            'product_name' => ['required', 'string', 'max:150'],
            'product_model' => ['nullable', 'string', 'max:150'],
            'product_type' => ['required', Rule::enum(ProductType::class)],
            'requires_serial_number' => ['required', 'boolean'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'uom' => ['required', 'string', 'max:20'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
            'accessories' => ['nullable', 'array', 'max:50'],
            'accessories.*.accessory_name' => ['required_with:accessories', 'string', 'max:150'],
            'accessories.*.quantity' => ['nullable', 'integer', 'min:1'],
            'accessories.*.remarks' => ['nullable', 'string', 'max:500'],
            'conditions' => ['nullable', 'array', 'max:50'],
            'conditions.*.condition_name' => ['required_with:conditions', 'string', Rule::in(ProductCondition::availableConditions())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('selling_price')) {
            return;
        }

        $this->merge([
            'selling_price' => $this->normalizeSellingPrice($this->input('selling_price')),
        ]);
    }

    private function normalizeSellingPrice(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return 0;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            return str_replace(',', '', $normalized);
        }

        if (str_contains($normalized, ',')) {
            return str_replace(',', '.', $normalized);
        }

        return $normalized;
    }

    protected function allowedFields(): array
    {
        return [
            'product_code',
            'product_name',
            'product_model',
            'product_type',
            'requires_serial_number',
            'supplier_id',
            'selling_price',
            'uom',
            'reorder_level',
            'remarks',
            'status',
            'accessories',
            'conditions',
        ];
    }
}
