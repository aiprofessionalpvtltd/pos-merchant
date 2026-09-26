<?php

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\InvoicePaymentService;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

afterEach(fn () => Cache::flush());

function planId(string $key): int
{
    return SubscriptionPlan::where('key', $key)->value('id');
}

/**
 * Puts the merchant on Gold, ending $daysLeft days from now (negative = already ended).
 */
function goldFor(User $owner, int $daysLeft): MerchantSubscription
{
    return MerchantSubscription::create([
        'merchant_id' => $owner->merchant->id,
        'subscription_plan_id' => planId('gold'),
        'start_date' => now()->addDays($daysLeft)->subMonth()->toDateString(),
        'end_date' => now()->addDays($daysLeft)->toDateString(),
        'transaction_status' => 'Paid',
    ]);
}

function ownerToken(): string
{
    return login()->json('data.token');
}

function enableSimulation(): void
{
    app()->detectEnvironment(fn () => 'local');
    config()->set('exelo.simulate_payments', true);
}

function change(array $body, ?string $token = null)
{
    $body += ['idempotency_key' => (string) Illuminate\Support\Str::uuid()];

    return test()->withToken($token)->postJson('/api/v1/subscription/change', $body);
}

it('lists the plans without signing in', function () {
    $plans = $this->getJson('/api/v1/plans')->assertOk()->json('data.plans');

    expect($plans)->toHaveCount(2)
        ->and($plans[0]['key'])->toBe('silver')
        ->and($plans[0]['price']['display'])->toBe('Free')
        ->and($plans[0]['is_default'])->toBeTrue()
        ->and($plans[1]['key'])->toBe('gold')
        ->and($plans[1]['price']['amount'])->toBe(92000)
        ->and($plans[1]['price']['display'])->toBe('92,000 SLSH')
        ->and($plans[1]['price_alt']['display'])->toBe('$11.50')
        ->and($plans[1]['features'])->toContain('pos.register', 'employees.manage');
});

it('requires a token for the subscription endpoints', function () {
    $this->getJson('/api/v1/subscription')->assertStatus(401);
    $this->postJson('/api/v1/subscription/change', [])->assertStatus(401);
    $this->postJson('/api/v1/subscription/cancel')->assertStatus(401);
});

it('reads Silver for a merchant with no subscription row without creating one', function () {
    $user = makeMerchant('2580');
    MerchantSubscription::where('merchant_id', $user->merchant->id)->forceDelete();

    $this->withToken(ownerToken())->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan', 'silver')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.expires_at', null)
        ->assertJsonPath('data.can_upgrade', true)
        ->assertJsonPath('data.can_downgrade', false)
        ->assertJsonPath('data.features', ['dashboard.basic', 'inventory.read', 'payments.request']);

    expect(MerchantSubscription::where('merchant_id', $user->merchant->id)->count())->toBe(0);
});

it('reports an active Gold plan with its features', function () {
    goldFor(makeMerchant('2580'), 30);

    $this->withToken(ownerToken())->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('message', 'You are currently on the Gold package.')
        ->assertJsonPath('data.plan', 'gold')
        ->assertJsonPath('data.plan_name', 'Gold')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.can_upgrade', false)
        ->assertJsonPath('data.can_downgrade', true)
        ->assertJsonPath('data.resubscribe_eligible', false)
        ->assertJsonPath('data.renews_automatically', false)
        ->assertJsonPath('data.grace', null)
        ->assertJsonPath('data.features.0', 'dashboard.full');
});

it('moves through grace and expiry, dropping to Silver features only after grace', function () {
    $owner = makeMerchant('2580');
    $row = goldFor($owner, -2);
    $token = ownerToken();

    $this->withToken($token)->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', 'grace')
        ->assertJsonPath('data.plan', 'gold')
        ->assertJsonPath('data.resubscribe_eligible', true)
        ->assertJsonPath('data.features.0', 'dashboard.full');

    expect($this->withToken($token)->getJson('/api/v1/subscription')->json('data.grace.days_remaining'))->toBeGreaterThan(0);

    $row->update(['end_date' => now()->subDays(20)->toDateString()]);

    $this->withToken($token)->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', 'expired')
        ->assertJsonPath('data.plan', 'gold')
        ->assertJsonPath('data.features', ['dashboard.basic', 'inventory.read', 'payments.request'])
        ->assertJsonPath('data.can_upgrade', true);
});

