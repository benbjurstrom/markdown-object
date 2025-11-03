<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

final class EmittedChunk
{
    public function __construct(
        public ?int $id,
        /** @var list<string> */
        public array $breadcrumb,
        public string $markdown,
        public int $tokenCount
    ) {}
}
