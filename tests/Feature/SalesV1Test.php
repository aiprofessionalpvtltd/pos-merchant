<?php

use App\Models\InventoryHistory;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\Order;
use App\Models\ProductInventory;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    (new PlanCatalogueSeeder)->run();

    Http::fake([
        'edahab.net/api/api/IssueInvoice*' => Http::response(['StatusDescription' => 'Success', 'InvoiceId' => 555001]),
        'edahab.net/api/api/checkInvoiceStatus*' => fn () => Http::response(['InvoiceStatus' => Cache::get('test-edahab-status', 'Pending'), 'TransactionId' => 'TX-9']),
    ]);
});

function till(): array
{
    [$owner, $token, $rice, $tea] = tillWithStock();
    $owner->merchant->update(['edahab_number' => '+252651110001']);
    app('auth')->forgetGuards();

    return [$owner, $token, $rice, $tea];
}

function shelf(int $productId): int
{
    return (int) ProductInventory::where('product_id', $productId)->where('type', 'shop')->value('quantity');
}

function ticket(string $token): array
{
    return test()->withToken($token)->getJson('/api/v1/cart')->json('data');
}

function payCart(string $token, array $body = [])
{
    $cart = ticket($token);

    return test()->withToken($token)->postJson('/api/v1/cart/pay', $body + ['cart_version' => $cart['version'], 'rail' => 'cash', 'idempotency_key' => freshKey()]);
}

function fillTicket(string $token, int $riceId): void
{
    addLine($token, $riceId, 2)->assertOk();
}

it('takes a cash sale in one call: order, stock and cleared ticket', function () {
    [$owner, $token, $rice] = till();
    fillTicket($token, $rice['id']);

    $response = payCart($token, ['amount_tendered' => ['amount' => 4000, 'currency' => 'USD'], 'customer' => ['name' => 'Amina Yusuf']])
        ->assertOk()
        ->assertJsonPath('message', 'Sale complete')
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.order.order_status', 'Complete')
        ->assertJsonPath('data.order.total.amount', 3885)
        ->assertJsonPath('data.change_due.amount', 115)
        ->assertJsonPath('data.cart.is_empty', true);

    $orderId = $response->json('data.order.id');
    expect($response->json('data.charge_id'))->toStartWith('chg_')
        ->and($response->json('data.receipt.url'))->toBe('/api/v1/orders/'.$orderId.'/receipt')
        ->and(shelf($rice['id']))->toBe(22);

    $order = Order::find($orderId);
    expect($order->paid_at)->not->toBeNull()
        ->and($order->payment_method)->toBe('cash')
        ->and($order->name)->toBe('Amina Yusuf')
        ->and((float) $order->total_price)->toBe(38.85)
        ->and((float) $order->vat)->toBe(1.85)
        ->and($order->items)->toHaveCount(1)
        ->and(InventoryHistory::where('product_id', $rice['id'])->where('kind', 'sale')->sum('quantity'))->toEqual(2);

    test()->withToken($token)->getJson('/api/v1/payments/charges/'.$response->json('data.charge_id'))
        ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.order.id', $orderId)->assertJsonPath('data.amount.amount', 3885);
});

it('does not sell twice when the pay request is retried', function () {
    [, $token, $rice] = till();
    fillTicket($token, $rice['id']);

    $cart = ticket($token);
    $body = ['cart_version' => $cart['version'], 'rail' => 'cash', 'idempotency_key' => freshKey()];

    $first = test()->withToken($token)->postJson('/api/v1/cart/pay', $body)->assertOk()->json('data.order.id');
    test()->withToken($token)->postJson('/api/v1/cart/pay', $body)->assertOk()->assertJsonPath('data.order.id', $first);

    expect(Order::count())->toBe(1)->and(shelf($rice['id']))->toBe(22);
});

