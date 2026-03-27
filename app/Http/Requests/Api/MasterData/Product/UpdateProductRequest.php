<?php

namespace App\Http\Requests\Api\MasterData\Product;

use App\Domain\MasterData\Enums\ProductType;
use App\Domain\MasterData\Enums\RecordStatus;
use App\Http\Requests\Api\StrictFormRequest;
use App\Models\Product;
use App\Models\ProductCondition;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends StrictFormRequest
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
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'product_code' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('products', 'product_code')->ignore($product->id)],
            'product_name' => ['sometimes', 'required', 'string', 'max:150'],
            'product_type' => ['sometimes', 'required', Rule::enum(ProductType::class)],
            'selling_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'uom' => ['sometimes', 'required', 'string', 'max:20'],
            'reorder_level' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', Rule::enum(RecordStatus::class)],
            'accessories' => ['sometimes', 'nullable', 'array', 'max:50'],
            'accessories.*.accessory_name' => ['required_with:accessories', 'string', 'max:150'],
            'accessories.*.quantity' => ['nullable', 'integer', 'min:1'],
            'accessories.*.remarks' => ['nullable', 'string', 'max:500'],
            'conditions' => ['sometimes', 'nullable', 'array', 'max:50'],
            'conditions.*.condition_name' => ['required_with:conditions', 'string', Rule::in(ProductCondition::availableConditions())],
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
            'accessories',
            'conditions',
        ];
    }
}
