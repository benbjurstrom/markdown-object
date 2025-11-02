<?php

namespace BenBjurstrom\MarkdownObject\Planning;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;

interface Splitter
{
    /** @return list<Unit> */
    public function split(object $node, Tokenizer $tok, int $target, int $hardCap): array;
}