it('refuses a payment that is wrong for the ticket', function () {
    [, $token, $rice] = till();

    payCart($token)->assertStatus(422)->assertJsonPath('error.code', 'cart.empty');

    fillTicket($token, $rice['id']);
    $version = ticket($token)['version'];

    test()->withToken($token)->postJson('/api/v1/cart/pay', ['cart_version' => $version - 1, 'rail' => 'cash', 'idempotency_key' => freshKey()])
        ->assertStatus(409)->assertJsonPath('error.code', 'cart.version_conflict')->assertJsonPath('error.details.current.version', $version);

    payCart($token, ['amount_tendered' => ['amount' => 1000, 'currency' => 'USD']])
        ->assertStatus(422)->assertJsonPath('error.code', 'payment.tender_too_low')->assertJsonPath('error.details.due.amount', 3885);

    payCart($token, ['rail' => 'zaad'])->assertStatus(422)->assertJsonPath('error.code', 'payment.rail_unavailable');
    payCart($token, ['rail' => 'bitcoin'])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    expect(Order::count())->toBe(0)->and(shelf($rice['id']))->toBe(24)->and(ticket($token)['is_empty'])->toBeFalse();
});

it('takes a wallet payment: the sale waits for the customer, then completes when they approve', function () {
    [, $token, $rice] = till();
    fillTicket($token, $rice['id']);

    $pending = payCart($token, ['rail' => 'edahab', 'customer' => ['wallet_number' => '+252651110009', 'name' => 'Hodan']])
        ->assertStatus(202)
        ->assertJsonPath('message', 'Ask the customer to approve the payment')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.poll_after', 3);

    $chargeId = $pending->json('data.charge_id');
    expect($pending->json('data.poll'))->toBe('/api/v1/payments/charges/'.$chargeId)
        ->and(Order::count())->toBe(0)->and(shelf($rice['id']))->toBe(24)->and(ticket($token)['is_empty'])->toBeFalse();

    Cache::put('test-edahab-status', 'Pending');
    test()->withToken($token)->getJson('/api/v1/payments/charges/'.$chargeId)
        ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.amount.amount', 310800)->assertJsonPath('data.amount.currency', 'SLSH')
        ->assertJsonPath('data.purpose', 'pos_sale')->assertJsonPath('data.customer.name', 'Hodan');

    Cache::put('test-edahab-status', 'Paid');
    $paid = test()->withToken($token)->getJson('/api/v1/payments/charges/'.$chargeId)
        ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.order.order_status', 'Complete');

    test()->withToken($token)->getJson('/api/v1/payments/charges/'.$chargeId)->assertJsonPath('data.order.id', $paid->json('data.order.id'));

    expect(Order::count())->toBe(1)->and(shelf($rice['id']))->toBe(22)->and(ticket($token)['is_empty'])->toBeTrue()
        ->and(Order::first()->payment_method)->toBe('edahab');
});

it('stops a second payment starting while one is waiting for the customer', function () {
    [, $token, $rice] = till();
    fillTicket($token, $rice['id']);

    $first = payCart($token, ['rail' => 'edahab', 'customer' => ['wallet_number' => '+252651110009']])->assertStatus(202)->json('data.charge_id');

    payCart($token, ['rail' => 'edahab', 'customer' => ['wallet_number' => '+252651110009']])
        ->assertStatus(409)->assertJsonPath('error.code', 'payment.charge_pending')->assertJsonPath('error.details.charge_id', $first);
});

it('checks the customer wallet number before charging', function () {
    [, $token, $rice] = till();
    fillTicket($token, $rice['id']);

    payCart($token, ['rail' => 'edahab'])->assertStatus(422)->assertJsonPath('error.field', 'customer.wallet_number');
    payCart($token, ['rail' => 'edahab', 'customer' => ['wallet_number' => '+252632220009']])
        ->assertStatus(422)->assertJsonPath('error.code', 'payment.wallet_invalid');
});

it('passes the wallet fee to the customer on Gold', function () {
    [$owner, $token, $rice] = till();
    MerchantSubscription::where('merchant_id', $owner->merchant->id)->update(['subscription_plan_id' => 1]);
    app('auth')->forgetGuards();
    fillTicket($token, $rice['id']);

    $chargeId = payCart($token, ['rail' => 'edahab', 'customer' => ['wallet_number' => '+252651110009']])->assertStatus(202)->json('data.charge_id');

    Cache::put('test-edahab-status', 'Paid');
    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/payments/charges/'.$chargeId)
        ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.amount.amount', 319680);

    expect((float) Order::first()->exelo_amount)->toBe(1.11)->and((float) Order::first()->total_price)->toBe(38.85);
});

