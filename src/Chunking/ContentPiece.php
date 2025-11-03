<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

use BenBjurstrom\MarkdownObject\Model\Position;

/**
 * Represents a piece of markdown content with its token count and optional source position.
 * Used as the atomic unit during chunking - simpler than the old Unit class.
 */
final class ContentPiece
{
    public function __construct(
        public readonly string $markdown,
        public readonly int $tokens,
        public readonly ?Position $sourcePosition = null
    ) {}
}
