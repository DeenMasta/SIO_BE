<?php

namespace App\Http\Requests\Api\Inventory\Stocktake;

use App\Http\Requests\Api\StrictFormRequest;

class StoreStocktakeRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['stocktake_number' => ['nullable', 'string', 'max:50', 'alpha_dash'], 'stocktake_date' => ['required', 'date'], 'remarks' => ['nullable', 'string', 'max:2000']];
    }

    protected function allowedFields(): array
    {
        return ['stocktake_number', 'stocktake_date', 'remarks'];
    }
}
