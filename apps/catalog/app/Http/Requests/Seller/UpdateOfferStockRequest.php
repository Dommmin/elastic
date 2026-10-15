<?php

namespace App\Http\Requests\Seller;

use App\Http\Requests\Concerns\ExpectsOfferVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOfferStockRequest extends FormRequest
{
    use ExpectsOfferVersion;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [...$this->versionRules(), 'stock' => ['required', 'integer', 'min:0', 'max:100000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->versionMessages(), ...OfferFieldMessages::STOCK];
    }
}
