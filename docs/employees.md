# Employees & Shifts

Staff accounts, what they are allowed to do, and the work timer.

Employees sign in with the same [auth endpoints](auth.md) as merchants — phone
number and PIN. This module is about managing them: adding staff, giving them
permissions, tracking their shifts and seeing how they perform.

| Method | Path | Auth |
| --- | --- | --- |
| GET | `/permissions` | Bearer |
| GET | `/employees` | Bearer · `employees` |
| GET | `/employees/summary` | Bearer · `employees` |
| GET | `/employees/{id}` | Bearer · `employees` |
| POST | `/employees` | Bearer · `employees` + PIN confirmation |
| PATCH | `/employees/{id}` | Bearer · `employees` |
| DELETE | `/employees/{id}` | Bearer · `employees` |
| GET | `/shifts` | Bearer |
| POST | `/shifts/start` | Bearer |
| POST | `/shifts/{id}/end` | Bearer |
| PATCH | `/shifts/{id}` | Bearer · shop owner |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `Authorization` | Every request | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin) |
| `X-EXELO-Confirmation` | `POST /employees` (required) | `confirmation_token` from [`POST /auth/pin/verify`](auth.md#post-authpinverify) with `scope: "employees.create"` |
| `Idempotency-Key` | `POST /shifts/start`, `POST /shifts/{id}/end` (optional, alternative to the body field) | UUID generated once per tap |

| # | Method | Full path | Purpose | Needs | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/permissions` | List the assignable permissions | Bearer | — | `200` | — |
| 2 | GET | `/api/v1/employees` | List staff | `employees` | `status`, `q`, `page`, `per_page` | `200` + pagination | `403 auth.permission_denied`, `422 validation.failed` |
| 3 | GET | `/api/v1/employees/{id}` | One employee with performance | `employees` | `from`, `to` | `200` | `403`, `404 employee.not_found` |
| 4 | POST | `/api/v1/employees` | Add a staff member | `employees` + PIN confirmation, Gold plan | `first_name`, `last_name`, `phone_number`, `dob`, `role`, `permission_keys`; optional `salary`, `salary_period` | `201` | `401 auth.confirmation_required`, `403 plan.feature_unavailable`, `403 auth.permission_denied`, `409 employee.phone_taken`, `422 employee.permission_unknown`, `422 validation.failed` |
| 5 | PATCH | `/api/v1/employees/{id}` | Change a staff member | `employees` | any subset of the fields above | `200` | `403`, `404 employee.not_found`, `422` |
| 6 | DELETE | `/api/v1/employees/{id}` | Remove a staff member | `employees` | — | `200` | `403`, `404 employee.not_found` |
| 7 | GET | `/api/v1/employees/summary` | Team KPI strip | `employees` | `from`, `to` | `200` | `403`, `422` |
| 8 | GET | `/api/v1/shifts` | Shift history and the active shift | Bearer (`employees` to read others) | `employee_id`, `from`, `to`, `page`, `per_page` | `200` + pagination | `403 auth.permission_denied`, `404 employee.not_found` |
| 9 | POST | `/api/v1/shifts/start` | Clock in | Bearer | optional `start_time`, `idempotency_key` | `201` | `409 shift.already_active`, `422 shift.time_in_future` |
| 10 | POST | `/api/v1/shifts/{id}/end` | Clock out | Bearer (own shift) | optional `end_time`, `idempotency_key` | `200` | `404 shift.not_found`, `409 shift.already_ended`, `422 shift.end_before_start`, `422 shift.time_in_future` |
| 11 | PATCH | `/api/v1/shifts/{id}` | Correct a recorded shift | Bearer, owner only | `start_time` and/or `end_time`, `reason` | `200` | `403 auth.merchant_only`, `404 shift.not_found`, `422 shift.end_before_start` |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token — clear the session |
| `403` | `auth.permission_denied` | The caller lacks the `employees` permission (`error.details.required_permission`) |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | 60 requests per minute per IP on these endpoints |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`. Lists add
`meta.pagination`. Branch on `error.code`, never on `message`. See
[errors.md](errors.md).

---

## Concepts

### Permissions

Every permission has a stable **key** (`pos`, `inventory`, `transactions`,
`reports`, `employees`) plus a display name. Authorise on the key. The legacy app
sent numeric ids and matched the display **name**, so renaming a label in an admin
panel would silently lock a shop out of its own screens.

- A **shop owner** holds every key.
- **Staff** hold the keys the owner gave them.
- Nobody can give a permission they do not hold. A staff member with `employees`
  but not `reports` cannot hand `reports` to someone else
  (`403 auth.permission_denied`).
- `permission_keys` on `PATCH` **replaces** the whole set; leave it out to keep
  the current one.

### Status

| `status` | Meaning |
| --- | --- |
| `on_shift` | Has an open shift right now |
| `off_shift` | Active, not clocked in |
| `disabled` | Removed. Only returned with `?status=disabled` |

The legacy response carried the display strings `"On shift"` / `"Off shift"`,
which the UI matched on directly.

### Salary

`salary` is money in the smallest unit of its currency: cents for `USD` (`450` is
`$4.50`), whole units for `SLSH`. `salary_period` is `hourly`, `daily` (default)
or `monthly`. Salary is optional. Salaries entered before this API existed are
treated as `SLSH` per day.

### Payroll estimate

`total_salaries` in the summary is what the salaries cost over the period, in USD:

| Period | Cost |
| --- | --- |
| `hourly` | rate × hours worked |
| `daily` | rate × distinct days worked |
| `monthly` | rate × (days in the period ÷ 30) |

`SLSH` salaries are converted at the configured exchange rate. Treat it as an
estimate for the period, not a payslip.

### Adding staff: the PIN confirmation

Adding staff needs a fresh PIN confirmation from the owner, so a phone left
unlocked cannot create logins.

1. `POST /auth/pin/verify` with `{ "pin": "…", "scope": "employees.create" }`
   returns a `confirmation_token` valid for five minutes.
2. `POST /employees` sends it as `X-EXELO-Confirmation`.

Each token pays for **one** action and only for its own scope. A request refused
for another reason (for example the number is taken) does **not** spend it, so
the owner can correct the form without typing the PIN again.

### Removing staff

`DELETE` is immediate: the employee's tokens are revoked, an open shift is
closed, and their number is freed so a new person can be added with it. Orders
and shifts stay, so reports keep working. The removed person still appears under
`?status=disabled` and their shift history stays readable.

### Shifts

- A shift belongs to a **user**, so the owner can clock in too.
- Only one shift can be open at a time; starting another returns
  `409 shift.already_active` with the open shift.
- `start_time` / `end_time` are optional and default to server time. Send them when
  clocking in offline. A time more than 5 minutes ahead of the server is refused
  (`422 shift.time_in_future`).
- `duration_seconds` on an open shift runs to `server_time`, so the timer stays
  right even if the device clock is wrong.
- Shifts are clocked out by their own user. Corrections are owner-only, flagged
  `edited`, and record who changed it and why, because shift records drive payroll.
- Retrying with the same `idempotency_key` returns the original result instead of
  starting or ending twice.

---

## 1. GET `/api/v1/permissions` — List the assignable permissions

**Purpose:** The checklist shown when adding or editing staff. Replaces
`GET /api/employee/getPOSPermission`.

**Response `200`**

```json
{
  "success": true,
  "data": [
    { "key": "pos", "name": "POS", "description": "Use the register and take payment" },
    { "key": "inventory", "name": "Inventory", "description": "Add and edit products, move stock" },
    { "key": "transactions", "name": "Transactions", "description": "See orders and receipts" },
    { "key": "reports", "name": "Reports", "description": "See sales and inventory reports" },
    { "key": "employees", "name": "Employee Management", "description": "Add and manage staff" }
  ],
  "meta": { "request_id": "req_01M2WWJ6VWQS0MSC9545STXM59", "server_time": "2026-09-19T13:09:19Z" }
}
```

## 2. GET `/api/v1/employees` — List staff

**Purpose:** The staff screen. Replaces `GET /api/employee`. By default it shows
active staff (on shift and off shift), ordered by first name; removed staff show
only under `?status=disabled`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `status` | enum | active staff | `on_shift` \| `off_shift` \| `disabled` |
| `q` | string | — | Name or phone |
| `page`, `per_page` | int | `1`, `50` | `per_page` up to 100 |

**Response `200`** — `GET /employees?status=on_shift`

```json
{
  "success": true,
  "data": [
    {
      "id": 162,
      "first_name": "Layla",
      "last_name": "Ahmed",
      "short_name": "LA",
      "role": "Cashier",
      "phone_number": "+252634110101",
      "dob": "1998-04-12",
      "salary": { "amount": 450, "currency": "USD", "display": "$4.50" },
      "salary_period": "daily",
      "status": "on_shift",
      "has_pin": true,
      "permissions": [
        { "key": "pos", "name": "POS" },
        { "key": "inventory", "name": "Inventory" }
      ],
      "current_shift": { "shift_id": 88, "started_at": "2026-09-19T11:09:19Z", "elapsed_seconds": 7200 },
      "created_at": "2026-09-19T13:09:19Z"
    }
  ],
  "meta": {
    "request_id": "req_01M2WWJ6WEJ9TKE8ANM85XZYQ6",
    "server_time": "2026-09-19T13:09:19Z",
    "pagination": { "page": 1, "per_page": 50, "total": 1, "total_pages": 1, "has_more": false }
  }
}
```

**Response `403` — caller lacks the `employees` permission**

```json
{
  "success": false,
  "message": "You do not have access to this",
  "error": { "code": "auth.permission_denied", "details": { "required_permission": "employees" } }
}
```

## 3. GET `/api/v1/employees/{id}` — One employee with performance

**Purpose:** The staff detail screen: the employee plus how they performed over a
period. Replaces `GET /api/employee/getEmployeeSale/{id}`. Sales are the shop's
`Paid` and `Complete` orders taken by that employee.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `from`, `to` | date | last 30 days | Metric window |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 162,
    "first_name": "Layla",
    "last_name": "Ahmed",
    "short_name": "LA",
    "role": "Cashier",
    "phone_number": "+252634110101",
    "dob": "1998-04-12",
    "salary": { "amount": 450, "currency": "USD", "display": "$4.50" },
    "salary_period": "daily",
    "status": "on_shift",
    "has_pin": true,
    "permissions": [ { "key": "pos", "name": "POS" }, { "key": "inventory", "name": "Inventory" } ],
    "current_shift": { "shift_id": 88, "started_at": "2026-09-19T11:09:19Z", "elapsed_seconds": 7200 },
    "created_at": "2026-09-19T13:09:19Z",
    "metrics": {
      "period": { "from": "2026-08-20", "to": "2026-09-19" },
      "total_sales": { "amount": 2275, "currency": "USD", "display": "$22.75" },
      "order_count": 2,
      "average_order": { "amount": 1137, "currency": "USD", "display": "$11.37" },
      "working_hours": 18.5,
      "shifts_worked": 3,
      "sales_per_hour": { "amount": 123, "currency": "USD", "display": "$1.23" }
    }
  }
}
```

`404 employee.not_found` for an id that belongs to another shop.

## 4. POST `/api/v1/employees` — Add a staff member

**Purpose:** Creates a staff account. Requires a fresh PIN confirmation from the
merchant, and the Gold plan (`employees.manage`). Replaces `POST /api/employee`
and the `verifyUserPin` call that used to run before it with nothing linking the
two.

**Headers**

| Header | Required | Notes |
| --- | --- | --- |
| `X-EXELO-Confirmation` | yes | `confirmation_token` from [`POST /auth/pin/verify`](auth.md#post-authpinverify), scope `employees.create`. One use. |

**Request**

```json
{
  "first_name": "Nasra",
  "last_name": "Yusuf",
  "phone_number": "+252634110303",
  "dob": "2000-01-19",
  "role": "Cashier",
  "salary": { "amount": 450, "currency": "USD" },
  "salary_period": "daily",
  "permission_keys": ["pos", "inventory"]
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `first_name`, `last_name` | string | yes | Up to 50 characters |
| `phone_number` | string | yes | Must not already belong to an owner or active employee |
| `dob` | date | yes | `YYYY-MM-DD`, in the past |
| `role` | string | yes | Free text: `Cashier`, `Stock keeper`, `Supervisor` |
| `salary` | Money | no | `{ "amount": int, "currency": "USD" \| "SLSH" }`, in minor units |
| `salary_period` | enum | no | `hourly` \| `daily` \| `monthly`. Default `daily`. |
| `permission_keys` | array | yes | At least one stable key from `GET /permissions` |

**Response `201`**

```json
{
  "success": true,
  "message": "Nasra can now sign in with their phone number",
  "data": {
    "id": 165,
    "first_name": "Nasra",
    "last_name": "Yusuf",
    "short_name": "NY",
    "role": "Cashier",
    "phone_number": "+252634110303",
    "dob": "2000-01-19",
    "salary": { "amount": 450, "currency": "USD", "display": "$4.50" },
    "salary_period": "daily",
    "status": "off_shift",
    "has_pin": false,
    "permissions": [ { "key": "pos", "name": "POS" }, { "key": "inventory", "name": "Inventory" } ],
    "current_shift": null,
    "created_at": "2026-09-19T13:09:49Z"
  }
}
```

`has_pin: false` — the employee sets their own PIN on first sign-in with
[`POST /auth/pin`](auth.md#post-authpin). No default PIN exists.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.confirmation_required` | Missing, expired, used, or wrong-scope `X-EXELO-Confirmation` |
| `403` | `plan.feature_unavailable` | Silver does not include staff management (`details.required_plan: "gold"`) |
| `403` | `auth.permission_denied` | You tried to grant a permission you do not hold (`details.required_permission`) |
| `409` | `employee.phone_taken` | Number already belongs to an EXELO user |
| `422` | `employee.permission_unknown` | Bad key in `permission_keys` (`details.permission_keys`) |
| `422` | `validation.failed` | Per-field messages |

```json
{
  "success": false,
  "message": "Enter your PIN to continue",
  "error": { "code": "auth.confirmation_required" }
}
```

```json
{
  "success": false,
  "message": "This is part of the Gold plan",
  "error": { "code": "plan.feature_unavailable", "details": { "feature": "employees.manage", "required_plan": "gold" } }
}
```

```json
{
  "success": false,
  "message": "That number already belongs to an EXELO user",
  "error": { "code": "employee.phone_taken", "field": "phone_number" }
}
```

```json
{
  "success": false,
  "message": "Some permissions do not exist",
  "error": { "code": "employee.permission_unknown", "field": "permission_keys", "details": { "permission_keys": ["root"] } }
}
```

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": {
      "last_name": ["The last name field is required."],
      "phone_number": ["The phone number field is required."],
      "permission_keys": ["The permission keys field is required."]
    }
  }
}
```

## 5. PATCH `/api/v1/employees/{id}` — Change a staff member

**Purpose:** Edits any subset of a staff member's details. The phone number
cannot be changed, because it is their sign-in identity.

**Request**

```json
{
  "first_name": "Nasra",
  "salary": { "amount": 500, "currency": "USD" },
  "permission_keys": ["pos"]
}
```

Sending `permission_keys` **replaces** the whole set rather than merging, so a
removal is explicit. Send `"salary": null` to clear the salary.

**Response `200`** — the updated employee, same shape as the list item.

```json
{
  "success": true,
  "message": "Saved",
  "data": {
    "id": 165,
    "first_name": "Nasra",
    "last_name": "Yusuf",
    "short_name": "NY",
    "role": "Cashier",
    "phone_number": "+252634110303",
    "dob": "2000-01-19",
    "salary": { "amount": 500, "currency": "USD", "display": "$5.00" },
    "salary_period": "daily",
    "status": "off_shift",
    "has_pin": false,
    "permissions": [ { "key": "pos", "name": "POS" } ],
    "current_shift": null,
    "created_at": "2026-09-19T13:09:49Z"
  }
}
```

`404 employee.not_found` for another shop's employee or one already removed.

## 6. DELETE `/api/v1/employees/{id}` — Remove a staff member

**Purpose:** Removes staff and revokes their access immediately.

**Response `200`**

```json
{
  "success": true,
  "message": "Nasra Yusuf removed",
  "data": { "id": 165, "deleted": true, "tokens_revoked": 0, "open_shift_closed": true }
}
```

| Field | Notes |
| --- | --- |
| `tokens_revoked` | How many signed-in devices were signed out |
| `open_shift_closed` | Whether an active shift was ended as part of the removal |

History is preserved: orders they took keep their reference, so reports stay
correct. Removing the same person again returns `404 employee.not_found`.

## 7. GET `/api/v1/employees/summary` — Team KPI strip

**Purpose:** The numbers above the staff list. Replaces
`GET /api/employee/getEmployeeSaleCombine`. Covers active staff.

**Query**

| Param | Type | Default |
| --- | --- | --- |
| `from`, `to` | date | Current month |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-19" },
    "total_employees": 2,
    "on_shift_now": 1,
    "total_working_hours": 19,
    "total_salaries": { "amount": 1350, "currency": "USD", "display": "$13.50" },
    "total_sales": { "amount": 2275, "currency": "USD", "display": "$22.75" },
    "employees": [
      {
        "id": 162,
        "short_name": "LA",
        "first_name": "Layla",
        "last_name": "Ahmed",
        "role": "Cashier",
        "status": "on_shift",
        "hours": 18.5,
        "sales": { "amount": 2275, "currency": "USD", "display": "$22.75" }
      },
      {
        "id": 163,
        "short_name": "OF",
        "first_name": "Omar",
        "last_name": "Farah",
        "role": "Stock keeper",
        "status": "off_shift",
        "hours": 0,
        "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }
      }
    ]
  }
}
```

