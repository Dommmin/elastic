<?php

namespace App\Http\Requests\Shop;

use App\Models\Review;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Recenzja produktu: ocena 1-5, treść opcjonalna. Jeden użytkownik, jedna recenzja
 * produktu — sprawdzane w aplikacji, bez unikalnego indeksu, bo dane z seedera
 * (`reviews:seed`) mogą mieć duplikaty i migracja by je odrzuciła.
 */
class StoreReviewRequest extends FormRequest
{
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
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $exists = Review::query()
                    ->where('product_id', $this->route('product')->id)
                    ->where('user_id', $this->user()->getAuthIdentifier())
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('rating', 'Ten produkt już oceniłeś.');
                }
            },
        ];
    }
}
