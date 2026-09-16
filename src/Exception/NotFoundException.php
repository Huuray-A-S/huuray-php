<?php

declare(strict_types=1);

namespace Huuray\Exception;

/**
 * 404 — the order, voucher, or product was not found.
 *
 * Also how the API can signal an **empty result set**: `POST /v4/Search` with no
 * match answers 404. `POST /v4/Template` has been observed live to answer 404
 * ("There were no active templates") when the account had no templates, and 200
 * with an empty `templates` list for an account with PDF templates but no email
 * or SMS templates — handle both. From `orders->search()` in a reconciliation
 * flow, read it as "the order did not land".
 */
class NotFoundException extends ApiException {}
