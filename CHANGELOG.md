# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### What the API was confirmed to do

The assumptions the specification left open were checked with real calls against
the live API, made through the Huuray clients — the 2026-08-15 checks through the
Node.js client — and each entry below is dated. This client's own suite makes no
live calls.

- **`X-API-HASH` encoding is lowercase hex** (2026-08-15) — authenticated against
  `GET /v4/Balance`; the other three candidate encodings return 401. The default
  is pinned by a test; `hashEncoding` remains available as an override.
- **Base URL `https://api.huuray.com`** works for every endpoint exercised
  (2026-08-15).
- **`POST /v4/Template` accepts a bodyless request**, as the spec implies
  (2026-08-15).
- **The full order loop works end to end** (2026-08-15): Balance → sync Order
  (quantity 1, no delivery) → Search by `RefID` (matched) → Cancel (full) → Balance.
- **`POST /v4/Template` answered HTTP 404** ("There were no active templates") for an
  account with no templates — observed live 2026-08-15. This is why the
  reconciliation examples treat `NotFoundException` from `/v4/Search` as
  "the order did not land".
- **`POST /v4/Order` can take longer than 30 seconds**, even for quantity 1 —
  observed live 2026-08-16. The client raised the indeterminate-order error, and a
  search by `RefID` showed the order had landed. This is why the docs say to raise
  `timeoutMs` and reconcile rather than retry.
- **An account with PDF templates but no email or SMS templates gets `200`** with
  an empty `Templates` list, and its PDF templates in `PDFTemplates` — observed live
  2026-09-16 through this client. The 2026-08-15 404 predates PDF templates in the
  v4 specification.

## [0.1.0] — unreleased

First release. Complete coverage of the Huuray API v4, ported from the Node.js,
Python and .NET clients.

### Added

- `HuurayClient` with request signing, nonce generation, timeouts, retries for
  reads, and typed exceptions under `Huuray\Exception`.
- All nine v4 operations: balances, catalogue, templates, stock, exchange rates,
  orders (create, createSync, search, resend, cancel).
- `sendReward()` — one gift card to one recipient in a single call.
- PDF templates, added to the v4 specification: `templates->list()` returns
  `pdfTemplates` (`PdfTemplate`: `uid`, `name`, `type`, `language`, `country`,
  `brandName`) alongside `templates`. `orders->create()`, `orders->createSync()`
  and `sendReward()` accept an optional `pdfTemplateUid`, sent as
  `DeliveryPDFTemplateUid` and omitted when not supplied. It is rejected before any
  request unless `templateId` is also set; the API requires that template to be an
  email template.
- `request()` — an escape hatch that signs any call.
- Read-only CLI, `vendor/bin/huuray`: `balance`, `catalogue`, `templates`, `stock`,
  `rates`, `search`. `templates` lists PDF templates as well as delivery templates,
  in table and `--json` output.
- `Redact::redact()` and `Redact::safeJson()` for keeping voucher codes out of logs.
- Zero Composer runtime dependencies: PHP 8.2+, ext-curl and ext-json. The default
  `CurlTransport` sits behind a small `Huuray\Http\Transport` interface, so tests and
  custom HTTP stacks can inject their own.

### Safety behaviour worth calling out

- **Orders, resends and cancels are never retried automatically.** The API has no
  idempotency key, so a retry can order twice or re-deliver a live gift card.
  Retries are opt-in per operation and never inferred from the HTTP method — four
  read-only v4 endpoints are POSTs.
- A failed order throws `IndeterminateOrderException`, which points at
  `$client->orders->search(refId: ...)` for reconciliation and carries the `refId`.
- **Every request has a timeout.** The default transport sets both
  `CURLOPT_TIMEOUT_MS` and `CURLOPT_CONNECTTIMEOUT_MS`, and a `timeoutMs` below 1 is
  rejected — without a timeout, a hung order would never become an
  `IndeterminateOrderException`.
- **The response body is read inside the same error handling as the request**, so
  a connection dropped or timed out mid-body is mapped rather than escaping raw
  past the order-safety wrapper.
- **A 2xx with an empty or unparseable body throws `ConnectionException`** instead
  of masquerading as an empty result — a garbled `/v4/Search` response must never
  read as "the order did not land".
- **Amounts must be integers in minor units, and `quantity` a positive integer;
  anything else is rejected** — every float (including `50.00` and `2.0`) and
  every bool. Both are declared natively as `mixed` (PHPDoc `int`) rather than
  `int`, because in a caller's file without `strict_types` PHP would silently
  coerce `50.00` to `50` (ordering 0.50), `quantity: 1.5` to `1`, and `true` to `1`
  before any guard could see it. PHP can tell `50.00` from `50`; JavaScript cannot.
- **Recipient contact details, credentials and request/response bodies are marked
  `#[\SensitiveParameter]`**, so exception stack traces do not carry them in clear
  text even with `zend.exception_ignore_args` Off (PHP's default). Dumping the client
  with `var_dump()` / `print_r()` shows the API token and secret redacted.
- **The request line and headers are checked before anything is sent.** An API
  token or user agent containing a line break or other control character, an empty
  or non-visible-ASCII custom nonce, a base URL with spaces, control characters or
  non-ASCII bytes, or — through `request()` — an HTTP method that is not a token or
  a path that does not start with `/` or contains anything but visible ASCII, is
  rejected. None of them can inject a header, send the credentials to another host,
  or truncate the header block. A token read from a file often ends in a newline:
  trim it.
- **`206 Partial Content`** on cancel and resend is surfaced as `partial: true`
  rather than being treated as plain success.
- **Voucher codes are never logged** by this library at any level. Exceptions keep
  only a redacted copy of a response body, and `var_dump()` / `print_r()` of a
  voucher or a result holding one show the code, CVV and redeem link redacted.
- **The CLI cannot move value.**

### Enforced mechanically

Three gates in [`tests/ConformanceTest.php`](tests/ConformanceTest.php) run on every
commit, reading the vendored specification at test time:

- **no-invention** — every request the SDK can emit maps to a spec path and verb,
  and sends no property the spec does not define
- **coverage** — every operation in the spec has an SDK method
- **request-conformance** — every request body validates against the spec schema

The validator **fails closed** on schema shapes it does not understand
(`allOf`/`oneOf`/`anyOf`, a missing `type`, an array without `items`), so a weekly
spec refresh cannot leave a vacuous gate green. A reflection-based inventory of the
public surface pins the harness, so a new method cannot bypass the gates.

### Not included, on purpose

- No release workflow: Packagist publishes from git tags through its GitHub
  integration, with no token involved.
- No CodeQL workflow: CodeQL does not support PHP.

[Unreleased]: https://github.com/Huuray-A-S/huuray-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Huuray-A-S/huuray-php/releases/tag/v0.1.0