`total_salaries` is the [payroll estimate](#payroll-estimate).

## 8. GET `/api/v1/shifts` — Shift history and the active shift

**Purpose:** The work-timer history. Replaces `GET /api/user/getShiftData`. With
no `employee_id` it returns the caller's own shifts; with the `employees`
permission you can read any employee's, including removed ones.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `employee_id` | int | caller | Needs the `employees` permission |
| `from`, `to` | date | last 30 days | |
| `page`, `per_page` | int | `1`, `50` | |

**Response `200`** — `GET /shifts?employee_id=162`

```json
{
  "success": true,
  "data": [
    {
      "id": 88,
      "employee": { "id": 162, "name": "Layla Ahmed", "short_name": "LA" },
      "date": "2026-09-19",
      "start_time": "2026-09-19T11:09:19Z",
      "end_time": null,
      "duration_seconds": 7200,
      "is_active": true,
      "edited": false
    },
    {
      "id": 87,
      "employee": { "id": 162, "name": "Layla Ahmed", "short_name": "LA" },
      "date": "2026-09-18",
      "start_time": "2026-09-18T06:00:00Z",
      "end_time": "2026-09-18T14:00:00Z",
      "duration_seconds": 28800,
      "is_active": false,
      "edited": false
    }
  ],
  "meta": {
    "request_id": "req_01M2WWJ6YY8Q8EMXT3EDXKDK6S",
    "server_time": "2026-09-19T13:09:19Z",
    "pagination": { "page": 1, "per_page": 50, "total": 3, "total_pages": 1, "has_more": false },
    "summary": { "total_hours": 18.5, "active_shift_id": 88 }
  }
}
```

`employee.id` is `null` for the shop owner's own shifts. `summary.total_hours`
covers the whole filtered range, not only the current page.

**Errors** — `403 auth.permission_denied` when reading another person's shifts
without the `employees` permission; `404 employee.not_found`.

## 9. POST `/api/v1/shifts/start` — Clock in

**Purpose:** Starts the caller's shift. Replaces `POST /api/user/shift` with a
`start_time` field.

**Request** — body is optional

```json
{
  "start_time": "2026-09-19T05:09:19Z",
  "idempotency_key": "b4f1c8de-92a7-4f10-9c33-0a5e7d2b6f81"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `start_time` | timestamp | no | Defaults to server time. Send when clocking in offline. |
| `idempotency_key` | string | no | Retries with the same key return the same shift. |

**Response `201`**

```json
{
  "success": true,
  "message": "Shift started",
  "data": {
    "id": 89,
    "employee": { "id": null, "name": "Kalid Ahmed", "short_name": "KA" },
    "date": "2026-09-19",
    "start_time": "2026-09-19T05:09:19Z",
    "end_time": null,
    "duration_seconds": 28800,
    "is_active": true,
    "edited": false
  }
}
```

**Response `409` — already clocked in**

```json
{
  "success": false,
  "message": "You are already clocked in",
  "error": {
    "code": "shift.already_active",
    "details": { "shift": { "id": 89, "started_at": "2026-09-19T05:09:19Z" } }
  }
}
```

`422 shift.time_in_future` when `start_time` is more than 5 minutes ahead of the
server.

## 10. POST `/api/v1/shifts/{id}/end` — Clock out

**Purpose:** Ends the caller's own shift. The legacy endpoint distinguished start
from end by which field was present, so a double tap was ambiguous. Splitting them
makes each unambiguous and independently idempotent.

**Request** — body is optional

```json
{ "end_time": "2026-09-19T13:09:19Z", "idempotency_key": "e1a2b3c4-1111-4222-8333-444455556666" }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Shift ended — 8h 00m",
  "data": {
    "id": 89,
    "employee": { "id": null, "name": "Kalid Ahmed", "short_name": "KA" },
    "date": "2026-09-19",
    "start_time": "2026-09-19T05:09:19Z",
    "end_time": "2026-09-19T13:09:19Z",
    "duration_seconds": 28800,
    "is_active": false,
    "edited": false
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `shift.not_found` | Not your shift |
| `409` | `shift.already_ended` | Already ended. A retry with the same `idempotency_key` returns `200` instead. |
| `422` | `shift.end_before_start` | `end_time` is before the start |
| `422` | `shift.time_in_future` | `end_time` is more than 5 minutes ahead of the server |

```json
{
  "success": false,
  "message": "The end time is before the start time",
  "error": { "code": "shift.end_before_start", "field": "end_time" }
}
```

```json
{
  "success": false,
  "message": "That shift has already ended",
  "error": { "code": "shift.already_ended" }
}
```

## 11. PATCH `/api/v1/shifts/{id}` — Correct a recorded shift

**Purpose:** Fixes a wrong start or end time, for example a forgotten clock-out.
**Owner only** — even staff with the `employees` permission cannot edit payroll
records. Replaces `PUT /api/user/shift/{id}`.

**Request**

```json
{
  "start_time": "2026-09-18T06:00:00Z",
  "end_time": "2026-09-18T14:30:00Z",
  "reason": "Forgot to clock out"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `start_time`, `end_time` | timestamp | at least one | The one you leave out is kept |
| `reason` | string | yes | Up to 200 characters; recorded with the edit |

**Response `200`**

```json
{
  "success": true,
  "message": "Shift updated",
  "data": {
    "id": 89,
    "employee": { "id": null, "name": "Kalid Ahmed", "short_name": "KA" },
    "date": "2026-09-18",
    "start_time": "2026-09-18T06:00:00Z",
    "end_time": "2026-09-18T14:30:00Z",
    "duration_seconds": 30600,
    "is_active": false,
    "edited": true,
    "edited_by": { "id": 1146, "name": "Kalid Ahmed" },
    "edited_at": "2026-09-19T13:09:19Z",
    "edit_reason": "Forgot to clock out"
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Only the shop owner can do this |
| `404` | `shift.not_found` | Not a shift of this shop |
| `422` | `shift.end_before_start` | The corrected end is before the start |
| `422` | `validation.failed` | Missing `reason`, or neither time given |

```json
{
  "success": false,
  "message": "Only the shop owner can do this",
  "error": { "code": "auth.merchant_only" }
}
```

---

## Step by step: adding a staff member

```
POST /auth/pin/verify   { pin, scope: "employees.create" }   → confirmation_token
POST /employees         X-EXELO-Confirmation: <token>        → 201, has_pin: false
   (the employee, on their own phone)
POST /auth/lookup       { phone_number }                     → user_type: "employee", has_pin: false
POST /auth/pin          { phone_number, pin, pin_confirmation }  → token, permissions
POST /shifts/start                                           → clocked in
```

| State | Client does |
| --- | --- |
| `401 auth.confirmation_required` | Ask the owner for their PIN again, then retry |
| `403 plan.feature_unavailable` | Show the upgrade screen for `details.required_plan` |
| `409 employee.phone_taken` | Show the error under the phone field; the PIN confirmation is **not** spent |
| `422 employee.permission_unknown` | Refresh the permission list from `GET /permissions` |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl $BASE/permissions -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# 1. confirm the owner's PIN (returns confirmation_token)
curl -X POST $BASE/auth/pin/verify -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -d '{"pin":"2580","scope":"employees.create"}'

# 2. add the employee
curl -X POST $BASE/employees -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -H "X-EXELO-Confirmation: $CONFIRMATION" \
  -d '{"first_name":"Nasra","last_name":"Yusuf","phone_number":"+252634110303","dob":"2000-01-19","role":"Cashier","salary":{"amount":450,"currency":"USD"},"permission_keys":["pos","inventory"]}'

# list, detail, summary
curl "$BASE/employees?status=on_shift" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl "$BASE/employees/165" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl "$BASE/employees/summary" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# change, then remove
curl -X PATCH $BASE/employees/165 -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -d '{"permission_keys":["pos"]}'
curl -X DELETE $BASE/employees/165 -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# shifts
curl -X POST $BASE/shifts/start -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -X POST $BASE/shifts/89/end -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl "$BASE/shifts?employee_id=162" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -X PATCH $BASE/shifts/89 -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -d '{"end_time":"2026-09-18T14:30:00Z","reason":"Forgot to clock out"}'
```

---

## Implementation notes

Implemented in `EmployeeController`, `ShiftController`, `EmployeeService` and
`ShiftService`. The legacy `/api/employee/*` and `/api/user/shift` routes are
unchanged.

- **Schema.** `employees` gained `salary_currency`, `salary_period`, `removed_at`
  and `former_phone_number`; `salary` and `location` are now optional. `shifts`
  gained `edited_by`, `edited_at` and `edit_reason`.
- **List default.** `GET /employees` returns active staff by default rather than
  every state, so removed staff do not clutter the everyday list. Use
  `?status=disabled` for them.
- **Shift corrections are owner-only.** An earlier draft of this document listed
  the `employees` permission for `PATCH /shifts/{id}` while also calling it
  merchant-only; the stricter rule is implemented.
- **Removal.** The employee's number is stored in `former_phone_number` and the
  active number is cleared (`0`), matching the legacy behaviour that lets a number
  be registered again. The linked login is soft-deleted.
- **No default PIN.** New staff get an unusable password until they set their own
  PIN. The legacy endpoint gave everyone the PIN `1234`.
- **Sales figures** come from the shop's `Paid` and `Complete` orders taken by the
  employee, in USD.
- **Wording.** The 201 message uses "their", since the app does not store gender.
- **Plan gating.** Only `POST /employees` checks the plan, as in the original spec.