it('holds a ticket as a pending order without touching stock', function () {
    [, $token, $rice] = till();
    fillTicket($token, $rice['id']);
    $version = ticket($token)['version'];
    $body = ['customer' => ['name' => 'Amina Yusuf', 'mobile_number' => '+252635550101'], 'note' => 'Collecting Thursday', 'idempotency_key' => freshKey()];

    $held = test()->withToken($token)->postJson('/api/v1/cart/hold', $body)->assertCreated()
        ->assertJsonPath('message', 'Order held for Amina Yusuf')
        ->assertJsonPath('data.order.order_status', 'Pending')
        ->assertJsonPath('data.order.total.amount', 3885)
        ->assertJsonPath('data.cart.is_empty', true);

    expect(shelf($rice['id']))->toBe(24)->and(Order::first()->paid_at)->toBeNull()->and(Order::first()->note)->toBe('Collecting Thursday')
        ->and(ticket($token)['version'])->toBeGreaterThan($version);

    test()->withToken($token)->postJson('/api/v1/cart/hold', $body)->assertCreated()->assertJsonPath('data.order.id', $held->json('data.order.id'));
    expect(Order::count())->toBe(1);

    test()->withToken($token)->postJson('/api/v1/cart/hold', ['idempotency_key' => freshKey()] + ['customer' => ['name' => 'X', 'mobile_number' => '1']])
        ->assertStatus(422)->assertJsonPath('error.code', 'cart.empty');
    test()->withToken($token)->postJson('/api/v1/cart/hold', ['idempotency_key' => freshKey()])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
});

it('lists and reads orders', function () {
    [, $token, $rice, $tea] = till();
    fillTicket($token, $rice['id']);
    payCart($token)->assertOk();
    addLine($token, $tea['id'], 1)->assertOk();
    test()->withToken($token)->postJson('/api/v1/cart/hold', ['customer' => ['name' => 'Amina Yusuf', 'mobile_number' => '+252635550101'], 'idempotency_key' => freshKey()])->assertCreated();

    $list = fn (string $q = '') => test()->withToken($token)->getJson('/api/v1/orders'.$q);

    $all = $list()->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('meta.summary.pending_count', 1)
        ->assertJsonPath('meta.summary.complete_count', 1)
        ->assertJsonPath('meta.summary.pending_value.amount', 500)
        ->assertJsonPath('data.0.order_status', 'Pending')
        ->assertJsonPath('data.0.initial_name', 'AY')
        ->assertJsonPath('data.0.item_count', 1)
        ->assertJsonPath('data.0.unit_count', 1)
        ->assertJsonMissingPath('data.0.items');

    expect($all->json('data.1.created_at_display'))->not->toBeNull();

    $list('?status=pending')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Amina Yusuf');
    $list('?status=complete')->assertJsonCount(1, 'data')->assertJsonPath('data.0.payment_method', 'cash');
    $list('?status=cancelled')->assertJsonCount(0, 'data');
    $list('?q=amina')->assertJsonCount(1, 'data');
    $list('?q='.$all->json('data.1.id'))->assertJsonCount(1, 'data');
    $list('?from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data');
    $list('?sort=total')->assertJsonPath('data.0.total.amount', 500);
    $list('?sort=-total')->assertJsonPath('data.0.total.amount', 3885);
    $list('?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.has_more', true);
    $list('?status=paid')->assertStatus(422);

    $id = $all->json('data.1.id');
    test()->withToken($token)->getJson('/api/v1/orders/'.$id)->assertOk()
        ->assertJsonPath('data.items.0.product_name', 'Rice')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.totals.subtotal.amount', 3700)
        ->assertJsonPath('data.totals.vat.amount', 185)
        ->assertJsonPath('data.totals.total.amount', 3885)
        ->assertJsonPath('data.totals.total_alt.amount', 310800)
        ->assertJsonPath('data.charge.rail', 'cash')
        ->assertJsonPath('data.charge.status', 'paid')
        ->assertJsonPath('data.employee.name', 'Kalid Ahmed')
        ->assertJsonPath('data.paid_at', fn ($value) => $value !== null);

    test()->withToken($token)->getJson('/api/v1/orders/999999')->assertStatus(404)->assertJsonPath('error.code', 'order.not_found');
});

