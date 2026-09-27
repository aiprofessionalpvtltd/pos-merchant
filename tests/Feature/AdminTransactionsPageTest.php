<?php

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantAccount;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

it('lists a registration transaction with no shop', function () {
    $admin = merchantPagesAdmin(['view-transaction']);

    $regInvoice = Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'invoice_id' => 'REG-1', 'transaction_id' => 'reg_tx_1', 'hash' => '0',
        'mobile_number' => '+252655990701', 'amount' => 5, 'currency' => 'USD', 'status' => 'Paid', 'paid_at' => now(), 'type' => 'Registration', 'rail' => 'edahab',
    ]);
    Transaction::create([
        'invoice_id' => $regInvoice->id, 'transaction_amount' => '42500', 'transaction_status' => 'Paid',
        'transaction_message' => 'Registration payment received', 'phone_number' => '+252655990701', 'transaction_id' => 'reg_tx_1', 'payment_method' => 'edahab',
    ]);

    $rows = collect(table($admin, 'admin.transactions.show', [], ['transaction_id'], 'reg_tx_1')->assertOk()->json('data'));
    $regRow = $rows->firstWhere('transaction_id', 'reg_tx_1');

    expect($regRow['type'])->toBe('Registration')
        ->and($regRow['shop'])->toBe('—')
        ->and($regRow['merchant_account'])->toBeNull()
        ->and($regRow['amount'])->toBe('42,500 SLSH ($4.05)')
        ->and($regRow['action'])->toContain('/admin/invoices/'.$regInvoice->id.'/document')
        ->and($regRow['action'])->not->toContain('/admin/orders/');
});

it('lists a sale transaction with its shop, merchant and order', function () {
    $admin = merchantPagesAdmin(['view-transaction']);

    $owner = User::create(['name' => 'Sale Owner', 'email' => 'sale-owner-txn@example.test', 'password' => 'x', 'user_type' => 'merchant']);
    $account = MerchantAccount::create(['user_id' => $owner->id, 'first_name' => 'Sale', 'last_name' => 'Owner', 'phone_number' => '+252655990702']);
    $shop = Merchant::create(['merchant_id' => $account->id, 'business_name' => 'Sale Shop', 'phone_number' => '+252634990702', 'is_approved' => true, 'exchange_rate' => 8000]);
    $order = Order::create([
        'shop_id' => $shop->id, 'order_status' => 'Complete', 'sub_total' => 40, 'vat' => 0, 'exelo_amount' => 0,
        'total_price' => 40, 'total_price_sls' => 320000, 'exchange_rate' => 8000, 'order_type' => 'shop',
    ]);
    $saleInvoice = Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'invoice_id' => 'CASH-1', 'transaction_id' => 'CASH', 'hash' => '0',
        'merchant_id' => $shop->id, 'order_id' => $order->id, 'mobile_number' => $shop->phone_number, 'amount' => 40, 'currency' => 'USD',
        'status' => 'Paid', 'paid_at' => now(), 'type' => 'Sale', 'rail' => 'cash',
    ]);
    Transaction::create([
        'invoice_id' => $saleInvoice->id, 'merchant_id' => $shop->id, 'order_id' => $order->id, 'transaction_amount' => '320000',
        'transaction_status' => 'Paid', 'transaction_message' => 'Sale payment received', 'phone_number' => $shop->phone_number, 'transaction_id' => 'CASH', 'payment_method' => 'cash',
    ]);

    $saleRow = collect(table($admin, 'admin.transactions.show', [], ['transaction_id'], 'CASH')->assertOk()->json('data'))->first();

    expect($saleRow['type'])->toBe('Sale')
        ->and($saleRow['shop'])->toBe('Sale Shop')
        ->and($saleRow['merchant_account'])->toBe('Sale Owner')
        ->and($saleRow['amount'])->toBe('320,000 SLSH ($40.00)')
        ->and($saleRow['action'])->toContain('/admin/invoices/'.$saleInvoice->id.'/document')
        ->and($saleRow['action'])->toContain('/admin/orders/'.$order->id.'/view');
});

function seedStatusFilterTransactions(): void
{
    $paidInvoice = Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'invoice_id' => 'V-1', 'transaction_id' => 'vtxpaid9901', 'hash' => '0',
        'mobile_number' => '+252655990703', 'amount' => 2, 'currency' => 'USD', 'status' => 'Paid', 'paid_at' => now(), 'type' => 'Verification', 'rail' => 'edahab',
    ]);
    Transaction::create([
        'invoice_id' => $paidInvoice->id, 'transaction_amount' => '17000', 'transaction_status' => 'Paid',
        'transaction_message' => 'Verification payment received', 'phone_number' => '+252655990703', 'transaction_id' => 'vtxpaid9901', 'payment_method' => 'edahab',
    ]);
    Transaction::create([
        'transaction_amount' => '17000', 'transaction_status' => 'Failed',
        'transaction_message' => 'Failed', 'phone_number' => '+252655990704', 'transaction_id' => 'vtxfailed9902', 'payment_method' => 'edahab',
    ]);
}

it('filters transactions to only the paid ones', function () {
    $admin = merchantPagesAdmin(['view-transaction']);
    seedStatusFilterTransactions();

    $paid = table($admin, 'admin.transactions.show', ['status' => 'Paid'], ['transaction_id'], 'vtx')->assertOk()->json('data');
    expect(collect($paid)->pluck('transaction_id')->all())->toBe(['vtxpaid9901']);
});

it('filters transactions to only the failed ones', function () {
    $admin = merchantPagesAdmin(['view-transaction']);
    seedStatusFilterTransactions();

    $failed = table($admin, 'admin.transactions.show', ['status' => 'Failed'], ['transaction_id'], 'vtx')->assertOk()->json('data');
    expect(collect($failed)->pluck('transaction_id')->all())->toBe(['vtxfailed9902']);
});

it('keeps the transactions page behind view-transaction', function () {
    $noAccess = merchantPagesAdmin(['view-invoice']);

    table($noAccess, 'admin.transactions.show')->assertForbidden();
});
