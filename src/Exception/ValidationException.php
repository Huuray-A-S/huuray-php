<?php

declare(strict_types=1);

namespace Huuray\Exception;

/**
 * 422 — the request was well-formed but rejected. Read `statusMessage`.
 *
 * This includes an order whose `pdfTemplateUid` is not available for the ordered
 * product's brand and country; this client does not pre-check that.
 */
class ValidationException extends ApiException {}
