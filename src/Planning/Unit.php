<?php

namespace BenBjurstrom\MarkdownObject\Planning;

final readonly class Unit
{
    public function __construct(
        public UnitKind $kind,
        public string $markdown,
        public int $tokens,
        public ?int $partIndex = null,
        public ?int $partOf = null,
    ) {}
}
