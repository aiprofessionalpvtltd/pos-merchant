# Employees & Shifts

Staff accounts, what they are allowed to do, and the work timer.

Employees sign in with the same [auth endpoints](auth.md) as merchants — phone
number and PIN. This module is about managing them.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/employees`](#get-employees) | Bearer · `employees` |
| GET | [`/employees/{id}`](#get-employeesid) | Bearer · `employees` |
| POST | [`/employees`](#post-employees) | Bearer · `employees` + PIN confirmation |
| PATCH | [`/employees/{id}`](#patch-employeesid) | Bearer · `employees` |
| DELETE | [`/employees/{id}`](#delete-employeesid) | Bearer · `employees` |
| GET | [`/employees/summary`](#get-employeessummary) | Bearer · `employees` |
| GET | [`/permissions`](#get-permissions) | Bearer |
| GET | [`/shifts`](#get-shifts) | Bearer |
| POST | [`/shifts/start`](#post-shiftsstart) | Bearer |
| POST | [`/shifts/{id}/end`](#post-shiftsidend) | Bearer |
| PATCH | [`/shifts/{id}`](#patch-shiftsid) | Bearer · `employees` |

---

## GET /employees

Replaces `GET /api/employee`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `status` | enum | all | `on_shift` \| `off_shift` \| `disabled` |
| `q` | string | — | Name or phone |
| `page`, `per_page` | int | `1`, `50` | |

**Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 21,
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
      "current_shift": { "shift_id": 5512, "started_at": "2026-09-18T06:02:00Z", "elapsed_seconds": 28800 },
      "created_at": "2026-02-11T10:00:00Z"
    }
  ],
  "meta": { "pagination": { "page": 1, "per_page": 50, "total": 4, "total_pages": 1, "has_more": false } }
}
```

`status` is a real enum. The legacy response carried the display strings
`"On shift"` / `"Off shift"`, which the UI matched on directly.

---

## GET /employees/{id}

One employee with their sales performance. Replaces
`GET /api/employee/getEmployeeSale/{employeeId}`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `from`, `to` | date | last 30 days | Metric window |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 21,
    "first_name": "Layla",
    "last_name": "Ahmed",
    "short_name": "LA",
    "role": "Cashier",
    "phone_number": "+252634110101",
    "dob": "1998-04-12",
    "salary": { "amount": 450, "currency": "USD", "display": "$4.50" },
    "salary_period": "daily",
    "status": "on_shift",
    "permissions": [ { "key": "pos", "name": "POS" } ],
    "metrics": {
      "period": { "from": "2026-08-19", "to": "2026-09-18" },
      "total_sales": { "amount": 214000, "currency": "USD", "display": "$2,140.00" },
      "order_count": 186,
      "average_order": { "amount": 1150, "currency": "USD", "display": "$11.50" },
      "working_hours": 148.5,
      "shifts_worked": 22,
      "sales_per_hour": { "amount": 1441, "currency": "USD", "display": "$14.41" }
    }
  }
}
```

---

## POST /employees

Adds a staff member. Requires a fresh PIN confirmation from the merchant — the
legacy app called `POST /api/login/verifyUserPin` first, then the create
endpoint, with nothing linking the two.

**Headers**

| Header | Required | Notes |
| --- | --- | --- |
| `X-EXELO-Confirmation` | yes | `confirmation_token` from [`POST /auth/pin/verify`](auth.md#post-authpinverify) |

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
| `first_name` | string | yes | |
| `last_name` | string | yes | |
| `phone_number` | string | yes | Must not already be an EXELO user |
| `dob` | date | yes | |
| `role` | string | yes | Free text: `Cashier`, `Stock keeper`, `Supervisor` |
| `salary` | Money | no | |
| `salary_period` | enum | no | `hourly` \| `daily` \| `monthly`. Default `daily`. |
| `permission_keys` | array | yes | Stable keys, not display names |

The legacy endpoint sent `permissions` as an array of numeric ids, and the app
then authorised by matching the display **name** (`'Employee Management'`).
Renaming a label in an admin panel would have silently locked merchants out of
their own screens. v1 uses stable keys throughout.

**Response `201`**

```json
{
  "success": true,
  "message": "Nasra can now sign in with her phone number",
  "data": {
    "id": 23,
    "first_name": "Nasra",
    "last_name": "Yusuf",
    "phone_number": "+252634110303",
    "status": "off_shift",
    "has_pin": false,
    "permissions": [ { "key": "pos", "name": "POS" }, { "key": "inventory", "name": "Inventory" } ]
  }
}
```

`has_pin: false` — the employee sets their own PIN on first sign-in via
[`POST /auth/pin`](auth.md#post-authpin).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.confirmation_required` | Missing or expired `X-EXELO-Confirmation` |
| `409` | `employee.phone_taken` | Number already belongs to an EXELO user |
| `403` | `plan.feature_unavailable` | Silver does not include staff management |
| `422` | `employee.permission_unknown` | Bad key in `permission_keys` |

---

## PATCH /employees/{id}

**Request**

```json
{
  "first_name": "Nasra",
  "salary": { "amount": 500, "currency": "USD" },
  "permission_keys": ["pos"]
}
```

Any subset. Sending `permission_keys` **replaces** the whole set rather than
merging — an explicit list avoids ambiguity about removal.

**Response `200`** — the updated employee.

---

## DELETE /employees/{id}

