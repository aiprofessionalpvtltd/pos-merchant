<?php

use App\Models\MerchantSubscription;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function quoteBody(array $overrides = []): array
{
    return $overrides + ['amount' => ['amount' => 3774, 'currency' => 'USD'], 'rail' => 'cash', 'purpose' => 'pos_sale'];
}

function switchPlanTo(User $owner, int $planId): void
{
    MerchantSubscription::where('merchant_id', $owner->merchant->id)->update(['subscription_plan_id' => $planId]);
}

it('lists the wallets and only the rails the shop can really use', function () {
    $owner = makeMerchant('2580');
    $token = ownerToken();

    test()->withToken($token)->getJson('/api/v1/payments/methods')
        ->assertOk()
        ->assertJsonPath('data.accepts', ['cash'])
        ->assertJsonPath('data.wallets.0.rail', 'zaad')
        ->assertJsonPath('data.wallets.0.status', 'not_set')
        ->assertJsonPath('data.card.enabled', false);

    $owner->merchant->update(['zaad_number' => '+252632220001', 'edahab_number' => '+252651110001', 'wallet_states' => ['edahab' => ['status' => 'pending']]]);
    app('auth')->forgetGuards();

    test()->withToken($token)->getJson('/api/v1/payments/methods')
        ->assertOk()
        ->assertJsonPath('data.accepts', ['zaad', 'cash'])
        ->assertJsonPath('data.wallets.1.status', 'pending');

    config()->set('exelo.payments.card.enabled', true);
    app('auth')->forgetGuards();

    test()->withToken($token)->getJson('/api/v1/payments/methods')
        ->assertJsonPath('data.accepts', ['zaad', 'cash', 'card'])
        ->assertJsonPath('data.card.environment', 'sandbox');
});

it('needs a token for the payment endpoints', function () {
    test()->getJson('/api/v1/payments/methods')->assertStatus(401);
    test()->postJson('/api/v1/payments/quote', quoteBody())->assertStatus(401);
});

it('quotes cash without any fee', function () {
    makeMerchant('2580');

    test()->withToken(ownerToken())->postJson('/api/v1/payments/quote', quoteBody())
        ->assertOk()
        ->assertJsonPath('data.amount.amount', 3774)
        ->assertJsonPath('data.customer_charge.amount', 3774)
        ->assertJsonPath('data.merchant_receives.amount', 3774)
        ->assertJsonPath('data.fees.platform.amount', 0)
        ->assertJsonPath('data.fee_payer', null)
        ->assertJsonPath('data.amount.display', '$37.74')
        ->assertJsonPath('data.exchange_rate', (int) config('exelo.conversion_rate'));
});

it('makes the shop absorb the wallet fee on Silver and the customer on Gold', function () {
    $owner = makeMerchant('2580');
    $owner->merchant->update(['zaad_number' => '+252632220001', 'exchange_rate' => 8000]);
    $token = ownerToken();

    test()->withToken($token)->postJson('/api/v1/payments/quote', quoteBody(['rail' => 'zaad']))
        ->assertOk()
        ->assertJsonPath('data.fees.platform.amount', 108)
        ->assertJsonPath('data.fee_payer', 'merchant')
        ->assertJsonPath('data.customer_charge.amount', 3774)
        ->assertJsonPath('data.merchant_receives.amount', 3666)
        ->assertJsonPath('data.amount_alt.amount', 301920)
        ->assertJsonPath('data.amount_alt.currency', 'SLSH')
        ->assertJsonPath('data.exchange_rate', 8000);

    switchPlanTo($owner, 1);
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/payments/quote', quoteBody(['rail' => 'zaad']))
        ->assertOk()
        ->assertJsonPath('data.fee_payer', 'customer')
        ->assertJsonPath('data.customer_charge.amount', 3882)
        ->assertJsonPath('data.merchant_receives.amount', 3774);
});

it('quotes an SLSH amount and shows its dollar value', function () {
    $owner = makeMerchant('2580');
    $owner->merchant->update(['exchange_rate' => 8000]);

    test()->withToken(ownerToken())->postJson('/api/v1/payments/quote', quoteBody(['amount' => ['amount' => 80000, 'currency' => 'SLSH']]))
        ->assertOk()
        ->assertJsonPath('data.amount.display', '80,000 SLSH')
        ->assertJsonPath('data.amount_alt.amount', 1000)
        ->assertJsonPath('data.amount_alt.display', '$10.00');
});

it('remembers the quote so a charge can lock it', function () {
    $owner = makeMerchant('2580');

    $quoteId = test()->withToken(ownerToken())->postJson('/api/v1/payments/quote', quoteBody())->json('data.quote_id');

    expect($quoteId)->toStartWith('qte_')
        ->and(Cache::get('payment-quote:'.$quoteId)['merchant_id'])->toBe($owner->merchant->id);
});

it('refuses a rail the shop cannot use', function () {
    makeMerchant('2580');
    $token = ownerToken();

    test()->withToken($token)->postJson('/api/v1/payments/quote', quoteBody(['rail' => 'zaad']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'payment.rail_unavailable')
        ->assertJsonPath('error.details.available_rails', ['cash']);

    test()->withToken($token)->postJson('/api/v1/payments/quote', quoteBody(['rail' => 'card']))
        ->assertStatus(422)->assertJsonPath('error.code', 'payment.rail_unavailable');
});

it('validates the quote request', function () {
    makeMerchant('2580');
    $token = ownerToken();

    test()->withToken($token)->postJson('/api/v1/payments/quote', ['rail' => 'bitcoin', 'purpose' => 'gift', 'amount' => ['amount' => 0, 'currency' => 'EUR']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');

    test()->withToken($token)->postJson('/api/v1/payments/quote', [])->assertStatus(422);
});

it('lets staff with the pos permission quote and refuses staff without it', function () {
    $owner = makeMerchant('2580');
    makeStaff($owner, '+252634990211', ['pos']);
    makeStaff($owner, '+252634990212', ['inventory']);

    $withPos = staffToken('+252634990211');
    test()->withToken($withPos)->getJson('/api/v1/payments/methods')->assertOk();
    test()->withToken($withPos)->postJson('/api/v1/payments/quote', quoteBody())->assertOk();

    app('auth')->forgetGuards();
    $withoutPos = staffToken('+252634990212');
    test()->withToken($withoutPos)->getJson('/api/v1/payments/methods')->assertOk();
    test()->withToken($withoutPos)->postJson('/api/v1/payments/quote', quoteBody())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.permission_denied')
        ->assertJsonPath('error.details.required_permission', 'pos');
});
