<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class StrictFormRequest extends FormRequest
{
    /**
     * @return array<int, string>
     */
    abstract protected function allowedFields(): array;

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unexpected = array_diff(array_keys($this->all()), $this->allowedFields());

            if ($unexpected !== []) {
                $validator->errors()->add('payload', 'Unexpected fields: '.implode(', ', $unexpected));
            }
        });
    }
}