Removes a staff member and revokes their tokens immediately.

**Response `200`**

```json
{
  "success": true,
  "message": "Nasra Yusuf removed",
  "data": { "id": 23, "deleted": true, "tokens_revoked": 1, "open_shift_closed": true }
}
```

History is preserved: orders they took keep the `employee` reference, so reports
stay correct. `open_shift_closed` reports whether an active shift was ended as
part of the removal.

---

## GET /employees/summary

The team KPI strip. Replaces `GET /api/employee/getEmployeeSaleCombine`.

**Query**

| Param | Type | Default |
| --- | --- | --- |
| `from`, `to` | date | Current month |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-18" },
    "total_employees": 4,
    "on_shift_now": 3,
    "total_working_hours": 148,
    "total_salaries": { "amount": 61200, "currency": "USD", "display": "$612.00" },
    "total_sales": { "amount": 214000, "currency": "USD", "display": "$2,140.00" },
    "employees": [
      {
        "id": 21,
        "short_name": "LA",
        "first_name": "Layla",
        "last_name": "Ahmed",
        "role": "Cashier",
        "status": "on_shift",
        "hours": 42.5,
        "sales": { "amount": 86400, "currency": "USD", "display": "$864.00" }
      }
    ]
  }
}
```

The legacy response nested the full employee list inside the summary and
returned `total_salaries`, `total_sale` and `total_working_hours` as bare
numbers with no currency or period.

---

## GET /permissions

The assignable permission list, for the checklist when adding staff. Replaces
`GET /api/employee/getPOSPermission`.

**Response `200`**

```json
{
  "success": true,
  "data": [
    { "key": "pos", "name": "POS", "description": "Use the register and take payment" },
    { "key": "inventory", "name": "Inventory", "description": "Add and edit products, move stock" },
    { "key": "transactions", "name": "Transactions", "description": "See orders and receipts" },
    { "key": "employees", "name": "Employee Management", "description": "Add and manage staff" },
    { "key": "reports", "name": "Reports", "description": "See sales and inventory reports" }
  ]
}
```

---

## GET /shifts

Shift history and the active shift. Replaces `GET /api/user/getShiftData`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `employee_id` | int | caller | Merchants may query any employee |
| `from`, `to` | date | last 30 days | |
| `page`, `per_page` | int | `1`, `50` | |

**Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 5512,
      "employee": { "id": 21, "name": "Layla Ahmed", "short_name": "LA" },
      "date": "2026-09-18",
      "start_time": "2026-09-18T06:02:00Z",
      "end_time": null,
      "duration_seconds": 28800,
      "is_active": true,
      "edited": false
    },
    {
      "id": 5498,
      "employee": { "id": 21, "name": "Layla Ahmed", "short_name": "LA" },
      "date": "2026-09-17",
      "start_time": "2026-09-17T06:00:00Z",
      "end_time": "2026-09-17T14:30:00Z",
      "duration_seconds": 30600,
      "is_active": false,
      "edited": true
    }
  ],
  "meta": {
    "pagination": { "page": 1, "per_page": 50, "total": 22, "total_pages": 1, "has_more": false },
    "summary": { "total_hours": 148.5, "active_shift_id": 5512 }
  }
}
```

`duration_seconds` on an active shift is computed to `server_time`, so the
client's timer stays right even if the device clock is wrong.

---

## POST /shifts/start

Clocks in. Replaces `POST /api/user/shift` with a `start_time` field.

**Request**

```json
{ "start_time": "2026-09-18T06:02:00Z", "idempotency_key": "..." }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `start_time` | timestamp | no | Defaults to server time. Supplied when clocking in offline. |

**Response `201`**

```json
{
  "success": true,
  "message": "Shift started",
  "data": { "id": 5512, "start_time": "2026-09-18T06:02:00Z", "is_active": true }
}
```

**Errors** — `409 shift.already_active` with the open shift in
`error.details.shift`.

---

## POST /shifts/{id}/end

Clocks out. Replaces the same `POST /api/user/shift` with an `end_time` field
instead — one endpoint that distinguished start from end by which field was
present, so a double tap was ambiguous. Splitting them makes each
unambiguous and independently idempotent.

**Request**

```json
{ "end_time": "2026-09-18T14:30:00Z", "idempotency_key": "..." }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Shift ended — 8h 28m",
  "data": {
    "id": 5512,
    "start_time": "2026-09-18T06:02:00Z",
    "end_time": "2026-09-18T14:30:00Z",
    "duration_seconds": 30480,
    "is_active": false
  }
}
```

**Errors** — `409 shift.already_ended`, `422 shift.end_before_start`.

---

## PATCH /shifts/{id}

Corrects a recorded shift. Merchant-only. Replaces
`PUT /api/user/shift/{shiftId}`.

**Request**

```json
{ "start_time": "2026-09-17T06:00:00Z", "end_time": "2026-09-17T14:30:00Z", "reason": "Forgot to clock out" }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Shift updated",
  "data": {
    "id": 5498,
    "start_time": "2026-09-17T06:00:00Z",
    "end_time": "2026-09-17T14:30:00Z",
    "duration_seconds": 30600,
    "edited": true,
    "edited_by": { "id": 91, "name": "Kalid Ahmed" },
    "edited_at": "2026-09-18T14:40:00Z"
  }
}
```

Edits are flagged and attributed, because shift records drive payroll.
