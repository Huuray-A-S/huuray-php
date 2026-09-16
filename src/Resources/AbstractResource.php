<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\HuurayClient;

/**
 * Shared base for the typed resources.
 *
 * Every resource method maps 1:1 onto a single documented v4 operation. A method
 * with no corresponding path and verb in `openapi/huuray-v4.json` fails the
 * no-invention gate in `tests/ConformanceTest.php`, and a new public method that
 * is not added to that test's harness fails its inventory check.
 */
abstract class AbstractResource
{
    public function __construct(
        protected readonly HuurayClient $client,
    ) {}
}