it('never shows another shop its orders', function () {
    [, $token] = till();
    $other = otherShop();
    $theirs = Order::create(['merchant_id' => $other->id, 'order_status' => 'Pending', 'total_price' => 5, 'vat' => 0, 'exelo_amount' => 0, 'sub_total' => 5, 'order_type' => 'shop', 'name' => 'Not yours']);

    test()->withToken($token)->getJson('/api/v1/orders')->assertJsonCount(0, 'data');
    test()->withToken($token)->getJson('/api/v1/orders/'.$theirs->id)->assertStatus(404);
    test()->withToken($token)->deleteJson('/api/v1/orders/'.$theirs->id)->assertStatus(404);
    test()->withToken($token)->postJson('/api/v1/orders/'.$theirs->id.'/pay', ['rail' => 'cash', 'idempotency_key' => freshKey()])->assertStatus(404);
});

function heldOrder(string $token, int $productId, int $quantity = 2): int
{
    addLine($token, $productId, $quantity)->assertOk();

    return test()->withToken($token)->postJson('/api/v1/cart/hold', ['customer' => ['name' => 'Amina Yusuf', 'mobile_number' => '+252635550101'], 'idempotency_key' => freshKey()])
        ->assertCreated()->json('data.order.id');
}

it('settles a held order with cash', function () {
    [, $token, $rice] = till();
    $id = heldOrder($token, $rice['id']);

    test()->withToken($token)->postJson('/api/v1/orders/'.$id.'/pay', ['rail' => 'cash', 'amount_tendered' => ['amount' => 5000, 'currency' => 'USD'], 'idempotency_key' => freshKey()])
        ->assertOk()
        ->assertJsonPath('message', 'Order paid')
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.order.order_status', 'Complete')
        ->assertJsonPath('data.order.paid_at', fn ($value) => $value !== null)
        ->assertJsonPath('data.change_due.amount', 1115);

    expect(shelf($rice['id']))->toBe(22);

    test()->withToken($token)->postJson('/api/v1/orders/'.$id.'/pay', ['rail' => 'cash', 'idempotency_key' => freshKey()])
        ->assertStatus(409)->assertJsonPath('error.code', 'order.already_paid');
});

it('settles a held order through a wallet once the customer approves', function () {
    [, $token, $rice] = till();
    $id = heldOrder($token, $rice['id']);

    $pending = test()->withToken($token)->postJson('/api/v1/orders/'.$id.'/pay', ['rail' => 'edahab', 'customer' => ['wallet_number' => '+252651110009'], 'idempotency_key' => freshKey()])
        ->assertStatus(202)->assertJsonPath('data.order_id', $id)->assertJsonPath('data.status', 'pending');

    expect(Order::find($id)->paid_at)->toBeNull();

    Cache::put('test-edahab-status', 'Paid');
    test()->withToken($token)->getJson('/api/v1/payments/charges/'.$pending->json('data.charge_id'))
        ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.purpose', 'order_settlement')->assertJsonPath('data.order.id', $id);

    expect(Order::find($id)->paid_at)->not->toBeNull()->and(Order::find($id)->payment_method)->toBe('edahab')->and(shelf($rice['id']))->toBe(22);
});

it('moves an order through its allowed statuses', function () {
    [, $token, $rice] = till();
    $id = heldOrder($token, $rice['id']);
    $set = fn (string $status, array $extra = []) => test()->withToken($token)->patchJson('/api/v1/orders/'.$id.'/status', ['status' => $status, 'idempotency_key' => freshKey()] + $extra);

    $set('complete')->assertOk()->assertJsonPath('message', 'Order marked complete')->assertJsonPath('data.order_status', 'Complete')->assertJsonPath('data.paid_at', null)->assertJsonPath('data.version', 2);
    expect(shelf($rice['id']))->toBe(22);

    $set('cancelled')->assertStatus(409)->assertJsonPath('error.code', 'order.invalid_transition')->assertJsonPath('error.details.allowed', ['pending']);
    $set('pending')->assertOk()->assertJsonPath('data.order_status', 'Pending');
    $set('complete')->assertOk();
    expect(shelf($rice['id']))->toBe(22);

    $set('pending')->assertOk();
    $set('cancelled')->assertStatus(422)->assertJsonPath('error.field', 'reason');
    $set('cancelled', ['reason' => 'Customer changed their mind'])->assertOk()->assertJsonPath('data.order_status', 'Cancelled');
    $set('pending')->assertStatus(409)->assertJsonPath('error.details.allowed', []);
    $set('complete')->assertStatus(409);

    test()->withToken($token)->getJson('/api/v1/orders?status=cancelled')->assertJsonCount(1, 'data');
    test()->withToken($token)->patchJson('/api/v1/orders/'.$id.'/status', ['status' => 'complete'])->assertStatus(422);
});

