<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Merchant;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function tillWithStock(): array
{
    [$owner, $token] = shopOwner();
    $rice = addProduct($token, ['bar_code' => 'RCE-005', 'product_name' => 'Rice', 'price' => ['amount' => 1850, 'currency' => 'USD'], 'quantity' => 24]);
    $tea = addProduct($token, ['bar_code' => 'TEA-1', 'product_name' => 'Tea', 'price' => ['amount' => 500, 'currency' => 'USD'], 'vat_rate' => 0, 'quantity' => 3]);
    $sugar = addProduct($token, ['bar_code' => 'SUG-1', 'product_name' => 'Sugar', 'quantity' => 0]);

    return [$owner, $token, $rice, $tea, $sugar];
}

function addLine(string $token, int $productId, int $quantity = 1, array $extra = [])
{
    return test()->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => $productId, 'quantity' => $quantity, 'idempotency_key' => freshKey()] + $extra);
}

it('returns an empty ticket rather than a 404', function () {
    [, $token] = shopOwner();

    test()->withToken($token)->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.is_empty', true)
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.type', 'shop')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.totals.total.amount', 0)
        ->assertJsonPath('data.item_count', 0);

    expect(Cart::count())->toBeGreaterThanOrEqual(1);
});

it('adds lines and prices the ticket with VAT', function () {
    [, $token, $rice, $tea] = tillWithStock();

    addLine($token, $rice['id'], 2)->assertOk();
    $cart = addLine($token, $tea['id'], 1)->assertOk();

    $cart->assertJsonPath('data.is_empty', false)
        ->assertJsonPath('data.item_count', 2)
        ->assertJsonPath('data.unit_count', 3)
        ->assertJsonPath('data.items.0.product_name', 'Rice')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.unit_price.amount', 1850)
        ->assertJsonPath('data.items.0.line_total.amount', 3700)
        ->assertJsonPath('data.items.0.line_total_alt.amount', 296000)
        ->assertJsonPath('data.items.0.available_quantity', 24)
        ->assertJsonPath('data.items.0.held', false)
        ->assertJsonPath('data.totals.subtotal.amount', 4200)
        ->assertJsonPath('data.totals.vat.amount', 185)
        ->assertJsonPath('data.totals.fee.amount', 0)
        ->assertJsonPath('data.totals.total.amount', 4385)
        ->assertJsonPath('data.totals.total.display', '$43.85')
        ->assertJsonPath('data.totals.total_alt.amount', 350800)
        ->assertJsonPath('data.totals.vat_rate', 0.05)
        ->assertJsonPath('data.totals.exchange_rate', 8000)
        ->assertJsonPath('data.version', 3);

    test()->withToken($token)->getJson('/api/v1/cart')->assertJsonPath('data.totals.total.amount', 4385);
});

it('adds to the quantity of a line already on the ticket', function () {
    [, $token, $rice] = tillWithStock();

    addLine($token, $rice['id'], 2)->assertOk();
    addLine($token, $rice['id'], 3)->assertOk()->assertJsonPath('data.item_count', 1)->assertJsonPath('data.items.0.quantity', 5);
});

it('will not put more on the ticket than is in stock', function () {
    [, $token, $rice, $tea, $sugar] = tillWithStock();

    addLine($token, $tea['id'], 3)->assertOk();
    addLine($token, $tea['id'], 1)
        ->assertStatus(409)->assertJsonPath('error.code', 'product.out_of_stock')
        ->assertJsonPath('error.details.available', 3)->assertJsonPath('error.details.requested', 4);

    addLine($token, $sugar['id'])->assertStatus(409)->assertJsonPath('error.details.available', 0);

    test()->withToken($token)->getJson('/api/v1/cart')->assertJsonPath('data.items.0.quantity', 3)->assertJsonCount(1, 'data.items');
});

it('rejects a bad line', function () {
    [, $token, $rice] = tillWithStock();

    addLine($token, 999999)->assertStatus(404)->assertJsonPath('error.code', 'product.not_found');
    addLine($token, $rice['id'], 0)->assertStatus(422)->assertJsonPath('error.code', 'cart.quantity_invalid');
    addLine($token, $rice['id'], -2)->assertStatus(422)->assertJsonPath('error.code', 'cart.quantity_invalid');
    test()->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => $rice['id']])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    test()->withToken($token)->postJson('/api/v1/cart/items', ['quantity' => 1, 'idempotency_key' => freshKey()])->assertStatus(422);
});

it('does not add a scanned line twice when the request is retried', function () {
    [, $token, $rice] = tillWithStock();
    $body = ['product_id' => $rice['id'], 'quantity' => 1, 'idempotency_key' => $k = freshKey()];

    test()->withToken($token)->postJson('/api/v1/cart/items', $body)->assertOk()->assertJsonPath('data.items.0.quantity', 1);
    test()->withToken($token)->postJson('/api/v1/cart/items', $body)->assertOk()->assertJsonPath('data.items.0.quantity', 1)->assertJsonPath('data.version', 2);

    test()->withToken($token)->postJson('/api/v1/cart/items', ['quantity' => 2] + $body)
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency.key_reused');

    expect(CartItem::latest('id')->first()->quantity)->toBe(1);
});

