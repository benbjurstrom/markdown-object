<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

/**
 * Represents a piece of markdown content with its token count.
 * Used as the atomic unit during chunking - simpler than the old Unit class.
 */
final class ContentPiece
{
    public function __construct(
        public readonly string $markdown,
        public readonly int $tokens
    ) {}
}
