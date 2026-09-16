<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\HuurayClient;
use Huuray\Recipient;
use Huuray\Resources\AbstractResource;
use Huuray\Tests\Support\CapturedRequest;
use Huuray\Tests\Support\MockResponse;
use Huuray\Tests\Support\SpecValidator;
use Huuray\Tests\Support\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * Spec-fidelity gates.
 *
 * The SDK's central promise is that it invents nothing: it calls only documented
 * operations and sends only documented fields. That promise has to be mechanical,
 * not a matter of discipline, or it quietly decays.
 *
 *   no-invention          every request the SDK makes exists in the specification
 *   coverage              every operation in the specification has an SDK method
 *   request-conformance   every request body validates against the spec schema,
 *                         including no unknown properties
 *
 * The specification is read from openapi/huuray-v4.json at test time.
 */
final class ConformanceTest extends TestCase
{
    private const ORDER_PARAMETERS = [
        'productToken', 'value', 'currency', 'quantity', 'expires', 'refId', 'templateId',
        'pdfTemplateUid', 'deliveryDatetime', 'personalMessage', 'recipients',
    ];

    private const SEND_REWARD_PARAMETERS = [
        'productToken', 'value', 'currency', 'recipient', 'templateId', 'refId',
        'pdfTemplateUid', 'expires', 'deliveryDatetime', 'personalMessage',
    ];

    /**
     * Every public method that sends a request — each resource method, and the
     * client's own `sendReward` — with its parameter names in declaration order.
     *
     * The gates only inspect requests exerciseEverything() happens to make, and the
     * request builders drop nulls, so a method or an optional parameter the harness
     * never uses is invisible to them. This inventory closes both holes mechanically:
     *
     *   - it must equal the reflected public surface, parameter names included, so a
     *     new method or parameter fails until it is listed here;
     *   - the set of methods exerciseEverything() actually sent requests through
     *     must equal it, so a listed method cannot go uncalled;
     *   - every listed parameter must be non-null in at least one exercised call, so
     *     a listed parameter cannot go unused.
     */
    private const INVENTORY = [
        'BalancesResource' => ['list' => []],
        'CatalogueResource' => ['list' => ['all']],
        'TemplatesResource' => ['list' => []],
        'StockResource' => ['check' => ['productToken', 'value']],
        'ExchangeRatesResource' => ['get' => ['from', 'to']],
        'OrdersResource' => [
            'cancel' => ['orderUid', 'voucherId'],
            'create' => self::ORDER_PARAMETERS,
            'createSync' => self::ORDER_PARAMETERS,
            'resend' => ['orderUid', 'voucherId'],
            'search' => [
                'orderUid', 'voucherId', 'productToken', 'refId', 'smsTemplateId', 'emailTemplateId',
                'deliveryDatetime', 'recipientName', 'recipientEmail', 'recipientPhone', 'recipientRefId',
            ],
            'sendReward' => self::SEND_REWARD_PARAMETERS,
        ],
        'HuurayClient' => ['sendReward' => self::SEND_REWARD_PARAMETERS],
    ];

    /**
     * The client's unopinionated escape hatches, which send exactly what the caller
     * gives them. Pinned with their parameters, but not part of the exercised
     * inventory: there is no fixed request for the harness to validate.
     */
    private const ESCAPE_HATCHES = [
        'request' => ['method', 'path', 'body', 'query', 'retryable'],
        'send' => ['method', 'path', 'body', 'query', 'retryable'],
    ];

    /** @var list<CapturedRequest>|null */
    private static ?array $calls = null;

    private static ?SpecValidator $spec = null;

    // ------------------------------------------------------------ no-invention

    public function testEveryRequestTheSdkMakesIsADocumentedV4Operation(): void
    {
        $documented = self::spec()->operations();
        $undocumented = [];
        foreach (self::calls() as $call) {
            $key = strtoupper($call->method) . ' ' . $call->path;
            if (!in_array($key, $documented, true)) {
                $undocumented[] = $key;
            }
        }

        self::assertSame([], array_values(array_unique($undocumented)));
    }

