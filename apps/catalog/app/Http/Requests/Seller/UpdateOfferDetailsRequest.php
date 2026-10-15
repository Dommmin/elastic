<?php

namespace App\Http\Requests\Seller;

use App\Http\Requests\Concerns\ExpectsOfferVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SellerPortal\Domain\Offer\Condition;

class UpdateOfferDetailsRequest extends FormRequest
{
    use ExpectsOfferVersion;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            ...$this->versionRules(),
            'condition' => ['required', Rule::enum(Condition::class)],
            'shipping_days' => ['required', 'integer', 'min:1', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->versionMessages(), ...OfferFieldMessages::DETAILS];
    }
}
