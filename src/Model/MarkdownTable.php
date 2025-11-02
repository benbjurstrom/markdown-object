<?php

namespace BenBjurstrom\MarkdownObject\Model;

final class MarkdownTable
{
    public function __construct(public string $raw, public ?Position $pos = null) {}
}