    public function testEveryQueryParameterTheSdkSendsIsDeclaredInTheSpec(): void
    {
        $sawQuery = false;
        foreach (self::calls() as $call) {
            $declared = self::spec()->queryParameters($call->method, $call->path);
            foreach (array_keys($call->query) as $name) {
                $sawQuery = true;
                self::assertContains($name, $declared, sprintf('%s %s sent undeclared query param "%s"', $call->method, $call->path, $name));
            }
        }

        self::assertTrue($sawQuery, 'exerciseEverything() must make at least one call with a query string.');
    }

    public function testTheSdkReachesNoHostButTheDocumentedOne(): void
    {
        $origins = array_values(array_unique(array_map(static fn(CapturedRequest $call): string => $call->origin, self::calls())));

        self::assertSame(['https://api.huuray.com'], $origins);
    }

    // --------------------------------------------------------------- coverage

    public function testEveryDocumentedV4OperationHasAnSdkMethod(): void
    {
        $exercised = array_map(static fn(CapturedRequest $call): string => strtoupper($call->method) . ' ' . $call->path, self::calls());
        $missing = array_values(array_diff(self::spec()->operations(), $exercised));

        self::assertSame([], $missing);
    }

    public function testCoversExactlyTheNineV4OperationsNoMoreNoFewer(): void
    {
        self::assertCount(9, self::spec()->operations());
    }

    public function testTheSpecIsStillV4ThisClientTargetsV4Only(): void
    {
        $info = self::spec()->spec['info'] ?? null;
        if (!is_array($info)) {
            self::fail('The vendored spec has no info object.');
        }

        self::assertSame('v4', $info['version'] ?? null);
    }

    // ---------------------------------------------------- request-conformance

    public function testEveryRequestBodyValidatesAgainstItsSpecSchema(): void
    {
        $failures = [];

        foreach (self::calls() as $call) {
            $schema = self::spec()->requestSchema($call->method, $call->path);

            if ($schema === null) {
                // The spec declares no body for this operation, so the SDK must send none.
                if (!$call->bodyOmitted) {
                    $failures[] = sprintf('%s %s: spec declares no requestBody, but the SDK sent one', $call->method, $call->path);
                }
                continue;
            }

            array_push($failures, ...self::spec()->validate($schema, $call->bodyAsObjects(), $call->method . ' ' . $call->path));
        }

        self::assertSame([], $failures);
    }

    public function testSeesDeliveryPdfTemplateUidOnEveryOrderCreateCreateSyncAndSendRewardMake(): void
    {
        // exerciseEverything() must populate pdfTemplateUid, or the gate above never
        // validates the field against the spec.
        $orders = array_values(array_filter(
            self::calls(),
            static fn(CapturedRequest $call): bool => $call->method === 'POST' && $call->path === '/v4/Order',
        ));

        // create, createSync, orders->sendReward and client->sendReward.
        self::assertCount(4, $orders);
        foreach ($orders as $call) {
            self::assertIsString($call->field('DeliveryPDFTemplateUid'));
        }
        $properties = self::spec()->schema('OrderRequest')['properties'] ?? null;
        if (!is_array($properties)) {
            self::fail('OrderRequest declares no properties.');
        }
        self::assertArrayHasKey('DeliveryPDFTemplateUid', $properties);
    }

    public function testSendsNoBodyToPostV4TemplateWhichDeclaresNone(): void
    {
        $templateCalls = array_values(array_filter(self::calls(), static fn(CapturedRequest $call): bool => $call->path === '/v4/Template'));

        self::assertCount(1, $templateCalls);
        self::assertTrue($templateCalls[0]->bodyOmitted);
    }

