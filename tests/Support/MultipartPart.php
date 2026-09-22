<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

/**
 * One part of a multipart/form-data body, as {@see self::parseAll()} read it.
 *
 * The parser is strict on purpose: it reads the bodies this SDK builds, so
 * anything it cannot account for — no boundary, a preamble, a part without a
 * form-data Content-Disposition, bytes after the closing delimiter — is an
 * error rather than something skipped.
 */
final readonly class MultipartPart
{
    /**
     * @param array<string, string> $headers Part headers, lower-cased name => value.
     */
    public function __construct(
        public ?string $name,
        public ?string $filename,
        public array $headers,
        /** The part's bytes exactly as sent. */
        public string $content,
    ) {}

    public function contentType(): ?string
    {
        return $this->headers['content-type'] ?? null;
    }

    /**
     * @param string $contentType The request's Content-Type header, which carries the boundary.
     *
     * @return list<self>
     *
     * @throws \UnexpectedValueException for a body this parser cannot account for
     */
    public static function parseAll(string $contentType, string $body): array
    {
        if (preg_match('/;\s*boundary=("?)([^"\s;]+)\1(?:\s*;|\s*$)/iD', $contentType, $match) !== 1) {
            throw new \UnexpectedValueException('the Content-Type carries no boundary');
        }
        $delimiter = '--' . $match[2];
        $close = "\r\n" . $delimiter . '--';

        if (!str_starts_with($body, $delimiter . "\r\n")) {
            throw new \UnexpectedValueException('the body does not start with the boundary delimiter');
        }
        $end = strrpos($body, $close);
        if ($end === false) {
            throw new \UnexpectedValueException('the body has no closing delimiter');
        }
        if ($end < strlen($delimiter) + 2) {
            throw new \UnexpectedValueException('the body has no parts');
        }
        $epilogue = substr($body, $end + strlen($close));
        if ($epilogue !== '' && $epilogue !== "\r\n") {
            throw new \UnexpectedValueException('the body has bytes after the closing delimiter');
        }

        $inner = substr($body, strlen($delimiter) + 2, $end - strlen($delimiter) - 2);
        $parts = [];
        foreach (explode("\r\n" . $delimiter . "\r\n", $inner) as $raw) {
            $split = strpos($raw, "\r\n\r\n");
            if ($split === false) {
                throw new \UnexpectedValueException('a part has no blank line after its headers');
            }

            $headers = [];
            foreach (explode("\r\n", substr($raw, 0, $split)) as $line) {
                $colon = strpos($line, ':');
                if ($colon === false || $colon === 0) {
                    throw new \UnexpectedValueException('a part header line is not "Name: value"');
                }
                $name = strtolower(substr($line, 0, $colon));
                if (array_key_exists($name, $headers)) {
                    throw new \UnexpectedValueException('a part repeats the ' . $name . ' header');
                }
                $headers[$name] = trim(substr($line, $colon + 1), ' ');
            }

            [$name, $filename] = self::disposition($headers['content-disposition'] ?? null);
            $parts[] = new self($name, $filename, $headers, substr($raw, $split + 4));
        }

        return $parts;
    }

    /**
     * The `name` and `filename` of a `form-data` Content-Disposition. Only quoted
     * parameter values are read; the SDK percent-encodes a quote in a file name.
     *
     * @return array{?string, ?string}
     */
    private static function disposition(?string $value): array
    {
        if ($value === null) {
            throw new \UnexpectedValueException('a part has no Content-Disposition');
        }
        if (preg_match('/^form-data((?:\s*;\s*[A-Za-z0-9*-]+="[^"]*")*)\s*$/D', $value, $match) !== 1) {
            throw new \UnexpectedValueException('a Content-Disposition is not form-data with quoted parameters');
        }

        preg_match_all('/;\s*([A-Za-z0-9*-]+)="([^"]*)"/', $match[1], $found, PREG_SET_ORDER);
        $parameters = [];
        foreach ($found as $parameter) {
            $key = strtolower($parameter[1]);
            if (!in_array($key, ['name', 'filename'], true) || array_key_exists($key, $parameters)) {
                throw new \UnexpectedValueException('a Content-Disposition has an unexpected or repeated ' . $key . ' parameter');
            }
            $parameters[$key] = $parameter[2];
        }

        return [$parameters['name'] ?? null, $parameters['filename'] ?? null];
    }
}
