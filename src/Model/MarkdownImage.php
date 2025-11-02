<?php

namespace BenBjurstrom\MarkdownObject\Model;

final class MarkdownImage
{
    public function __construct(
        public string $alt,
        public string $src,
        public ?string $title,
        public string $raw,
        public ?Position $pos = null
    ) {}
}
