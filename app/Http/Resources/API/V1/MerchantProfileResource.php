<?php

namespace App\Http\Resources\API\V1;

use App\Models\File;
use App\Models\Merchant;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @property Merchant $resource
 */
class MerchantProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $merchant = $this->resource;
        $state = app(SubscriptionService::class)->state($merchant);
        $name = trim($merchant->first_name.' '.$merchant->last_name);

        return [
            'id' => $merchant->id,
            'version' => $merchant->version,
            'business_name' => $merchant->business_name,
            'merchant_code' => $merchant->merchant_code,
            'other_merchant_code' => $merchant->other_merchant_code,
            'owner' => [
                'id' => $merchant->user_id,
                'first_name' => $merchant->first_name,
                'last_name' => $merchant->last_name,
                'short_name' => collect(explode(' ', $name))->filter()->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))->take(2)->implode(''),
                'email' => $merchant->email,
                'phone_number' => $merchant->phone_number,
                'dob' => $merchant->dob ? Carbon::parse($merchant->dob)->toDateString() : null,
            ],
            'address' => [
                'state' => $merchant->state,
                'state_code' => $merchant->state_code,
                'city' => $merchant->city,
                'location' => $merchant->location,
            ],
            'logo' => $this->logo($merchant),
            'currency' => 'USD',
            'alt_currency' => config('exelo.alt_currency'),
            'exchange_rate' => $merchant->effectiveExchangeRate(),
            'vat_rate' => $merchant->vat_rate,
            'timezone' => $merchant->timezone,
            'subscription' => ['plan_id' => $state->plan->id, 'plan' => $state->plan->key, 'status' => $state->status],
            'created_at' => ApiResponse::iso($merchant->created_at),
        ];
    }

    private function logo(Merchant $merchant): ?array
    {
        if (! $merchant->logo_file_id) {
            return null;
        }

        $file = File::where('merchant_id', $merchant->id)->where('public_id', $merchant->logo_file_id)->first();

        return $file ? ['id' => $file->public_id, 'url' => $file->url()] : null;
    }
}
