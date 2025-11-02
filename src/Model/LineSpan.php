<?php

namespace BenBjurstrom\MarkdownObject\Model;

final readonly class LineSpan
{
    public function __construct(public int $startLine, public int $endLine) {}
}
