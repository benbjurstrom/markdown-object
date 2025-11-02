<?php

namespace BenBjurstrom\MarkdownObject\Build;

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
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;

final class MarkdownObjectBuilder
{
    /**
     * Transforms a CommonMark Document into a structured MarkdownObject with nested headings.
     * CommonMark flattens headings at parse time, so we reconstruct the hierarchy based on heading levels.
     * Tracks positions (byte/line spans) for all blocks to enable source mapping.
     */
    public function build(Document $document, string $filename, string $source): MarkdownObject
    {
        $root = new MarkdownObject($filename);
        $lines = preg_split("/\R/", $source) ?: [''];
        $lineStarts = $this->computeLineStarts($source);

        // Collect top-level children (CommonMark flattens headings; we'll nest manually)
        /** @var list<Node> $nodes */
        $nodes = [];
        for ($child = $document->firstChild(); $child; $child = $child->next()) {
            $nodes[] = $child;
        }

        $i = 0;
        $n = count($nodes);
        while ($i < $n) {
            $node = $nodes[$i];
            if ($node instanceof Heading) {
                $root->children[] = $this->consumeHeading($nodes, $i, $source, $lines, $lineStarts, $node->getLevel());

                continue; // $i advanced inside consumeHeading
            }
            $root->children[] = $this->toLeaf($node, $source, $lines, $lineStarts);
            $i++;
        }

        return $root;
    }

    /**
     * Recursively builds a heading and nests all subsequent content and sub-headings under it.
     * Stops when encountering a heading of equal or higher level (lower heading number).
     * Advances the $i index by reference as it consumes nodes from the array.
     *
     * @param  list<Node>  $nodes
     * @param  list<string>  $lines
     * @param  list<int>  $lineStarts
     */
    private function consumeHeading(array $nodes, int &$i, string $src, array $lines, array $lineStarts, int $level): MarkdownHeading
    {
        /** @var Heading $hNode */
        $hNode = $nodes[$i];
        $mh = new MarkdownHeading($level, $this->inlineText($hNode), $this->lineSlice($lines, $hNode->getStartLine(), $hNode->getStartLine()), $this->pos($hNode, $lineStarts));
        $i++;

        $n = count($nodes);
        while ($i < $n) {
            $node = $nodes[$i];
            if ($node instanceof Heading) {
                if ($node->getLevel() <= $level) {
                    break;
                }
                $mh->children[] = $this->consumeHeading($nodes, $i, $src, $lines, $lineStarts, $node->getLevel());

                continue;
            }
            $mh->children[] = $this->toLeaf($node, $src, $lines, $lineStarts);
            $i++;
        }

        return $mh;
    }

    /**
     * Converts a CommonMark block node to its corresponding MarkdownObject model class.
     * Handles special cases like image-only paragraphs and distinguishes between fenced/indented code.
     * Falls back to MarkdownText for unknown node types to prevent data loss.
     *
     * @param  list<string>  $lines
     * @param  list<int>  $lineStarts
     */
    private function toLeaf(object $node, string $src, array $lines, array $lineStarts): MarkdownNode
    {
        if ($node instanceof Paragraph) {
            $first = $node->firstChild();
            $onlyImage = $first instanceof Image && $first === $node->lastChild();
            if ($onlyImage) {
                /** @var Image $img */
                $img = $first;

                return new MarkdownImage(
                    alt: $this->inlineText($img),
                    src: $img->getUrl(),
                    title: $img->getTitle(),
                    raw: $this->sliceByLines($lines, $node->getStartLine(), $node->getEndLine()),
                    pos: $this->pos($node, $lineStarts)
                );
            }

            return new MarkdownText(
                raw: $this->sliceByLines($lines, $node->getStartLine(), $node->getEndLine()),
                pos: $this->pos($node, $lineStarts)
            );
        }

        if ($node instanceof FencedCode) {
            return new MarkdownCode(
                bodyRaw: $node->getLiteral(), // body only
                info: $node->getInfo() ?: null,
                pos: $this->pos($node, $lineStarts)
            );
        }

        if ($node instanceof IndentedCode) {
            return new MarkdownCode(
                bodyRaw: $node->getLiteral(),
                info: null,
                pos: $this->pos($node, $lineStarts)
            );
        }

        if ($node instanceof Table) {
            return new MarkdownTable(
                raw: $this->sliceByLines($lines, $node->getStartLine(), $node->getEndLine()),
                pos: $this->pos($node, $lineStarts)
            );
        }

        // Fallback: raw line slice
        // All CommonMark blocks extend AbstractBlock, which has getStartLine/getEndLine
        if (! $node instanceof AbstractBlock) {
            return new MarkdownText(
                raw: '',
                pos: null
            );
        }

        return new MarkdownText(
            raw: $this->sliceByLines($lines, $node->getStartLine(), $node->getEndLine()),
            pos: $this->pos($node, $lineStarts)
        );
    }

    /**
     * Extracts plain text from a node with inline formatting (bold, italic, links, etc.).
     * Recursively walks the node tree to gather all text literals, stripping formatting.
     */
    private function inlineText(Node $node): string
    {
        $txt = '';
        for ($c = $node->firstChild(); $c; $c = $c->next()) {
            if ($c instanceof StringContainerInterface) {
                $v = $c->getLiteral();
                if ($v !== '') {
                    $txt .= $v;
                }
            }
            if ($c->firstChild()) {
                $txt .= $this->inlineText($c);
            }
        }

        return $txt;
    }

    /**
     * Convenience wrapper for sliceByLines() to maintain semantic clarity at call sites.
     *
     * @param  list<string>  $lines
     */
    private function lineSlice(array $lines, ?int $start, ?int $end): string
    {
        return $this->sliceByLines($lines, $start, $end);
    }

    /**
     * Extracts a range of lines from the source and joins them with newlines.
     * Line numbers are 1-indexed (CommonMark convention), so we adjust for 0-indexed arrays.
     *
     * @param  list<string>  $lines
     */
    private function sliceByLines(array $lines, ?int $start, ?int $end): string
    {
        $s = max(1, (int) $start);
        $e = max($s, (int) $end);
        $slice = array_slice($lines, $s - 1, $e - $s + 1);

        return implode("\n", $slice);
    }

    /**
     * Creates a Position object tracking both byte offsets and line numbers for a block.
     * Returns null if the block has no position info (shouldn't happen with valid CommonMark nodes).
     *
     * @param  list<int>  $lineStarts
     */
    private function pos(AbstractBlock $block, array $lineStarts): ?Position
    {
        $start = $block->getStartLine();
        $end = $block->getEndLine();
        if ($start === null) {
            return null;
        }
        $startByte = $lineStarts[$start - 1] ?? 0;
        $endLine = $end ?? $start;
        $lastIndex = count($lineStarts) - 1;
        $endByte = $lineStarts[$endLine] ?? ($lastIndex >= 0 ? $lineStarts[$lastIndex] : $startByte);

        return new Position(new ByteSpan($startByte, $endByte), new LineSpan($start, $endLine));
    }

    /**
     * Builds a map of byte offsets where each line starts in the source string.
     * Used to convert line numbers to byte positions for accurate position tracking.
     *
     * @return list<int>
     */
    private function computeLineStarts(string $src): array
    {
        $starts = [0];
        $len = strlen($src);
        for ($i = 0; $i < $len; $i++) {
            if ($src[$i] === "\n") {
                $starts[] = $i + 1;
            }
        }
        $starts[] = $len;

        return $starts;
    }
}
