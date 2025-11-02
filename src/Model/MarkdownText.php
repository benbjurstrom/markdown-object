<?php

namespace BenBjurstrom\MarkdownObject\Model;

final class MarkdownText
{
    public function __construct(public string $raw, public ?Position $pos = null) {}
}
