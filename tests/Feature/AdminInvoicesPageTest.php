<?php

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\InvoiceDocumentService;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function makeInvoice(array $overrides = []): Invoice
{
    return Invoice::create($overrides + [
        'public_id' => Invoice::generatePublicId(), 'invoice_id' => 'CASH-x', 'transaction_id' => 'cash_x', 'hash' => '0',
        'mobile_number' => '+252634990001', 'amount' => 92000, 'currency' => 'SLSH', 'status' => 'Paid', 'paid_at' => now(),
        'type' => 'Subscription', 'rail' => 'cash', 'subscription_plan_id' => SubscriptionPlan::where('key', 'gold')->value('id'),
    ]);
}

function listInvoices(User $admin, array $query = [])
{
    $query += [
        'draw' => 1, 'start' => 0, 'length' => 25,
        'columns' => [['data' => 'mobile_number', 'name' => 'mobile_number', 'searchable' => 'true', 'orderable' => 'false']],
        'search' => ['value' => '+252634990001'],
    ];

    return test()->actingAs($admin, 'web')->getJson(route('admin.invoices.show', $query), ['X-Requested-With' => 'XMLHttpRequest']);
}

it('lists invoices with number, formatted amount, method and only the right buttons', function () {
    $admin = merchantPagesAdmin(['view-invoice', 'edit-invoice']);
    $paid = makeInvoice();
    $pending = makeInvoice(['status' => 'Pending', 'paid_at' => null]);

    $rows = collect(listInvoices($admin)->assertOk()->json('data'));

    $paidRow = $rows->firstWhere('invoice_no', 'INV-'.str_pad((string) $paid->id, 6, '0', STR_PAD_LEFT));
    $pendingRow = $rows->firstWhere('invoice_no', 'INV-'.str_pad((string) $pending->id, 6, '0', STR_PAD_LEFT));

    expect($paidRow['amount'])->toBe('92,000 SLSH')
        ->and($paidRow['method'])->toBe('Cash')
        // a paid invoice has a document; pending does not, and only pending cash can be confirmed
        ->and($paidRow['action'])->toContain("/admin/invoices/{$paid->id}/pdf")->toContain("/admin/invoices/{$paid->id}/document")
        ->and($paidRow['action'])->not->toContain('confirm-cash')
        ->and($pendingRow['action'])->toContain('confirm-cash')->not->toContain('/pdf');
});

it('filters the list by status', function () {
    $admin = merchantPagesAdmin(['view-invoice']);
    makeInvoice();
    makeInvoice(['status' => 'Pending', 'paid_at' => null]);

    $statuses = collect(listInvoices($admin, ['status' => 'Paid'])->assertOk()->json('data'))->pluck('status')->unique()->values();

    expect($statuses->all())->toBe(['Paid']);
});

it('finds an invoice by its number', function () {
    $admin = merchantPagesAdmin(['view-invoice']);
    $invoice = makeInvoice();
    $number = 'INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);

    $rows = listInvoices($admin, [
        'columns' => [['data' => 'invoice_no', 'name' => 'invoice_no', 'searchable' => 'true', 'orderable' => 'false']],
        'search' => ['value' => $number],
    ])->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['invoice_no'])->toBe($number);
});

it('escapes merchant names in the list instead of trusting them as HTML', function () {
    $admin = merchantPagesAdmin(['view-invoice']);
    $merchant = Merchant::create(['phone_number' => '+252634990066', 'first_name' => 'X', 'last_name' => 'Y', 'business_name' => '<script>alert(1)</script>']);
    makeInvoice(['merchant_id' => $merchant->id, 'mobile_number' => '+252634990066']);

    $rows = listInvoices($admin, ['search' => ['value' => '+252634990066']])->assertOk()->json('data');

    expect($rows[0]['merchant'])->not->toContain('<script>');
});

it('shows a printable invoice for a paid subscription with the plan and period', function () {
    $admin = merchantPagesAdmin(['view-invoice']);
    $owner = makeMerchant('2580');
    $invoice = makeInvoice(['merchant_id' => $owner->merchant->id]);
    MerchantSubscription::create([
        'merchant_id' => $owner->merchant->id, 'subscription_plan_id' => $invoice->subscription_plan_id, 'invoice_id' => $invoice->id,
        'start_date' => '2026-09-19', 'end_date' => '2026-10-19', 'transaction_status' => 'Paid',
    ]);
    $company = Setting::first()?->company_name ?? config('app.name');

    $this->actingAs($admin, 'web')->get(route('admin.invoices.document', $invoice->id))
        ->assertOk()
        ->assertSee('INVOICE')
        ->assertSee('INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT))
        ->assertSee($company)
        ->assertSee('Billed to')
        ->assertSee('Exelo Retail')
        ->assertSee('Hargeisa, Maroodi Jeex')
        ->assertSee('Gold subscription (monthly)')
        ->assertSee('19 Sep 2026 – 19 Oct 2026')
        ->assertSee('92,000 SLSH')
        ->assertSee('PAID')
        ->assertSee('Download PDF');
});

it('describes a registration invoice that has no merchant yet', function () {
    $admin = merchantPagesAdmin(['view-invoice']);
    $invoice = makeInvoice(['type' => 'Registration', 'rail' => 'edahab', 'subscription_plan_id' => null, 'amount' => 550, 'mobile_number' => '+252654110199']);

    $this->actingAs($admin, 'web')->get(route('admin.invoices.document', $invoice->id))
        ->assertOk()
        ->assertSee('merchant registration fee')
        ->assertSee('+252654110199')
        ->assertSee('eDahab')
        ->assertSee('550 SLSH');
});

it('downloads the invoice as a real PDF', function () {
    $admin = merchantPagesAdmin(['view-invoice']);
    $owner = makeMerchant('2580');
    $invoice = makeInvoice(['merchant_id' => $owner->merchant->id]);

    $response = $this->actingAs($admin, 'web')->get(route('admin.invoices.pdf', $invoice->id))->assertOk();

    $filename = 'INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT).'.pdf';

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('attachment')->toContain($filename)
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('has no invoice document until the payment is paid', function () {
    $admin = merchantPagesAdmin(['view-invoice']);
    $pending = makeInvoice(['status' => 'Pending', 'paid_at' => null]);

    $this->actingAs($admin, 'web')->get(route('admin.invoices.document', $pending->id))->assertNotFound();
    $this->actingAs($admin, 'web')->get(route('admin.invoices.pdf', $pending->id))->assertNotFound();
});

it('requires view-invoice for the list and both invoice documents', function () {
    $admin = merchantPagesAdmin([]);
    $invoice = makeInvoice();

    $this->actingAs($admin, 'web')->get(route('admin.invoices.document', $invoice->id))->assertForbidden();
    $this->actingAs($admin, 'web')->get(route('admin.invoices.pdf', $invoice->id))->assertForbidden();
    listInvoices($admin)->assertForbidden();
});

it('numbers invoices with a zero-padded id', function () {
    $invoice = makeInvoice();

    expect(app(InvoiceDocumentService::class)->number($invoice))->toBe('INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT));
});