it('deletes only pending orders and puts stock back when it had left', function () {
    [, $token, $rice, $tea] = till();
    $pending = heldOrder($token, $tea['id'], 1);
    $completed = heldOrder($token, $rice['id']);

    test()->withToken($token)->patchJson('/api/v1/orders/'.$completed.'/status', ['status' => 'complete', 'idempotency_key' => freshKey()])->assertOk();
    test()->withToken($token)->deleteJson('/api/v1/orders/'.$completed)->assertStatus(409)->assertJsonPath('error.code', 'order.cannot_delete_complete');

    test()->withToken($token)->patchJson('/api/v1/orders/'.$completed.'/status', ['status' => 'pending', 'idempotency_key' => freshKey()])->assertOk();
    expect(shelf($rice['id']))->toBe(22);
    test()->withToken($token)->deleteJson('/api/v1/orders/'.$completed)->assertOk()->assertJsonPath('data.deleted', true)->assertJsonPath('message', 'Pending order deleted');
    expect(shelf($rice['id']))->toBe(24)->and(Order::find($completed))->toBeNull();

    test()->withToken($token)->deleteJson('/api/v1/orders/'.$pending)->assertOk();
    test()->withToken($token)->deleteJson('/api/v1/orders/'.$pending)->assertStatus(404);
});

it('saves an order composed offline, once', function () {
    [, $token, $rice, $tea] = till();
    $body = [
        'client_order_id' => 'ord_local_44', 'customer' => ['name' => 'Hodan Farah', 'mobile_number' => '+252635550202'],
        'items' => [['product_id' => $rice['id'], 'quantity' => 1], ['product_id' => $tea['id'], 'quantity' => 3]],
        'created_at' => '2026-09-18T11:02:00Z',
    ];

    $created = test()->withToken($token)->postJson('/api/v1/orders', $body)->assertCreated()
        ->assertJsonPath('data.client_order_id', 'ord_local_44')
        ->assertJsonPath('data.order_status', 'Pending')
        ->assertJsonPath('data.totals.total.amount', 3443)
        ->assertJsonPath('data.created_at', '2026-09-18T11:02:00Z');

    test()->withToken($token)->postJson('/api/v1/orders', $body)->assertOk()->assertJsonPath('data.id', $created->json('data.id'))->assertJsonPath('message', 'Order already saved');
    expect(Order::count())->toBe(1);

    test()->withToken($token)->postJson('/api/v1/orders', ['client_order_id' => 'x', 'status' => 'complete', 'items' => [['product_id' => $rice['id'], 'quantity' => 1]]])->assertStatus(422);
    test()->withToken($token)->postJson('/api/v1/orders', ['client_order_id' => 'y', 'items' => [['product_id' => 999999, 'quantity' => 1]]])->assertStatus(404)->assertJsonPath('error.code', 'product.not_found');
    test()->withToken($token)->postJson('/api/v1/orders', ['client_order_id' => 'z', 'items' => []])->assertStatus(422);
});

it('produces a receipt for an order', function () {
    [$owner, $token, $rice] = till();
    $owner->merchant->update(['zaad_number' => '+252632220001', 'merchant_code' => 'TST-RCPT-1', 'preferences' => ['receipt' => ['footer' => 'Mahadsanid!']]]);
    app('auth')->forgetGuards();
    fillTicket($token, $rice['id']);
    $orderId = payCart($token, ['customer' => ['name' => 'Amina Yusuf']])->assertOk()->json('data.order.id');

    test()->withToken($token)->getJson('/api/v1/orders/'.$orderId.'/receipt')->assertOk()
        ->assertJsonPath('data.merchant.business_name', 'Exelo Retail')
        ->assertJsonPath('data.merchant.merchant_code', 'TST-RCPT-1')
        ->assertJsonPath('data.merchant.zaad_number', '+252632220001')
        ->assertJsonPath('data.invoice.invoice_no', 'INV-'.$orderId)
        ->assertJsonPath('data.invoice.payment_status', 'Paid')
        ->assertJsonPath('data.invoice.payment_method', 'cash')
        ->assertJsonPath('data.customer.name', 'Amina Yusuf')
        ->assertJsonPath('data.items.0.line_total.amount', 3700)
        ->assertJsonPath('data.totals.total.amount', 3885)
        ->assertJsonPath('data.totals.vat_label', '5%')
        ->assertJsonPath('data.footer', 'Mahadsanid!');

    test()->withToken($token)->getJson('/api/v1/orders/999999/receipt')->assertStatus(404);
});