it('lets the shopkeeper override a line price in either currency', function () {
    [, $token, $rice] = tillWithStock();

    addLine($token, $rice['id'], 1, ['unit_price' => ['amount' => 1500, 'currency' => 'USD']])
        ->assertOk()->assertJsonPath('data.items.0.unit_price.amount', 1500)->assertJsonPath('data.totals.subtotal.amount', 1500);

    test()->withToken($token)->patchJson('/api/v1/cart/items/'.$rice['id'], ['unit_price' => ['amount' => 80000, 'currency' => 'SLSH']])
        ->assertOk()->assertJsonPath('data.items.0.unit_price.amount', 1000)->assertJsonPath('data.items.0.quantity', 1);
});

it('changes a quantity and refuses a stale edit', function () {
    [, $token, $rice] = tillWithStock();
    $url = '/api/v1/cart/items/'.$rice['id'];

    addLine($token, $rice['id'], 1)->assertOk();

    test()->withToken($token)->patchJson($url, ['quantity' => 4])->assertOk()->assertJsonPath('data.items.0.quantity', 4)->assertJsonPath('data.version', 3);

    test()->withToken($token)->patchJson($url, ['quantity' => 0])->assertStatus(422)->assertJsonPath('error.code', 'cart.quantity_invalid');
    test()->withToken($token)->patchJson($url, ['quantity' => 999])->assertStatus(409)->assertJsonPath('error.code', 'product.out_of_stock');
    test()->withToken($token)->patchJson('/api/v1/cart/items/999999', ['quantity' => 1])->assertStatus(404)->assertJsonPath('error.code', 'cart.line_not_found');

    test()->withToken($token)->withHeader('If-Match', '2')->patchJson($url, ['quantity' => 5])
        ->assertStatus(409)->assertJsonPath('error.code', 'cart.version_conflict')->assertJsonPath('error.details.current.version', 3);

    test()->withToken($token)->withHeader('If-Match', '3')->patchJson($url, ['quantity' => 5])->assertOk()->assertJsonPath('data.items.0.quantity', 5);
});

it('removes a line and clears the ticket', function () {
    [, $token, $rice, $tea] = tillWithStock();

    addLine($token, $rice['id'], 2)->assertOk();
    addLine($token, $tea['id'], 1)->assertOk();

    test()->withToken($token)->deleteJson('/api/v1/cart/items/'.$rice['id'])->assertOk()->assertJsonPath('data.item_count', 1)->assertJsonPath('data.items.0.product_name', 'Tea');
    test()->withToken($token)->deleteJson('/api/v1/cart/items/'.$rice['id'])->assertStatus(404)->assertJsonPath('error.code', 'cart.line_not_found');

    test()->withToken($token)->deleteJson('/api/v1/cart')
        ->assertOk()->assertJsonPath('message', 'Sale cancelled')->assertJsonPath('data.is_empty', true)->assertJsonPath('data.totals.total.amount', 0);

    test()->withToken($token)->deleteJson('/api/v1/cart')->assertOk()->assertJsonPath('data.is_empty', true);
});

it('keeps a separate ticket for each till', function () {
    [, $token, $rice] = tillWithStock();

    addLine($token, $rice['id'], 2)->assertOk();

    app('auth')->forgetGuards();
    $second = test()->postJson('/api/v1/auth/pin/login', ['phone_number' => PHONE, 'pin' => '2580'], device('till-2'))->json('data.token');

    test()->withToken($second)->getJson('/api/v1/cart')->assertJsonPath('data.is_empty', true);
    addLine($second, $rice['id'], 1)->assertOk()->assertJsonPath('data.items.0.quantity', 1);

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/cart')->assertJsonPath('data.items.0.quantity', 2);

    expect(Cart::where('merchant_id', Merchant::where('phone_number', PHONE)->value('id'))->where('cart_type', 'shop')->pluck('device_id')->sort()->values()->all())->toBe(['dev-1', 'till-2']);
});

it('sells the back room from a stock ticket', function () {
    [, $token, $rice] = tillWithStock();

    test()->withToken($token)->postJson('/api/v1/inventory/transfers', ['product_id' => $rice['id'], 'quantity' => 6, 'from' => 'shop', 'to' => 'stock', 'idempotency_key' => freshKey()])->assertOk();

    addLine($token, $rice['id'], 5, ['type' => 'stock'])->assertOk()->assertJsonPath('data.type', 'stock')->assertJsonPath('data.items.0.available_quantity', 6);
    addLine($token, $rice['id'], 2, ['type' => 'stock'])->assertStatus(409)->assertJsonPath('error.details.available', 6);

    test()->withToken($token)->getJson('/api/v1/cart?type=shop')->assertJsonPath('data.is_empty', true);
    test()->withToken($token)->getJson('/api/v1/cart?type=bogus')->assertStatus(422);
});

