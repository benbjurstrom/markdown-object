<?php

namespace BenBjurstrom\MarkdownObject\Model;

final class MarkdownCode extends MarkdownNode
{
    public function __construct(public string $bodyRaw, public ?string $info = null, public ?Position $pos = null) {}

    /**
     * @return array{__type: class-string<self>, bodyRaw: string, info: string|null, pos: array<string, mixed>|null}
     */
    protected function serializePayload(): array
    {
        return [
            '__type' => self::class,
            'bodyRaw' => $this->bodyRaw,
            'info' => $this->info,
            'pos' => $this->pos?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function deserialize(array $data): static
    {
        $bodyRaw = self::expectString($data, 'bodyRaw');
        $info = self::expectNullableString($data, 'info');
        $pos = Position::fromArray(self::expectNullableArray($data, 'pos'));

        return new self($bodyRaw, $info, $pos);
    }
}
