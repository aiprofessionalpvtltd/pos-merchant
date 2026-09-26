<?php

namespace App\Services;

use App\Http\Resources\API\V1\EmployeeResource;
use App\Http\Resources\API\V1\SubscriptionResource;
use App\Models\Merchant;
use App\Models\User;

/**
 * The optional extras of GET /merchant (?include=…): the merchant, all their shops, the
 * subscription in full, and their staff. Built from the same pieces as GET /account,
 * GET /account/employees and GET /subscription. See docs/merchant.md.
 */
class MerchantDetailsService
{
    public const INCLUDES = ['merchant', 'shops', 'subscription', 'employees'];

    /** Staff embedded in the response; the full, paged list is GET /account/employees. */
    public const EMPLOYEE_LIMIT = 100;

    public function __construct(
        private readonly MerchantAccountService $accounts,
        private readonly EmployeeService $employees,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Keys to merge over the current shop's profile.
     *
     * @param  array<int, string>  $include  values of INCLUDES, or "all"
     * @return array<string, mixed>
     */
    public function details(User $user, Merchant $shop, array $include): array
    {
        $include = in_array('all', $include, true) ? self::INCLUDES : $include;
        $data = [];

        if (in_array('merchant', $include, true)) {
            $data['merchant'] = $this->accounts->merchantSummary($user);
        }

        if (in_array('shops', $include, true)) {
            $data['shops'] = $this->accounts->shops($user, in_array('subscription', $include, true));
        }

        if (in_array('subscription', $include, true)) {
            // Same keys as the short subscription in the profile, plus features, dates and options.
            $data['subscription'] = (new SubscriptionResource($this->subscriptions->state($shop)))->resolve();
        }

        if (in_array('employees', $include, true)) {
            $data['employees'] = $this->employeesBlock($user);
        }

        return $data;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool, by_shop: array<int, array<string, mixed>>}
     */
    private function employeesBlock(User $user): array
    {
        $result = $this->employees->listForMerchant($user, ['per_page' => self::EMPLOYEE_LIMIT, 'page' => 1]);

        return [
            'items' => $result['items']
                ->map(fn ($employee) => (new EmployeeResource($employee, $result['shifts']->get($employee->id)))->resolve())
                ->values()
                ->all(),
            'total' => $result['pagination']['total'],
            'has_more' => $result['pagination']['has_more'],
            'by_shop' => $result['by_shop'],
        ];
    }
}
