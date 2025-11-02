<?php

namespace BenBjurstrom\MarkdownObject\Model;

final readonly class Position
{
    public function __construct(
        public ByteSpan $bytes,
        public ?LineSpan $lines = null
    ) {}
}
