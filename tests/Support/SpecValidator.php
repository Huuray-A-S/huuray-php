<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

/**
 * Reads the vendored OpenAPI specification and validates request bodies against it.
 *
 * FAILS CLOSED: a schema shape this validator does not understand is an error,
 * never a silent pass. The spec-drift job re-downloads the live specification
 * weekly — if a refresh starts using `allOf` wrappers (standard Swashbuckle
 * output for nullable `$ref`s), drops `type`, or declares an array without
 * `items`, the gates must break loudly rather than validate nothing while
 * staying green.
 */
final class SpecValidator
{
    /** @var array<string, mixed> */
    public readonly array $spec;

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 2) . '/openapi/huuray-v4.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Cannot read the vendored spec at ' . $path);
        }

        $spec = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($spec)) {
            throw new \RuntimeException('The vendored spec is not a JSON object.');
        }

        /** @var array<string, mixed> $spec */
        $this->spec = $spec;
    }

    /**
     * `POST /v4/Order` style keys for every operation the API documents.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        $operations = [];
        foreach (self::map($this->spec['paths'] ?? null) as $path => $item) {
            foreach (array_keys(self::map($item)) as $verb) {
                if ($verb !== 'parameters') {
                    $operations[] = strtoupper($verb) . ' ' . $path;
                }
            }
        }

        return $operations;
    }

    /** @return array<string, mixed> The operation object for a path and verb; empty when undocumented. */
    public function operation(string $method, string $path): array
    {
        return self::map(self::map(self::map($this->spec['paths'] ?? null)[$path] ?? null)[strtolower($method)] ?? null);
    }

    /** @return list<string> The query parameter names an operation declares. */
    public function queryParameters(string $method, string $path): array
    {
        $names = [];
        foreach (self::sequence($this->operation($method, $path)['parameters'] ?? null) as $parameter) {
            $parameter = self::map($parameter);
            if (($parameter['in'] ?? null) === 'query' && is_string($parameter['name'] ?? null)) {
                $names[] = $parameter['name'];
            }
        }

        return $names;
    }

    /** @return array<string, mixed>|null The JSON request body schema, or null when the operation declares none. */
    public function requestSchema(string $method, string $path): ?array
    {
        $requestBody = self::map($this->operation($method, $path)['requestBody'] ?? null);
        $content = self::map($requestBody['content'] ?? null);
        $json = self::map($content['application/json'] ?? null);
        $schema = $json['schema'] ?? null;

        return is_array($schema) ? self::map($schema) : null;
    }

    /** @return array<string, mixed> A named component schema. */
    public function schema(string $name): array
    {
        $schemas = self::map(self::map($this->spec['components'] ?? null)['schemas'] ?? null);
        if (!isset($schemas[$name])) {
            throw new \RuntimeException('No component schema named ' . $name);
        }

        return self::map($schemas[$name]);
    }

    /**
     * Returns human-readable violations; an empty list means the value conforms.
     *
     * @param array<string, mixed> $schema
     * @param mixed                $value  Decoded with JSON objects as `stdClass`, so objects and lists differ.
     *
     * @return list<string>
     */
    public function validate(array $schema, mixed $value, string $at = '$'): array
    {
        $resolved = $this->deref($schema);

        foreach (['allOf', 'oneOf', 'anyOf'] as $composition) {
            if (array_key_exists($composition, $resolved)) {
                return [
                    $at . ': schema uses allOf/oneOf/anyOf, which this validator does not handle — '
                    . 'extend validate() before trusting this run',
                ];
            }
        }

        if ($value === null) {
            return ($resolved['nullable'] ?? false) === true
                ? []
                : [$at . ': null but the spec does not mark it nullable'];
        }

        $type = $resolved['type'] ?? null;
        $errors = [];

        switch ($type) {
            case 'object':
                if (!$value instanceof \stdClass) {
                    return [$at . ': expected object, got ' . get_debug_type($value)];
                }
                $properties = self::map($resolved['properties'] ?? null);
                $present = [];
                foreach (get_object_vars($value) as $key => $_) {
                    $present[] = (string) $key;
                }

                // The invention detector: a property the spec does not define.
                foreach ($present as $key) {
                    if (!array_key_exists($key, $properties)) {
                        $errors[] = $at . '.' . $key . ': not defined in the spec — the SDK must not send undocumented fields';
                    }
                }
                foreach (self::sequence($resolved['required'] ?? null) as $required) {
                    if (is_string($required) && !in_array($required, $present, true)) {
                        $errors[] = $at . '.' . $required . ': required by the spec but not sent';
                    }
                }
                foreach ($properties as $key => $subSchema) {
                    $key = (string) $key;
                    if (in_array($key, $present, true)) {
                        array_push($errors, ...$this->validate(self::map($subSchema), $value->{$key}, $at . '.' . $key));
                    }
                }

                return $errors;

            case 'array':
                if (!is_array($value) || !array_is_list($value)) {
                    return [$at . ': expected array, got ' . get_debug_type($value)];
                }
                if (!isset($resolved['items']) || !is_array($resolved['items'])) {
                    // An array with no `items` would let every element through
                    // unvalidated, so the no-invention check would silently not run.
                    return [
                        $at . ": array schema has no 'items' — this validator cannot check its elements; "
                        . 'extend validate() before trusting this run',
                    ];
                }
                foreach ($value as $index => $item) {
                    array_push($errors, ...$this->validate(self::map($resolved['items']), $item, $at . '[' . $index . ']'));
                }

                return $errors;

            case 'integer':
                return is_int($value) ? [] : [$at . ': expected integer, got ' . var_export($value, true)];

            case 'number':
                return is_int($value) || is_float($value) ? [] : [$at . ': expected number, got ' . get_debug_type($value)];

            case 'boolean':
                return is_bool($value) ? [] : [$at . ': expected boolean, got ' . get_debug_type($value)];

            case 'string':
                return is_string($value) ? [] : [$at . ': expected string, got ' . get_debug_type($value)];

            default:
                return [
                    $at . ': schema has ' . ($type === null ? 'no "type"' : 'unknown type ' . var_export($type, true))
                    . ' — this validator cannot check it; extend validate() before trusting this run',
                ];
        }
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function deref(array $schema): array
    {
        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref)) {
            return $schema;
        }

        return $this->schema(str_replace('#/components/schemas/', '', $ref));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /** @return list<mixed> */
    private static function sequence(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
