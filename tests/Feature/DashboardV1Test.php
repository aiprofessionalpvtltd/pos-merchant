<?php

use App\Models\Order;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function dashboardShop(): array
{
    return [makeMerchant('2580'), ownerToken()];
}

/**
 * Sells $quantity of $productId for cash, then backdates the order's paid_at
 * and created_at so revenue/report queries can be tested across periods.
 */
function sellOn(string $token, int $productId, int $quantity, ?\Illuminate\Support\Carbon $when = null): Order
{
    addLine($token, $productId, $quantity)->assertOk();
    $cart = test()->withToken($token)->getJson('/api/v1/cart')->json('data');

    $orderId = test()->withToken($token)->postJson('/api/v1/cart/pay', [
        'cart_version' => $cart['version'], 'rail' => 'cash', 'idempotency_key' => freshKey(),
    ])->assertOk()->json('data.order.id');

    $order = Order::find($orderId);

    if ($when) {
        $order->forceFill(['paid_at' => $when, 'created_at' => $when])->save();
    }

    return $order->refresh();
}

it('summarises revenue, orders, product stock and alerts on the dashboard', function () {
    [$owner, $token] = dashboardShop();
    $rice = addProduct($token, ['bar_code' => 'DSH-1', 'product_name' => 'Rice', 'price' => ['amount' => 1000, 'currency' => 'USD'], 'quantity' => 20, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);
    addProduct($token, ['bar_code' => 'DSH-2', 'product_name' => 'Low stock', 'quantity' => 2, 'limits' => ['stock_limit' => 10, 'alarm_limit' => 4]]);

    sellOn($token, $rice['id'], 2, now());
    sellOn($token, $rice['id'], 1, now()->subWeek());

    $response = test()->withToken($token)->getJson('/api/v1/dashboard?weeks=2&history_limit=5')->assertOk();

    $response->assertJsonPath('data.period.timezone', 'Africa/Mogadishu')
        ->assertJsonPath('data.revenue.total.amount', 3150)
        ->assertJsonPath('data.series.granularity', 'day')
        ->assertJsonPath('data.series.weeks.0.is_current', true)
        ->assertJsonPath('data.series.weeks.0.total.amount', 2100)
        ->assertJsonPath('data.series.weeks.1.is_current', false)
        ->assertJsonPath('data.series.weeks.1.total.amount', 1050)
        ->assertJsonPath('data.orders.complete_count', 2)
        ->assertJsonPath('data.products.total_sold', 3)
        ->assertJsonPath('data.alerts.alarm_count', 1)
        ->assertJsonPath('data.alerts.restock_count', 2);

    expect(collect($response->json('data.top_selling'))->pluck('product_id'))->toContain($rice['id'])
        ->and($response->json('data.transaction_history'))->not->toBeEmpty()
        ->and($response->json('data.generated_at'))->not->toBeNull();
});

it('defaults weeks and history_limit and validates their bounds', function () {
    [, $token] = dashboardShop();

    test()->withToken($token)->getJson('/api/v1/dashboard')->assertOk()->assertJsonCount(4, 'data.series.weeks');
    test()->withToken($token)->getJson('/api/v1/dashboard?weeks=27')->assertStatus(422);
    test()->withToken($token)->getJson('/api/v1/dashboard?history_limit=0')->assertStatus(422);
});

it('needs a token for the dashboard and lets any signed-in staff read it', function () {
    test()->getJson('/api/v1/dashboard')->assertStatus(401);

    $owner = makeMerchant('2580');
    makeStaff($owner, '+252634990801', ['pos']);
    $staffToken = staffToken('+252634990801');

    test()->withToken($staffToken)->getJson('/api/v1/dashboard')->assertOk();
});

it('reports sales totals with a payment-method breakdown', function () {
    [, $token] = dashboardShop();
    $rice = addProduct($token, ['bar_code' => 'RPT-1', 'price' => ['amount' => 1000, 'currency' => 'USD'], 'vat_rate' => 0.05, 'quantity' => 10]);

    sellOn($token, $rice['id'], 2, now());

    $response = test()->withToken($token)->getJson('/api/v1/reports/sales')->assertOk();

    $response->assertJsonPath('data.totals.gross.amount', 2100)
        ->assertJsonPath('data.totals.order_count', 1)
        ->assertJsonPath('data.totals.products_sold', 2)
        ->assertJsonPath('data.totals.average_order.amount', 2100);

    $cash = collect($response->json('data.by_payment_method'))->firstWhere('method', 'cash');
    expect($cash['order_count'])->toBe(1)->and($cash['total']['amount'])->toBe(2100);
});

it('groups the sales report rows by week and by payment method', function () {
    [, $token] = dashboardShop();
    $rice = addProduct($token, ['bar_code' => 'RPT-2', 'price' => ['amount' => 1000, 'currency' => 'USD'], 'quantity' => 10]);
    sellOn($token, $rice['id'], 1, now());

    test()->withToken($token)->getJson('/api/v1/reports/sales?group_by=week')->assertOk()
        ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.products_sold', 1);
    test()->withToken($token)->getJson('/api/v1/reports/sales?group_by=day')->assertOk()
        ->assertJsonPath('data.rows.0.products_sold', 1);
    test()->withToken($token)->getJson('/api/v1/reports/sales?group_by=payment_method')->assertOk()
        ->assertJsonPath('data.rows.0.bucket', 'cash')->assertJsonPath('data.rows.0.products_sold', 1);
    test()->withToken($token)->getJson('/api/v1/reports/sales?group_by=bogus')->assertStatus(422);
});

it('reports inventory valuation and movement counts', function () {
    [, $token] = dashboardShop();
    $rice = addProduct($token, ['bar_code' => 'RPT-3', 'price' => ['amount' => 1000, 'currency' => 'USD'], 'quantity' => 10]);
    test()->withToken($token)->postJson('/api/v1/inventory/transfers', ['product_id' => $rice['id'], 'quantity' => 3, 'from' => 'shop', 'to' => 'stock', 'idempotency_key' => freshKey()])->assertOk();
    sellOn($token, $rice['id'], 1, now());

    $response = test()->withToken($token)->getJson('/api/v1/reports/inventory')->assertOk();

    $response->assertJsonPath('data.totals.product_count', 1)
        ->assertJsonPath('data.totals.units_in_shop', 6)
        ->assertJsonPath('data.totals.units_in_stock', 3)
        ->assertJsonPath('data.totals.shop_value.amount', 6000)
        ->assertJsonPath('data.totals.stock_value.amount', 3000)
        ->assertJsonPath('data.totals.total_value.amount', 9000)
        ->assertJsonPath('data.movement.added', 10)
        ->assertJsonPath('data.movement.sold', 1)
        ->assertJsonPath('data.movement.transferred', 3)
        ->assertJsonPath('data.rows.0.product_id', $rice['id'])
        ->assertJsonPath('data.rows.0.sold', 1);
});

it('reports products by each metric', function () {
    [, $token] = dashboardShop();
    $sold = addProduct($token, ['bar_code' => 'RPT-4', 'product_name' => 'Sold well', 'price' => ['amount' => 500, 'currency' => 'USD'], 'quantity' => 20]);
    addProduct($token, ['bar_code' => 'RPT-5', 'product_name' => 'On the shelf', 'quantity' => 15]);
    sellOn($token, $sold['id'], 4, now());

    $soldReport = test()->withToken($token)->getJson('/api/v1/reports/products?metric=sold')->assertOk();
    $soldReport->assertJsonPath('data.metric', 'sold')
        ->assertJsonPath('data.rows.0.product_id', $sold['id'])
        ->assertJsonPath('data.rows.0.units', 4)
        ->assertJsonPath('data.totals.units', 4)
        ->assertJsonPath('meta.pagination.total', 1);

    test()->withToken($token)->getJson('/api/v1/reports/products?metric=in_shop&sort=-units')->assertOk()
        ->assertJsonCount(2, 'data.rows');

    test()->withToken($token)->getJson('/api/v1/reports/products?metric=new_shop')->assertOk()
        ->assertJsonCount(2, 'data.rows');

    test()->withToken($token)->getJson('/api/v1/reports/products?metric=bogus')->assertStatus(422);
});

it('reports the catalogue grouped by category with sales attached', function () {
    [, $token] = dashboardShop();
    $categoryId = test()->withToken($token)->postJson('/api/v1/categories', ['name' => 'Dry goods'])->json('data.id');
    $rice = addProduct($token, ['bar_code' => 'RPT-6', 'category_id' => $categoryId, 'price' => ['amount' => 1000, 'currency' => 'USD'], 'quantity' => 10]);
    sellOn($token, $rice['id'], 2, now());

    $response = test()->withToken($token)->getJson('/api/v1/reports/catalogue')->assertOk();

    $category = collect($response->json('data.categories'))->firstWhere('id', $categoryId);
    expect($category['product_count'])->toBe(1)
        ->and($category['units_sold'])->toBe(2)
        ->and($category['revenue']['amount'])->toBe(2000)
        ->and($category['products'][0]['product_id'])->toBe($rice['id'])
        ->and($category['products'][0]['total_sold'])->toBe(2);
});

it('keeps the report endpoints behind the reports permission', function () {
    $owner = makeMerchant('2580');
    makeStaff($owner, '+252634990802', ['pos']);
    $token = staffToken('+252634990802');

    test()->withToken($token)->getJson('/api/v1/reports/sales')
        ->assertStatus(403)->assertJsonPath('error.details.required_permission', 'reports');

    app('auth')->forgetGuards();
    makeStaff($owner, '+252634990803', ['reports']);
    $reportsToken = staffToken('+252634990803');

    test()->withToken($reportsToken)->getJson('/api/v1/reports/sales')->assertOk();
    test()->withToken($reportsToken)->getJson('/api/v1/reports/inventory')->assertOk();
    test()->withToken($reportsToken)->getJson('/api/v1/reports/products')->assertOk();
    test()->withToken($reportsToken)->getJson('/api/v1/reports/catalogue')->assertOk();
});
