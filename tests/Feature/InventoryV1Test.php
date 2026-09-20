<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\InventoryHistory;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function shopOwner(): array
{
    $owner = makeMerchant('2580');
    $owner->merchant->update(['exchange_rate' => 8000]);

    return [$owner, ownerToken()];
}

function productBody(array $overrides = []): array
{
    return $overrides + [
        'client_uuid' => (string) Str::uuid(), 'product_name' => 'Basmati Rice 5kg', 'bar_code' => 'RCE-005',
        'price' => ['amount' => 1850, 'currency' => 'USD'], 'vat_rate' => 0.05, 'quantity' => 24, 'type' => 'shop',
        'limits' => ['stock_limit' => 10, 'alarm_limit' => 4],
    ];
}

function addProduct(string $token, array $overrides = []): array
{
    return test()->withToken($token)->postJson('/api/v1/products', productBody($overrides))->assertSuccessful()->json('data');
}

function otherShop(): Merchant
{
    return Merchant::create(['first_name' => 'Other', 'last_name' => 'Shop', 'phone_number' => '+252634990999', 'business_name' => 'Other shop']);
}

function freshKey(): string
{
    return (string) Str::uuid();
}

it('adds a product with its opening stock and history', function () {
    [$owner, $token] = shopOwner();

    $response = test()->withToken($token)->postJson('/api/v1/products', productBody(['client_uuid' => 'dev-uuid-1']))
        ->assertCreated()
        ->assertJsonPath('message', 'Product added')
        ->assertJsonPath('data.client_uuid', 'dev-uuid-1')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.price.amount', 1850)
        ->assertJsonPath('data.price.display', '$18.50')
        ->assertJsonPath('data.price_alt.amount', 148000)
        ->assertJsonPath('data.vat_rate', 0.05)
        ->assertJsonPath('data.quantities.in_shop', 24)
        ->assertJsonPath('data.quantities.in_stock', 0)
        ->assertJsonPath('data.limits.alarm_limit', 4)
        ->assertJsonPath('data.image', null);

    $product = Product::find($response->json('data.id'));
    expect($product->merchant_id)->toBe($owner->merchant->id)
        ->and((float) $product->total_price)->toBe(19.43)
        ->and($product->vat)->toBe(5)
        ->and(InventoryHistory::where('product_id', $product->id)->where('kind', 'opening')->count())->toBe(1);
});

it('returns the same product when an offline create is replayed', function () {
    [, $token] = shopOwner();

    $first = test()->withToken($token)->postJson('/api/v1/products', productBody(['client_uuid' => 'dev-uuid-2']))->assertCreated()->json('data.id');
    $again = test()->withToken($token)->postJson('/api/v1/products', productBody(['client_uuid' => 'dev-uuid-2', 'product_name' => 'Changed']))
        ->assertOk()->assertJsonPath('message', 'Product already added');

    expect($again->json('data.id'))->toBe($first)->and(Product::where('client_uuid', 'dev-uuid-2')->count())->toBe(1);
});

it('converts an SLSH price and refuses a taken barcode or foreign category', function () {
    [, $token] = shopOwner();

    test()->withToken($token)->postJson('/api/v1/products', productBody(['bar_code' => 'SLS-1', 'price' => ['amount' => 80000, 'currency' => 'SLSH']]))
        ->assertCreated()->assertJsonPath('data.price.amount', 1000);

    test()->withToken($token)->postJson('/api/v1/products', productBody(['bar_code' => 'SLS-1']))
        ->assertStatus(409)->assertJsonPath('error.code', 'product.barcode_taken')->assertJsonPath('error.field', 'bar_code');

    $foreign = Category::create(['merchant_id' => otherShop()->id, 'name' => 'Not mine']);
    test()->withToken($token)->postJson('/api/v1/products', productBody(['bar_code' => 'NEW-1', 'category_id' => $foreign->id]))
        ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    test()->withToken($token)->postJson('/api/v1/products', ['product_name' => 'x'])->assertStatus(422);
});

it('lists the catalogue with filters and pagination', function () {
    [, $token] = shopOwner();
    $category = test()->withToken($token)->postJson('/api/v1/categories', ['name' => 'Dry goods'])->json('data.id');

    addProduct($token, ['bar_code' => 'A-1', 'product_name' => 'Rice', 'category_id' => $category]);
    addProduct($token, ['bar_code' => 'B-1', 'product_name' => 'Sugar', 'quantity' => 0, 'type' => 'stock']);
    addProduct($token, ['bar_code' => 'C-1', 'product_name' => 'Tea']);

    $list = fn (string $query) => test()->withToken($token)->getJson('/api/v1/products'.$query);

    $list('')->assertOk()->assertJsonPath('meta.pagination.total', 3)->assertJsonCount(3, 'data');
    $list('?type=stock')->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_name', 'Sugar');
    $list('?type=shop&in_stock_only=1')->assertJsonCount(2, 'data');
    $list('?in_stock_only=1')->assertJsonCount(2, 'data');
    $list('?category_id='.$category)->assertJsonCount(1, 'data')->assertJsonPath('data.0.category.name', 'Dry goods');
    $list('?q=su')->assertJsonCount(1, 'data');
    $list('?q=C-1')->assertJsonCount(1, 'data');
    $list('?per_page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.has_more', true)->assertJsonPath('meta.pagination.total_pages', 2);
    $list('?per_page=2&page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.has_more', false);
    $list('?per_page=500')->assertStatus(422);

    expect($list('')->json('meta.sync_cursor'))->not->toBeNull();
});

