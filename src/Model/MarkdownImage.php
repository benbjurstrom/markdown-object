<?php

namespace BenBjurstrom\MarkdownObject\Model;

final class MarkdownImage extends MarkdownNode
{
    public function __construct(
        public string $alt,
        public string $src,
        public ?string $title = null,
        public string $raw = '',
        ?Position $pos = null,
        int $tokenCount = 0
    ) {
        $this->pos = $pos;
        $this->tokenCount = $tokenCount;
    }

    /**
     * @return array{
     *     __type: class-string<self>,
     *     alt: string,
     *     src: string,
     *     title: string|null,
     *     raw: string,
     *     pos: array<string, mixed>|null,
     *     tokenCount: int
     * }
     */
    protected function serializePayload(): array
    {
        return [
            '__type' => self::class,
            'alt' => $this->alt,
            'src' => $this->src,
            'title' => $this->title,
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
        $alt = self::expectString($data, 'alt');
        $src = self::expectString($data, 'src');
        $title = self::expectNullableString($data, 'title');
        $raw = self::expectString($data, 'raw');
        $pos = Position::fromArray(self::expectNullableArray($data, 'pos'));
        $tokenCount = self::expectInt($data, 'tokenCount');

        return new self($alt, $src, $title, $raw, $pos, $tokenCount);
    }
}
