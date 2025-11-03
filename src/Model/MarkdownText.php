<?php

namespace BenBjurstrom\MarkdownObject\Model;

final class MarkdownText extends MarkdownNode
{
    public function __construct(
        public string $raw,
        public ?Position $pos = null,
        public int $tokenCount = 0
    ) {}

    /**
     * @return array{__type: class-string<self>, raw: string, pos: array<string, mixed>|null, tokenCount: int}
     */
    protected function serializePayload(): array
    {
        return [
            '__type' => self::class,
            'raw' => $this->raw,
            'pos' => $this->pos?->toArray(),
            'tokenCount' => $this->tokenCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function deserialize(array $data): static
    {
        $raw = self::expectString($data, 'raw');
        $pos = Position::fromArray(self::expectNullableArray($data, 'pos'));
        $tokenCount = self::expectInt($data, 'tokenCount');

        return new self($raw, $pos, $tokenCount);
    }
}
