<?php

namespace BenBjurstrom\MarkdownObject\Contracts;

interface Tokenizer
{
    public function count(string $text): int;
}
