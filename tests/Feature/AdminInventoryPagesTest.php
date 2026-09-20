<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

/**
 * @param  array<int, string>  $columns
 */
function table(User $admin, string $route, array $query = [], array $columns = ['product_name'], string $search = '')
{
    $query += [
        'draw' => 1, 'start' => 0, 'length' => 50,
        'columns' => collect($columns)->map(fn ($name) => ['data' => $name, 'name' => $name, 'searchable' => in_array($name, ['shop', 'stock', 'transit', 'lines', 'units', 'total', 'price_display'], true) ? 'false' : 'true', 'orderable' => 'true'])->all(),
        'search' => ['value' => $search],
    ];

    return test()->actingAs($admin, 'web')->getJson(route($route, $query), ['X-Requested-With' => 'XMLHttpRequest']);
}

function portalShop(): array
{
    [$owner, $token] = shopOwner();

    return [$owner, $token, merchantPagesAdmin(['view-product', 'view-cart'])];
}

it('lists every product with stock per location and a stock status', function () {
    [$owner, $token, $admin] = portalShop();
    addProduct($token, ['bar_code' => 'ADM-OK', 'product_name' => 'Plenty', 'quantity' => 40, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    addProduct($token, ['bar_code' => 'ADM-LOW', 'product_name' => 'Running low', 'quantity' => 2, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    addProduct($token, ['bar_code' => 'ADM-OUT', 'product_name' => 'Gone', 'quantity' => 0, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    $gone = addProduct($token, ['bar_code' => 'ADM-DEL', 'product_name' => 'Removed', 'quantity' => 5]);
    test()->withToken($token)->deleteJson('/api/v1/products/'.$gone['id'])->assertOk();

    $rows = collect(table($admin, 'admin.products.index', [], ['bar_code'], 'ADM-')->assertOk()->json('data'))->keyBy('product_name');

    expect($rows)->toHaveCount(4)
        ->and($rows['Plenty']['status'])->toBe('In stock')
        ->and($rows['Running low']['status'])->toBe('Low stock')
        ->and($rows['Gone']['status'])->toBe('Out of stock')
        ->and($rows['Removed']['status'])->toBe('Deleted')
        ->and($rows['Plenty']['merchant'])->toBe('Exelo Retail')
        ->and($rows['Plenty']['price_display'])->toBe('$18.50')
        ->and($rows['Plenty']['shop'])->toBe(40)
        ->and($rows['Plenty']['stock'])->toBe(0)
        ->and($rows['Plenty']['action'])->toContain('/admin/products/'.$rows['Plenty']['id']);
});

it('filters products by stock status, barcode and merchant', function () {
    [$owner, $token, $admin] = portalShop();
    addProduct($token, ['bar_code' => 'FLT-1', 'product_name' => 'Plenty', 'quantity' => 40, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    addProduct($token, ['bar_code' => 'FLT-2', 'product_name' => 'Running low', 'quantity' => 2, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    addProduct($token, ['bar_code' => 'FLT-3', 'product_name' => 'Gone', 'quantity' => 0]);

    $names = fn (array $query, string $search = 'FLT-') => collect(table($admin, 'admin.products.index', $query, ['bar_code'], $search)->assertOk()->json('data'))->pluck('product_name')->sort()->values()->all();

    expect($names(['status' => 'low']))->toBe(['Running low'])
        ->and($names(['status' => 'out']))->toBe(['Gone'])
        ->and($names(['status' => 'ok']))->toBe(['Plenty'])
        ->and($names(['status' => 'deleted']))->toBe([]);
});

it('finds a product by its barcode', function () {
    [$owner, $token, $admin] = portalShop();
    addProduct($token, ['bar_code' => 'FND-1', 'product_name' => 'First']);
    addProduct($token, ['bar_code' => 'FND-2', 'product_name' => 'Second']);

    $rows = table($admin, 'admin.products.index', [], ['bar_code'], 'FND-2')->assertOk()->json('data');

    expect(collect($rows)->pluck('product_name')->all())->toBe(['Second']);
});

it('finds products by merchant name', function () {
    [$owner, $token, $admin] = portalShop();
    addProduct($token, ['bar_code' => 'MER-1', 'product_name' => 'Belongs to the shop']);

    $rows = table($admin, 'admin.products.index', [], ['merchant'], 'Exelo Retail')->assertOk()->json('data');

    expect(collect($rows)->pluck('bar_code'))->toContain('MER-1');
});

it('sorts products by stock', function () {
    [$owner, $token, $admin] = portalShop();
    addProduct($token, ['bar_code' => 'SRT-1', 'product_name' => 'A', 'quantity' => 5]);
    addProduct($token, ['bar_code' => 'SRT-2', 'product_name' => 'B', 'quantity' => 50]);

    $response = table($admin, 'admin.products.index', ['order' => [['column' => 0, 'dir' => 'desc']]], ['shop', 'bar_code'], 'SRT-')->assertOk();

    expect(collect($response->json('data'))->pluck('product_name')->all())->toBe(['B', 'A']);
});

it('shows a product with its stock, sales and movement history', function () {
    [$owner, $token, $admin] = portalShop();
    $rice = addProduct($token, ['bar_code' => 'VIEW-1', 'product_name' => 'Basmati Rice 5kg', 'quantity' => 24]);

    test()->withToken($token)->postJson('/api/v1/inventory/transfers', ['product_id' => $rice['id'], 'quantity' => 5, 'from' => 'shop', 'to' => 'stock', 'note' => 'End of day', 'idempotency_key' => freshKey()])->assertOk();
    test()->withToken($token)->patchJson('/api/v1/inventory/'.$rice['id'].'/quantities', ['in_shop' => 15, 'reason' => 'recount', 'idempotency_key' => freshKey()])->assertOk();
    addLine($token, $rice['id'], 2)->assertOk();
    test()->withToken($token)->postJson('/api/v1/cart/pay', ['cart_version' => ticket($token)['version'], 'rail' => 'cash', 'idempotency_key' => freshKey()])->assertOk();

    test()->actingAs($admin, 'web')->get(route('admin.products.view', $rice['id']))
        ->assertOk()
        ->assertSee('Basmati Rice 5kg')
        ->assertSee('VIEW-1')
        ->assertSee('Exelo Retail')
        ->assertSee('Opening stock')
        ->assertSee('Transfer')
        ->assertSee('shop &rarr; stock', false)
        ->assertSee('End of day')
        ->assertSee('Correction')
        ->assertSee('recount')
        ->assertSee('Sale')
        ->assertSee('$37.00 before VAT');

    test()->actingAs($admin, 'web')->get(route('admin.products.view', 999999))->assertNotFound();
});

it('shows a deleted product and marks it deleted', function () {
    [$owner, $token, $admin] = portalShop();
    $gone = addProduct($token, ['bar_code' => 'DEL-1', 'product_name' => 'Removed item']);
    test()->withToken($token)->deleteJson('/api/v1/products/'.$gone['id'])->assertOk();

    test()->actingAs($admin, 'web')->get(route('admin.products.view', $gone['id']))->assertOk()->assertSee('Removed item')->assertSee('Deleted');
});

it('lists categories with how many products each holds', function () {
    [$owner, $token, $admin] = portalShop();
    $category = test()->withToken($token)->postJson('/api/v1/categories', ['name' => 'Portal test category'])->json('data.id');
    addProduct($token, ['bar_code' => 'CAT-1', 'category_id' => $category]);
    addProduct($token, ['bar_code' => 'CAT-2', 'category_id' => $category]);

    $rows = collect(table($admin, 'admin.categories.index', [], ['name'], 'Portal test category')->assertOk()->json('data'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['merchant'])->toBe('Exelo Retail')
        ->and($rows[0]['products_count'])->toBe(2)
        ->and($rows[0]['status'])->toBe('Active');
});

it('lists the open tickets on each till and hides empty ones', function () {
    [$owner, $token, $admin] = portalShop();
    $rice = addProduct($token, ['bar_code' => 'TKT-1', 'product_name' => 'Rice', 'quantity' => 24]);
    $tea = addProduct($token, ['bar_code' => 'TKT-2', 'product_name' => 'Tea', 'price' => ['amount' => 175, 'currency' => 'USD'], 'quantity' => 9]);
    addLine($token, $rice['id'], 2)->assertOk();
    addLine($token, $tea['id'], 1)->assertOk();

    Cart::create(['merchant_id' => $owner->merchant->id, 'user_id' => $owner->id, 'device_id' => 'till-empty', 'cart_type' => 'shop']);

    $open = collect(table($admin, 'admin.carts.index', [], ['device_id'])->assertOk()->json('data'))->where('merchant', 'Exelo Retail');
    expect($open)->toHaveCount(1);

    $row = $open->first();
    expect($row['cashier'])->toBe('Kalid Ahmed')
        ->and($row['device_id'])->toBe('dev-1')
        ->and($row['type'])->toBe('Shop')
        ->and($row['lines'])->toBe(2)
        ->and($row['units'])->toBe(3)
        ->and($row['total'])->toBe('$40.69')
        ->and($row['action'])->toContain('/admin/carts/'.$row['id']);

    $all = collect(table($admin, 'admin.carts.index', ['include_empty' => 1], ['device_id'])->assertOk()->json('data'))->where('merchant', 'Exelo Retail');
    expect($all)->toHaveCount(2)->and($all->pluck('device_id')->sort()->values()->all())->toBe(['dev-1', 'till-empty']);
});

it('shows a ticket with its lines and totals exactly as the till does', function () {
    [$owner, $token, $admin] = portalShop();
    $rice = addProduct($token, ['bar_code' => 'TKV-1', 'product_name' => 'Basmati Rice 5kg', 'quantity' => 24]);
    addLine($token, $rice['id'], 2)->assertOk();
    $cartId = ticket($token)['cart_id'];

    test()->actingAs($admin, 'web')->get(route('admin.carts.view', $cartId))
        ->assertOk()
        ->assertSee('Basmati Rice 5kg')
        ->assertSee('TKV-1')
        ->assertSee('Kalid Ahmed')
        ->assertSee('dev-1')
        ->assertSee('$37.00')
        ->assertSee('$1.85')
        ->assertSee('$38.85')
        ->assertSee('310,800 SLSH');

    test()->actingAs($admin, 'web')->get(route('admin.carts.view', 999999))->assertNotFound();
});

it('names a staff member and a legacy ticket clearly', function () {
    $owner = makeMerchant('2580');
    $staff = makeStaff($owner, '+252634990611', ['pos']);
    $admin = merchantPagesAdmin(['view-cart']);
    $rice = addProduct(ownerToken(), ['bar_code' => 'LGC-1', 'quantity' => 5]);

    $legacy = Cart::create(['merchant_id' => $owner->merchant->id, 'user_id' => $staff->user_id, 'cart_type' => 'shop']);
    CartItem::create(['cart_id' => $legacy->id, 'product_id' => $rice['id'], 'quantity' => 1, 'price' => 18.5]);

    $row = collect(table($admin, 'admin.carts.index', [], ['device_id'])->assertOk()->json('data'))->firstWhere('id', $legacy->id);

    expect($row['cashier'])->toBe('Layla Ahmed (staff)')->and($row['device_id'])->toBe('Legacy app');
    test()->actingAs($admin, 'web')->get(route('admin.carts.view', $legacy->id))->assertOk()->assertSee('Legacy app');
});

it('keeps the pages behind their permissions', function () {
    shopOwner();
    $noAccess = merchantPagesAdmin(['view-invoice']);

    foreach (['admin.products.index', 'admin.categories.index', 'admin.carts.index'] as $route) {
        table($noAccess, $route)->assertForbidden();
    }

    test()->actingAs($noAccess, 'web')->get(route('admin.products.view', 1))->assertForbidden();
    test()->actingAs($noAccess, 'web')->get(route('admin.carts.view', 1))->assertForbidden();

    auth()->forgetGuards();
    test()->get(route('admin.products.index'))->assertRedirect();
    test()->get(route('admin.carts.index'))->assertRedirect();
});

it('shows the menu entries to users who may open the pages', function () {
    $withBoth = merchantPagesAdmin(['view-product', 'view-cart']);

    test()->actingAs($withBoth, 'web')->get(route('admin.products.index'))
        ->assertOk()
        ->assertSee(route('admin.products.index'), false)
        ->assertSee(route('admin.categories.index'), false)
        ->assertSee(route('admin.carts.index'), false);
});
