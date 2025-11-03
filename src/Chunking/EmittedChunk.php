<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

use BenBjurstrom\MarkdownObject\Model\Position;

final class EmittedChunk
{
    public function __construct(
        public ?int $id,
        /** @var list<string> */
        public array $breadcrumb,
        public string $markdown,
        public int $tokenCount,
        public readonly Position $sourcePosition
    ) {}
}
