<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Model\ByteSpan;
use BenBjurstrom\MarkdownObject\Model\LineSpan;
use BenBjurstrom\MarkdownObject\Model\MarkdownCode;
use BenBjurstrom\MarkdownObject\Model\MarkdownHeading;
use BenBjurstrom\MarkdownObject\Model\MarkdownImage;
use BenBjurstrom\MarkdownObject\Model\MarkdownNode;
use BenBjurstrom\MarkdownObject\Model\MarkdownObject;
use BenBjurstrom\MarkdownObject\Model\MarkdownTable;
use BenBjurstrom\MarkdownObject\Model\MarkdownText;
use BenBjurstrom\MarkdownObject\Model\Position;

/**
 * Hierarchical chunking service implementing greedy top-down packing.
 *
 * Algorithm:
 * 1. Try to fit entire document in one chunk
 * 2. If too large, split by top-level headings
 * 3. For each heading, try to pack all children together
 * 4. If a heading's subtree is too large, recursively split by its children
 * 5. Use hardCap for hierarchy decisions, target for content splitting
 */
final class HierarchicalChunker
{
    public function __construct(
        private readonly Tokenizer $tokenizer,
        private readonly int $target,
        private readonly int $hardCap,
        private readonly TextSplitter $textSplitter,
        private readonly CodeSplitter $codeSplitter,
        private readonly TableSplitter $tableSplitter,
    ) {}

    /**
     * Chunk a MarkdownObject into EmittedChunk[].
     *
     * @return list<EmittedChunk>
     */
    public function chunk(MarkdownObject $root): array
    {
        $breadcrumb = [$root->filename];

        // Process children (will handle grouping based on token counts)
        $chunks = $this->processChildren($root->children, $breadcrumb);
        $chunks = $this->mergeSiblingChunks($chunks);

        // Assign IDs
        foreach ($chunks as $i => $chunk) {
            $chunk->id = $i + 1;
        }

        return $chunks;
    }

    /**
     * Process a list of nodes (handling preamble and headings).
     *
     * @param  list<MarkdownNode>  $children
     * @param  list<string>  $breadcrumb
     * @return list<EmittedChunk>
     */
    private function processChildren(array $children, array $breadcrumb): array
    {
        // Separate preamble content from headings
        $preamble = [];
        $headings = [];

        foreach ($children as $child) {
            if ($child instanceof MarkdownHeading) {
                $headings[] = $child;
            } else {
                $pieces = $this->splitDirectContent($child);
                $preamble = array_merge($preamble, $pieces);
            }
        }

        // Try to fit everything in one chunk (algorithm step 1)
        if (! empty($preamble) && ! empty($headings)) {
            $preambleTokens = $this->countPieces($preamble);
            $headingsTokens = 0;
            foreach ($headings as $heading) {
                $headingsTokens += $this->countAllRecursive($heading);
            }

            $totalTokens = $preambleTokens + $headingsTokens;

            if ($totalTokens <= $this->hardCap) {
                // Everything fits! Combine preamble + all headings into one chunk
                $allPieces = $preamble;
                foreach ($headings as $heading) {
                    $allPieces = array_merge($allPieces, $this->flattenAllRecursive($heading));
                }
                $markdown = $this->renderContentPieces($allPieces);

                return [new EmittedChunk(
                    id: null,
                    breadcrumb: $breadcrumb,
                    markdown: $markdown,
                    tokenCount: $this->tokenizer->count($markdown),
                    sourcePosition: $this->calculateSourcePosition($allPieces)
                )];
            }
        }

        // Can't fit everything - emit preamble and headings separately
        $chunks = [];

        // Emit preamble if exists
        if (! empty($preamble)) {
            $preambleTokens = $this->countPieces($preamble);

            // Check if preamble exceeds hardCap - if so, pack into multiple chunks
            if ($preambleTokens > $this->hardCap) {
                $preambleChunks = $this->packPiecesIntoChunks($preamble, $breadcrumb);
                $chunks = array_merge($chunks, $preambleChunks);
            } else {
                // Preamble fits in one chunk
                $markdown = $this->renderContentPieces($preamble);
                $chunks[] = new EmittedChunk(
                    id: null,
                    breadcrumb: $breadcrumb,
                    markdown: $markdown,
                    tokenCount: $this->tokenizer->count($markdown),
                    sourcePosition: $this->calculateSourcePosition($preamble)
                );
            }
        }

        // Process each heading
        foreach ($headings as $heading) {
            $headingChunks = $this->processHeading($heading, $breadcrumb);
            $chunks = array_merge($chunks, $headingChunks);
        }

        return $chunks;
    }

