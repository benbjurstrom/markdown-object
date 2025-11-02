<?php

namespace BenBjurstrom\MarkdownObject\Model;

final readonly class ByteSpan
{
    public function __construct(public int $startByte, public int $endByte) {}
}
