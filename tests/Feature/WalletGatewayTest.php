<?php

use App\Exceptions\ApiException;
use App\Models\ApiLog;
use App\Models\Invoice;
use App\Services\WalletGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config([
        'exelo.providers.edahab.api_key' => 'test-edahab-key',
        'exelo.providers.edahab.agent_code' => '700000',
        'exelo.providers.edahab.secret' => 'test-secret',
        'exelo.providers.waafi.merchant_uid' => 'M0000001',
        'exelo.providers.waafi.api_user_id' => '1000001',
        'exelo.providers.waafi.api_key' => 'API-TEST-KEY',
    ]);
});

/** Shape the code reads from WaafiPay; no successful response has been captured yet. */
function waafiApproved(string $state = 'approved'): array
{
    return [
        'schemaVersion' => '1.0',
        'responseCode' => '2001',
        'errorCode' => '0',
        'responseMsg' => 'RCS_SUCCESS',
        'params' => ['state' => $state, 'referenceId' => '324922', 'transactionId' => '42750126', 'txAmount' => '500'],
    ];
}

function edahabInvoice(): Invoice
{
    return new Invoice(['rail' => 'edahab', 'invoice_id' => '35917256c9d0459cacbe4852e6594e8e']);
}

function zaadInvoice(): Invoice
{
    return new Invoice(['rail' => 'zaad', 'invoice_id' => '324922', 'transaction_id' => '42750126']);
}

it('signs an eDahab invoice with the hash of the exact body sent', function () {
    Http::fake(['edahab.net/api/api/IssueInvoice*' => Http::response(fixture('edahab/check-invoice-pending.json'))]);

    $issued = app(WalletGateway::class)->issue('edahab', '+252656486734', 510, 'SLSH');

    Http::assertSent(function (Request $request) {
        $body = $request->body();
        $sent = json_decode($body, true);

        return str_starts_with($request->url(), 'https://edahab.net/api/api/IssueInvoice?hash=')
            && $request->url() === 'https://edahab.net/api/api/IssueInvoice?hash='.hash('sha256', $body.'test-secret')
            && $sent['EdahabNumber'] === '656486734'
            && $sent['Amount'] === 510
            && $sent['AgentCode'] === '700000'
            && $sent['Currency'] === 'SLSH'
            && str_starts_with($sent['transactionId'], 'txn_1_');
    });

    expect($issued['invoice_id'])->toBe('35917256c9d0459cacbe4852e6594e8e')
        ->and($issued['transaction_id'])->toStartWith('txn_1_')
        ->and($issued['hash'])->toHaveLength(64);
});

it('keeps an eDahab invoice open when the customer declines the prompt', function () {
    Http::fake(['edahab.net/api/api/IssueInvoice*' => Http::response(fixture('edahab/issue-invoice-declined.json'))]);

    $issued = app(WalletGateway::class)->issue('edahab', '+252656486734', 510, 'SLSH');

    expect($issued['invoice_id'])->toBe('35917256c9d0459cacbe4852e6594e8e')
        ->and($issued['prompt'])->toBe('declined');
});

it('reports no prompt outcome when eDahab issues the invoice normally', function () {
    Http::fake(['edahab.net/*' => Http::response(fixture('edahab/check-invoice-pending.json'))]);

    expect(app(WalletGateway::class)->issue('edahab', '+252656486734', 510, 'SLSH')['prompt'])->toBeNull();
});

it('gives eDahab its own, longer timeout than WaafiPay', function () {
    config(['exelo.providers.timeout' => 30, 'exelo.providers.edahab.timeout' => 90]);
    $timeouts = [];

    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[str_contains($request->url(), 'edahab') ? 'edahab' : 'waafi'] = $options['timeout'];

        return str_contains($request->url(), 'edahab')
            ? Http::response(fixture('edahab/check-invoice-pending.json'))
            : Http::response(waafiApproved());
    });

    app(WalletGateway::class)->status(edahabInvoice());
    app(WalletGateway::class)->status(zaadInvoice());

    expect($timeouts)->toBe(['edahab' => 90, 'waafi' => 30]);
});

it('turns an eDahab validation error into wallet_invalid', function () {
    Http::fake(['edahab.net/*' => Http::response([
        'InvoiceId' => null,
        'StatusDescription' => 'Validation Error',
        'ValidationErrors' => [['Property' => 'EdahabNumber', 'ErrorMessage' => 'Invalid eDahab number']],
    ])]);

    expect(fn () => app(WalletGateway::class)->issue('edahab', '+252656486734', 510, 'SLSH'))
        ->toThrow(fn (ApiException $e) => expect([$e->errorCode, $e->status, $e->getMessage()])
            ->toBe(['payment.wallet_invalid', 422, 'Invalid eDahab number']));
});

it('treats an eDahab answer without an invoice as the provider being unavailable', function () {
    Http::fake(['edahab.net/*' => Http::response(['StatusDescription' => 'Something else'])]);

    expect(fn () => app(WalletGateway::class)->issue('edahab', '+252656486734', 510, 'SLSH'))
        ->toThrow(fn (ApiException $e) => expect([$e->errorCode, $e->status])->toBe(['payment.provider_unavailable', 502]));
});

