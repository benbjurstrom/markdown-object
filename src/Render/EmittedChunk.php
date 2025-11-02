<?php

namespace BenBjurstrom\MarkdownObject\Render;

final class EmittedChunk
{
    public function __construct(
        public ?string $id,
        /** @var list<string> */
        public array $breadcrumb,
        public string $markdown,
        public int $tokenCount,
        public ?int $partIndex = null,
        public ?int $partOf = null
    ) {}
}
