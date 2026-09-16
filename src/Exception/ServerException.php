<?php

declare(strict_types=1);

namespace Huuray\Exception;

/** 5xx — a server-side failure. Retried automatically only for reads. */
class ServerException extends ApiException {}