it('only shows a shop its own products', function () {
    [, $token] = shopOwner();
    $theirs = Product::create(['merchant_id' => otherShop()->id, 'product_name' => 'Other shop', 'price' => 1, 'vat' => 0, 'total_price' => 1, 'stock_limit' => 0, 'alarm_limit' => 0]);
    $mine = addProduct($token);

    test()->withToken($token)->getJson('/api/v1/products')->assertJsonCount(1, 'data');
    test()->withToken($token)->getJson('/api/v1/products/'.$mine['id'])->assertOk()->assertJsonPath('data.product_name', 'Basmati Rice 5kg');
    test()->withToken($token)->getJson('/api/v1/products/'.$theirs->id)
        ->assertStatus(404)->assertJsonPath('error.code', 'product.not_found');
});

it('reports deleted products as tombstones for a delta pull', function () {
    [, $token] = shopOwner();
    $gone = addProduct($token, ['bar_code' => 'G-1']);
    addProduct($token, ['bar_code' => 'K-1']);

    $since = now()->subMinute()->toIso8601String();

    test()->withToken($token)->deleteJson('/api/v1/products/'.$gone['id'])->assertOk()->assertJsonPath('message', 'Product deleted');

    test()->withToken($token)->getJson('/api/v1/products')->assertJsonCount(1, 'data');

    $delta = test()->withToken($token)->getJson('/api/v1/products?updated_since='.urlencode($since))->assertOk();
    $rows = collect($delta->json('data'))->keyBy('id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$gone['id']]['deleted_at'])->not->toBeNull()
        ->and(array_keys($rows[$gone['id']]))->toBe(['id', 'version', 'deleted_at']);
});

it('finds a product by barcode in any spelling', function () {
    [, $token] = shopOwner();
    addProduct($token, ['bar_code' => 'RCE-005']);
    addProduct($token, ['bar_code' => '0012345678905', 'product_name' => 'Tea']);

    $find = fn (string $code, string $type = 'shop') => test()->withToken($token)->getJson('/api/v1/products/lookup?barcode='.urlencode($code).'&type='.$type);

    $find('RCE-005')->assertOk()->assertJsonPath('data.product_name', 'Basmati Rice 5kg')->assertJsonPath('data.quantities.in_shop', 24);
    $find('rce005')->assertOk()->assertJsonPath('data.product_name', 'Basmati Rice 5kg');
    $find('12345678905')->assertOk()->assertJsonPath('data.product_name', 'Tea');
    $find('0012345678905')->assertOk()->assertJsonPath('data.product_name', 'Tea');

    $find('RCE-999')->assertStatus(404)
        ->assertJsonPath('error.code', 'product.barcode_unknown')
        ->assertJsonPath('error.details.barcode', 'RCE-999')
        ->assertJsonPath('error.details.normalised', 'RCE999');

    $find('RCE-005', 'stock')->assertStatus(404);
    test()->withToken($token)->getJson('/api/v1/products/lookup')->assertStatus(422);
});

it('lets a till with only the pos permission scan but not browse', function () {
    $owner = makeMerchant('2580');
    $ownerToken = ownerToken();
    addProduct($ownerToken);
    makeStaff($owner, '+252634990311', ['pos']);

    app('auth')->forgetGuards();
    $token = staffToken('+252634990311');

    test()->withToken($token)->getJson('/api/v1/products/lookup?barcode=RCE-005')->assertOk();
    test()->withToken($token)->getJson('/api/v1/categories')->assertOk();
    test()->withToken($token)->getJson('/api/v1/products')->assertStatus(403)->assertJsonPath('error.details.required_permission', 'inventory');
    test()->withToken($token)->postJson('/api/v1/products', productBody())->assertStatus(403);
    test()->withToken($token)->getJson('/api/v1/inventory/alerts')->assertStatus(403);
});

it('edits a product, keeps its version and refuses stale or reused requests', function () {
    [, $token] = shopOwner();
    $product = addProduct($token);
    $url = '/api/v1/products/'.$product['id'];
    $edit = ['product_name' => 'Basmati Rice Premium', 'price' => ['amount' => 1900, 'currency' => 'USD'], 'idempotency_key' => $k = freshKey()];

    test()->withToken($token)->patchJson($url, $edit)
        ->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.price.amount', 1900)
        ->assertJsonPath('data.price_alt.amount', 152000)
        ->assertJsonPath('data.quantities.in_shop', 24);

    test()->withToken($token)->patchJson($url, $edit)->assertOk()->assertJsonPath('data.version', 2);
    expect(Product::find($product['id'])->version)->toBe(2);

    test()->withToken($token)->patchJson($url, ['product_name' => 'Different', 'idempotency_key' => $k])
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency.key_reused');

    test()->withToken($token)->withHeader('If-Match', '1')->patchJson($url, ['product_name' => 'Late', 'idempotency_key' => freshKey()])
        ->assertStatus(409)->assertJsonPath('error.code', 'resource.version_conflict')->assertJsonPath('error.details.current.version', 2);

    test()->withToken($token)->withHeader('If-Match', '2')->patchJson($url, ['vat_rate' => 0.1, 'idempotency_key' => freshKey()])
        ->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.vat_rate', 0.1);

    test()->withToken($token)->patchJson($url, ['product_name' => 'No key'])->assertStatus(422);
});

it('refuses to move a barcode onto another product', function () {
    [, $token] = shopOwner();
    addProduct($token, ['bar_code' => 'ONE-1']);
    $two = addProduct($token, ['bar_code' => 'TWO-1']);

    test()->withToken($token)->patchJson('/api/v1/products/'.$two['id'], ['bar_code' => 'ONE-1', 'idempotency_key' => freshKey()])
        ->assertStatus(409)->assertJsonPath('error.code', 'product.barcode_taken');

    test()->withToken($token)->patchJson('/api/v1/products/'.$two['id'], ['bar_code' => 'TWO-1', 'idempotency_key' => freshKey()])->assertOk();
});

it('will not delete a product that is on an open ticket', function () {
    [$owner, $token] = shopOwner();
    $product = addProduct($token);
    $cart = Cart::create(['merchant_id' => $owner->merchant->id, 'user_id' => $owner->id, 'cart_type' => 'shop']);
    CartItem::create(['cart_id' => $cart->id, 'product_id' => $product['id'], 'quantity' => 1, 'price' => 18.5]);

    test()->withToken($token)->deleteJson('/api/v1/products/'.$product['id'])
        ->assertStatus(409)->assertJsonPath('error.code', 'product.in_active_cart')->assertJsonPath('error.details.cart_ids', [$cart->id]);

    expect(Product::find($product['id']))->not->toBeNull();
});

it('creates categories, refuses duplicates and counts products', function () {
    [, $token] = shopOwner();

    $id = test()->withToken($token)->postJson('/api/v1/categories', ['name' => 'Dairy'])
        ->assertCreated()->assertJsonPath('data.name', 'Dairy')->assertJsonPath('data.product_count', 0)->json('data.id');

    test()->withToken($token)->postJson('/api/v1/categories', ['name' => 'dairy'])
        ->assertStatus(409)->assertJsonPath('error.code', 'category.name_taken')->assertJsonPath('error.details.category.id', $id);

    addProduct($token, ['category_id' => $id]);

    test()->withToken($token)->getJson('/api/v1/categories?with_counts=1')->assertOk()->assertJsonPath('data.0.product_count', 1);
    test()->withToken($token)->getJson('/api/v1/categories')->assertJsonMissingPath('data.0.product_count');
    test()->withToken($token)->getJson('/api/v1/categories?q=zzz')->assertJsonCount(0, 'data');
    test()->withToken($token)->postJson('/api/v1/categories', [])->assertStatus(422);
});

it('moves stock between locations and records the movement', function () {
    [$owner, $token] = shopOwner();
    $product = addProduct($token);

    $move = fn (array $body) => test()->withToken($token)->postJson('/api/v1/inventory/transfers', $body + ['product_id' => $product['id'], 'idempotency_key' => freshKey()]);

    $body = ['quantity' => 5, 'from' => 'shop', 'to' => 'stock', 'note' => 'End of day', 'idempotency_key' => $k = freshKey()];
    $move($body)->assertOk()
        ->assertJsonPath('message', '5 moved from shop to stock')
        ->assertJsonPath('data.quantities_after.in_shop', 19)
        ->assertJsonPath('data.quantities_after.in_stock', 5);

    $move($body)->assertOk()->assertJsonPath('data.quantities_after.in_shop', 19);
    expect(ProductInventory::where('product_id', $product['id'])->where('type', 'shop')->value('quantity'))->toBe(19)
        ->and(InventoryHistory::where('product_id', $product['id'])->where('kind', 'transfer')->count())->toBe(1);

    $move(['quantity' => 50, 'from' => 'shop', 'to' => 'stock'])
        ->assertStatus(409)->assertJsonPath('error.code', 'inventory.insufficient_quantity')->assertJsonPath('error.details.available', 19);

    $move(['quantity' => 1, 'from' => 'shop', 'to' => 'shop'])->assertStatus(422);
    $move(['quantity' => 0, 'from' => 'shop', 'to' => 'stock'])->assertStatus(422);
    $move(['quantity' => 1, 'from' => 'shop', 'to' => 'transportation'])->assertOk()->assertJsonPath('data.quantities_after.in_transportation', 1);

    test()->withToken($token)->postJson('/api/v1/inventory/transfers', array_merge($body, ['product_id' => $product['id'], 'quantity' => 6]))
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency.key_reused');

    test()->withToken($token)->postJson('/api/v1/inventory/transfers', ['product_id' => 999999, 'quantity' => 1, 'from' => 'shop', 'to' => 'stock', 'idempotency_key' => freshKey()])
        ->assertStatus(404)->assertJsonPath('error.code', 'product.not_found');
});

it('corrects quantities with a reason and reports the change', function () {
    [, $token] = shopOwner();
    $product = addProduct($token);
    addProduct($token, ['bar_code' => 'X-9']);
    $url = '/api/v1/inventory/'.$product['id'].'/quantities';

    test()->withToken($token)->patchJson($url, ['in_shop' => 20, 'in_stock' => 6, 'reason' => 'recount', 'note' => 'Monthly count', 'idempotency_key' => $k = freshKey()])
        ->assertOk()
        ->assertJsonPath('data.quantities.in_shop', 20)
        ->assertJsonPath('data.quantities.in_stock', 6)
        ->assertJsonPath('data.adjustment.in_shop', -4)
        ->assertJsonPath('data.adjustment.in_stock', 6)
        ->assertJsonPath('data.reason', 'recount')
        ->assertJsonPath('data.version', 2);

    expect(InventoryHistory::where('product_id', $product['id'])->where('kind', 'adjustment')->where('reason', 'recount')->count())->toBe(2);

    test()->withToken($token)->patchJson($url, ['in_shop' => 20, 'reason' => 'recount', 'idempotency_key' => freshKey()])
        ->assertOk()->assertJsonPath('data.adjustment', [])->assertJsonPath('data.version', 2);

    test()->withToken($token)->patchJson($url, ['reason' => 'recount', 'idempotency_key' => freshKey()])->assertStatus(422);
    test()->withToken($token)->patchJson($url, ['in_shop' => 1, 'reason' => 'lost', 'idempotency_key' => freshKey()])->assertStatus(422);
    test()->withToken($token)->patchJson($url, ['in_shop' => -1, 'reason' => 'recount', 'idempotency_key' => freshKey()])->assertStatus(422);
});

it('lists low stock alerts with severity and a summary', function () {
    [, $token] = shopOwner();
    addProduct($token, ['bar_code' => 'L-1', 'product_name' => 'Low', 'quantity' => 2, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    addProduct($token, ['bar_code' => 'L-2', 'product_name' => 'Empty', 'quantity' => 0, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    addProduct($token, ['bar_code' => 'L-3', 'product_name' => 'Fine', 'quantity' => 50, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    addProduct($token, ['bar_code' => 'L-4', 'product_name' => 'No limits', 'quantity' => 0, 'limits' => ['stock_limit' => 0, 'alarm_limit' => 0]]);

    $alarm = test()->withToken($token)->getJson('/api/v1/inventory/alerts')->assertOk();

    expect(collect($alarm->json('data'))->pluck('product_name')->all())->toBe(['Empty', 'Low']);
    $alarm->assertJsonPath('data.0.severity', 'critical')
        ->assertJsonPath('data.0.shortfall', 4)
        ->assertJsonPath('data.1.severity', 'warning')
        ->assertJsonPath('data.1.shortfall', 2)
        ->assertJsonPath('meta.summary.alarm_count', 2)
        ->assertJsonPath('meta.summary.restock_count', 3)
        ->assertJsonPath('meta.pagination.total', 2);

    test()->withToken($token)->getJson('/api/v1/inventory/alerts?type=restock')->assertOk()->assertJsonPath('meta.pagination.total', 3);
    test()->withToken($token)->getJson('/api/v1/inventory/alerts?type=bogus')->assertStatus(422);
});

it('needs a token for the inventory endpoints', function () {
    test()->getJson('/api/v1/products')->assertStatus(401);
    test()->getJson('/api/v1/categories')->assertStatus(401);
    test()->postJson('/api/v1/inventory/transfers', [])->assertStatus(401);
});
