<?php

namespace App\Http\Requests;

use App\Models\Flight;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'passenger' && $this->user()->isActive();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cabin_class' => $this->input('cabin_class', Flight::CABIN_CLASS_ECONOMY),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'flight_id' => ['required', 'integer', Rule::exists('flights', 'id')],
            'number_of_passengers' => ['required', 'integer', 'min:1', 'max:1'],
            'cabin_class' => ['required', Rule::in(Flight::CABIN_CLASSES)],
        ];
    }
}
