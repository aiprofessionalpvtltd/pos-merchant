<?php

use App\Models\Merchant;
use App\Models\MerchantAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

it('shows every order amount in both USD and SLSH, on the list and the detail page', function () {
    $admin = merchantPagesAdmin(['view-order']);

    $owner = User::create(['name' => 'Idil Osman', 'email' => 'idil-orders@example.test', 'password' => 'x', 'user_type' => 'merchant']);
    $account = MerchantAccount::create(['user_id' => $owner->id, 'first_name' => 'Idil', 'last_name' => 'Osman', 'phone_number' => '+252655990501']);
    $shop = Merchant::create(['merchant_id' => $account->id, 'user_id' => $owner->id, 'business_name' => 'Idil Store', 'phone_number' => '+252634990501', 'is_approved' => true, 'exchange_rate' => 8000]);

    $product = Product::create(['shop_id' => $shop->id, 'product_name' => 'Rice', 'price' => 20, 'vat' => 5, 'total_price' => 21, 'stock_limit' => 0, 'alarm_limit' => 0]);

    // Amounts are stored in USD, as OrderService writes them; total_price_sls is the
    // authoritative frozen SLSH figure. Using round numbers here to catch the old bug,
    // which misread these USD values as shillings and divided them down to near zero.
    $order = Order::create([
        'shop_id' => $shop->id, 'order_status' => 'Complete', 'name' => 'Customer One', 'mobile_number' => '+252635550101',
        'sub_total' => 20, 'vat' => 1, 'exelo_amount' => 0, 'total_price' => 21,
        'total_price_sls' => 168000, 'exchange_rate' => 8000, 'order_type' => 'shop',
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 20]);

    $row = collect(table($admin, 'admin.orders.show', [], ['id'], (string) $order->id)->assertOk()->json('data'))->first();

    expect($row['sub_total'])->toBe('$20.00 (160,000 SLSH)')
        ->and($row['vat'])->toBe('$1.00 (8,000 SLSH)')
        ->and($row['total_price'])->toBe('$21.00 (168,000 SLSH)');

    test()->actingAs($admin, 'web')->get(route('admin.orders.view', $order->id))
        ->assertOk()
        ->assertSee('$20.00')
        ->assertSee('160,000 SLSH')
        ->assertSee('$21.00')
        ->assertSee('168,000 SLSH');
});

it('keeps the orders pages behind view-order', function () {
    $noAccess = merchantPagesAdmin(['view-invoice']);

    table($noAccess, 'admin.orders.show')->assertForbidden();
    test()->actingAs($noAccess, 'web')->get(route('admin.orders.view', 1))->assertForbidden();
});