it('lets an employee read the shop plan but not change or cancel it', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);

    $user = User::create(['name' => 'Aisha Ali', 'email' => 'aisha-sub@example.test', 'password' => Hash::make('2580'), 'user_type' => 'employee', 'pin_set_at' => now()]);
    Employee::create([
        'user_id' => $user->id, 'shop_id' => $owner->merchant->id, 'phone_number' => '+252634990002',
        'first_name' => 'Aisha', 'last_name' => 'Ali', 'dob' => '1995-05-05', 'location' => 'Hargeisa', 'role' => 'Cashier', 'salary' => 100, 'status' => 'active',
    ]);

    $token = $this->postJson('/api/v1/auth/pin/login', ['phone_number' => '+252634990002', 'pin' => '2580'], device())->json('data.token');

    $this->withToken($token)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.plan', 'gold');
    $this->withToken($token)->postJson('/api/v1/subscription/cancel')->assertStatus(403)->assertJsonPath('error.code', 'auth.merchant_only');
    change(['plan_id' => planId('silver')], $token)->assertStatus(403)->assertJsonPath('error.code', 'auth.merchant_only');
});

it('starts an upgrade with a wallet invoice and applies Gold once it is paid', function () {
    fakeEdahab('Pending');
    $owner = makeMerchant('2580');
    $token = ownerToken();
    $key = 'f92d1c07-3b4a-4f88-8a21-6d5e9c1b4477';
    $body = ['plan_id' => planId('gold'), 'rail' => 'edahab', 'wallet_number' => '+252654990001', 'idempotency_key' => $key];

    $charge = change($body, $token)
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'payment_required')
        ->assertJsonPath('data.amount.amount', 92000)
        ->assertJsonPath('data.next_action', 'await_customer_approval')
        ->assertJsonPath('data.applies_on_payment', true)
        ->json('data.charge_id');

    // a retry with the same key returns the same charge instead of a second prompt
    change($body, $token)->assertStatus(202)->assertJsonPath('data.charge_id', $charge);
    expect(Invoice::where('type', 'Subscription')->where('merchant_id', $owner->merchant->id)->count())->toBe(1);

    $this->withToken($token)->getJson("/api/v1/payments/charges/{$charge}")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.purpose', 'subscription');

    $this->withToken($token)->getJson('/api/v1/subscription')->assertJsonPath('data.plan', 'silver');

    enableSimulation();
    $this->withoutToken()->postJson("/api/v1/registration/invoices/{$charge}/simulate-payment")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $this->withToken($token)->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan', 'gold')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.expires_at', now()->addMonth()->endOfDay()->utc()->format('Y-m-d\TH:i:s\Z'));

    expect(Invoice::where('public_id', $charge)->value('consumed_at'))->not->toBeNull();
    expect(MerchantSubscription::where('merchant_id', $owner->merchant->id)->where('subscription_plan_id', planId('gold'))->count())->toBe(1);

    // polling again does not start a second period
    $this->withToken($token)->getJson("/api/v1/payments/charges/{$charge}")->assertOk()->assertJsonPath('data.status', 'paid');
    expect(MerchantSubscription::where('merchant_id', $owner->merchant->id)->where('subscription_plan_id', planId('gold'))->count())->toBe(1);

    // retrying the same request after payment says it is paid, not "approve the payment" again
    change($body, $token)
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.charge_id', $charge)
        ->assertJsonPath('data.applies_on_payment', false);
    expect(MerchantSubscription::where('merchant_id', $owner->merchant->id)->where('subscription_plan_id', planId('gold'))->count())->toBe(1);
});

it('refuses to reuse the idempotency key of a payment that already closed', function () {
    fakeEdahab('Pending');
    makeMerchant('2580');
    $token = ownerToken();
    $body = ['plan_id' => planId('gold'), 'rail' => 'edahab', 'wallet_number' => '+252654990001', 'idempotency_key' => 'closed-key-1'];

    $charge = change($body, $token)->assertStatus(202)->json('data.charge_id');

    enableSimulation();
    $this->withoutToken()->postJson("/api/v1/registration/invoices/{$charge}/simulate-payment", ['outcome' => 'expired'])->assertOk();

    change($body, $token)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'subscription.charge_closed')
        ->assertJsonPath('error.details.charge_id', $charge)
        ->assertJsonPath('error.details.status', 'expired');

    // a fresh key starts a new payment
    change(['idempotency_key' => 'closed-key-2'] + $body, $token)->assertStatus(202);
});

