# NFC & Device Keys

Contactless payment tags, and the encryption keys that protect them.

**This module is the entire Firebase migration.** Everything else in the app
already runs on Laravel; these two Firestore documents are the only live
Firebase data.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/nfc/keys/current`](#get-nfckeyscurrent) | Bearer |
| POST | [`/nfc/keys/rotate`](#post-nfckeysrotate) | Bearer · merchant only |
| GET | [`/nfc/tags`](#get-nfctags) | Bearer |
| POST | [`/nfc/tags`](#post-nfctags) | Bearer · `pos` |
| POST | [`/nfc/tags/{tag_id}/verify`](#post-nfctagstag_idverify) | Bearer · `pos` |
| DELETE | [`/nfc/tags/{tag_id}`](#delete-nfctagstag_id) | Bearer · merchant only |

---

## What exists today

| Firestore path | Contents | Problem |
| --- | --- | --- |
| `config/encryption` | `{ "key": "<base64 AES key>" }` | **One key for every merchant on the platform.** Any device that has ever booted the app can read it and decrypt any shop's tags. |
| `nfc_tags/admin` | `{ "<tagId>": "<encryptedPassword>" }` | **One shared document** holding every tag for every merchant. Passwords are fetched to the device and compared locally. |

Read and written by `lib/nfc_functionality.dart`, with the AES key cached in
`FlutterSecureStorage` under `exelo_encryption_key` and Firestore used as the
fallback when the local cache is empty. `lib/payments.dart` uses the same key to
decrypt a tag during a contactless payment.

A straight lift-and-shift of these two documents would carry both flaws into
the new backend, so the shape changes:

1. Keys become **per merchant**, not global.
2. Tag passwords are **verified server-side against a hash**, never sent to the
   device.

---

## GET /nfc/keys/current

The merchant's current tag-encryption key, for writing and reading tags offline.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "key_id": "nkey_01JBY02M4T",
    "algorithm": "AES-256-GCM",
    "key": "base64:8f2Kd9Lm3Qp7Rv1Xz5Bn0Ct4Hy6Jw8Ea2Sg4Uk6Mi8=",
    "version": 3,
    "created_at": "2026-06-01T00:00:00Z",
    "previous_keys": [
      { "key_id": "nkey_01JAX77K2M", "version": 2,
        "key": "base64:3Rt5Yu7Io9Pa1Sd3Fg5Hj7Kl9Zx1Cv3Bn5Mq7We9=",
        "retired_at": "2026-06-01T00:00:00Z" }
    ]
  }
}
```

`previous_keys` lets a device decrypt tags written before the last rotation. A
shop will have tags in circulation that predate any key change, and those must
keep working.

**Client caching.** The key is stored in `FlutterSecureStorage` so NFC keeps
working offline. That cache stays — what changes is that the fallback is this
endpoint, not a Firestore document readable by every install.

**Errors** — `403 plan.feature_unavailable` when the merchant is on Silver
(`nfc.payments` is a Gold feature).

---

## POST /nfc/keys/rotate

Issues a new key. Merchant-only, and requires PIN confirmation.

