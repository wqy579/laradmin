<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class BaseFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    abstract public function rules(): array;

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'code' => 422,
                'message' => $validator->errors()->first(),
                'data' => null,
            ], 422)
        );
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('GET')) {
            $this->merge([
                'page' => $this->input('page', 1),
                'page_size' => $this->input('page_size', 20),
            ]);
        }
    }
}
