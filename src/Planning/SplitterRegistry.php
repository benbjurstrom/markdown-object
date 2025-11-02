<?php

namespace BenBjurstrom\MarkdownObject\Planning;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Model\MarkdownCode;
use BenBjurstrom\MarkdownObject\Model\MarkdownImage;
use BenBjurstrom\MarkdownObject\Model\MarkdownTable;
use BenBjurstrom\MarkdownObject\Model\MarkdownText;

final class SplitterRegistry
{
    public function __construct(
        private TextSplitter $text,
        private CodeSplitter $code,
        private TableSplitter $table
    ) {}

    /** @return list<Unit> */
    public function split(object $node, Tokenizer $tok, int $target, int $hardCap): array
    {
        return match (true) {
            $node instanceof MarkdownText => $this->text->split($node, $tok, $target, $hardCap),
            $node instanceof MarkdownCode => $this->code->split($node, $tok, $target, $hardCap),
            $node instanceof MarkdownTable => $this->table->split($node, $tok, $target, $hardCap),
            $node instanceof MarkdownImage => [new Unit(UnitKind::Image, $node->raw, $tok->count($node->raw))],
            default => [],
        };
    }
}