    /**
     * Process a heading node using greedy top-down packing.
     *
     * @param  list<string>  $breadcrumb
     * @return list<EmittedChunk>
     */
    private function processHeading(MarkdownHeading $heading, array $breadcrumb): array
    {
        $newBreadcrumb = [...$breadcrumb, $heading->text];

        // Separate direct content from child headings
        [$directContent, $childHeadings] = $this->separateChildren($heading->children);

        // Split direct content into pieces
        $directPieces = [];
        foreach ($directContent as $node) {
            $directPieces = array_merge($directPieces, $this->splitDirectContent($node));
        }

        // Include the heading itself
        $headingPiece = new ContentPiece($heading->rawLine ?? '', $this->tokenizer->count($heading->rawLine ?? ''), $heading->pos ?? null);
        $allPieces = [$headingPiece, ...$directPieces];
        $directTokens = $this->countPieces($directPieces);

        // Base case: no child headings
        if (empty($childHeadings)) {
            $headingTokens = $this->tokenizer->count($heading->rawLine ?? '');
            if ($headingTokens + $directTokens <= $this->hardCap) {
                $markdown = $this->renderContentPieces($allPieces);

                return [new EmittedChunk(
                    id: null,
                    breadcrumb: $newBreadcrumb,
                    markdown: $markdown,
                    tokenCount: $this->tokenizer->count($markdown),
                    sourcePosition: $this->calculateSourcePosition($allPieces)
                )];
            }

            // Direct content exceeds hardCap - pack into multiple chunks (include heading)
            return $this->packPiecesIntoChunks($allPieces, $newBreadcrumb);
        }

        // Try to fit everything (heading + direct content + all children)
        $totalTokens = $this->countAllRecursive($heading);

        if ($totalTokens <= $this->hardCap) {
            // Everything fits! Inline all children (include this heading + direct content + all child headings)
            $allContentPieces = $allPieces;  // Start with heading + direct content
            foreach ($childHeadings as $child) {
                // When inlining children, include their headings
                $allContentPieces = array_merge($allContentPieces, $this->flattenAllRecursive($child));
            }
            $markdown = $this->renderContentPieces($allContentPieces);

            return [new EmittedChunk(
                id: null,
                breadcrumb: $newBreadcrumb,
                markdown: $markdown,
                tokenCount: $this->tokenizer->count($markdown),  // Count actual rendered markdown
                sourcePosition: $this->calculateSourcePosition($allContentPieces)
            )];
        }

        // Can't fit everything - greedy pack children
        $chunks = [];
        $accumulated = $allPieces;  // Start with heading + direct content
        $currentTokens = $this->tokenizer->count($heading->rawLine ?? '') + $directTokens;

        foreach ($childHeadings as $child) {
            $childTokens = $this->countAllRecursive($child);

            // Can we fit this child in current accumulation?
            if ($currentTokens + $childTokens <= $this->hardCap) {
                // Yes - inline it completely (include child heading + all its content)
                $childPieces = $this->flattenAllRecursive($child);
                $accumulated = array_merge($accumulated, $childPieces);
                $currentTokens += $childTokens;
            } else {
                // Doesn't fit - emit accumulated chunk
                if (! empty($accumulated)) {
                    $markdown = $this->renderContentPieces($accumulated);
                    $chunks[] = new EmittedChunk(
                        id: null,
                        breadcrumb: $newBreadcrumb,
                        markdown: $markdown,
                        tokenCount: $this->tokenizer->count($markdown),
                        sourcePosition: $this->calculateSourcePosition($accumulated)
                    );
                }

                // If the child itself fits, start a fresh accumulation with it.
                if ($childTokens <= $this->hardCap) {
                    $accumulated = $this->flattenAllRecursive($child);
                    $currentTokens = $childTokens;

                    continue;
                }

                // Otherwise recursively process the oversized child
                $childChunks = $this->processHeading($child, $newBreadcrumb);
                $chunks = array_merge($chunks, $childChunks);

                $accumulated = [];
                $currentTokens = 0;
            }
        }

        // Emit any remaining accumulated content
        if (! empty($accumulated)) {
            $markdown = $this->renderContentPieces($accumulated);
            $chunks[] = new EmittedChunk(
                id: null,
                breadcrumb: $newBreadcrumb,
                markdown: $markdown,
                tokenCount: $this->tokenizer->count($markdown),
                sourcePosition: $this->calculateSourcePosition($accumulated)
            );
        }

        return $chunks;
    }

