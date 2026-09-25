<?php

use App\Models\Invoice;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new Database\Seeders\PlanCatalogueSeeder)->run());

const NEW_PHONE = '+252654990010';

function fakeEdahab(string $status = 'Paid'): void
{
    Cache::put('test-edahab-status', $status);

    Http::fake([
        'edahab.net/api/api/IssueInvoice*' => Http::response(['StatusDescription' => 'Success', 'InvoiceId' => 987654]),
        'edahab.net/api/api/checkInvoiceStatus*' => fn () => Http::response(['InvoiceStatus' => Cache::get('test-edahab-status'), 'TransactionId' => 'TX-1']),
    ]);
}

function issueInvoice(string $key = 'idem-1'): Illuminate\Testing\TestResponse
{
    $quote = test()->getJson('/api/v1/registration/quote')->json('data.quote_id');

    return test()->postJson('/api/v1/registration/invoices', [
        'phone_number' => NEW_PHONE,
        'wallet_number' => NEW_PHONE,
        'rail' => 'edahab',
        'purpose' => 'registration',
        'quote_id' => $quote,
        'idempotency_key' => $key,
    ]);
}

function registrationBody(string $invoiceId): array
{
    return [
        'invoice_id' => $invoiceId,
        'first_name' => 'Kalid',
        'last_name' => 'Ahmed',
        'dob' => '1990-01-01',
        'email' => 'kalid-reg@example.test',
        'phone_number' => NEW_PHONE,
        'business_name' => 'Exelo Retail',
        'state' => 'maroodi_jeex',
        'city' => 'Hargeisa',
        'merchant_code' => 'TEST-EXL-102',
        'other_merchant_code' => 'TEST-ZAAD-88',
    ];
}

it('lists the states', function () {
    $this->getJson('/api/v1/geo/states')
        ->assertOk()
        ->assertJsonPath('data.country', 'SO')
        ->assertJsonCount(5, 'data.states');
});

it('quotes the signup fee', function () {
    $this->getJson('/api/v1/registration/quote')
        ->assertOk()
        ->assertJsonPath('data.purpose', 'registration')
        ->assertJsonPath('data.base.slsh.amount', 500)
        ->assertJsonPath('data.exelo_fee.slsh.amount', 50)
        ->assertJsonPath('data.total.slsh.amount', 550)
        ->assertJsonPath('data.total.slsh.display', '550 SLSH')
        ->assertJsonStructure(['data' => [
            'base' => ['slsh', 'usd' => ['amount', 'currency', 'display']],
            'exelo_fee' => ['slsh', 'usd'],
            'total' => ['slsh', 'usd'],
            'quote_id', 'expires_at',
        ]])
        ->assertJsonMissingPath('data.fee')
        ->assertJsonMissingPath('data.customer_charge')
        ->assertJsonMissingPath('data.amount_in_usd');
});

it('says a fresh number is available and needs an invoice', function () {
    $this->postJson('/api/v1/registration/phone/check', ['phone_number' => NEW_PHONE])
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.invoice_required', true)
        ->assertJsonPath('data.pending_invoice', null);
});

it('issues an invoice, polls to paid, registers, then lets the merchant set a PIN', function () {
    fakeEdahab();

    $invoice = issueInvoice()->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount.amount', 550)
        ->assertJsonPath('data.next_action', 'await_customer_approval')
        ->json('data.invoice_id');

    // an unpaid invoice cannot create an account
    fakeEdahab('Pending');
    $this->getJson("/api/v1/registration/invoices/{$invoice}")->assertOk()->assertJsonPath('data.status', 'pending');
    $this->postJson('/api/v1/merchants', registrationBody($invoice))
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'registration.invoice_unpaid');

    fakeEdahab('Paid');
    $this->getJson("/api/v1/registration/invoices/{$invoice}")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonStructure(['data' => ['paid_at', 'receipt_no']]);

    $this->postJson('/api/v1/registration/phone/check', ['phone_number' => NEW_PHONE])
        ->assertJsonPath('data.invoice_required', false)
        ->assertJsonPath('data.pending_invoice.invoice_id', $invoice);

    $this->postJson('/api/v1/merchants', registrationBody($invoice))
        ->assertCreated()
        ->assertJsonPath('data.merchant.location', 'Hargeisa, Maroodi Jeex')
        ->assertJsonPath('data.merchant.state_code', 'maroodi_jeex')
        ->assertJsonPath('data.user.has_pin', false)
        ->assertJsonPath('data.subscription.plan', 'silver')
        ->assertJsonPath('data.next_step', 'set_pin')
        ->assertJsonMissingPath('data.token');

    $this->postJson('/api/v1/merchants', registrationBody($invoice))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'registration.invoice_consumed');

    $this->postJson('/api/v1/registration/phone/check', ['phone_number' => NEW_PHONE])
        ->assertJsonPath('data.available', false);

    $this->postJson('/api/v1/auth/lookup', ['phone_number' => NEW_PHONE])
        ->assertJsonPath('data.has_pin', false)
        ->assertJsonPath('data.registration.complete', true);

    $this->postJson('/api/v1/auth/pin', ['phone_number' => NEW_PHONE, 'pin' => '2580', 'pin_confirmation' => '2580'], ['X-EXELO-Device-Id' => 'dev-1'])
        ->assertCreated()
        ->assertJsonPath('data.merchant.city', 'Hargeisa')
        ->assertJsonPath('data.merchant.merchant_code', 'TEST-EXL-102');

    $merchant = Merchant::where('phone_number', NEW_PHONE)->first();
    expect($merchant->is_approved)->toBeTruthy();

    // the default plan never expires, so its row has no end date
    $row = App\Models\MerchantSubscription::where('merchant_id', $merchant->id)->first();
    expect($row->subscription_plan_id)->toBe(App\Models\SubscriptionPlan::default()->value('id'))->and($row->end_date)->toBeNull();
});