it('accepts cash: the plan starts only after staff confirm it, with no wallet call', function () {
    Http::fake();
    $owner = makeMerchant('2580');
    $token = ownerToken();

    $charge = change(['plan_id' => planId('gold'), 'rail' => 'cash'], $token)
        ->assertStatus(202)
        ->assertJsonPath('data.rail', 'cash')
        ->assertJsonPath('data.next_action', 'await_cash_confirmation')
        ->json('data.charge_id');

    Http::assertNothingSent();

    $this->withToken($token)->getJson("/api/v1/payments/charges/{$charge}")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.next_action', 'await_cash_confirmation');

    $this->withToken($token)->getJson('/api/v1/subscription')->assertJsonPath('data.plan', 'silver');

    // a second change while cash is outstanding is refused
    change(['plan_id' => planId('gold'), 'rail' => 'cash'], $token)->assertStatus(409)->assertJsonPath('error.code', 'subscription.change_pending');

    app(InvoicePaymentService::class)->confirmCash(Invoice::where('public_id', $charge)->first());

    $this->withToken($token)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.plan', 'gold')->assertJsonPath('data.status', 'active');

    expect(fn () => app(InvoicePaymentService::class)->confirmCash(Invoice::where('public_id', $charge)->first()))
        ->toThrow(App\Exceptions\ApiException::class);
});

it('lets an admin confirm cash from the invoices page', function () {
    $owner = makeMerchant('2580');
    $token = ownerToken();
    $charge = change(['plan_id' => planId('gold'), 'rail' => 'cash'], $token)->json('data.charge_id');
    $invoice = Invoice::where('public_id', $charge)->first();

    $admin = User::create(['name' => 'Staff', 'email' => 'staff-sub@example.test', 'password' => Hash::make('x'), 'user_type' => 'admin']);

    $this->actingAs($admin, 'web')->postJson(route('admin.invoices.confirm-cash', $invoice->id))->assertForbidden();

    // "edit-invoice" already exists under the web guard; earlier API calls in this test switched the default guard
    auth()->shouldUse('web');
    $admin->givePermissionTo('edit-invoice');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($admin, 'web')->postJson(route('admin.invoices.confirm-cash', $invoice->id))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($invoice->refresh()->status)->toBe('Paid');
    expect(MerchantSubscription::where('merchant_id', $owner->merchant->id)->where('subscription_plan_id', planId('gold'))->exists())->toBeTrue();
});

it('validates and rejects bad change requests', function () {
    makeMerchant('2580');
    $token = ownerToken();

    change(['plan_id' => planId('silver')], $token)->assertStatus(409)->assertJsonPath('error.code', 'subscription.already_on_plan');
    change(['plan_id' => 999999, 'rail' => 'cash'], $token)->assertStatus(422)->assertJsonPath('error.code', 'subscription.plan_unavailable');
    change(['plan_id' => planId('gold')], $token)->assertStatus(422)->assertJsonPath('error.field', 'rail');
    change(['plan_id' => planId('gold'), 'rail' => 'card'], $token)->assertStatus(422)->assertJsonPath('error.code', 'payment.rail_unavailable');
    change(['plan_id' => planId('gold'), 'rail' => 'edahab'], $token)->assertStatus(422)->assertJsonPath('error.field', 'wallet_number');
    change(['plan_id' => planId('gold'), 'rail' => 'edahab', 'wallet_number' => '+252634990001'], $token)->assertStatus(422)->assertJsonPath('error.code', 'payment.wallet_invalid');

    $this->withToken($token)->postJson('/api/v1/subscription/change', ['plan_id' => planId('gold'), 'rail' => 'cash'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');
});

it('only allows renewing a paid plan close to expiry', function () {
    $owner = makeMerchant('2580');
    $row = goldFor($owner, 30);
    $token = ownerToken();

    change(['plan_id' => planId('gold'), 'rail' => 'cash'], $token)->assertStatus(409)->assertJsonPath('error.code', 'subscription.already_on_plan');

    $row->update(['end_date' => now()->addDays(3)->toDateString()]);

    $charge = change(['plan_id' => planId('gold'), 'rail' => 'cash'], $token)
        ->assertStatus(202)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Gold'))
        ->json('data.charge_id');

    app(InvoicePaymentService::class)->confirmCash(Invoice::where('public_id', $charge)->first());

    // renewing before expiry continues from the old end date
    $this->withToken($token)->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.expires_at', now()->addDays(3)->addMonth()->endOfDay()->utc()->format('Y-m-d\TH:i:s\Z'));
});

it('schedules a downgrade for period end and applies it lazily', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 20);
    $token = ownerToken();

    $effectiveAt = change(['plan_id' => planId('silver')], $token)
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.current_plan', 'gold')
        ->assertJsonPath('data.next_plan', 'silver')
        ->json('data.effective_at');

    expect($effectiveAt)->toBe(now()->addDays(20)->endOfDay()->utc()->format('Y-m-d\TH:i:s\Z'));

    // repeating the request is harmless; Gold stays until the period ends
    change(['plan_id' => planId('silver')], $token)->assertOk()->assertJsonPath('data.status', 'scheduled');
    $this->withToken($token)->getJson('/api/v1/subscription')
        ->assertJsonPath('data.plan', 'gold')
        ->assertJsonPath('data.can_downgrade', false);

    $this->travelTo(now()->addDays(21));

    $this->withToken($token)->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan', 'silver')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.expires_at', null);
});