it('starts a charge directly for a ticket, locking a quote and refusing a changed total', function () {
    [, $token, $rice] = till();
    fillTicket($token, $rice['id']);
    $cart = ticket($token);
    $charge = fn (array $extra = []) => test()->withToken($token)->postJson('/api/v1/payments/charges', $extra + [
        'rail' => 'cash', 'purpose' => 'pos_sale', 'amount' => ['amount' => 3885, 'currency' => 'USD'], 'cart_id' => $cart['cart_id'], 'idempotency_key' => freshKey(),
    ]);

    $charge(['amount' => ['amount' => 3000, 'currency' => 'USD']])->assertStatus(409)->assertJsonPath('error.code', 'payment.cart_changed')->assertJsonPath('error.details.current_total.amount', 3885);
    $charge(['cart_version' => $cart['version'] - 1])->assertStatus(409)->assertJsonPath('error.code', 'payment.cart_changed');
    $charge(['cart_id' => 999999])->assertStatus(404)->assertJsonPath('error.code', 'cart.not_found');
    $charge(['quote_id' => 'qte_GONE'])->assertStatus(410)->assertJsonPath('error.code', 'quote.expired');
    $charge(['amount' => ['amount' => 3885, 'currency' => 'SLSH']])->assertStatus(422);
    $charge(['cart_id' => null])->assertStatus(422);

    $quote = test()->withToken($token)->postJson('/api/v1/payments/quote', ['amount' => ['amount' => 3885, 'currency' => 'USD'], 'rail' => 'edahab', 'purpose' => 'pos_sale'])->json('data.quote_id');
    $charge(['quote_id' => $quote])->assertStatus(422)->assertJsonPath('error.field', 'quote_id');

    $cashQuote = test()->withToken($token)->postJson('/api/v1/payments/quote', ['amount' => ['amount' => 3885, 'currency' => 'USD'], 'rail' => 'cash', 'purpose' => 'pos_sale'])->json('data.quote_id');
    $charge(['quote_id' => $cashQuote, 'customer' => ['name' => 'Amina']])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.order.order_status', 'Complete')
        ->assertJsonPath('data.customer_charge.amount', 3885);

    expect(Order::count())->toBe(1);
});

it('settles an order through the charge endpoint too', function () {
    [, $token, $rice] = till();
    $id = heldOrder($token, $rice['id']);

    test()->withToken($token)->postJson('/api/v1/payments/charges', ['rail' => 'cash', 'purpose' => 'order_settlement', 'amount' => ['amount' => 3885, 'currency' => 'USD'], 'order_id' => $id, 'idempotency_key' => freshKey()])
        ->assertOk()->assertJsonPath('data.purpose', 'order_settlement')->assertJsonPath('data.status', 'paid');

    expect(Order::find($id)->paid_at)->not->toBeNull()->and(Order::count())->toBe(1);
});

it('limits the till to what each permission allows', function () {
    $owner = makeMerchant('2580');
    $ownerToken = ownerToken();
    $rice = addProduct($ownerToken);
    makeStaff($owner, '+252634990511', ['pos']);
    makeStaff($owner, '+252634990512', ['transactions']);

    app('auth')->forgetGuards();
    $cashier = staffToken('+252634990511');
    addLine($cashier, $rice['id'], 1)->assertOk();
    payCart($cashier)->assertOk();
    test()->withToken($cashier)->getJson('/api/v1/orders')->assertStatus(403)->assertJsonPath('error.details.required_permission', 'transactions');

    app('auth')->forgetGuards();
    $auditor = staffToken('+252634990512');
    test()->withToken($auditor)->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.employee.name', 'Layla Ahmed');
    test()->withToken($auditor)->postJson('/api/v1/cart/pay', ['cart_version' => 1, 'rail' => 'cash', 'idempotency_key' => freshKey()])->assertStatus(403);
    test()->withToken($auditor)->postJson('/api/v1/orders', ['client_order_id' => 'a', 'items' => [['product_id' => $rice['id'], 'quantity' => 1]]])->assertStatus(403);
});