it('keeps the ticket for staff with the pos permission only', function () {
    $owner = makeMerchant('2580');
    $ownerToken = ownerToken();
    makeStaff($owner, '+252634990411', ['pos']);
    makeStaff($owner, '+252634990412', ['inventory']);

    app('auth')->forgetGuards();
    $till = staffToken('+252634990411');
    test()->withToken($till)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.is_empty', true);

    app('auth')->forgetGuards();
    $stockroom = staffToken('+252634990412');
    test()->withToken($stockroom)->getJson('/api/v1/cart')->assertStatus(403)->assertJsonPath('error.details.required_permission', 'pos');

    app('auth')->forgetGuards();
    test()->withoutToken()->getJson('/api/v1/cart')->assertStatus(401);
});

it('reconciles a held offline ticket line by line', function () {
    [, $token, $rice, $tea] = tillWithStock();

    $body = [
        'client_ticket_id' => 'tkt_local_18', 'idempotency_key' => $k = freshKey(),
        'lines' => [
            ['client_line_id' => 'ln_1', 'product_id' => $rice['id'], 'quantity' => 2],
            ['client_line_id' => 'ln_2', 'product_id' => 999999, 'quantity' => 1],
            ['client_line_id' => 'ln_3', 'product_id' => $tea['id'], 'quantity' => 5, 'unit_price' => ['amount' => 400, 'currency' => 'USD']],
        ],
    ];

    $first = test()->withToken($token)->postJson('/api/v1/cart/sync', $body)->assertOk()
        ->assertJsonPath('message', '2 held lines applied, 1 needs attention')
        ->assertJsonPath('data.results.0.status', 'applied')
        ->assertJsonPath('data.results.0.quantity', 2)
        ->assertJsonPath('data.results.1.status', 'rejected')
        ->assertJsonPath('data.results.1.reason.code', 'product.not_found')
        ->assertJsonPath('data.results.2.status', 'adjusted')
        ->assertJsonPath('data.results.2.quantity', 3)
        ->assertJsonPath('data.results.2.reason.code', 'product.partial_stock')
        ->assertJsonPath('data.cart.item_count', 2)
        ->assertJsonPath('data.cart.items.1.unit_price.amount', 400);

    $version = $first->json('data.cart.version');

    test()->withToken($token)->postJson('/api/v1/cart/sync', $body)->assertOk()
        ->assertJsonPath('data.results.0.status', 'duplicate')
        ->assertJsonPath('data.results.1.status', 'rejected')
        ->assertJsonPath('data.results.2.status', 'duplicate')
        ->assertJsonPath('data.cart.version', $version)
        ->assertJsonPath('data.cart.items.0.quantity', 2);

    test()->withToken($token)->postJson('/api/v1/cart/sync', ['lines' => [['client_line_id' => 'ln_1', 'product_id' => $rice['id'], 'quantity' => 9]]] + $body)
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency.key_reused');
});

it('merges into or replaces the server ticket when syncing', function () {
    [, $token, $rice, $tea] = tillWithStock();

    addLine($token, $rice['id'], 2)->assertOk();

    $sync = fn (array $extra) => test()->withToken($token)->postJson('/api/v1/cart/sync', $extra + [
        'client_ticket_id' => 'tkt_1', 'idempotency_key' => freshKey(),
        'lines' => [['client_line_id' => 'a', 'product_id' => $tea['id'], 'quantity' => 1]],
    ]);

    $sync([])->assertOk()->assertJsonPath('data.cart.item_count', 2)->assertJsonPath('data.cart.items.0.quantity', 2);
    $sync(['strategy' => 'replace'])->assertOk()->assertJsonPath('data.cart.item_count', 1)->assertJsonPath('data.cart.items.0.product_name', 'Tea');

    $sync(['lines' => [['client_line_id' => 'z', 'product_id' => $rice['id'], 'quantity' => 100]]])->assertOk()
        ->assertJsonPath('data.results.0.status', 'adjusted')->assertJsonPath('data.results.0.quantity', 24);

    $sync(['lines' => []])->assertStatus(422);
    $sync(['strategy' => 'other'])->assertStatus(422);
    $sync(['lines' => [['client_line_id' => 'a', 'product_id' => 1, 'quantity' => 1], ['client_line_id' => 'a', 'product_id' => 2, 'quantity' => 1]]])->assertStatus(422);
});

it('rejects a held line with a zero quantity without failing the batch', function () {
    [, $token, $rice] = tillWithStock();

    test()->withToken($token)->postJson('/api/v1/cart/sync', [
        'client_ticket_id' => 't', 'idempotency_key' => freshKey(),
        'lines' => [['client_line_id' => 'a', 'product_id' => $rice['id'], 'quantity' => 0], ['client_line_id' => 'b', 'product_id' => $rice['id'], 'quantity' => 1]],
    ])->assertOk()->assertJsonPath('data.results.0.status', 'rejected')->assertJsonPath('data.results.0.reason.code', 'cart.quantity_invalid')->assertJsonPath('data.results.1.status', 'applied');
});