it('applies scheduled downgrades from the daily command', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 5)->update(['next_plan_id' => planId('silver')]);

    $this->artisan('subscriptions:apply-scheduled')->assertSuccessful();
    expect(MerchantSubscription::where('merchant_id', $owner->merchant->id)->latest('id')->first()->subscription_plan_id)->toBe(planId('gold'));

    $this->travelTo(now()->addDays(6));
    $this->artisan('subscriptions:apply-scheduled')->assertSuccessful();

    $latest = MerchantSubscription::where('merchant_id', $owner->merchant->id)->latest('id')->first();
    expect($latest->subscription_plan_id)->toBe(planId('silver'))->and($latest->end_date)->toBeNull();
});

it('cancels at period end and keeps access until then', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 20);
    $token = ownerToken();

    $this->withToken($token)->postJson('/api/v1/subscription/cancel', ['reason' => 'too_expensive', 'comment' => 'Back next season'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.reverts_to', 'silver')
        ->assertJsonPath('data.resubscribe_eligible', true);

    $this->withToken($token)->getJson('/api/v1/subscription')
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.plan', 'gold')
        ->assertJsonPath('data.features.0', 'dashboard.full');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/session')->assertJsonPath('data.subscription.status', 'cancelled');

    $this->withToken($token)->postJson('/api/v1/subscription/cancel')->assertStatus(409)->assertJsonPath('error.code', 'subscription.already_cancelled');
    change(['plan_id' => planId('silver')], $token)->assertStatus(409)->assertJsonPath('error.code', 'subscription.change_pending');

    $this->travelTo(now()->addDays(21));
    $this->withToken($token)->getJson('/api/v1/subscription')->assertJsonPath('data.status', 'expired');
});

it('has nothing to cancel on the default plan and rejects an unknown reason', function () {
    makeMerchant('2580');
    $token = ownerToken();

    $this->withToken($token)->postJson('/api/v1/subscription/cancel')->assertStatus(409)->assertJsonPath('error.code', 'subscription.nothing_to_cancel');
    $this->withToken($token)->postJson('/api/v1/subscription/cancel', ['reason' => 'because'])->assertStatus(422);
});

it('only shows a subscription charge to the shop that owns it', function () {
    makeMerchant('2580');
    $other = Merchant::create(['phone_number' => '+252634990077', 'first_name' => 'Other']);

    $invoice = Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'merchant_id' => $other->id, 'invoice_id' => 'CASH-x', 'transaction_id' => 'cash_x',
        'hash' => '0', 'mobile_number' => '+252634990077', 'amount' => 92000, 'currency' => 'SLSH', 'status' => 'Pending',
        'type' => 'Subscription', 'rail' => 'cash', 'subscription_plan_id' => planId('gold'),
    ]);

    $this->withToken(ownerToken())->getJson("/api/v1/payments/charges/{$invoice->public_id}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'charge.not_found');
});
