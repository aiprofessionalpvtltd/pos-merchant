<?php

namespace App\Http\Requests\API\V1;

use App\Exceptions\ApiException;
use App\Services\MerchantDetailsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /merchant?include=merchant,shops,subscription,employees (or "all").
 */
class ShowMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The extras span every shop of the merchant, so they are for the owner only.
        if ($this->includes() !== [] && ! $this->user()->isMerchantAccount()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'include' => ['nullable', 'array'],
            'include.*' => ['string', Rule::in([...MerchantDetailsService::INCLUDES, 'all'])],
        ];
    }

    public function messages(): array
    {
        return ['include.*.in' => 'Use merchant, shops, subscription, employees or all'];
    }

    /**
     * The requested extras. Accepts "include=shops,employees" and "include[]=shops".
     *
     * @return array<int, string>
     */
    public function includes(): array
    {
        $include = $this->query('include');

        $values = is_array($include) ? $include : explode(',', (string) $include);

        return array_values(array_unique(array_filter(array_map(fn ($value) => trim((string) $value), $values))));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['include' => $this->includes()]);
    }
}
