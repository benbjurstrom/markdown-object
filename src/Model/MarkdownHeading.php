<?php

namespace BenBjurstrom\MarkdownObject\Model;

use InvalidArgumentException;

final class MarkdownHeading extends MarkdownNode
{
    /** @var list<MarkdownNode> */
    public array $children = [];

    public function __construct(
        public int $level,
        public string $text,
        public ?string $rawLine = null,
        public ?Position $pos = null
    ) {}

    /**
     * @return array{
     *     __type: class-string<self>,
     *     level: int,
     *     text: string,
     *     rawLine: string|null,
     *     pos: array<string, mixed>|null,
     *     children: list<array<string, mixed>>
     * }
     */
    protected function serializePayload(): array
    {
        $children = array_map(
            static fn (MarkdownNode $child): array => $child->serialize(),
            $this->children
        );

        return [
            '__type' => self::class,
            'level' => $this->level,
            'text' => $this->text,
            'rawLine' => $this->rawLine,
            'pos' => $this->pos?->toArray(),
            'children' => $children,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function deserialize(array $data): static
    {
        $level = self::expectInt($data, 'level');
        $text = self::expectString($data, 'text');
        $rawLine = self::expectNullableString($data, 'rawLine');
        $pos = Position::fromArray(self::expectNullableArray($data, 'pos'));

        $childrenData = $data['children'] ?? [];
        if (! is_array($childrenData)) {
            throw new InvalidArgumentException('Heading children must be an array.');
        }

        $children = [];
        foreach ($childrenData as $child) {
            if (! is_array($child)) {
                throw new InvalidArgumentException('Heading children must contain node payloads.');
            }

            self::assertStringKeys($child, 'Node payload');

            /** @var array<string, mixed> $child */
            $children[] = self::hydrate($child);
        }

        $heading = new self($level, $text, $rawLine, $pos);
        $heading->children = $children;

        return $heading;
    }
}
