<?php

namespace BenBjurstrom\MarkdownObject\Model;

use InvalidArgumentException;

abstract class MarkdownNode
{
    /**
     * @return array<string, mixed>
     */
    final public function serialize(): array
    {
        $payload = $this->serializePayload();

        if (! isset($payload['__type'])) {
            $payload['__type'] = static::class;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-return array{__type?: class-string<static>} & array<string, mixed>
     */
    abstract protected function serializePayload(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract public static function deserialize(array $data): static;

    /**
     * @param  array<string, mixed>  $payload
     */
    final public static function hydrate(array $payload): self
    {
        $type = $payload['__type'] ?? null;
        if (! is_string($type)) {
            throw new InvalidArgumentException('Node payload is missing a __type string.');
        }

        if (! is_a($type, self::class, true)) {
            throw new InvalidArgumentException('Unsupported node type: '.$type);
        }

        /** @var class-string<self> $type */
        return $type::deserialize($payload);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function assertStringKeys(array $data, string $context): void
    {
        foreach (array_keys($data) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException($context.' keys must be strings.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    final protected static function expectString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    final protected static function expectNullableString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a string or null.', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    final protected static function expectInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    final protected static function expectNullableArray(array $data, string $key): ?array
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        if ($value !== null && ! is_array($value)) {
            throw new InvalidArgumentException(sprintf('%s must be an array or null.', $key));
        }

        if ($value === null) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
