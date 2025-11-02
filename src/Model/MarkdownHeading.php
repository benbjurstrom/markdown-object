<?php

namespace BenBjurstrom\MarkdownObject\Model;

final class MarkdownHeading
{
    /** @var list<object> */
    public array $children = [];

    public function __construct(
        public int $level,
        public string $text,
        public ?string $rawLine = null, // exact heading line if available
        public ?Position $pos = null
    ) {}
}
