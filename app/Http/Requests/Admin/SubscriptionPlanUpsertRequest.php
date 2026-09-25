<?php

namespace App\Http\Requests\Admin;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit a subscription plan. The key is only accepted on create.
 */
class SubscriptionPlanUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isCreate() ? 'create-subscription' : 'edit-subscription') ?? false;
    }

    public function rules(): array
    {
        $plan = $this->route('subscription_plan');

        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique('subscription_plans', 'name')->ignore($plan?->id)->whereNull('deleted_at')],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'price_slsh' => ['required', 'integer', 'min:0', 'max:100000000'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'distinct', Rule::in(array_keys(SubscriptionPlan::FEATURES))],
            'is_default' => ['boolean'],
        ];

        if ($this->isCreate()) {
            // Unique across deleted plans too: a key can't be reused for a different plan.
            $rules['key'] = ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('subscription_plans', 'key')];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'Use lower-case letters, numbers, "-" or "_", starting with a letter (e.g. gold).',
            'key.unique' => 'Another plan, possibly a deleted one, already uses this key.',
        ];
    }

    public function attributes(): array
    {
        return ['price' => 'price (USD)', 'price_slsh' => 'price (SLSH)'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_default' => $this->boolean('is_default'),
            'key' => $this->has('key') ? strtolower(trim((string) $this->input('key'))) : null,
            'price_slsh' => is_string($this->input('price_slsh')) ? str_replace([',', ' '], '', $this->input('price_slsh')) : $this->input('price_slsh'),
        ]);
    }

    private function isCreate(): bool
    {
        return $this->route('subscription_plan') === null;
    }
}
