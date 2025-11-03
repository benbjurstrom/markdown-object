<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Model\MarkdownText;

/**
 * Splits text blocks at target boundaries.
 * Strategy: paragraphs → sentences → character fallback
 */
final class TextSplitter
{
    /**
     * @return list<ContentPiece>
     */
    public function split(MarkdownText $node, Tokenizer $tok, int $target, int $hardCap): array
    {
        $raw = $node->raw;
        $pieces = [];

        // Split by paragraphs
        $paras = preg_split("/\n{2,}/", trim($raw)) ?: [$raw];
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            $pTok = $tok->count($p);
            if ($pTok <= $target) {
                $pieces[] = new ContentPiece($p, $pTok);

                continue;
            }

            // Split by sentences
            $sentences = preg_split("/(?<=[.!?])\s+/", $p) ?: [$p];
            $buf = '';
            $sum = 0;

            foreach ($sentences as $s) {
                $sTok = $tok->count($s);
                if ($sTok > $hardCap) {
                    // Fallback: split by characters
                    $pieces = array_merge($pieces, $this->splitByChars($s, $tok, $target, $hardCap));

                    continue;
                }
                if ($sum + $sTok > $target) {
                    if ($buf !== '') {
                        $pieces[] = new ContentPiece(trim($buf), $tok->count($buf));
                    }
                    $buf = $s;
                    $sum = $sTok;
                } else {
                    $buf = $buf === '' ? $s : $buf.' '.$s;
                    $sum += $sTok;
                }
            }
            if (trim($buf) !== '') {
                $pieces[] = new ContentPiece(trim($buf), $tok->count($buf));
            }
        }

        return $pieces ?: [new ContentPiece($raw, $tok->count($raw))];
    }

    /**
     * @return list<ContentPiece>
     */
    private function splitByChars(string $s, Tokenizer $tok, int $target, int $hardCap): array
    {
        $out = [];
        $len = \mb_strlen($s);
        $start = 0;
        while ($start < $len) {
            $low = 1;
            $high = min(2000, $len - $start);
            $best = 1;
            while ($low <= $high) {
                $mid = intdiv($low + $high, 2);
                $sub = \mb_substr($s, $start, $mid);
                $t = $tok->count($sub);
                if ($t <= $target) {
                    $best = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
            $piece = \mb_substr($s, $start, $best);
            $out[] = new ContentPiece($piece, $tok->count($piece));
            $start += $best;
        }

        return $out;
    }
}
