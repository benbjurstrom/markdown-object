<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Model\MarkdownTable;

/**
 * Splits table rows at target boundaries.
 * Repeats header in each split piece.
 */
final class TableSplitter
{
    public function __construct(
        private readonly bool $repeatHeader = true
    ) {}

    /**
     * @return list<ContentPiece>
     */
    public function split(MarkdownTable $node, Tokenizer $tok, int $target, int $hardCap): array
    {
        $pos = $node->pos;
        $lines = preg_split("/\R/", trim($node->raw)) ?: [''];
        if (count($lines) < 3) {
            $md = implode("\n", $lines);

            return [new ContentPiece($md, $tok->count($md), $pos)];
        }

        $hdr = $lines[0];
        $div = $lines[1];
        $rows = array_slice($lines, 2);
        $pieces = [];
        $buf = $this->repeatHeader ? [$hdr, $div] : [];

        $emit = function (array $buf) use ($tok, $pos): ContentPiece {
            $md = implode("\n", $buf);

            return new ContentPiece($md, $tok->count($md), $pos);
        };

        foreach ($rows as $r) {
            $trial = array_merge($buf, [$r]);
            $trialT = $tok->count(implode("\n", $trial));
            if ($trialT > $target && ! empty($buf)) {
                $pieces[] = $emit($buf);
                $buf = $this->repeatHeader ? [$hdr, $div, $r] : [$r];
            } else {
                $buf[] = $r;
            }
        }
        if (! empty($buf)) {
            $pieces[] = $emit($buf);
        }

        // Safety trim if > hardCap
        $safe = [];
        foreach ($pieces as $piece) {
            if ($piece->tokens <= $hardCap) {
                $safe[] = $piece;

                continue;
            }
            // Coarse split rows in half until under hardCap
            $bufLines = preg_split("/\R/", $piece->markdown) ?: [''];
            if (count($bufLines) < 2) {
                $safe[] = $piece;

                continue;
            }
            $h = $bufLines[0];
            $d = $bufLines[1];
            $rs = array_slice($bufLines, 2);
            $chunk = [];
            foreach ($rs as $row) {
                $trial = $this->repeatHeader ? [$h, $d, ...$chunk, $row] : [...$chunk, $row];
                $trialMd = implode("\n", $trial);
                if ($tok->count($trialMd) > $hardCap && ! empty($chunk)) {
                    $chunkMd = implode("\n", $this->repeatHeader ? [$h, $d, ...$chunk] : $chunk);
                    $safe[] = new ContentPiece($chunkMd, $tok->count($chunkMd), $pos);
                    $chunk = [$row];
                } else {
                    $chunk[] = $row;
                }
            }
            if (! empty($chunk)) {
                $chunkMd = implode("\n", $this->repeatHeader ? [$h, $d, ...$chunk] : $chunk);
                $safe[] = new ContentPiece($chunkMd, $tok->count($chunkMd), $pos);
            }
        }

        return $safe;
    }
}
