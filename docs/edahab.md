# eDahab Provider Integration

How the server talks to **eDahab** (Somaliland mobile wallet): billing a customer
(an **invoice**), checking whether they paid, sending money out to a wallet
(an **agent payment**), and the hosted payment page.

This is a **server-to-provider** reference. The app never calls eDahab directly;
it goes through [Payments](payments.md) (`rail: "edahab"`), [Registration](registration.md)
and [Subscription](subscription.md). The other wallet is documented in
[WaafiPay (Zaad)](waafipay.md).

> **Status**
>
> | eDahab call | Used by | State |
> | --- | --- | --- |
> | `IssueInvoice` | `WalletGateway::issue()` (charges, registration, subscription) | **Live**. `ReturnUrl` is not sent yet |
> | `CheckInvoiceStatus` | `WalletGateway::status()` (charge polling) | **Live** |
> | `agentPayment` | Legacy `API\PaymentController::makeMerchantPayment()` only | **Legacy only**. Not in `WalletGateway`, no v1 endpoint |
> | Hosted payment page | — | **Not used** |
>
> See [Gaps and implementation plan](#gaps-and-implementation-plan).

---

## Configuration

Credentials live in `.env` only. **Never commit them, and never paste them into
docs, tickets or chat.** Read them through `config('exelo.providers.edahab')`, not
`env()`, so they survive `config:cache`.

| `.env` key | Config key | Meaning |
| --- | --- | --- |
| `EXELO_API_KEY` | `exelo.providers.edahab.api_key` | `apiKey` sent in every body |
| `EXELO_AGENT_CODE` | `exelo.providers.edahab.agent_code` | `AgentCode` on `IssueInvoice` (the shop account that receives the money) |
| `SECRET_KEY` | `exelo.providers.edahab.secret` | Signs every request (see [Signing](#signing-requests)). Never sent on the wire |
| `EDAHAB_RETURN_URL` *(to add)* | `exelo.providers.edahab.return_url` | Where the hosted payment page sends the customer back to |
| `API_TIMEOUT` | `exelo.providers.timeout` | HTTP timeout in seconds (default 30) |

```dotenv
EXELO_API_KEY=
EXELO_AGENT_CODE=
SECRET_KEY=
EDAHAB_RETURN_URL=https://exelo.aiprofessionals.co/verifyPayment
```

**Base URLs**

| Use | URL |
| --- | --- |
| `IssueInvoice`, `CheckInvoiceStatus` | `https://edahab.net/api/api/{Endpoint}?hash={hash}` |
| `agentPayment` | `https://www.edahab.net/api/api/agentPayment?hash={hash}` |
| Hosted payment page | `https://edahab.net/api/payment?invoiceId={InvoiceId}` |

These are the hosts used in the captured calls. `WalletGateway` has one
`base_url` without `www`; when the payout is added, either confirm that
`agentPayment` also works without `www`, or give it its own URL in config.

---

## Signing requests

Every API call carries a `hash` query parameter:

```
hash = SHA-256_hex( <exact JSON body string> + SECRET_KEY )
```

```php
$body = json_encode($payload);
$hash = hash('sha256', $body.config('exelo.providers.edahab.secret'));

Http::withBody($body, 'application/json')
    ->post(config('exelo.providers.edahab.base_url')."/IssueInvoice?hash={$hash}");
```

Rules:

1. **Hash the exact bytes you send.** Build the JSON string once, hash it, send
   that same string with `withBody()`. Passing the array to `->post($url, $payload)`
   re-encodes it and any difference (key order, `\/` escaping in `ReturnUrl`,
   number formatting) gives an invalid-hash rejection. `WalletGateway::postEdahab()`
   already does this correctly; the legacy controller does not.
2. Key order matters because the string is hashed. Keep the order shown below.
3. `Amount` / `transactionAmount` are JSON **numbers**, not strings.
4. The secret is only ever in the hash. Do not log it; `apiKey` is removed from
   `api_logs` by `WalletGateway::redact()`.

### Postman pre-request script

The Postman collection signs each request in the **Scripts → Pre-request** tab,
the same way the server does. Keep the secret in a Postman **environment**
variable (`secretKey`, type *secret*), not in the script, so exporting or sharing
the collection doesn't leak it.

```javascript
const CryptoJS = require('crypto-js');

// A fresh transaction id for each run: txn_<iteration>_<epoch ms>
const transactionId = 'txn_' + pm.info.iteration + '_' + Date.now();
pm.environment.set('transactionId', transactionId);

// Hash the body exactly as it will be sent: substitute the variable ourselves first
const body = pm.request.body.raw.replace('{{transactionId}}', transactionId);

const secret = pm.environment.get('secretKey');
const hash = CryptoJS.SHA256(body + secret).toString(CryptoJS.enc.Hex);

pm.environment.set('hash', hash);
console.log('transactionId', transactionId, 'hash', hash);
```

The request URL then uses the variable, e.g.
`https://edahab.net/api/api/IssueInvoice?hash={{hash}}`, and the raw body carries
`"transactionId": "{{transactionId}}"`.

Why it works, and how it breaks:

- The script hashes `pm.request.body.raw`, which is the body text **exactly as
  typed**, including spaces and line breaks. Postman sends that same text, so the
  hashes match. Reformatting the body after the hash is computed breaks it.
- Only `{{transactionId}}` is substituted before hashing. Any **other** `{{var}}`
  in the body (e.g. `{{apiKey}}`) is filled in by Postman *after* the script runs,
  so the hash is computed over the literal `{{apiKey}}` text and eDahab rejects it.
  Either write those values into the body literally or add a `.replace()` for each
  one before hashing.
- `{{hash}}` in the URL is fine: the URL is not part of the hashed string.

PHP equivalent for one request (what `WalletGateway::postEdahab()` does):

```php
$payload = [
    'apiKey' => $config['api_key'],
    'EdahabNumber' => '656486734',
    'Amount' => 500,
    'AgentCode' => $config['agent_code'],
    'transactionId' => 'txn_1_'.round(microtime(true) * 1000),
    'Currency' => 'SLSH',
];
$body = json_encode($payload);                        // compact, no spaces
$hash = hash('sha256', $body.$config['secret']);
```

The server sends compact JSON (`json_encode`), Postman sends whatever is typed.
Both are valid because each one hashes the exact text it sends.

---

## 1. `IssueInvoice` — bill a customer's wallet

Sends a payment request to the customer's handset. The customer approves it on
their phone (or on the hosted page). Nothing is paid until
[`CheckInvoiceStatus`](#2-checkinvoicestatus--has-the-customer-paid) says `Paid`.

```
POST https://edahab.net/api/api/IssueInvoice?hash={hash}
Content-Type: application/json
```

**Request**

```json
{
  "apiKey": "<EXELO_API_KEY>",
  "EdahabNumber": "656486734",
  "Amount": 500,
  "AgentCode": "<EXELO_AGENT_CODE>",
  "ReturnUrl": "https://exelo.aiprofessionals.co/verifyPayment",
  "transactionId": "txn_1_1727164800000",
  "Currency": "SLSH"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `apiKey` | string | yes | `EXELO_API_KEY` |
| `EdahabNumber` | string | yes | Customer number, **9 digits, no `+252`**, starting `65`, `66` or `62` (see `App\Support\PhoneNumber`). From E.164: `substr($e164, 4)` |
| `Amount` | number | yes | Whole shillings for `SLSH` |
| `AgentCode` | string | yes | `EXELO_AGENT_CODE` |
| `ReturnUrl` | string | for the hosted page | Where eDahab redirects after the hosted payment page. Not needed for the push-to-handset flow |
| `Currency` | string | yes | `SLSH` (what EXELO bills in). `USD` only if eDahab has enabled it on the agent account |
| `transactionId` | string | yes (sent by the collection and the server) | Our own reference, `txn_<n>_<epoch ms>`. The Postman collection sets it at runtime (see [Postman pre-request script](#postman-pre-request-script)); `WalletGateway` sends `txn_1_<ms>` and stores it on `invoices.transaction_id` |

**Response `200`: customer declined the prompt** (captured twice, 2026-09-24 and 2026-09-25)

```json
{
  "InvoiceStatus": "Unpaid",
  "TransactionId": "MP260925.1148.A17068",
  "InvoiceId": "35917256c9d0459cacbe4852e6594e8e",
  "StatusCode": 7,
  "RequestId": 1728197,
  "StatusDescription": "User declined",
  "ValidationErrors": null
}
```

| Field | Meaning |
| --- | --- |
| `InvoiceId` | eDahab's id for the invoice. **Present even when the customer declined**: the invoice exists either way |
| `InvoiceStatus` | `Unpaid` after a decline. Expected `Paid` when the customer approves (not yet captured) |
| `TransactionId` | **eDahab's** reference (`MP<yymmdd>.<hhmm>.<code>`), not the `transactionId` we sent. `CheckInvoiceStatus` returns the same value |
| `StatusCode` / `StatusDescription` | The outcome of the **push prompt**: `7` / `User declined`. Other codes are not documented yet |
| `RequestId` | eDahab's request number (an integer); keep it in `api_logs` for support tickets |

**What the captures show.** `IssueInvoice` waits for the customer's answer to the
push prompt and reports it (`StatusCode 7`, `User declined`). But a
`CheckInvoiceStatus` sent straight afterwards for the same invoice answered
**`InvoiceStatus: "Pending"`, `StatusCode: 0`** (see
[below](#2-checkinvoicestatus--has-the-customer-paid)). So declining the prompt
does **not** close the invoice: it stays open at eDahab.

That means:

- **Do not mark the invoice `Failed` because the prompt was declined.** If the
  customer can still pay the open invoice (for example on the
  [hosted payment page](#4-hosted-payment-page)), we would receive money for a
  payment we had already failed. Keep it `Pending` and let polling and the expiry
  decide.
- **Do tell the shopkeeper the prompt was declined**, so they can ask the customer
  to try again or pick another method, instead of watching a spinner for 10
  minutes.
- One `IssueInvoice` call lasts as long as the customer takes to answer.
  `API_TIMEOUT` (30 s) may cut it off. A timeout does **not** mean nothing
  happened, so check the invoice rather than issuing a new one.
- An approved invoice may come back `Paid` straight away, so the charge could
  settle without waiting for the first poll. Not captured yet.

Still unknown: whether a declined invoice **can** still be paid, and what it turns
into later (`Expired`? `Cancelled`?). See
[Responses still to capture](#responses-still-to-capture).

**Response `200`: rejected** (eDahab returns `200` with an error body)

```json
{
  "InvoiceId": null,
  "StatusDescription": "Validation Error",
  "ValidationErrors": [{ "Property": "EdahabNumber", "ErrorMessage": "Invalid eDahab number" }]
}
```

| Outcome | How to detect | Map to |
| --- | --- | --- |
| Paid | `InvoiceStatus == "Paid"` | Stored `Pending`; the first poll (a few seconds later) finds it `Paid` and settles it. Settling straight from the issue answer is not built, as no approved `IssueInvoice` has been captured yet |
| Prompt declined | `InvoiceId` present, `StatusCode == 7` | Stored `Pending` with `meta.provider_prompt = "declined"`; `202` as usual **plus `"prompt": "declined"`**, on this response and every poll while it stays pending. **Not** `Failed` |
| Issued, awaiting the customer | `InvoiceId` present, anything else | Store `InvoiceId` on `invoices.invoice_id`, status `Pending`, return `202` |
| Bad number / amount | `StatusDescription == "Validation Error"` | `422 payment.wallet_invalid` with `ValidationErrors[0].ErrorMessage` |
| Anything else without `InvoiceId` | — | `502 payment.provider_unavailable` (log the body) |
| HTTP error / timeout | exception or non-2xx | `502 payment.provider_unavailable`. The customer may still have been prompted |

Built in `App\Services\Payments\Providers\EdahabProvider::issue()`. The call uses
`EDAHAB_TIMEOUT` (default 90 s), because it waits for the customer. If it still
times out there is no `InvoiceId` to poll, so the app gets `502` and the call is
recorded in `api_logs` with its `error`.

---

## 2. `CheckInvoiceStatus` — has the customer paid?

```
POST https://edahab.net/api/api/CheckInvoiceStatus?hash={hash}
Content-Type: application/json
```

**Request**

```json
{
  "apiKey": "<EXELO_API_KEY>",
  "invoiceId": "474e68fa575b4a37b014066b481a5cda"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `apiKey` | string | yes | `EXELO_API_KEY` |
| `invoiceId` | string | yes | The `InvoiceId` from `IssueInvoice` |

`WalletGateway` calls the endpoint as `checkInvoiceStatus` (lower-case `c`);
eDahab accepts either.

**Response `200`: still open** (captured 2026-09-25, straight after the declined
`IssueInvoice` above)

```json
{
  "InvoiceStatus": "Pending",
  "TransactionId": "MP260925.1148.A17068",
  "InvoiceId": "35917256c9d0459cacbe4852e6594e8e",
  "StatusCode": 0,
  "RequestId": 1728198,
  "StatusDescription": "Success",
  "ValidationErrors": null
}
```

| Field | Meaning |
| --- | --- |
| `InvoiceStatus` | **The invoice's state**, `Pending` here. Decide on this field |
| `StatusCode` / `StatusDescription` | Whether **the lookup** worked (`0` / `Success`), not whether the customer paid. A non-zero code means the check itself failed: treat it as "unknown, poll again" |
| `TransactionId` | Same eDahab reference as `IssueInvoice` returned |

The same invoice reads `Unpaid` in the `IssueInvoice` answer and `Pending` here,
and `StatusCode` means different things in the two calls: the prompt's outcome in
`IssueInvoice`, the lookup's outcome here.

**Status mapping** (`App\Services\Payments\Providers\EdahabProvider::status()`)

| `InvoiceStatus` (case-insensitive) | `invoices.status` | Charge status in v1 |
| --- | --- | --- |
| `Paid` | `Paid` | `paid`, store `TransactionId` as the provider reference |
| `Pending`, `Unpaid` or empty | `Pending` | `pending`, poll again after `poll_after` |
| `Expired` | `Expired` | `expired` |
| `Cancelled` / `Canceled` | `Cancelled` | `cancelled` |
| anything else | `Failed` | `failed`, `failure.reason` = raw `InvoiceStatus` |

A declined invoice stays open, so `Unpaid` counts as still pending. A failed
lookup (non-zero `StatusCode`, no `InvoiceStatus`) reads as empty → pending, which
is the safe answer. `Paid`, `Expired` and `Cancelled` are still the code's guesses;
only `Pending` has been captured from this call.

Make **one call per poll**. The legacy controller loops up to 8 × `sleep(5)`
inside the request, which ties up a PHP worker for 40 seconds; the v1 flow lets
the app poll [`GET /payments/charges/{id}`](payments.md#4-get-apiv1paymentschargescharge_id--check-a-payment)
instead.

---

## 3. `agentPayment` — send money to a wallet (payout)

Moves money **out** of the EXELO agent account to an eDahab number. Used to pay a
merchant their share, or to pay a supplier. **This spends real money: it must
only run behind authentication, the `pos` permission and a PIN confirmation**
(see [payouts](payments.md#7-post-apiv1paymentspayouts--send-money-to-a-phone-number)).

```
POST https://www.edahab.net/api/api/agentPayment?hash={hash}
Content-Type: application/json
```

Captured calls went to `www.edahab.net`; the other calls use `edahab.net`.

**Request** (captured 2026-09-25)

```json
{
  "apiKey": "<EXELO_API_KEY>",
  "phoneNumber": "740853",
  "transactionAmount": 500,
  "transactionId": "txn_1_1727265060000",
  "currency": "SLSH"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `apiKey` | string | yes | `EXELO_API_KEY` |
| `phoneNumber` | string | yes | Recipient: an eDahab number (9 digits, no `+252`) **or a merchant's eDahab agent code** (6 digits). The legacy code sends `merchants.merchant_code` when set, and the captured response below went to agent `740853` |
| `transactionAmount` | number | yes | Whole shillings |
| `transactionId` | string | yes | **Our** unique id for this payout. Make it deterministic per payout (e.g. `pay_<ulid>`) and store it **before** calling, so a retry after a timeout can be matched rather than paid twice. The `MP…` value in the collection example is an eDahab-style id copied from a response; any unique string works |
| `currency` | string | accepted | `SLSH`. Accepted by eDahab (captured request above); the legacy code sends it too |

**Response `200`: sent** (captured 2026-09-24 and 2026-09-25)

```json
{
  "TransactionStatus": "Approved",
  "TransactionMesage": "500 Shilling Ayaad u Dirtay BARAKALLAH SHOP(661345015).Tix: PP260925.1151.D46212. Hadhaagaagu waa  : 4,415 Shilling Kharashyada Adeegga:0 Shilling. La soo dag App-ka DahabPlus https://onelink.to/dahabpluss Tar :25-09-2026",
  "PhoneNumber": "740853",
  "TransactionId": "PP260925.1151.D46212"
}
```

Between the two captures the balance in the message fell from 4,915 to 4,415
shillings: exactly the 500 sent, with a 0 service fee. So each payout comes
straight out of the EXELO agent balance, and a payout larger than that balance
should be refused (the refusal response hasn't been captured yet).

| Field | Meaning |
| --- | --- |
| `TransactionStatus` | `Approved` on success. Other values not captured yet |
| `TransactionId` | eDahab's payout reference (`PP<yymmdd>.<hhmm>.<code>`). Invoices use `MP…`, payouts `PP…` |
| `PhoneNumber` | The recipient as sent in `phoneNumber` (here an agent code, not a phone) |
| `TransactionMesage` | The SMS text eDahab sends, **in Somali**. Note the provider's spelling (one `s`) |

The message translates roughly as: *"You sent 500 Shilling to BARAKALLAH SHOP
(661345015). Ref: PP260924.1344.D70328. Your balance is 4,915 Shilling. Service
fee: 0 Shilling. Download the DahabPlus app … Date: 24-09-2026."*

**Never show `TransactionMesage` to a shopkeeper or customer.** It is written for
the **sender**, which is the EXELO agent account, so it reveals **EXELO's own
balance** ("Hadhaagaagu waa: 4,915 Shilling"). Store it in `api_logs` for support
only, and build any user-facing message from our own fields (amount, recipient,
`TransactionId`). The legacy `makeMerchantPayment()` saves it to
`transactions.transaction_message`; check nothing displays that column.

Decide success on `TransactionStatus == "Approved"` **and** a non-null
`TransactionId`, not on `TransactionId` alone.

| Outcome | How to detect | Map to |
| --- | --- | --- |
| Sent | `TransactionStatus == "Approved"` and `TransactionId` not null | Record the payout as `sent`, keep `TransactionId` as `provider_ref` |
| Refused | anything else | `402 payment.declined`. Log `TransactionMesage`; show a generic reason, not the raw text |
| HTTP error / timeout | exception or non-2xx | `502 payment.provider_unavailable`. **Do not retry automatically** with a new `transactionId`: the money may have left. Mark the payout `unknown` and reconcile |

---

## 4. Hosted payment page

For a customer who pays in a browser instead of approving a push prompt:

```
GET https://edahab.net/api/payment?invoiceId={InvoiceId}
```

1. Call [`IssueInvoice`](#1-issueinvoice--bill-a-customers-wallet) **with `ReturnUrl`**.
2. Send the customer to `https://edahab.net/api/payment?invoiceId={InvoiceId}`.
3. The customer pays; eDahab redirects to `ReturnUrl`.
4. On `ReturnUrl`, **do not trust the redirect**. Look up the invoice and call
   [`CheckInvoiceStatus`](#2-checkinvoicestatus--has-the-customer-paid); only a
   `Paid` answer from eDahab settles it.

The query string eDahab appends to `ReturnUrl` is not documented in the collection.
Capture it from a sandbox run before building the return route.

---

## Sequence: sale on eDahab (as built)

```
App                     EXELO server                         eDahab
 │ POST /payments/charges │                                     │
 │  rail: edahab ───────► │ IssueInvoice?hash=… ──────────────► │
 │                        │ ◄──────────────────── InvoiceId     │
 │ ◄── 202 pending ────── │ invoices row, status Pending        │  push prompt
 │                        │                                     │  ──► customer approves
 │ GET /payments/charges/{id}                                   │
 │ ─────────────────────► │ CheckInvoiceStatus?hash=… ────────► │
 │ ◄── pending / paid ─── │ ◄──────────────── InvoiceStatus     │
 │                        │ on Paid: InvoicePaid → order created, cart cleared
```

---

## Gaps and implementation plan

What is missing compared with the provider collection, and the smallest change
for each. ✅ = built (2026-09-25, Phases 0–2 of the plan in
[payment-flows.md](payment-flows.md#suggested-build-order)); the rest is not built yet.

| # | Gap | Change | Files |
| --- | --- | --- | --- |
| 1 | `ReturnUrl` is never sent | Add `return_url` to `exelo.providers.edahab` (`EDAHAB_RETURN_URL`), send it from `issueEdahab()` when set | `config/exelo.php`, `.env.example`, `app/Services/WalletGateway.php` |
| 2 | `agentPayment` exists only in the legacy controller, which posts unauthenticated, re-encodes the body after hashing and loops on `env()` | Add `WalletGateway::payout(string $walletE164, int $amount, string $transactionId): array` using `postEdahab('agentPayment', …)` | `app/Services/WalletGateway.php` |
| 3 | No v1 payout endpoint | Build [`POST /payments/payouts`](payments.md#7-post-apiv1paymentspayouts--send-money-to-a-phone-number): FormRequest → `PayoutService` (idempotency, PIN confirmation scope `payments.payout`, `DB::transaction`) → `WalletGateway::payout()`. Record in a `payouts` table (new migration) and log to activity | `routes/api.php`, `V1\PaymentController`, `StorePayoutRequest`, `PayoutService`, `Payout` model + migration, `PayoutResource` |
| 4 | Hosted page not supported | Return `payment_url` (`https://edahab.net/api/payment?invoiceId=…`) on eDahab charges; add a named `verifyPayment` return route that re-checks status via `WalletGateway::status()` and shows the result | `ChargeService`, `routes/web.php`, a small controller + view |
| 5 ✅ | `.env.example` has no eDahab keys | Add the empty keys listed under [Configuration](#configuration) | `.env.example` |
| 6 ✅ | The prompt outcome in the `IssueInvoice` response is ignored, and `Unpaid` from `CheckInvoiceStatus` would count as failed | In `issueEdahab()`: keep the invoice `Pending` on `StatusCode 7` (it stays open at eDahab) but return a `prompt: "declined"` hint; if `InvoiceStatus == "Paid"`, return it so the caller settles at once. In `edahabStatus()`: treat `Unpaid` as pending | `app/Services/WalletGateway.php`, and the callers in `ChargeService` / registration / subscription for the hint and the paid-at-issue case |
| 7 ✅ | `IssueInvoice` waits for the customer, so a 30 s timeout can end the call while the prompt is still open | Give eDahab calls their own, longer timeout (e.g. 90 s); on a timeout, keep the invoice `Pending` and let polling find the result instead of returning an error | `config/exelo.php`, `app/Services/WalletGateway.php` |
| 8 | The legacy payout stores the Somali SMS text (with EXELO's balance) in `transactions.transaction_message` | Check where that column is shown; stop displaying it | `API\PaymentController`, any view/resource that outputs `transaction_message` |

Suggested order: 1 and 5 (config only), then 6 and 7 (correctness of what is
live), then 2, then 3 (needs a migration, so confirm the schema first), then 4
once the `ReturnUrl` query string is known. 8 is a quick check at any point.

### Responses still to capture

| Call | Situation | Status |
| --- | --- | --- |
| `IssueInvoice` | Customer declines | ✅ captured |
| `IssueInvoice` | Customer **approves** (is it `Paid` straight away?) | to capture |
| `IssueInvoice` | Customer **does not answer** (how long does it wait?) | to capture |
| `CheckInvoiceStatus` | Right after a decline | ✅ captured: `Pending` |
| `CheckInvoiceStatus` | Paid | to capture |
| `CheckInvoiceStatus` | The declined invoice **10+ minutes later** (does it expire?) | to capture |
| Hosted page | Pay the **declined** invoice at `https://edahab.net/api/payment?invoiceId=…`, then `CheckInvoiceStatus` (proves whether a declined invoice can still be paid) | to capture |
| `agentPayment` | Sent | ✅ captured |
| `agentPayment` | **Refused** (e.g. more than the agent balance) | to capture |

### Open questions for eDahab

- Does `agentPayment` enforce `transactionId` uniqueness (so a retry is safe)?
- Is `USD` allowed on `IssueInvoice` for this agent?
- What does eDahab append to `ReturnUrl`, and is there a server-to-server callback
  (needed before [webhooks](payments.md#9-post-apiv1webhookspaymentsprovider--provider-callbacks)
  can include eDahab)?
- How long before an unpaid invoice becomes `Expired`?
- The full list of `StatusCode` values (`7` = user declined is the only one seen).

---

## Testing locally

- `EXELO_SIMULATE_PAYMENTS=true` with `APP_ENV=local` skips the handset for
  registration and subscription invoices (see [payments.md](payments.md#postman--curl-quick-start)).
- Every provider call is recorded in `api_logs` (URL, redacted payload, status,
  response), which is the first place to look when eDahab rejects a request.
- Tests must fake the provider with `Http::fake(['edahab.net/*' => …])`; never hit
  the real API, since `agentPayment` moves real money.
