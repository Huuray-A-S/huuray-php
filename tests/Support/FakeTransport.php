<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

use Huuray\Http\HttpRequest;
use Huuray\Http\HttpResponse;
use Huuray\Http\Transport;

/**
 * A transport that records requests and replays canned responses.
 *
 * No test in this suite touches the network: ordering gift cards from a test
 * runner would spend real money.
 *
 * Queue semantics: a list is strict — one response per request, and a request
 * beyond the end fails, so a test can never silently absorb an extra HTTP call
 * (an accidental order retry is exactly the bug class this suite exists to
 * catch). A single MockResponse repeats for every request.
 *
 * Each captured request also records which public SDK method it was made through
 * and with which arguments, read from the call stack. The conformance gates use
 * that to prove the harness really calls every method with every parameter.
 */
final class FakeTransport implements Transport
{
    /** @var list<CapturedRequest> */
    public array $calls = [];

    /** True once a strict queue was asked for more responses than it held. */
    public bool $exhausted = false;

    /** @var list<MockResponse> */
    private array $queue;

    private readonly bool $strict;

    /**
     * @param MockResponse|list<MockResponse>|null $responses
     */
    public function __construct(MockResponse|array|null $responses = null)
    {
        if (is_array($responses)) {
            $this->strict = true;
            $this->queue = array_values($responses);
        } else {
            $this->strict = false;
            $this->queue = [$responses ?? new MockResponse()];
        }
    }

    public function send(
        #[\SensitiveParameter]
        HttpRequest $request,
    ): HttpResponse {
        [$sdkMethod, $sdkArguments] = self::sdkEntryPoint();
        $this->calls[] = CapturedRequest::from($request, $sdkMethod, $sdkArguments);

        if ($this->strict) {
            $next = array_shift($this->queue);
            if ($next === null) {
                $this->exhausted = true;

                throw new \LogicException(sprintf(
                    'FakeTransport: request #%d (%s %s) exceeds the queued responses — the code under test '
                    . 'made more HTTP calls than the test expected.',
                    count($this->calls),
                    $request->method,
                    $request->url,
                ));
            }
        } else {
            $next = $this->queue[0];
        }

        if ($next->throws !== null) {
            throw $next->throws;
        }

        return new HttpResponse($next->status, $next->body());
    }

    /**
     * The outermost public SDK method on the call stack, as `ShortClassName::method`,
     * with the arguments it received — `#[\SensitiveParameter]` values unwrapped, so a
     * null stays null. Frames list innermost first, so the last match wins.
     *
     * @return array{?string, list<mixed>}
     */
    private static function sdkEntryPoint(): array
    {
        $method = null;
        $arguments = [];

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            $class = $frame['class'] ?? null;
            if (
                $class === null
                || !str_starts_with($class, 'Huuray\\')
                || str_starts_with($class, 'Huuray\\Tests\\')
                || !method_exists($class, $frame['function'])
                || str_starts_with($frame['function'], '__')
                || !(new \ReflectionMethod($class, $frame['function']))->isPublic()
            ) {
                continue;
            }

            // The runtime class, so a public method inherited from a base class is
            // attributed to the resource it was called on.
            $runtimeClass = isset($frame['object']) ? $frame['object']::class : $class;
            $method = (new \ReflectionClass($runtimeClass))->getShortName() . '::' . $frame['function'];
            $arguments = array_map(
                static fn(mixed $argument): mixed => $argument instanceof \SensitiveParameterValue ? $argument->getValue() : $argument,
                $frame['args'] ?? [],
            );
        }

        return [$method, $arguments];
    }
}
