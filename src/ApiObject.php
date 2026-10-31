<?php

declare(strict_types=1);

namespace ZapSMTP;

/**
 * An object the API returned (an email, a domain, ...).
 *
 * Its typed properties hold the fields this SDK knows. A field the API left
 * out, or sent with a type the SDK did not expect, stays null there instead
 * of failing. Array access and toArray() give the JSON exactly as received,
 * including fields newer than this SDK; reading an unknown field as a
 * property ($obj->new_field) works too.
 *
 * Objects are read-only: array writes throw.
 *
 * @implements \ArrayAccess<string, mixed>
 */
abstract class ApiObject implements \ArrayAccess, \JsonSerializable
{
    /**
     * The known fields and their types: a scalar name (string, int, float,
     * bool, mixed), a model class, or ['list'|'map', element type].
     *
     * @var array<string, mixed>
     */
    protected const FIELDS = [];

    /** @var array<array-key, mixed> */
    private array $rawValues;

    private ?ApiResponse $rawResponse;

    /**
     * @param mixed $values the decoded JSON object; anything else gives an empty object
     */
    final public function __construct(mixed $values = [], ?ApiResponse $response = null)
    {
        $this->rawValues = \is_array($values) ? $values : [];
        $this->rawResponse = $response;
        foreach (static::FIELDS as $name => $type) {
            if (\array_key_exists($name, $this->rawValues)) {
                [$ok, $value] = self::cast($type, $this->rawValues[$name]);
                if ($ok) {
                    $this->{$name} = $value;
                }
            }
        }
    }

    /**
     * The answer this object came in (status, headers, request id); null for
     * objects nested in another one.
     */
    public function getLastResponse(): ?ApiResponse
    {
        return $this->rawResponse;
    }

    /**
     * The JSON object as received, unknown fields included.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->rawValues;
    }

    /**
     * The JSON as received. Decoded PHP arrays cannot tell {} from []: a
     * known map or object field that is empty is written back as {}.
     */
    public function jsonSerialize(): mixed
    {
        if ($this->rawValues === []) {
            return new \stdClass();
        }
        $out = $this->rawValues;
        foreach (static::FIELDS as $name => $type) {
            if (\array_key_exists($name, $out)) {
                $out[$name] = self::forJson($type, $out[$name]);
            }
        }

        return $out;
    }

    private static function forJson(mixed $type, mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\is_string($type) && is_subclass_of($type, self::class)) {
            return (new $type($value))->jsonSerialize();
        }
        if (!\is_array($type)) {
            return $value; // mixed: as decoded
        }
        if ($type[0] === 'map' && $value === []) {
            return new \stdClass();
        }

        return array_map(static fn (mixed $v): mixed => self::forJson($type[1], $v), $value);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->rawValues[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->rawValues[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException(static::class . ' is read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException(static::class . ' is read-only');
    }

    /** A field this SDK does not know yet, as received; null when absent. */
    public function __get(string $name): mixed
    {
        return $this->rawValues[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->rawValues[$name]);
    }

    /**
     * Converts a JSON value to a field type: [true, value], or [false, null]
     * when it does not fit.
     *
     * @return array{bool, mixed}
     */
    private static function cast(mixed $type, mixed $value): array
    {
        if (\is_float($value) && !is_finite($value)) {
            return [true, null]; // a number too large for a float
        }
        if ($value === null || $type === 'mixed') {
            return [true, $value];
        }
        if (\is_array($type)) {
            if (!\is_array($value) || ($type[0] === 'list' && !array_is_list($value))) {
                return [false, null];
            }
            $out = [];
            foreach ($value as $k => $v) {
                [$ok, $out[$k]] = self::cast($type[1], $v);
                if (!$ok) {
                    return [false, null];
                }
            }

            return [true, $out];
        }

        return match ($type) {
            'string' => [\is_string($value), \is_string($value) ? $value : null],
            'bool' => [\is_bool($value), \is_bool($value) ? $value : null],
            'int' => \is_int($value) ? [true, $value]
                : (\is_float($value) && floor($value) === $value && abs($value) < 2 ** 53 ? [true, (int) $value] : [false, null]),
            'float' => \is_int($value) || \is_float($value) ? [true, (float) $value] : [false, null],
            default => \is_array($value) && \is_string($type) && is_subclass_of($type, self::class)
                ? [true, new $type($value)] : [false, null],
        };
    }
}
