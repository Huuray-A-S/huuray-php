<?php

declare(strict_types=1);

namespace Huuray\Exception;

/** The client is misconfigured — missing credentials, a bad base URL. Not a server response. */
class ConfigurationException extends HuurayException {}
