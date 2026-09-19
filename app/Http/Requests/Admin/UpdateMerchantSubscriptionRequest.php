<?php

namespace App\Http\Requests\Admin;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit-merchant') ?? false;
    }

    public function rules(): array
    {
        $isDefaultPlan = SubscriptionPlan::whereKey($this->input('subscription_plan_id'))->value('is_default');

        return [
            'subscription_plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')->whereNotNull('key')->whereNull('deleted_at')],
            'end_date' => [Rule::requiredIf(! $isDefaultPlan), 'nullable', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.required' => 'Choose when this plan ends',
            'end_date.after' => 'The end date must be in the future',
        ];
    }
}