it('reads eDahab invoice statuses', function (array $response, string $status, ?string $reference) {
    Http::fake(['edahab.net/api/api/checkInvoiceStatus*' => Http::response($response)]);

    $result = app(WalletGateway::class)->status(edahabInvoice());

    expect($result['status'])->toBe($status)
        ->and($result['provider_transaction_id'])->toBe($reference);
})->with([
    'captured pending' => [fixture('edahab/check-invoice-pending.json'), 'Pending', 'MP260925.1148.A17068'],
    'paid' => [['InvoiceStatus' => 'Paid', 'TransactionId' => 'MP1'], 'Paid', 'MP1'],
    'expired' => [['InvoiceStatus' => 'Expired'], 'Expired', null],
    'cancelled' => [['InvoiceStatus' => 'Canceled'], 'Cancelled', null],
    'no status' => [['StatusCode' => 5], 'Pending', null],
    'unpaid stays open' => [['InvoiceStatus' => 'Unpaid', 'StatusCode' => 7], 'Pending', null],
]);

it('logs eDahab calls without the API key', function () {
    Http::fake(['edahab.net/*' => Http::response(fixture('edahab/check-invoice-pending.json'))]);

    app(WalletGateway::class)->status(edahabInvoice());

    $log = ApiLog::latest('id')->first();

    expect($log->url)->toStartWith('https://edahab.net/api/api/checkInvoiceStatus?hash=')
        ->and($log->payload)->toBe(['invoiceId' => '35917256c9d0459cacbe4852e6594e8e'])
        ->and($log->response_body['InvoiceStatus'])->toBe('Pending');
});

it('pre-authorises a Zaad payment', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(waafiApproved())]);

    $issued = app(WalletGateway::class)->issue('zaad', '+252634110101', 500, 'SLSH');

    Http::assertSent(function (Request $request) {
        $params = $request['serviceParams'];

        return $request->url() === 'https://api.waafipay.net/asm'
            && $request['serviceName'] === 'API_PREAUTHORIZE'
            && $params['merchantUid'] === 'M0000001'
            && $params['apiKey'] === 'API-TEST-KEY'
            && $params['payerInfo']['accountNo'] === '252634110101'
            && $params['transactionInfo']['amount'] === 500
            && $params['transactionInfo']['currency'] === 'SLSH';
    });

    expect($issued)->toBe(['invoice_id' => '324922', 'transaction_id' => '42750126', 'hash' => '0', 'prompt' => null]);
});

it('shows the customer what a Zaad payment is for', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(waafiApproved())]);

    app(WalletGateway::class)->issue('zaad', '+252634110101', 500, 'SLSH', 'EXELO sale');

    Http::assertSent(fn (Request $request) => $request['serviceParams']['transactionInfo']['description'] === 'EXELO sale');
});

it('accepts a Zaad approval in any letter case', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(waafiApproved('APPROVED'))]);

    expect(app(WalletGateway::class)->status(zaadInvoice())['status'])->toBe('Paid');
});

it('does not treat a WaafiPay answer without an errorCode as success', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(['responseMsg' => 'Unexpected'])]);

    expect(fn () => app(WalletGateway::class)->issue('zaad', '+252634110101', 500, 'SLSH'))->toThrow(ApiException::class)
        ->and(app(WalletGateway::class)->status(zaadInvoice())['status'])->toBe('Pending');
});

it('refuses a Zaad pre-authorisation that WaafiPay rejects', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(fixture('waafi/credit-account-refused.json'))]);

    expect(fn () => app(WalletGateway::class)->issue('zaad', '+252634110101', 500, 'SLSH'))
        ->toThrow(fn (ApiException $e) => expect([$e->errorCode, $e->status])->toBe(['payment.wallet_invalid', 422]));
});

it('commits a Zaad payment to check it', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(waafiApproved())]);

    $result = app(WalletGateway::class)->status(zaadInvoice());

    Http::assertSent(fn (Request $request) => $request['serviceName'] === 'API_PREAUTHORIZE_COMMIT'
        && $request['serviceParams']['transactionId'] === '42750126'
        && $request['serviceParams']['referenceId'] === '324922');

    expect($result)->toBe(['status' => 'Paid', 'provider_transaction_id' => '42750126', 'reason' => null]);
});

it('keeps a Zaad payment pending while the commit is refused', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(fixture('waafi/credit-account-refused.json'))]);

    expect(app(WalletGateway::class)->status(zaadInvoice())['status'])->toBe('Pending');
});

it('logs Zaad calls without the API key', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(waafiApproved())]);

    app(WalletGateway::class)->status(zaadInvoice());

    expect(ApiLog::latest('id')->first()->payload['serviceParams'])->not->toHaveKey('apiKey');
});

