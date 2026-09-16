<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

/** One canned answer for {@see FakeTransport}. */
final readonly class MockResponse
{
    /**
     * @param int             $status HTTP status to answer with.
     * @param mixed           $json   Encoded as the body. Null means `{"Status": <status>}`. Use
     *                                `new \stdClass()` for an empty JSON object — `[]` encodes as a list.
     * @param string|null     $text   Raw body; takes precedence over `$json`. Use to simulate garbled responses.
     * @param \Throwable|null $throws Throw instead of answering: a connection failure before or
     *                                during the body, or a timeout.
     */
    public function __construct(
        public int $status = 200,
        public mixed $json = null,
        public ?string $text = null,
        public ?\Throwable $throws = null,
    ) {}

    public function body(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        return json_encode($this->json ?? ['Status' => $this->status], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
