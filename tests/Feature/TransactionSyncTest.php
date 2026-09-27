<?php

use App\Models\Invoice;
use App\Models\MerchantSubscription;
use App\Models\Transaction;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    (new PlanCatalogueSeeder)->run();

    Http::fake([
        'edahab.net/api/api/IssueInvoice*' => Http::response(fixture('edahab/check-invoice-pending.json')),
        'edahab.net/api/api/checkInvoiceStatus*' => Http::response(['InvoiceStatus' => 'Paid', 'TransactionId' => 'MP260926.1000.A00002', 'StatusCode' => 0]),
    ]);
});

/**
 * Every invoice v1 settles is also logged as a `transactions` row, the table the
 * legacy admin dashboard and reports read — registration and verification (which
 * happen before any shop exists, so `merchant_id` is null), a subscription
 * payment, and a sale. See InvoiceObserver and docs/data-model.md.
 */
function transactionFor(string $publicId): ?Transaction
{
    $invoiceId = Invoice::where('public_id', $publicId)->value('id');

    return Transaction::where('invoice_id', $invoiceId)->first();
}

it('logs a registration payment, with no shop yet', function () {
    $invoiceId = onboardingPay('registration', 'txn-reg-key');

    $row = transactionFor($invoiceId);

    expect($row)->not->toBeNull()
        ->and($row->merchant_id)->toBeNull()
        ->and($row->order_id)->toBeNull()
        ->and($row->transaction_status)->toBe('Paid')
        ->and((float) $row->transaction_amount)->toBeGreaterThan(0);
});

it('logs a verification payment, with no shop yet', function () {
    merchantAccount();
    $invoiceId = onboardingPay('verification', 'txn-verify-key');

    $row = transactionFor($invoiceId);

    expect($row)->not->toBeNull()
        ->and($row->merchant_id)->toBeNull()
        ->and($row->transaction_status)->toBe('Paid');
});

it('logs a subscription payment, against the shop that paid it', function () {
    $owner = makeMerchant('2580');
    $token = ownerToken();
    enableSimulation();

    $charge = change(['plan_id' => planId('gold'), 'rail' => 'edahab', 'wallet_number' => '+252654990001', 'idempotency_key' => 'txn-sub-key'], $token)
        ->assertStatus(202)->json('data.charge_id');

    test()->withoutToken()->postJson("/api/v1/registration/invoices/{$charge}/simulate-payment")->assertOk();

    $row = transactionFor($charge);

    expect($row)->not->toBeNull()
        ->and($row->merchant_id)->toBe($owner->merchant->id)
        ->and($row->transaction_status)->toBe('Paid')
        ->and((float) $row->transaction_amount)->toBeGreaterThan(0);

    expect(MerchantSubscription::where('merchant_id', $owner->merchant->id)->where('subscription_plan_id', planId('gold'))->count())->toBe(1);
});

it('logs a sale (order) payment, with the shop and the order it paid for', function () {
    [$owner, $token, $rice] = till();
    fillTicket($token, $rice['id']);

    $response = payCart($token, ['amount_tendered' => ['amount' => 4000, 'currency' => 'USD']])->assertOk();
    $orderId = $response->json('data.order.id');
    $chargeId = $response->json('data.charge_id');

    $row = transactionFor($chargeId);

    expect($row)->not->toBeNull()
        ->and($row->merchant_id)->toBe($owner->merchant->id)
        ->and($row->order_id)->toBe($orderId)
        ->and($row->transaction_status)->toBe('Paid')
        ->and($row->payment_method)->toBe('cash');
});

it('never logs a still-pending invoice', function () {
    $phone = '+252654990102';
    $quote = test()->getJson('/api/v1/registration/quote?purpose=registration')->json('data.quote_id');
    $invoiceId = test()->postJson('/api/v1/registration/invoices', [
        'phone_number' => $phone, 'wallet_number' => $phone, 'rail' => 'edahab',
        'purpose' => 'registration', 'quote_id' => $quote, 'idempotency_key' => 'txn-still-pending',
    ])->json('data.invoice_id');

    expect(transactionFor($invoiceId))->toBeNull();
});