it('does not double-issue when the same idempotency key is replayed', function () {
    fakeEdahab();

    $first = issueInvoice('same-key')->assertStatus(202)->json('data.invoice_id');
    $second = issueInvoice('same-key')->assertStatus(202)->json('data.invoice_id');

    expect($second)->toBe($first)
        ->and(Invoice::where('public_id', $first)->count())->toBe(1);

    Http::assertSentCount(1);
});

it('rejects an expired quote and a wallet on the wrong rail', function () {
    fakeEdahab();

    $this->postJson('/api/v1/registration/invoices', [
        'phone_number' => NEW_PHONE, 'wallet_number' => NEW_PHONE, 'rail' => 'edahab',
        'purpose' => 'registration', 'quote_id' => 'qte_GONE', 'idempotency_key' => 'k',
    ])->assertStatus(410)->assertJsonPath('error.code', 'quote.expired');

    $quote = $this->getJson('/api/v1/registration/quote')->json('data.quote_id');

    $this->postJson('/api/v1/registration/invoices', [
        'phone_number' => NEW_PHONE, 'wallet_number' => '+252634990011', 'rail' => 'edahab',
        'purpose' => 'registration', 'quote_id' => $quote, 'idempotency_key' => 'k2',
    ])->assertStatus(422)->assertJsonPath('error.code', 'payment.wallet_invalid');
});

it('keeps a declined eDahab prompt pending and says it was declined', function () {
    Http::fake([
        'edahab.net/api/api/IssueInvoice*' => Http::response(fixture('edahab/issue-invoice-declined.json')),
        'edahab.net/api/api/checkInvoiceStatus*' => Http::response(fixture('edahab/check-invoice-pending.json')),
    ]);

    $invoiceId = issueInvoice('declined')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.prompt', 'declined')
        ->json('data.invoice_id');

    $this->getJson('/api/v1/registration/invoices/'.$invoiceId)
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.prompt', 'declined');

    expect(Invoice::where('public_id', $invoiceId)->first()->apiLogs()->pluck('operation')->all())
        ->toBe(['IssueInvoice', 'checkInvoiceStatus']);
});

it('reports provider outages as 502', function () {
    Http::fake(['edahab.net/*' => Http::response('down', 503)]);

    issueInvoice('outage')->assertStatus(502)->assertJsonPath('error.code', 'payment.provider_unavailable');
});

it('validates the registration form per field', function () {
    fakeEdahab();
    $invoice = issueInvoice()->json('data.invoice_id');

    $this->postJson('/api/v1/merchants', ['invoice_id' => $invoice, 'state' => 'mars', 'dob' => 'nope'] + registrationBody($invoice))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.state.0', 'Choose a state')
        ->assertJsonPath('error.details.dob.0', 'Enter a valid date of birth');
});

it('returns 404 for an unknown invoice', function () {
    $this->getJson('/api/v1/registration/invoices/inv_NOPE')->assertStatus(404)->assertJsonPath('error.code', 'invoice.not_found');
});

it('reports wallet verification status for the signed in merchant only', function () {
    $user = makeMerchant('2580');
    $merchant = $user->merchant;
    $merchant->update(['edahab_number' => '+252654990001']);

    $token = login()->json('data.token');

    $this->withToken($token)->postJson("/api/v1/merchants/{$merchant->id}/verification/complete")
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.wallets.edahab_number.status', 'verified')
        ->assertJsonPath('data.wallets.zaad_number.status', 'not_set');

    $this->withToken($token)->postJson('/api/v1/merchants/999999/verification/complete')->assertStatus(404);
    app('auth')->forgetGuards();
    $this->withoutToken()->postJson("/api/v1/merchants/{$merchant->id}/verification/complete")->assertStatus(401);
});

it('hides the simulate-payment endpoint unless local testing is enabled', function () {
    fakeEdahab('Pending');
    $invoice = issueInvoice()->json('data.invoice_id');

    $this->postJson("/api/v1/registration/invoices/{$invoice}/simulate-payment")->assertStatus(404);

    config()->set('exelo.simulate_payments', true);
    $this->postJson("/api/v1/registration/invoices/{$invoice}/simulate-payment")->assertStatus(404);

    expect(Invoice::where('public_id', $invoice)->value('status'))->toBe('Pending');
});

it('lets a local developer mark an invoice paid and then register', function () {
    fakeEdahab('Pending');
    $invoice = issueInvoice()->json('data.invoice_id');

    app()->detectEnvironment(fn () => 'local');
    config()->set('exelo.simulate_payments', true);

    $this->postJson("/api/v1/registration/invoices/{$invoice}/simulate-payment")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $this->postJson("/api/v1/registration/invoices/{$invoice}/simulate-payment")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invoice.not_pending');

    $this->postJson('/api/v1/merchants', registrationBody($invoice))->assertCreated();
});

afterEach(fn () => Cache::flush());
