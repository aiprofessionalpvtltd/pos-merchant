<?php

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    if (! Setting::query()->exists()) {
        DB::table('settings')->insert(['company_name' => 'EXELO', 'company_email' => 'fees-test@example.test', 'company_website' => 'fees-test.example.test']);
    }

    Setting::query()->update(['registration_fee' => null, 'registration_fee_charge' => null, 'verification_fee' => null, 'verification_fee_charge' => null]);
    Cache::forget('settings:payment-fees');

    config(['exelo.registration.fees' => [
        'registration' => ['base' => 500, 'fee' => 50],
        'verification' => ['base' => 400, 'fee' => 40],
    ]]);
});

function feesAdmin(array $permissions = ['view-setting', 'edit-setting']): User
{
    $admin = User::create(['name' => 'Fees Admin', 'email' => 'fees-admin@example.test', 'password' => Hash::make('x'), 'user_type' => 'admin']);

    auth()->shouldUse('web');
    $admin->givePermissionTo($permissions);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin;
}

function feesBody(int $regBase, int $regFee, int $verBase, int $verFee): array
{
    return ['fees' => [
        'registration' => ['base' => $regBase, 'fee' => $regFee],
        'verification' => ['base' => $verBase, 'fee' => $verFee],
    ]];
}

it('quotes the configured defaults until an admin sets the fees', function () {
    test()->getJson('/api/v1/registration/quote?purpose=registration')
        ->assertOk()
        ->assertJsonPath('data.base.slsh.amount', 500)
        ->assertJsonPath('data.exelo_fee.slsh.amount', 50)
        ->assertJsonPath('data.total.slsh.amount', 550);
});

it('quotes the fees an admin saved, base price and EXELO fee separately', function () {
    test()->actingAs(feesAdmin(), 'web')
        ->put(route('admin.settings.payment-fees.update'), feesBody(1000, 150, 700, 70))
        ->assertRedirect(route('admin.settings.payment-fees.edit'))
        ->assertSessionHas('success');

    config(['exelo.conversion_rate' => 8000]);

    test()->getJson('/api/v1/registration/quote?purpose=registration')
        ->assertJsonPath('data.base.slsh', ['amount' => 1000, 'currency' => 'SLSH', 'display' => '1000 SLSH'])
        ->assertJsonPath('data.base.usd', ['amount' => 13, 'currency' => 'USD', 'display' => '$0.13'])
        ->assertJsonPath('data.exelo_fee.slsh.amount', 150)
        ->assertJsonPath('data.exelo_fee.usd.amount', 2)
        ->assertJsonPath('data.total.slsh.amount', 1150)
        ->assertJsonPath('data.total.usd.amount', 14);

    test()->getJson('/api/v1/registration/quote?purpose=verification')
        ->assertJsonPath('data.purpose', 'verification')
        ->assertJsonPath('data.base.slsh.amount', 700)
        ->assertJsonPath('data.exelo_fee.slsh.amount', 70)
        ->assertJsonPath('data.total.slsh.amount', 770)
        ->assertJsonMissingPath('data.fee');

    expect(Setting::query()->first()->only(['registration_fee', 'registration_fee_charge', 'verification_fee', 'verification_fee_charge']))
        ->toBe(['registration_fee' => 1000, 'registration_fee_charge' => 150, 'verification_fee' => 700, 'verification_fee_charge' => 70]);
});

it('bills the quoted total and keeps the base price and EXELO fee on the invoice', function () {
    test()->actingAs(feesAdmin(), 'web')->put(route('admin.settings.payment-fees.update'), feesBody(1000, 150, 700, 70));

    Http::fake([
        'edahab.net/*' => Http::response(fixture('edahab/check-invoice-pending.json')),
    ]);

    $quote = test()->getJson('/api/v1/registration/quote?purpose=registration')->json('data.quote_id');

    $invoiceId = test()->postJson('/api/v1/registration/invoices', [
        'phone_number' => '+252654990077', 'wallet_number' => '+252654990077', 'rail' => 'edahab',
        'purpose' => 'registration', 'quote_id' => $quote, 'idempotency_key' => 'fees-1',
    ])->assertStatus(202)->json('data.invoice_id');

    $invoice = Invoice::where('public_id', $invoiceId)->first();

    expect((int) $invoice->amount)->toBe(1150)
        ->and($invoice->meta)->toBe(['base' => 1000, 'exelo_fee' => 150]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'IssueInvoice') && json_decode($request->body(), true)['Amount'] === 1150);
});

it('shows the fee page with the current values and total', function () {
    test()->actingAs(feesAdmin(['view-setting']), 'web')
        ->get(route('admin.settings.payment-fees.edit'))
        ->assertOk()
        ->assertSee('Base price')
        ->assertSee('EXELO fee')
        ->assertSee('550 SLSH')
        ->assertSee('Using the default');
});

it('validates every fee', function () {
    test()->actingAs(feesAdmin(), 'web')
        ->put(route('admin.settings.payment-fees.update'), ['fees' => [
            'registration' => ['base' => -1, 'fee' => 0],
            'verification' => ['base' => 'abc'],
        ]])
        ->assertSessionHasErrors(['fees.registration.base', 'fees.verification.base', 'fees.verification.fee']);

    expect(Setting::query()->value('registration_fee'))->toBeNull();
});

it('accepts amounts typed with thousands separators', function () {
    test()->actingAs(feesAdmin(), 'web')
        ->put(route('admin.settings.payment-fees.update'), ['fees' => [
            'registration' => ['base' => '92,000', 'fee' => '1 000'],
            'verification' => ['base' => '700', 'fee' => '70'],
        ]])
        ->assertSessionHasNoErrors();

    expect(Setting::query()->value('registration_fee'))->toBe(92000);
});

it('needs the setting permissions', function () {
    $viewer = feesAdmin(['view-setting']);

    test()->actingAs($viewer, 'web')->put(route('admin.settings.payment-fees.update'), feesBody(1, 1, 1, 1))->assertForbidden();

    $viewer->revokePermissionTo('view-setting');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    test()->actingAs($viewer->fresh(), 'web')->get(route('admin.settings.payment-fees.edit'))->assertForbidden();
});
