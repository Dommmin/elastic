<?php

namespace App\Http\Requests\Panel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Dostęp pilnuje już `can:panel` na grupie tras.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            // Tylko podkategorie: dział (parent_id null) nie nadaje się na kategorię produktu.
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->whereNotNull('parent_id')],
        ];
    }
}