    /**
     * Split non-heading content into ContentPiece[].
     *
     * @return list<ContentPiece>
     */
    private function splitDirectContent(MarkdownNode $node): array
    {
        // If node fits under hardCap, return as single piece
        if ($node->tokenCount <= $this->hardCap) {
            $markdown = $this->renderNode($node);

            return [new ContentPiece($markdown, $node->tokenCount, $node->pos ?? null)];
        }

        // Node exceeds hardCap - use splitters
        return match (true) {
            $node instanceof MarkdownText => $this->textSplitter->split($node, $this->tokenizer, $this->target, $this->hardCap),
            $node instanceof MarkdownCode => $this->codeSplitter->split($node, $this->tokenizer, $this->target, $this->hardCap),
            $node instanceof MarkdownTable => $this->tableSplitter->split($node, $this->tokenizer, $this->target, $this->hardCap),
            $node instanceof MarkdownImage => [new ContentPiece($node->raw, $node->tokenCount, $node->pos ?? null)],
            default => []
        };
    }

    /**
     * Count total tokens for a node and all its descendants recursively.
     */
    private function countAllRecursive(MarkdownNode $node): int
    {
        if (! $node instanceof MarkdownHeading) {
            return $node->tokenCount;
        }

        // For headings: heading line + all children
        $total = $this->tokenizer->count($node->rawLine ?? '');
        foreach ($node->children as $child) {
            $total += $this->countAllRecursive($child);
        }

        return $total;
    }

    /**
     * Flatten a heading and all descendants into ContentPiece[].
     *
     * @return list<ContentPiece>
     */
    private function flattenAllRecursive(MarkdownHeading $heading): array
    {
        $pieces = [];

        // Add heading itself
        $pieces[] = new ContentPiece($heading->rawLine ?? '', $this->tokenizer->count($heading->rawLine ?? ''), $heading->pos ?? null);

        // Add all children
        foreach ($heading->children as $child) {
            if ($child instanceof MarkdownHeading) {
                $pieces = array_merge($pieces, $this->flattenAllRecursive($child));
            } else {
                $markdown = $this->renderNode($child);
                $pieces[] = new ContentPiece($markdown, $child->tokenCount, $child->pos ?? null);
            }
        }

        return $pieces;
    }

    /**
     * Separate heading children from non-heading children.
     *
     * @param  list<MarkdownNode>  $children
     * @return array{0: list<MarkdownNode>, 1: list<MarkdownHeading>}
     */
    private function separateChildren(array $children): array
    {
        $direct = [];
        $headings = [];

        foreach ($children as $child) {
            if ($child instanceof MarkdownHeading) {
                $headings[] = $child;
            } else {
                $direct[] = $child;
            }
        }

        return [$direct, $headings];
    }

    /**
     * Pack ContentPieces into chunks at target boundaries.
     * Used when direct content exceeds hardCap.
     *
     * @param  list<ContentPiece>  $pieces
     * @param  list<string>  $breadcrumb
     * @return list<EmittedChunk>
     */
    private function packPiecesIntoChunks(array $pieces, array $breadcrumb): array
    {
        $chunks = [];
        $accumulated = [];
        $currentTokens = 0;

        foreach ($pieces as $piece) {
            if ($currentTokens + $piece->tokens > $this->hardCap && ! empty($accumulated)) {
                // Emit current accumulation
                $chunks[] = new EmittedChunk(
                    id: null,
                    breadcrumb: $breadcrumb,
                    markdown: $this->renderContentPieces($accumulated),
                    tokenCount: $currentTokens,
                    sourcePosition: $this->calculateSourcePosition($accumulated)
                );
                $accumulated = [$piece];
                $currentTokens = $piece->tokens;
            } else {
                $accumulated[] = $piece;
                $currentTokens += $piece->tokens;
            }
        }

        // Emit remaining
        if (! empty($accumulated)) {
            $markdown = $this->renderContentPieces($accumulated);
            $chunks[] = new EmittedChunk(
                id: null,
                breadcrumb: $breadcrumb,
                markdown: $markdown,
                tokenCount: $this->tokenizer->count($markdown),
                sourcePosition: $this->calculateSourcePosition($accumulated)
            );
        }

        return $chunks;
    }