    public function testSendsAJsonBodyOnDeleteV4Cancel(): void
    {
        $cancelCalls = array_values(array_filter(self::calls(), static fn(CapturedRequest $call): bool => $call->path === '/v4/Cancel'));

        self::assertCount(1, $cancelCalls);
        self::assertSame('DELETE', $cancelCalls[0]->method);
        self::assertFalse($cancelCalls[0]->bodyOmitted);
    }

    // ----------------------------------------- the harness stays linked to the surface

    public function testEveryPublicResourceMethodAndItsParametersAreOnTheInventory(): void
    {
        [$client] = TestClient::make();
        $resources = [
            $client->balances,
            $client->catalogue,
            $client->templates,
            $client->stock,
            $client->exchangeRates,
            $client->orders,
        ];

        $actual = [];
        foreach ($resources as $resource) {
            self::assertInstanceOf(AbstractResource::class, $resource);
            $reflection = new \ReflectionClass($resource);
            $actual[$reflection->getShortName()] = self::publicMethods($reflection);
        }

        $expected = self::INVENTORY;
        unset($expected['HuurayClient']);

        self::assertSame($expected, $actual);
    }

    public function testEveryResourceOnTheClientIsInTheInventory(): void
    {
        [$client] = TestClient::make();

        $properties = [];
        foreach ((new \ReflectionClass($client))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($client);
            if ($value instanceof AbstractResource) {
                $properties[] = (new \ReflectionClass($value))->getShortName();
            }
        }
        sort($properties);

        $expected = array_values(array_diff(array_keys(self::INVENTORY), ['HuurayClient']));
        sort($expected);

        self::assertSame($expected, $properties);
    }

    public function testTheClientsOwnPublicMethodsAndTheirParametersArePinned(): void
    {
        $expected = self::ESCAPE_HATCHES + self::INVENTORY['HuurayClient'];
        ksort($expected);

        self::assertSame($expected, self::publicMethods(new \ReflectionClass(HuurayClient::class)));
    }

