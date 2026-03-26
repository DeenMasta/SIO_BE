<?php

namespace App\Http\Requests\Api\MasterData\Product;

use App\Domain\MasterData\Enums\ProductType;
use App\Domain\MasterData\Enums\RecordStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:products,product_code'],
            'product_name' => ['required', 'string', 'max:150'],
            'product_type' => ['required', Rule::enum(ProductType::class)],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'uom' => ['required', 'string', 'max:20'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'product_code',
            'product_name',
            'product_type',
            'selling_price',
            'uom',
            'reorder_level',
            'remarks',
            'status',
        ];
    }
}
