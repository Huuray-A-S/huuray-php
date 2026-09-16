<?php

declare(strict_types=1);

namespace Huuray\Exception;

/**
 * Base class for everything this library throws. Catch this to catch it all.
 *
 * Input guards — a float amount, a quantity over the synchronous limit, a
 * recipient count that is neither 1 nor `quantity` when `templateId` is set —
 * throw the built-in `\InvalidArgumentException` instead, before any request is
 * sent. They are programming mistakes, not API responses.
 */
class HuurayException extends \RuntimeException {}
