<?php

namespace BenBjurstrom\MarkdownObject\Planning;

use BenBjurstrom\MarkdownObject\Model\MarkdownCode;
use BenBjurstrom\MarkdownObject\Model\MarkdownImage;
use BenBjurstrom\MarkdownObject\Model\MarkdownTable;
use BenBjurstrom\MarkdownObject\Model\MarkdownText;

final readonly class Section
{
    /**
     * @param list<string> $breadcrumb
     * @param list<MarkdownText|MarkdownCode|MarkdownImage|MarkdownTable> $blocks
     */
    public function __construct(
        public array $breadcrumb,
        public array $blocks,
        public ?string $headingRawLine
    ) {}
}
