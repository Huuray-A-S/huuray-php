# Contributing

Thanks for taking the time.

**This repository does not accept external pull requests.** It is a published client library that must stay in exact step with the Huuray API specification, and it moves real money, so changes come from Huuray. Please do not spend your time on a patch we cannot merge.

**Questions, bug reports and suggestions are very welcome** — open a [discussion](https://github.com/Huuray-A-S/huuray-php/discussions) to ask something, or an [issue](https://github.com/Huuray-A-S/huuray-php/issues) to report a bug. We read every one.

The rest of this file documents how the library is built and the rules it is held to, so you can read the code with confidence and describe a problem precisely.

Everyone taking part is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Getting set up

```bash
git clone https://github.com/Huuray-A-S/huuray-php.git
cd huuray-php
composer install
composer test
```

No test touches the network.

| Command | What it does |
|---|---|
| `composer test` | run every test, including the three spec-fidelity gates |
| `composer analyse` | PHPStan at level max over `src`, `tests`, `examples`, `scripts` and `bin` |
| `composer cs-check` | check coding style (PER-CS) without changing anything |
| `composer cs-fix` | fix coding style |
| `composer spec-fetch` | re-download the live spec over `openapi/huuray-v4.json` |

## Spec fidelity — read this before adding anything

**This client exposes nothing the API does not document.** It is the rule the whole library is built on, and it is enforced by tests rather than by review.

In practice:

1. **Never send a field the spec does not define.** Not "just in case", not because another endpoint accepts it.
2. **Never call a path or verb the spec does not define.**
3. **Never depend on undocumented behaviour** — an undocumented status code, an undocumented header, an undocumented error shape. If the specification is silent, we confirm with Huuray before implementing. An unanswered question blocks the feature; it does not get a best guess.
4. **Field names mirror the spec, differing only in casing.** `OrderUID` becomes `orderUid`. It does not become `orderId`, `uid`, or anything more tasteful. Someone reading the Huuray API reference must be able to map it across without a translation table.
5. **Convenience methods are allowed, but only as a documented composition of real operations.** `sendReward()` is fine: it is exactly one `POST /v4/Order`, and its documentation says so.

Three gates in [`tests/ConformanceTest.php`](../tests/ConformanceTest.php) enforce this:

- **no-invention** — every request the SDK can emit maps to a path and verb in the spec, and sends no undefined property
- **coverage** — every operation in the spec has a method
- **request-conformance** — every request body validates against the spec schema

They work by calling every public method with every optional parameter populated, then checking what came out. **If you add a method, add it to `exerciseEverything()` and to the `INVENTORY` constant** — a reflection-based inventory test fails otherwise, which is the point: a new method must never bypass the gates.

The validator deliberately **fails closed**. A schema shape it does not understand — `allOf`/`oneOf`/`anyOf`, a missing `type`, an array without `items` — is an error, not a silent pass. Extend `validate()` rather than loosening it.

## The vendored specification

`openapi/huuray-v4.json` is committed on purpose, byte-identical to the copy in the other Huuray clients. A scheduled workflow re-downloads it weekly and flags any change: it opens a pull request when a `SPEC_DRIFT_TOKEN` secret is configured, and otherwise the run fails. That is how we find out about API changes. Review every one of those changes — do not merge on green alone.

## Tests

- **No live API calls, ever.** Ordering gift cards from a test runner spends real money. Inject a fake via the client's `transport` argument; `tests/Support/FakeTransport.php` has one ready.
- **Fixtures contain invented data only.** Never record a real response.
- New behaviour needs a test that fails without your change.

## Money and value — extra care

Some of this library moves real money. Changes in these areas get closer review:

- **Never add automatic retries to `/v4/Order`, `/v4/Resend` or `/v4/Cancel`.** There is no idempotency key. A retried order orders twice; a retried resend re-delivers a live gift card. Retries are opt-in per operation and must never be inferred from the HTTP method — four read-only v4 endpoints are POSTs.
- **Never let a transport failure escape the error taxonomy.** The body is read inside the same error handling as the request, or a mid-body drop bypasses `IndeterminateOrderException` entirely.
- **Never send a request without a timeout.** The timeout is what makes an order that hangs reach `IndeterminateOrderException`; the default transport enforces it, and a custom transport must too.
- **Never coerce an unreadable 2xx into an empty result.** A garbled `/v4/Search` response reading as "no order found" would make the documented reconciliation flow re-order.
- **Never widen the CLI to move value.** It is read-only on purpose.
- **Never log a voucher code**, at any level, in any code path. New fields carrying value or personal data go into `SECRET_FIELDS` or `SENSITIVE_FIELDS` in `src/Redact.php`, and into the `__debugInfo()` of the result that carries them, with a test.
- **Keep amounts as integers in minor units.** Amount (`value`) and `quantity` parameters are declared natively as `mixed` with a PHPDoc type of `int`, and `Wire::requireMinorUnits()` / `Wire::requirePositiveInt()` reject anything but an int. A native `int` or `int|float` would let PHP coerce `50.00`, `1.5` or `true` in a caller's file without strict types before the SDK sees it. `tests/OrdersTest.php` asserts this from a caller without strict types; keep it passing. No silent rounding.

## Why there is no pull request workflow

Two reasons, both structural rather than a matter of taste:

1. **Spec fidelity.** Three CI gates assert the client sends nothing the API does not document. A change that looks like an improvement usually needs an API change first, which is a conversation with Huuray, not a patch here.
2. **It moves money.** The guards described above — never retrying an order, minor units as integers, never logging a voucher code — exist because getting them wrong costs real money. They are not open to drive-by modification.

If you have found a bug or need behaviour the client does not offer, please open an issue or a discussion and describe the case. That is genuinely the fastest route to a fix.

## Releases

There is no release workflow: Packagist publishes a new version from a git tag through its GitHub integration, so no token is involved.

There is no CodeQL workflow either: CodeQL does not support PHP. PHPStan at level max runs in CI on every pull request and every push to `main` instead.

## Reporting a bug

[Open an issue.](https://github.com/Huuray-A-S/huuray-php/issues) Include the SDK version, your PHP version, what you called, what you expected, and what happened.

**Never paste an API token, an API secret, or a voucher code into an issue.** For a vulnerability, see [SECURITY.md](SECURITY.md) instead.

## What belongs somewhere else

Questions about the API itself, your account, pricing, or a live production problem go to your Huuray representative rather than here — see [SUPPORT.md](SUPPORT.md). We cannot resolve those from a GitHub issue.
