<?php

use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

it('captures cart responses', function () {
    (new PlanCatalogueSeeder)->run();
    Http::fake(['edahab.net/api/api/IssueInvoice*' => Http::response(['StatusDescription' => 'Success', 'InvoiceId' => 555001])]);

    [$owner, $token] = shopOwner();
    $owner->merchant->update(['edahab_number' => '+252651110001', 'vat_rate' => 0.05]);
    app('auth')->forgetGuards();

    $rice = addProduct($token, ['bar_code' => 'RCE-005', 'product_name' => 'Basmati Rice 5kg', 'quantity' => 24]);
    $tea = addProduct($token, ['bar_code' => 'TEA-1', 'product_name' => 'Black Tea 100 bags', 'price' => ['amount' => 190, 'currency' => 'USD'], 'quantity' => 3]);
    $dates = addProduct($token, ['bar_code' => 'DTS-1', 'product_name' => 'Dates Premium 1kg', 'quantity' => 0]);

    $out = [];
    $save = function () use (&$out) { return file_put_contents('C:/Users/Jahan/AppData/Local/Temp/claude/d--laragon-www-pos-merchant/63068170-64bf-4868-8a63-a50d1740a6de/scratchpad/cart.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); };
    $call = function (string $name, string $method, string $url, array $body = [], array $headers = []) use (&$out, $token, $save) {
        $r = test()->withToken($token)->withHeaders($headers)->json($method, $url, $body);
        $out[$name] = ['status' => $r->status(), 'body' => $r->json()];
        test()->flushHeaders();
        $save();

        return $r;
    };
    $ver = function (string $name) use (&$out) { return $out[$name]['body']['data']['version'] ?? ($out[$name]['body']['data']['cart']['version'] ?? 1); };

    $call('cart_empty', 'GET', '/api/v1/cart');
    $call('add_rice', 'POST', '/api/v1/cart/items', ['product_id' => $rice['id'], 'quantity' => 2, 'idempotency_key' => 'k-add-1']);
    $call('add_rice_replay', 'POST', '/api/v1/cart/items', ['product_id' => $rice['id'], 'quantity' => 2, 'idempotency_key' => 'k-add-1']);
    $call('add_tea', 'POST', '/api/v1/cart/items', ['product_id' => $tea['id'], 'quantity' => 1, 'unit_price' => ['amount' => 175, 'currency' => 'USD'], 'idempotency_key' => 'k-add-2']);
    $call('add_too_many', 'POST', '/api/v1/cart/items', ['product_id' => $tea['id'], 'quantity' => 5, 'idempotency_key' => 'k-add-3']);
    $call('add_out_of_stock', 'POST', '/api/v1/cart/items', ['product_id' => $dates['id'], 'quantity' => 1, 'idempotency_key' => 'k-add-4']);
    $call('add_not_found', 'POST', '/api/v1/cart/items', ['product_id' => 999999, 'idempotency_key' => 'k-add-5']);
    $call('add_zero', 'POST', '/api/v1/cart/items', ['product_id' => $rice['id'], 'quantity' => 0, 'idempotency_key' => 'k-add-6']);
    $call('add_invalid', 'POST', '/api/v1/cart/items', ['quantity' => 1]);

    $call('update_qty', 'PATCH', '/api/v1/cart/items/'.$rice['id'], ['quantity' => 3, 'unit_price' => ['amount' => 1800, 'currency' => 'USD']]);
    $call('update_conflict', 'PATCH', '/api/v1/cart/items/'.$rice['id'], ['quantity' => 4], ['If-Match' => (string) ($ver('update_qty') - 1)]);
    $call('update_zero', 'PATCH', '/api/v1/cart/items/'.$rice['id'], ['quantity' => 0]);
    $call('update_missing', 'PATCH', '/api/v1/cart/items/999999', ['quantity' => 1]);

    $call('remove_tea', 'DELETE', '/api/v1/cart/items/'.$tea['id']);
    $call('remove_missing', 'DELETE', '/api/v1/cart/items/'.$tea['id']);

    $syncBody = [
        'client_ticket_id' => 'tkt_local_18', 'idempotency_key' => 'k-sync-1', 'strategy' => 'merge',
        'lines' => [
            ['client_line_id' => 'ln_1', 'product_id' => $rice['id'], 'quantity' => 2],
            ['client_line_id' => 'ln_2', 'product_id' => 999999, 'quantity' => 1],
            ['client_line_id' => 'ln_3', 'product_id' => $tea['id'], 'quantity' => 5, 'unit_price' => ['amount' => 175, 'currency' => 'USD']],
        ],
    ];
    $call('sync', 'POST', '/api/v1/cart/sync', $syncBody);
    $call('sync_replay', 'POST', '/api/v1/cart/sync', $syncBody);

    $call('cart_before_pay', 'GET', '/api/v1/cart');
    $v = $ver('cart_before_pay');
    $call('pay_stale', 'POST', '/api/v1/cart/pay', ['cart_version' => $v - 1, 'rail' => 'cash', 'idempotency_key' => 'k-pay-0']);
    $call('pay_tender_low', 'POST', '/api/v1/cart/pay', ['cart_version' => $v, 'rail' => 'cash', 'amount_tendered' => ['amount' => 1000, 'currency' => 'USD'], 'idempotency_key' => 'k-pay-1']);
    $call('pay_rail_unavailable', 'POST', '/api/v1/cart/pay', ['cart_version' => $v, 'rail' => 'zaad', 'customer' => ['wallet_number' => '+252632220009'], 'idempotency_key' => 'k-pay-2']);
    $call('pay_wallet_bad_number', 'POST', '/api/v1/cart/pay', ['cart_version' => $v, 'rail' => 'edahab', 'customer' => ['wallet_number' => '+252632220009'], 'idempotency_key' => 'k-pay-3']);
    $call('pay_wallet', 'POST', '/api/v1/cart/pay', ['cart_version' => $v, 'rail' => 'edahab', 'customer' => ['wallet_number' => '+252651110009', 'name' => 'Amina Yusuf'], 'idempotency_key' => 'k-pay-4']);
    $call('pay_wallet_again', 'POST', '/api/v1/cart/pay', ['cart_version' => $v, 'rail' => 'edahab', 'customer' => ['wallet_number' => '+252651110009'], 'idempotency_key' => 'k-pay-5']);

    $chargeId = $out['pay_wallet']['body']['data']['charge_id'] ?? null;
    if ($chargeId) {
        \App\Models\Invoice::where('public_id', $chargeId)->update(['status' => 'Expired', 'expires_at' => now()->subMinute()]);
    }

    $call('pay_cash', 'POST', '/api/v1/cart/pay', ['cart_version' => $v, 'rail' => 'cash', 'amount_tendered' => ['amount' => 6000, 'currency' => 'USD'], 'customer' => ['name' => 'Amina Yusuf', 'mobile_number' => '+252635550101'], 'idempotency_key' => 'k-pay-6']);
    $call('pay_empty', 'POST', '/api/v1/cart/pay', ['cart_version' => $ver('pay_cash'), 'rail' => 'cash', 'idempotency_key' => 'k-pay-7']);

    $call('add_for_hold', 'POST', '/api/v1/cart/items', ['product_id' => $rice['id'], 'quantity' => 2, 'idempotency_key' => 'k-add-7']);
    $call('hold', 'POST', '/api/v1/cart/hold', ['customer' => ['name' => 'Amina Yusuf', 'mobile_number' => '+252635550101'], 'note' => 'Collecting Thursday', 'idempotency_key' => 'k-hold-1']);
    $call('hold_empty', 'POST', '/api/v1/cart/hold', ['customer' => ['name' => 'Amina Yusuf', 'mobile_number' => '+252635550101'], 'idempotency_key' => 'k-hold-2']);
    $call('hold_invalid', 'POST', '/api/v1/cart/hold', ['idempotency_key' => 'k-hold-3']);

    $call('add_for_clear', 'POST', '/api/v1/cart/items', ['product_id' => $rice['id'], 'quantity' => 1, 'idempotency_key' => 'k-add-8']);
    $call('clear', 'DELETE', '/api/v1/cart');

    expect(true)->toBeTrue();
});
