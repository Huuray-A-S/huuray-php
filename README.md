# huuray/huuray-php

#### Easily send gift cards and rewards from PHP

<!-- badges: start -->
[![CI](https://github.com/Huuray-A-S/huuray-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Huuray-A-S/huuray-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/huuray/huuray-php.svg?color=45652a)](https://packagist.org/packages/huuray/huuray-php)
[![PHP](https://img.shields.io/packagist/dependency-v/huuray/huuray-php/php.svg?color=45652a)](https://packagist.org/packages/huuray/huuray-php)
[![License: MIT](https://img.shields.io/badge/license-MIT-45652a.svg)](LICENSE)
[![API v4](https://img.shields.io/badge/Huuray%20API-v4-9dcf73.svg)](https://api.huuray.com/swagger/index.html)
[![Sign up](https://img.shields.io/badge/Huuray-sign%20up-ff5c43.svg)](https://huuray.com/sign-up/)
<!-- badges: end -->

[Huuray](https://huuray.com) is a platform for sending digital gift cards and rewards to recipients in 170+ countries. `huuray/huuray-php` is the official, slightly-opinionated PHP client for the **Huuray API v4** — with, dare we say, *hurray*-worthy defaults for the parts of a rewards API that are easy to get wrong.

Use it to send employee recognition, customer incentives, survey payouts, referral bonuses, or research participant compensation — without anyone opening a dashboard.

```php
use Huuray\HuurayClient;
use Huuray\Recipient;

$huuray = new HuurayClient(
    apiToken: (string) getenv('HUURAY_API_TOKEN'),
    apiSecret: (string) getenv('HUURAY_API_SECRET'),
);

$huuray->sendReward(
    productToken: 'the-product-you-chose',
    value: 50_00,                           // minor units — 50.00
    currency: 'DKK',
    recipient: new Recipient(name: 'Jane Doe', email: 'jane@example.com'),
    templateId: 42,
    refId: 'payroll-2026-08-jane',          // your own key
);
```

- **Typed throughout**, and checked against the Huuray OpenAPI specification in CI, so it cannot drift from the API.
- **Request signing handled** — the nonce and SHA-512 hash every call needs.
- **Safe by default around money** — orders are never automatically retried, because the API has no idempotency key.
- **Zero Composer runtime dependencies** — just PHP, ext-curl and ext-json.

## Requirements

- **PHP 8.2 or newer**, with **ext-curl** (and ext-json, which is built in).
- **A Huuray B2B account.** New to Huuray? [Sign up here](https://huuray.com/sign-up/) — it takes a couple of minutes.
- **API credentials** — an API token and secret for your account. Ask your Huuray contact to enable API access if you do not have them yet.

The full API this client wraps is documented at the [Huuray API v4 reference (Swagger)](https://api.huuray.com/swagger/index.html).

## Install

```bash
composer require huuray/huuray-php
```

## Getting started

Start with calls that only read. None of these order anything, deliver anything, or spend anything:

```php
use Huuray\HuurayClient;

$huuray = new HuurayClient(
    apiToken: (string) getenv('HUURAY_API_TOKEN'),
    apiSecret: (string) getenv('HUURAY_API_SECRET'),
);

// What can you spend? Amounts are in minor units: 50000 is 500.00.
$balances = $huuray->balances->list()->balances;

// What can you send? Omitting `all` returns just your products, with tokens.
$products = $huuray->catalogue->list()->products;

// How will it be delivered? Templates are the emails and texts recipients get.
$templates = $huuray->templates->list()->templates;
```

Or from a terminal, without writing any code:

```bash
export HUURAY_API_TOKEN=... HUURAY_API_SECRET=...
vendor/bin/huuray balance
vendor/bin/huuray catalogue
```

## Sending a reward

`sendReward()` is one gift card to one recipient — the common case, and exactly one `POST /v4/Order`:

```php
$reward = $huuray->sendReward(
    productToken: 'the-product-you-chose',
    value: 50_00,
    currency: 'DKK',
    recipient: new Recipient(name: 'Jane Doe', email: 'jane@example.com'),
    templateId: 42,
    refId: 'payroll-2026-08-jane',
);

$reward->orderUid;  // keep this
```

For anything larger, use the orders resource directly:

```php
$huuray->orders->create(
    productToken: 'the-product-you-chose',
    value: 25_00,
    currency: 'DKK',
    quantity: 200,
    templateId: 42,
    refId: 'q3-customer-thankyou',
    recipients: [/* 1 Recipient, or exactly 200 */],
);
```

## Seven things worth knowing

These are the parts of the API that are easy to get wrong. The client handles each one, but the behaviour is worth understanding.

### 1. Money is in minor units

`value: 50_00` is 50.00, not 5000.00. Passing a major-unit amount into this field orders **1/100th** of what you meant, so the client rejects **every float** rather than rounding — including `50.00`:

```php
$huuray->sendReward(value: 50.5, /* … */);    // throws — a float
$huuray->sendReward(value: 50.00, /* … */);   // throws — a float is major units by mistake
$huuray->sendReward(value: 50_00, /* … */);   // 50.00
```

Why amounts (and `quantity`) are declared natively as `mixed` and not `int`, while their PHPDoc type is `int`: in a calling file **without** `declare(strict_types=1)`, PHP silently coerces `50.00` to the integer `50` (and `50.5` to `50`, with only a deprecation notice; `true` to `1`) — an `int` parameter would quietly order 0.50, and `quantity: 1.5` would order one code. Accepting the value un-coerced and rejecting anything but an int with an explanation is what makes the mistake visible.

PHP catches a mixup a JavaScript client cannot: `50.00` is a `float` here, not an integer, so it never reaches the API. The one case no guard can catch is a whole-number amount — `value: 50` is a perfectly valid order for 0.50 — so always write amounts as integers in minor units.

Every input guard throws the built-in `\InvalidArgumentException` **before any request is sent**. `stock->check(value: ...)` follows the same rule.

### 2. Orders are never retried automatically

`POST /v4/Order` has no idempotency key, so retrying a timed-out order can order a second time — real gift cards, real money. This client never does that. Instead it throws `IndeterminateOrderException`, and you reconcile:

```php
use Huuray\Exception\IndeterminateOrderException;
use Huuray\Exception\NotFoundException;

try {
    $huuray->sendReward(refId: 'payroll-2026-08-jane', /* … */);
} catch (IndeterminateOrderException $e) {
    // Do NOT retry. Find out what actually happened.
    try {
        $found = $huuray->orders->search(refId: 'payroll-2026-08-jane');
        if ($found->orderUid !== null) {
            // It landed. Nothing more to do.
        } else {
            // No match — it did not land. Safe to send again with the same refId.
        }
    } catch (NotFoundException) {
        // The API answers 404 when nothing matches: the order did not land.
        // Safe to send again with the same refId.
    }
    // Anything else from the lookup means it failed, and the outcome is still unknown.
}
```

This is why `sendReward()` requires a `refId` even though the API treats it as optional: without one, an order that times out cannot be looked up.

Every request has a timeout — 30 seconds by default, enforced by the transport — because a request that can hang forever never reaches this exception at all. Orders have been observed live to take longer than 30 seconds (see [CHANGELOG](CHANGELOG.md)); if yours do, raise `timeoutMs` rather than retrying.

Reads *are* retried — with backoff and jitter, on connection failures and 5xx.

### 3. Synchronous and asynchronous orders are different calls

| | `orders->create()` | `orders->createSync()` |
|---|---|---|
| Sends | `Sync: false` | `Sync: true` |
| Quantity | no documented limit | max 25 |
| Returns | `orderUid` only | `orderUid` **and vouchers** |
| Delivery | Huuray sends via your template | you handle the codes |

"No documented limit" is precise, not a promise: the API specification states a cap only for synchronous orders. It says nothing about an asynchronous maximum, so this client imposes none — but a very large single order is untested territory. Ask your Huuray contact before relying on one.

They are separate methods because their return types differ. Reading `vouchers` on an asynchronous order is a mistake static analysis should catch, not a runtime surprise.

### 4. `206 Partial Content` is a real outcome

Cancel and resend can partly succeed. Checking only that the request "worked" will miss it:

```php
$result = $huuray->orders->cancel(orderUid: $orderUid);

if ($result->partial) {
    $failed = array_filter($result->vouchers, fn($v) => !$v->cancelled);
    $logger->warning(count($failed) . ' vouchers could not be cancelled');
}
```

### 5. Voucher codes are blank unless your account allows them

`$voucher->code`, `$voucher->cvv` and `$voucher->redeemLink` are returned only if **`ReturnCode` is enabled on your B2B account**. Otherwise they come back empty and Huuray delivers the codes for you. If you need codes returned to your own system, ask your Huuray contact to enable it.

This client never logs a code. `var_dump()` and `print_r()` — the usual way a value ends up in a log — show a voucher's code, CVV and redeem link as `[redacted: bearer value]`, and a recipient's email and phone masked; that holds for results that contain vouchers, too. Reading the properties yourself, or `json_encode()`, gives the real values: that is you deliberately reading your own data, not logging it. (`var_export()` and `serialize()` are not redacted either.) To log a result safely, redact it first:

```php
use Huuray\Redact;

$logger->info('order complete', Redact::redact($result));   // codes stripped
```

### 6. "Nothing found" can be a 404, not an empty list

The API can signal "nothing found" as HTTP 404: `orders->search()` with no match throws `NotFoundException` rather than returning an empty result. `POST /v4/Template` has been observed live both ways (see [CHANGELOG](CHANGELOG.md)): a 404 (*"There were no active templates"*) when the account had no templates, which `templates->list()` throws as `NotFoundException`, and a 200 with an empty `templates` array for an account with PDF templates but no email or SMS templates. Handle both:

```php
use Huuray\Exception\NotFoundException;

try {
    $templates = $huuray->templates->list()->templates;   // can be []
} catch (NotFoundException) {
    $templates = [];   // the 404 observed when the account had no templates
}
```

### 7. Authentication, and what a 401 usually means

Every request carries three headers, all built for you:

| Header | Value |
|---|---|
| `X-API-TOKEN` | your API token |
| `X-API-NONCE` | a random value, **single-use within 60 days, max 50 characters** |
| `X-API-HASH` | SHA-512 of ( API secret + nonce ) |

Nonces are 24 random bytes as base64url — 32 characters, comfortably under the limit. Avoid rolling your own: 32-byte hex is 64 characters and is silently rejected, and timestamps collide under concurrency.

**If you get a 401 with credentials you know are correct**, the digest encoding is the thing to try. The API specification states the construction but not the encoding, so this client defaults to lowercase hex — confirmed against the live API — and lets you change it:

```php
new HuurayClient(apiToken: $token, apiSecret: $secret, hashEncoding: 'base64');
// 'hex' (default) | 'hex-upper' | 'base64' | 'base64url', or the Huuray\HashEncoding enum
```

## API coverage

All nine v4 operations, and nothing else. Every method maps to one operation in the [Swagger reference](https://api.huuray.com/swagger/index.html):

| Method | Endpoint |
|---|---|
| `balances->list()` | `GET /v4/Balance` |
| `catalogue->list(all: ...)` | `POST /v4/Catalogue` |
| `templates->list()` | `POST /v4/Template` |
| `stock->check(productToken: ..., value: ...)` | `POST /v4/Stock` |
| `exchangeRates->get(from: ..., to: ...)` | `GET /v4/ExchangeRates` |
| `orders->create(...)` | `POST /v4/Order` (`Sync: false`) |
| `orders->createSync(...)` | `POST /v4/Order` (`Sync: true`) |
| `orders->sendReward(...)` | `POST /v4/Order`, one recipient |
| `orders->search(...)` | `POST /v4/Search` |
| `orders->resend(...)` | `POST /v4/Resend` |
| `orders->cancel(...)` | `DELETE /v4/Cancel` |

Orders also accept an optional `pdfTemplateUid`, from `templates->list()->pdfTemplates`, which attaches a PDF template to the emails the delivery template sends. It needs a `templateId`, and the client rejects it without one before sending anything. The PDF template must also be available for the ordered product's brand and country (`brandName` / `country` on the PDF template, where null means any); otherwise the API rejects the order with a 422, thrown as `ValidationException`. The client does not pre-check that.

Need something not covered? `request()` signs any call for you:

```php
$huuray->request('POST', '/v4/Search', ['RefID' => 'payroll-2026-08-jane']);
```

**This client targets API v4 only.** Field names match the Huuray API reference exactly, differing only in casing (`OrderUID` → `orderUid`), so anything you read in the API documentation maps straight across.

## Errors

Every exception this library throws extends `Huuray\Exception\HuurayException`. Input guards — a float amount, a quantity over the synchronous limit, a recipient count that is neither 1 nor `quantity` when `templateId` is set — throw the built-in `\InvalidArgumentException` instead, before anything is sent.

| Class | When |
|---|---|
| `ConfigurationException` | missing or invalid client options |
| `ConnectionException` | the request never reached the API, or its response was unreadable |
| `TimeoutException` | the request exceeded `timeoutMs` |
| `AuthException` | 401 or 403 — see *Authentication* above |
| `NotFoundException` | 404 — including "no results", see above |
| `ValidationException` | 422 |
| `ServerException` | 5xx |
| `ApiException` | any other non-2xx; the base for the four above |
| `IndeterminateOrderException` | an order whose outcome is unknown — **do not retry** |

API exceptions carry `httpStatus`, `status`, `statusMessage`, and the decoded `body`. The client reads `StatusMessage` and falls back to the deprecated `Message`. The retained `body` is redacted, so logging an exception never leaks a voucher code.

## Client options

```php
use Huuray\HuurayClient;
use Huuray\RetryOptions;

new HuurayClient(
    apiToken: '...',                          // required
    apiSecret: '...',                         // required
    baseUrl: 'https://api.huuray.com',        // default; must be an absolute http(s) URL
    hashEncoding: 'hex',                      // 'hex' | 'hex-upper' | 'base64' | 'base64url'
    timeoutMs: 30_000,                        // per request, 1 to 2147483647
    retry: new RetryOptions(maxRetries: 2, baseDelayMs: 250, maxDelayMs: 4000),
    transport: null,                          // a Huuray\Http\Transport; default CurlTransport
    userAgent: 'my-app/1.0',
    nonceFactory: null,                       // supply your own; unique, <= 50 visible ASCII characters
);
```

`transport` is how you route requests through your own HTTP stack. A custom transport **must enforce `$request->timeoutMs` itself** — the timeout is what turns a hung order into an `IndeterminateOrderException` you can reconcile — and must return every status as a response rather than throwing; the contract is spelled out on the `Huuray\Http\Transport` interface. The client does not accept a PSR-18 client directly, because PSR-18 has no way to set a timeout.

## CLI

Read-only by design. Ordering, resending and cancelling move real value and belong in reviewed code, not a shell one-liner. Voucher codes are never printed.

```bash
vendor/bin/huuray balance
vendor/bin/huuray catalogue --all
vendor/bin/huuray templates
vendor/bin/huuray stock --token <token> --value 5000
vendor/bin/huuray rates --from EUR --to DKK
vendor/bin/huuray search --ref-id payroll-2026-08-jane
vendor/bin/huuray --help
```

Add `--json` to any command for machine-readable output. Credentials come from `HUURAY_API_TOKEN` and `HUURAY_API_SECRET`.

## Examples

- [`examples/quickstart.php`](examples/quickstart.php) — read-only tour, safe to run
- [`examples/reconcile-after-timeout.php`](examples/reconcile-after-timeout.php) — recovering from an order whose outcome is unknown

## Further reading

- [Huuray API v4 reference (Swagger)](https://api.huuray.com/swagger/index.html) — the specification this client is checked against
- [Sign up for a Huuray B2B account](https://huuray.com/sign-up/) — if you do not have one yet
- [Contributing](.github/CONTRIBUTING.md) — including the note on **spec fidelity**: this client deliberately exposes nothing the API does not document, and that rule is enforced by tests
- [Changelog](CHANGELOG.md)

## Feedback

Found a bug, or something in this library that could be friendlier? Please [file an issue](https://github.com/Huuray-A-S/huuray-php/issues) or start a [discussion](https://github.com/Huuray-A-S/huuray-php/discussions). This repository does not accept external pull requests — see [CONTRIBUTING.md](.github/CONTRIBUTING.md) for why.

For the API itself, your account, or a live production problem, contact your Huuray representative — see [SUPPORT.md](.github/SUPPORT.md) for which channel to use. Never open a public issue for a security vulnerability; see [SECURITY.md](.github/SECURITY.md).

## Code of Conduct

Please note that this project is released with a [Contributor Code of Conduct](.github/CODE_OF_CONDUCT.md). By contributing to this project, you agree to abide by its terms.

<p align="center">
  <img src="https://raw.githubusercontent.com/Huuray-A-S/huuray-php/main/.github/assets/huuray-logo.svg" width="96" alt="Huuray"/><br/>
  <sub>Made with 💚 in Denmark by <a href="https://huuray.com">Huuray A/S</a> · <a href="LICENSE">MIT</a></sub>
</p>