it('records the provider, operation, references and outcome of an eDahab call', function () {
    Http::fake(['edahab.net/*' => Http::response(fixture('edahab/issue-invoice-declined.json'))]);

    $issued = app(WalletGateway::class)->issue('edahab', '+252656486734', 510, 'SLSH');

    $log = ApiLog::latest('id')->first();

    expect($log->only(['provider', 'operation', 'our_reference', 'provider_reference', 'provider_status', 'status_code', 'error']))->toBe([
        'provider' => 'edahab',
        'operation' => 'IssueInvoice',
        'our_reference' => $issued['transaction_id'],
        'provider_reference' => 'MP260925.1148.A17068',
        'provider_status' => 'Unpaid · 7 User declined',
        'status_code' => 200,
        'error' => null,
    ])->and($log->duration_ms)->toBeInt();
});

it('records the outcome of a refused WaafiPay call', function () {
    Http::fake(['api.waafipay.net/*' => Http::response(fixture('waafi/credit-account-refused.json'))]);

    app(WalletGateway::class)->status(zaadInvoice());

    expect(ApiLog::latest('id')->first()->only(['provider', 'operation', 'our_reference', 'provider_status']))->toBe([
        'provider' => 'waafi',
        'operation' => 'API_PREAUTHORIZE_COMMIT',
        'our_reference' => '324922',
        'provider_status' => 'E10235 We are sorry this Operation is not Allowed at this moment',
    ]);
});

it('records a call that never got an answer', function () {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => app(WalletGateway::class)->status(edahabInvoice()))->toThrow(ApiException::class);

    expect(ApiLog::latest('id')->first()->only(['provider', 'operation', 'status_code', 'response_body', 'error']))->toBe([
        'provider' => 'edahab',
        'operation' => 'checkInvoiceStatus',
        'status_code' => null,
        'response_body' => null,
        'error' => 'cURL error 28: Operation timed out',
    ]);
});

it('records the HTTP status of a failed call', function () {
    Http::fake(['edahab.net/*' => Http::response('down', 503)]);

    expect(fn () => app(WalletGateway::class)->status(edahabInvoice()))->toThrow(ApiException::class);

    expect(ApiLog::latest('id')->first()->only(['status_code', 'error']))->toBe(['status_code' => 503, 'error' => 'HTTP 503']);
});

it('links every provider call to the invoice it belongs to', function (string $rail, string $wallet, string $pattern, array $issueAnswer, array $checkAnswer) {
    $calls = 0;

    // WaafiPay echoes the referenceId it was sent.
    Http::fake([$pattern => function (Request $request) use (&$calls, $issueAnswer, $checkAnswer) {
        $answer = $calls++ === 0 ? $issueAnswer : $checkAnswer;

        if ($reference = $request['serviceParams']['transactionInfo']['referenceId'] ?? $request['serviceParams']['referenceId'] ?? null) {
            $answer['params']['referenceId'] = $reference;
        }

        return Http::response($answer);
    }]);

    $issued = app(WalletGateway::class)->issue($rail, $wallet, 500, 'SLSH');

    $invoice = Invoice::create([
        'public_id' => Invoice::generatePublicId(),
        'invoice_id' => $issued['invoice_id'],
        'transaction_id' => $issued['transaction_id'],
        'hash' => $issued['hash'],
        'mobile_number' => $wallet,
        'wallet_number' => $wallet,
        'rail' => $rail,
        'amount' => 500,
        'currency' => 'SLSH',
        'status' => 'Pending',
        'type' => 'Registration',
    ]);

    app(WalletGateway::class)->status($invoice);

    expect($invoice->apiLogs()->pluck('operation')->all())->toBe($rail === 'edahab'
        ? ['IssueInvoice', 'checkInvoiceStatus']
        : ['API_PREAUTHORIZE', 'API_PREAUTHORIZE_COMMIT']);
})->with([
    'edahab' => ['edahab', '+252656486734', 'edahab.net/*', fixture('edahab/issue-invoice-declined.json'), fixture('edahab/check-invoice-pending.json')],
    'zaad' => ['zaad', '+252634110101', 'api.waafipay.net/*', waafiApproved(), waafiApproved()],
]);

it('stores legacy provider calls without the API key', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    app(App\Http\Controllers\API\BaseController::class)->logApiResponse(
        'https://api.waafipay.net/asm',
        ['serviceName' => 'API_CREDITACCOUNT', 'serviceParams' => ['merchantUid' => 'M1', 'apiKey' => 'API-SECRET']],
        Http::get('https://api.waafipay.net/asm'),
    );

    expect(ApiLog::latest('id')->first()->payload)->toBe(['serviceName' => 'API_CREDITACCOUNT', 'serviceParams' => ['merchantUid' => 'M1']]);
});

it('reports a provider HTTP error as unavailable', function (string $rail, string $pattern) {
    Http::fake([$pattern => Http::response('down', 503)]);

    expect(fn () => app(WalletGateway::class)->issue($rail, $rail === 'zaad' ? '+252634110101' : '+252656486734', 500, 'SLSH'))
        ->toThrow(fn (ApiException $e) => expect([$e->errorCode, $e->status])->toBe(['payment.provider_unavailable', 502]));
})->with([['edahab', 'edahab.net/*'], ['zaad', 'api.waafipay.net/*']]);