    /**
     * Render ContentPieces into markdown string.
     *
     * @param  list<ContentPiece>  $pieces
     */
    private function renderContentPieces(array $pieces): string
    {
        $parts = [];
        foreach ($pieces as $piece) {
            if (trim($piece->markdown) !== '') {
                $parts[] = $piece->markdown;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Render a single node to markdown.
     */
    private function renderNode(MarkdownNode $node): string
    {
        return match (true) {
            $node instanceof MarkdownHeading => $this->renderHeadingRecursive($node),
            $node instanceof MarkdownText => $node->raw,
            $node instanceof MarkdownCode => "```{$node->info}\n".rtrim($node->bodyRaw)."\n```",
            $node instanceof MarkdownTable => $node->raw,
            $node instanceof MarkdownImage => $node->raw,
            default => ''
        };
    }

    /**
     * Render a heading and all its children recursively.
     */
    private function renderHeadingRecursive(MarkdownHeading $heading): string
    {
        $parts = [$heading->rawLine ?? ''];
        foreach ($heading->children as $child) {
            $parts[] = $this->renderNode($child);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Count total tokens in ContentPiece[].
     *
     * @param  list<ContentPiece>  $pieces
     */
    private function countPieces(array $pieces): int
    {
        $total = 0;
        foreach ($pieces as $piece) {
            $total += $piece->tokens;
        }

        return $total;
    }

    /**
     * Merge adjacent chunks that share the same parent breadcrumb when the combined
     * markdown fits under the hard cap. This reduces over-fragmentation that can
     * happen after a split at a particular hierarchy level.
     *
     * @param  list<EmittedChunk>  $chunks
     * @return list<EmittedChunk>
     */
    private function mergeSiblingChunks(array $chunks): array
    {
        if (count($chunks) < 2) {
            return $chunks;
        }

        $merged = [];
        $i = 0;
        $total = count($chunks);

        while ($i < $total) {
            $group = [$chunks[$i]];
            $parentBreadcrumb = $this->parentBreadcrumb($chunks[$i]->breadcrumb);
            $groupMarkdown = $this->normalizedChunkMarkdown($chunks[$i]->markdown);
            $groupTokens = $groupMarkdown === '' ? 0 : $chunks[$i]->tokenCount;
            $j = $i + 1;

            while ($j < $total) {
                $next = $chunks[$j];

                if ($this->parentBreadcrumb($next->breadcrumb) !== $parentBreadcrumb) {
                    break;
                }

                $nextMarkdown = $this->normalizedChunkMarkdown($next->markdown);
                $candidateMarkdown = $this->concatenateMarkdown($groupMarkdown, $nextMarkdown);
                $candidateTokens = $candidateMarkdown === $groupMarkdown
                    ? $groupTokens
                    : ($candidateMarkdown === '' ? 0 : $this->tokenizer->count($candidateMarkdown));

                if ($candidateTokens > $this->hardCap) {
                    break;
                }

                $group[] = $next;
                $groupMarkdown = $candidateMarkdown;
                $groupTokens = $candidateTokens;
                $j++;
            }

            if (count($group) === 1) {
                $merged[] = $group[0];
            } else {
                $markdown = $groupMarkdown !== '' ? $groupMarkdown : $this->concatenateGroupMarkdown($group);
                $merged[] = new EmittedChunk(
                    id: null,
                    breadcrumb: $this->mergedBreadcrumb($group, $parentBreadcrumb),
                    markdown: $markdown,
                    tokenCount: $groupTokens !== 0 ? $groupTokens : $this->tokenizer->count($markdown),
                    sourcePosition: $this->mergeChunkPositions($group)
                );
            }

            $i += count($group);
        }

        return $merged;
    }

    /**
     * @param  list<EmittedChunk>  $chunks
     */
    private function concatenateGroupMarkdown(array $chunks): string
    {
        $markdown = '';
        foreach ($chunks as $chunk) {
            $markdown = $this->concatenateMarkdown($markdown, $this->normalizedChunkMarkdown($chunk->markdown));
        }

        return $markdown;
    }

    /**
     * @param  list<string>  $breadcrumb
     * @return list<string>
     */
    private function parentBreadcrumb(array $breadcrumb): array
    {
        if (count($breadcrumb) <= 1) {
            return [];
        }

        return array_slice($breadcrumb, 0, -1);
    }

    /**
     * Choose breadcrumb for a merged chunk. If all merged chunks already share
     * the same breadcrumb, keep it. Otherwise fall back to the shared parent
     * breadcrumb when available.
     *
     * @param  list<EmittedChunk>  $chunks
     * @param  list<string>  $parentBreadcrumb
     * @return list<string>
     */
    private function mergedBreadcrumb(array $chunks, array $parentBreadcrumb): array
    {
        $unique = array_unique(array_map(
            fn (EmittedChunk $chunk) => implode("\0", $chunk->breadcrumb),
            $chunks
        ));

        if (count($unique) === 1) {
            return $chunks[0]->breadcrumb;
        }

        if (! empty($parentBreadcrumb)) {
            return $parentBreadcrumb;
        }

        return $chunks[0]->breadcrumb;
    }

    /**
     * @param  list<EmittedChunk>  $chunks
     */
    private function mergeChunkPositions(array $chunks): Position
    {
        $positions = array_map(fn (EmittedChunk $chunk) => $chunk->sourcePosition, $chunks);

        $minStartByte = PHP_INT_MAX;
        $maxEndByte = PHP_INT_MIN;
        $minStartLine = PHP_INT_MAX;
        $maxEndLine = PHP_INT_MIN;
        $hasLines = false;

        foreach ($positions as $pos) {
            $minStartByte = min($minStartByte, $pos->bytes->startByte);
            $maxEndByte = max($maxEndByte, $pos->bytes->endByte);

            if ($pos->lines !== null) {
                $hasLines = true;
                $minStartLine = min($minStartLine, $pos->lines->startLine);
                $maxEndLine = max($maxEndLine, $pos->lines->endLine);
            }
        }

        return new Position(
            bytes: new ByteSpan($minStartByte, $maxEndByte),
            lines: $hasLines ? new LineSpan($minStartLine, $maxEndLine) : null
        );
    }

    private function normalizedChunkMarkdown(string $markdown): string
    {
        return trim($markdown) === '' ? '' : $markdown;
    }

    private function concatenateMarkdown(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        return $left."\n\n".$right;
    }

    /**
     * Calculate combined source position from ContentPieces.
     * Returns the span from min(startByte/startLine) to max(endByte/endLine).
     *
     * @param  list<ContentPiece>  $pieces
     */
    private function calculateSourcePosition(array $pieces): Position
    {
        $positions = array_filter(
            array_map(fn (ContentPiece $p) => $p->sourcePosition, $pieces),
            fn ($pos) => $pos !== null
        );

        if (empty($positions)) {
            // Fallback for edge case where no positions available
            return new Position(
                bytes: new ByteSpan(0, 0),
                lines: null
            );
        }

        $minStartByte = PHP_INT_MAX;
        $maxEndByte = PHP_INT_MIN;
        $minStartLine = PHP_INT_MAX;
        $maxEndLine = PHP_INT_MIN;
        $hasLines = false;

        foreach ($positions as $pos) {
            $minStartByte = min($minStartByte, $pos->bytes->startByte);
            $maxEndByte = max($maxEndByte, $pos->bytes->endByte);

            if ($pos->lines !== null) {
                $hasLines = true;
                $minStartLine = min($minStartLine, $pos->lines->startLine);
                $maxEndLine = max($maxEndLine, $pos->lines->endLine);
            }
        }

        return new Position(
            bytes: new ByteSpan($minStartByte, $maxEndByte),
            lines: $hasLines ? new LineSpan($minStartLine, $maxEndLine) : null
        );
    }
}
