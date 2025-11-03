<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

final class EmittedChunk
{
    public function __construct(
        public ?string $id,
        /** @var list<string> */
        public array $breadcrumb,
        public string $markdown,
        public int $tokenCount
    ) {}
}