**Headers** — `X-EXELO-Confirmation` from
[`POST /auth/pin/verify`](auth.md#post-authpinverify).

**Request**

```json
{ "reason": "device_lost", "retire_previous_after_days": 90 }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `reason` | enum | yes | `scheduled` \| `device_lost` \| `suspected_compromise` |
| `retire_previous_after_days` | int | no | Default `90`. How long old tags stay readable. |

**Response `201`**

```json
{
  "success": true,
  "message": "New key issued. Existing tags stay readable for 90 days.",
  "data": {
    "key_id": "nkey_01JBY0B8W5",
    "version": 4,
    "created_at": "2026-09-18T14:50:00Z",
    "previous_key_retires_at": "2026-12-17T14:50:00Z",
    "tags_to_rewrite": 12
  }
}
```

`tags_to_rewrite` is how many active tags still carry the old key, so the app
can prompt the merchant to re-write them.

---

## GET /nfc/tags

Tags registered to this merchant. Replaces reading the shared
`nfc_tags/admin` document.

**Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "tag_id": "04:A2:19:B7:5C:80:00",
      "label": "Till 1 customer card",
      "status": "active",
      "key_version": 4,
      "registered_at": "2026-07-02T10:00:00Z",
      "last_used_at": "2026-09-18T12:41:00Z",
      "use_count": 88
    }
  ],
  "meta": { "pagination": { "page": 1, "per_page": 50, "total": 12, "total_pages": 1, "has_more": false } }
}
```

Note what is **not** here: the password. The legacy client downloaded every
encrypted password to the device and compared locally. v1 never returns it.

| `status` | Meaning |
| --- | --- |
| `active` | Usable |
| `revoked` | Blocked — lost or stolen |
| `stale_key` | Written with a retired key; re-write it |

---

## POST /nfc/tags

Registers a tag. The device sends a hash, not the password.

**Request**

```json
{
  "tag_id": "04:A2:19:B7:5C:80:00",
  "label": "Till 1 customer card",
  "password_hash": "argon2id$v=19$m=65536,t=3,p=4$...",
  "key_version": 4,
  "idempotency_key": "..."
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `tag_id` | string | yes | Hardware UID |
| `label` | string | no | Shown in the tag list |
| `password_hash` | string | yes | Derived on-device. The raw password never leaves it. |
| `key_version` | int | yes | Which key the tag was written with |

**Response `201`**

```json
{
  "success": true,
  "message": "Tag registered",
  "data": {
    "tag_id": "04:A2:19:B7:5C:80:00",
    "label": "Till 1 customer card",
    "status": "active",
    "key_version": 4,
    "registered_at": "2026-09-18T14:52:00Z"
  }
}
```

**Errors** — `409 nfc.tag_already_registered`. If the tag belongs to a
**different** merchant, the response is a plain `404` rather than a `409`, so
the API cannot be used to discover which shop a tag belongs to.

---

## POST /nfc/tags/{tag_id}/verify

Checks a password before allowing a write to the tag. Replaces fetching the
stored password to the device and comparing it locally.

**Request**

```json
{ "password_hash": "argon2id$v=19$m=65536,t=3,p=4$..." }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Verified",
  "data": {
    "verified": true,
    "tag_id": "04:A2:19:B7:5C:80:00",
    "confirmation_token": "nfc_01JBY0F3X9",
    "expires_at": "2026-09-18T14:58:00Z"
  }
}
```

**Response `401`**

```json
{
  "success": false,
  "message": "That password is not correct",
  "error": { "code": "nfc.password_invalid", "details": { "attempts_remaining": 2 } }
}
```

Rate-limited per tag. Five failures in ten minutes locks verification for an
hour — a server-side control that was impossible when comparison happened on
the device.

### Offline

Tag **writing** needs verification, so it needs the network. Tag **reading**
during a payment does not: the device decrypts with its cached key and the
resulting charge goes through [`POST /payments/charges`](payments.md#post-paymentscharges)
with `rail: "nfc"`, which is online anyway. No payment is ever completed
offline.

---

## DELETE /nfc/tags/{tag_id}

Revokes a tag. Merchant-only.

**Request**

```json
{ "reason": "lost" }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Tag revoked",
  "data": { "tag_id": "04:A2:19:B7:5C:80:00", "status": "revoked", "revoked_at": "2026-09-18T14:55:00Z" }
}
```

Revocation is immediate server-side. A device holding a cached key can still
decrypt the tag, but the charge will be refused — which is why revocation is
enforced at the charge, not at the read.

---

## Migration from Firestore

| Step | Action |
| --- | --- |
| 1 | Read `config/encryption` and `nfc_tags/admin` from Firestore |
| 2 | Attribute each tag to a merchant. **This mapping does not exist today** — the document is keyed only by tag id. Expect to need shop-side confirmation, or to re-register tags on first use. |
| 3 | Generate a per-merchant key (version 1) and store the legacy global key as a retired `previous_key`, so existing tags keep working |
| 4 | Import tag passwords as hashes. The current values are encrypted, not hashed, so they must be decrypted with the global key and re-hashed during the import. |
| 5 | Ship the app release that reads keys from `/nfc/keys/current` instead of Firestore |
| 6 | Once telemetry shows no Firestore reads, delete both documents and remove `cloud_firestore` |

**Open question:** step 2 is the blocker. Because `nfc_tags/admin` is a single
shared document with no merchant field, there may be no way to attribute
existing tags. If the tag population is small, re-registering them on first use
is simpler and safer than attempting the import.
