<?php

declare(strict_types=1);

namespace Huuray\Http;

/**
 * Sends one HTTP request. The seam between the SDK and the network.
 *
 * The default is {@see CurlTransport}. Inject your own to route through a
 * framework HTTP stack, a proxy, or — in tests — a fake that never touches the
 * network.
 *
 * A custom transport MUST honour this contract, because the SDK's order-safety
 * guarantees rest on it:
 *
 * 1. **Enforce `$request->timeoutMs`** as the total time for the request,
 *    including connecting and reading the body. The timeout is what turns a hung
 *    order into an {@see \Huuray\Exception\IndeterminateOrderException} you can
 *    reconcile; a transport that can wait forever makes that unreachable. Orders
 *    have been observed live to take longer than 30 seconds, so do not cap it
 *    lower than the value you are given.
 * 2. **Return every HTTP response as an {@see HttpResponse}**, whatever its
 *    status — 4xx and 5xx included. Do not throw for a status code.
 * 3. **Read the whole body before returning.** A connection that drops while the
 *    body is still arriving must surface as an exception from `send()`, so it is
 *    mapped by the same error handling as a failure to connect.
 * 4. **Throw {@see TransportTimeoutException}** when the timeout fires, and any
 *    other exception — ideally {@see TransportException} — for every other
 *    failure. Never put a request or response body in an exception message.
 * 5. **Send `$request->body` exactly as given**: no body at all when it is null,
 *    even for POST, and the JSON body when it is set, even for DELETE.
 * 6. **Do not retry, and do not follow redirects to another host.** The SDK owns
 *    the retry decision, and it never repeats an order.
 */
interface Transport
{
    /**
     * @throws TransportTimeoutException when `$request->timeoutMs` elapses
     * @throws TransportException        for any other failure to complete the exchange
     */
    public function send(
        #[\SensitiveParameter]
        HttpRequest $request,
    ): HttpResponse;
}