    public function testTheHarnessSendsRequestsThroughExactlyTheInventoriedMethods(): void
    {
        // A method listed but never called would otherwise pass every gate, however
        // undocumented the request it makes.
        $expected = [];
        foreach (self::INVENTORY as $class => $methods) {
            foreach (array_keys($methods) as $method) {
                $expected[] = $class . '::' . $method;
            }
        }
        sort($expected);

        $actual = array_values(array_unique(array_map(
            static fn(CapturedRequest $call): string => $call->sdkMethod ?? '(no public SDK method on the stack)',
            self::calls(),
        )));
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testTheHarnessPopulatesEveryParameterOfEveryInventoriedMethod(): void
    {
        // Request builders drop nulls, so a parameter the harness never sets sends
        // nothing — an invented wire key behind it would pass every other gate.
        $unexercised = [];
        foreach (self::INVENTORY as $class => $methods) {
            foreach ($methods as $method => $parameters) {
                foreach ($parameters as $position => $parameter) {
                    $fqcn = $class === 'HuurayClient' ? HuurayClient::class : 'Huuray\\Resources\\' . $class;
                    $reflected = (new \ReflectionMethod($fqcn, $method))->getParameters()[$position] ?? null;
                    $default = $reflected !== null && $reflected->isDefaultValueAvailable() ? [$reflected->getDefaultValue()] : [];
                    if (!self::someCallPopulated($class . '::' . $method, $position, $default)) {
                        $unexercised[] = sprintf('%s::%s($%s)', $class, $method, $parameter);
                    }
                }
            }
        }

        self::assertSame([], $unexercised);
    }

    public function testTheCallAttributionSeesThroughDelegation(): void
    {
        // client->sendReward -> orders->sendReward -> orders->create -> client->send:
        // one request, attributed to the method the caller entered through.
        $rewards = array_values(array_filter(
            self::calls(),
            static fn(CapturedRequest $call): bool => $call->sdkMethod === 'HuurayClient::sendReward',
        ));

        self::assertCount(1, $rewards);
        self::assertSame('/v4/Order', $rewards[0]->path);
        self::assertCount(count(self::SEND_REWARD_PARAMETERS), $rewards[0]->sdkArguments);
        self::assertInstanceOf(Recipient::class, $rewards[0]->sdkArguments[3], 'A #[\SensitiveParameter] argument is unwrapped.');
    }

    // ---------------------------------------------------- the gates themselves work

    public function testFlagsAnUndocumentedProperty(): void
    {
        $errors = self::spec()->validate(self::spec()->schema('CancelRequest'), (object) ['OrderUID' => 'x', 'Invented' => true]);

        self::assertMatchesRegularExpression('/Invented.*not defined in the spec/', implode("\n", $errors));
    }

    public function testFlagsAMissingRequiredProperty(): void
    {
        $errors = self::spec()->validate(self::spec()->schema('CancelRequest'), new \stdClass());

        self::assertMatchesRegularExpression('/OrderUID.*required/', implode("\n", $errors));
    }

    public function testFlagsAWrongTypeOnDeliveryPdfTemplateUid(): void
    {
        $errors = self::spec()->validate(self::spec()->schema('OrderRequest'), (object) [
            'Product' => (object) ['Token' => 'tok', 'Value' => 5000, 'Currency' => 'DKK', 'Quantity' => 1],
            'Sync' => false,
            'DeliveryPDFTemplateUid' => 123,
        ]);

        self::assertMatchesRegularExpression('/DeliveryPDFTemplateUid.*expected string/', implode("\n", $errors));
    }

    public function testFlagsAnUndocumentedPropertyNestedInsideARefObjectProperty(): void
    {
        [$product, $recipient] = self::validOrderParts();
        $baseline = self::validateOrderRequest($product, $recipient);

        $product->Invented = true;
        $errors = self::validateOrderRequest($product, $recipient);

        self::assertSame([], $baseline);
        self::assertCount(1, $errors);
        self::assertMatchesRegularExpression('/^\$\.Product\.Invented: not defined in the spec/', implode("\n", $errors));
    }

    public function testFlagsAnUndocumentedPropertyInsideArrayItems(): void
    {
        [$product, $recipient] = self::validOrderParts();
        $baseline = self::validateOrderRequest($product, $recipient);

        $recipient->Invented = true;
        $errors = self::validateOrderRequest($product, $recipient);

        self::assertSame([], $baseline);
        self::assertCount(1, $errors);
        self::assertMatchesRegularExpression('/^\$\.Recipients\[0\]\.Invented: not defined in the spec/', implode("\n", $errors));
    }

    public function testFlagsAMisspeltRecipientRefIdInsideArrayItems(): void
    {
        [$product, $recipient] = self::validOrderParts();
        unset($recipient->RefID);
        $recipient->RefId = 'r-a';

        $errors = self::validateOrderRequest($product, $recipient);

        self::assertCount(1, $errors);
        self::assertMatchesRegularExpression('/^\$\.Recipients\[0\]\.RefId: not defined in the spec/', implode("\n", $errors));
    }

    public function testFlagsAWrongTypeNestedInsideARefObjectProperty(): void
    {
        [$product, $recipient] = self::validOrderParts();
        $product->Value = '5000';

        $errors = self::validateOrderRequest($product, $recipient);

        self::assertCount(1, $errors);
        self::assertMatchesRegularExpression('/^\$\.Product\.Value: expected integer, got \'5000\'/', implode("\n", $errors));
    }

    public function testFlagsAWrongTypeInsideArrayItems(): void
    {
        [$product, $recipient] = self::validOrderParts();
        $recipient->Email = 123;

        $errors = self::validateOrderRequest($product, $recipient);

        self::assertCount(1, $errors);
        self::assertMatchesRegularExpression('/^\$\.Recipients\[0\]\.Email: expected string/', implode("\n", $errors));
    }

    public function testFlagsAWrongType(): void
    {
        $errors = self::spec()->validate(self::spec()->schema('StockRequest'), (object) ['ProductToken' => 'x', 'Value' => 1.5]);

        self::assertMatchesRegularExpression('/Value.*expected integer/', implode("\n", $errors));
    }

    public function testFlagsABoolWhereAnIntegerIsExpected(): void
    {
        $errors = self::spec()->validate(self::spec()->schema('StockRequest'), (object) ['ProductToken' => 'x', 'Value' => true]);

        self::assertMatchesRegularExpression('/expected integer/', implode("\n", $errors));
    }

    public function testFlagsAListWhereAnObjectIsExpected(): void
    {
        $errors = self::spec()->validate(self::spec()->schema('SearchRequest'), []);

        self::assertMatchesRegularExpression('/expected object/', implode("\n", $errors));
    }

    public function testFailsClosedOnAComposedSchemaItDoesNotUnderstand(): void
    {
        foreach (['allOf', 'oneOf', 'anyOf'] as $composition) {
            $errors = self::spec()->validate([$composition => [['type' => 'object']]], (object) ['anything' => 1]);

            self::assertMatchesRegularExpression('/allOf\/oneOf\/anyOf/', implode("\n", $errors), $composition);
        }
    }

    public function testFailsClosedOnASchemaWithNoType(): void
    {
        $errors = self::spec()->validate(['description' => 'no type here'], 'anything');

        self::assertMatchesRegularExpression('/no "type"/', implode("\n", $errors));
    }

    public function testFailsClosedOnAnArraySchemaWithNoItems(): void
    {
        $errors = self::spec()->validate(['type' => 'array'], [1, 2]);

        self::assertMatchesRegularExpression("/no 'items'/", implode("\n", $errors));
    }

    public function testFailsClosedOnAnUnknownType(): void
    {
        $errors = self::spec()->validate(['type' => 'uuid'], 'x');

        self::assertMatchesRegularExpression('/unknown type/', implode("\n", $errors));
    }

    // ------------------------------------------------------------------- harness

    /**
     * Calls every public SDK method once, with every optional parameter populated,
     * so the gates see the widest request each method can produce.
     */
    private static function exerciseEverything(HuurayClient $client): void
    {
        $expires = new \DateTimeImmutable('2027-01-01T00:00:00Z');
        $deliverAt = new \DateTimeImmutable('2026-09-01T09:00:00Z');

        $client->balances->list();
        $client->catalogue->list(all: true);
        $client->templates->list();
        $client->stock->check(productToken: 'tok', value: 5000);
        $client->exchangeRates->get(from: 'DKK', to: 'EUR');

        $client->orders->create(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            quantity: 2,
            expires: $expires,
            refId: 'ref-1',
            templateId: 42,
            pdfTemplateUid: '00000000-0000-4000-8000-00000000c001',
            deliveryDatetime: $deliverAt,
            personalMessage: 'Thank you',
            recipients: [
                new Recipient(name: 'A', email: 'a@example.com', refId: 'r-a'),
                new Recipient(name: 'B', phone: '+4512345678', refId: 'r-b'),
            ],
        );

        $client->orders->createSync(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            quantity: 1,
            expires: $expires,
            refId: 'ref-sync',
            templateId: 42,
            pdfTemplateUid: '00000000-0000-4000-8000-00000000c002',
            deliveryDatetime: $deliverAt,
            personalMessage: 'Thanks',
            recipients: [new Recipient(name: 'C', email: 'c@example.com', phone: '+4500000001', refId: 'r-c')],
        );

        $client->orders->sendReward(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            recipient: new Recipient(name: 'Jane', email: 'jane@example.com', phone: '+4511223344', refId: 'r-jane'),
            templateId: 42,
            refId: 'ref-2',
            pdfTemplateUid: '00000000-0000-4000-8000-00000000c003',
            expires: '2027-01-01T00:00:00Z',
            deliveryDatetime: '2026-09-01T09:00:00Z',
            personalMessage: 'Nice work',
        );

        // The one-call convenience on the client itself, with every optional argument.
        $client->sendReward(
            productToken: 'tok',
            value: 2500,
            currency: 'DKK',
            recipient: new Recipient(name: 'Ole', email: 'ole@example.com', phone: '+4500000002', refId: 'r-ole'),
            templateId: 43,
            refId: 'ref-client',
            pdfTemplateUid: '00000000-0000-4000-8000-00000000c004',
            expires: $expires,
            deliveryDatetime: $deliverAt,
            personalMessage: 'Well done',
        );

        $client->orders->search(
            orderUid: 'uid',
            voucherId: 7,
            productToken: 'tok',
            refId: 'ref-1',
            smsTemplateId: 1,
            emailTemplateId: 2,
            deliveryDatetime: $deliverAt,
            recipientName: 'Jane',
            recipientEmail: 'jane@example.com',
            recipientPhone: '+4512345678',
            recipientRefId: 'r-a',
        );

        $client->orders->resend(orderUid: 'uid', voucherId: 7);
        $client->orders->cancel(orderUid: 'uid', voucherId: 7);
    }

    /** @return list<CapturedRequest> */
    private static function calls(): array
    {
        if (self::$calls === null) {
            [$client, $transport] = TestClient::make(new MockResponse(json: new \stdClass()));
            self::exerciseEverything($client);
            self::$calls = $transport->calls;
        }

        return self::$calls;
    }

    private static function spec(): SpecValidator
    {
        return self::$spec ??= new SpecValidator();
    }

    /** Whether some request made through `$sdkMethod` received a non-null argument at `$position`. */
    /**
     * A skipped parameter still shows up in the backtrace, filled in with its default —
     * so an argument equal to the default does not count as populated.
     *
     * @param array{0?: mixed} $default The parameter's default, or empty when it has none.
     */
    private static function someCallPopulated(string $sdkMethod, int $position, array $default = []): bool
    {
        foreach (self::calls() as $call) {
            if ($call->sdkMethod !== $sdkMethod) {
                continue;
            }
            $argument = $call->sdkArguments[$position] ?? null;
            if ($argument !== null && !($default !== [] && $argument === $default[0])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The product and the one recipient of an OrderRequest that validates cleanly,
     * for a self-test to break one nested field of.
     *
     * @return array{\stdClass, \stdClass}
     */
    private static function validOrderParts(): array
    {
        return [
            (object) ['Token' => 'tok', 'Value' => 5000, 'Currency' => 'DKK', 'Quantity' => 1],
            (object) ['Name' => 'A', 'Email' => 'a@example.com', 'RefID' => 'r-a'],
        ];
    }

    /**
     * Validates an OrderRequest built around the given product and recipient.
     *
     * @return list<string>
     */
    private static function validateOrderRequest(\stdClass $product, \stdClass $recipient): array
    {
        return self::spec()->validate(self::spec()->schema('OrderRequest'), (object) [
            'Product' => $product,
            'Sync' => false,
            'DeliveryTemplateId' => 42,
            'Recipients' => [$recipient],
        ]);
    }

    /**
     * Public methods of the class, inherited ones included — a public method added
     * to a shared base class must not slip past the inventory — excluding the
     * constructor and magic methods. Each maps to its parameter names in
     * declaration order; methods are sorted by name.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $reflection
     *
     * @return array<string, list<string>>
     */
    private static function publicMethods(\ReflectionClass $reflection): array
    {
        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), '__')) {
                $methods[$method->getName()] = array_map(
                    static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
                    $method->getParameters(),
                );
            }
        }
        ksort($methods);

        return $methods;
    }
}
