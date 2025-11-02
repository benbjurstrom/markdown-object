<?php

namespace BenBjurstrom\MarkdownObject\Planning;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Model\MarkdownText;

final class TextSplitter implements Splitter
{
    public function split(object $node, Tokenizer $tok, int $target, int $hardCap): array
    {
        if (! $node instanceof MarkdownText) {
            return [];
        }
        $raw = $node->raw;
        $units = [];

        // Split by paragraphs
        $paras = preg_split("/\n{2,}/", trim($raw)) ?: [$raw];
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            $pTok = $tok->count($p);
            if ($pTok <= $target) {
                $units[] = new Unit(UnitKind::Text, $p, $pTok);

                continue;
            }

            // Split by sentences
            $sentences = preg_split("/(?<=[.!?])\s+/", $p) ?: [$p];
            $buf = '';
            $sum = 0;

            foreach ($sentences as $s) {
                $sTok = $tok->count($s);
                if ($sTok > $hardCap) {
                    // Fallback: split by characters (grapheme clusters would be better; use mb_substr)
                    $units = array_merge($units, $this->splitByChars($s, $tok, $target, $hardCap));

                    continue;
                }
                if ($sum + $sTok > $target) {
                    if ($buf !== '') {
                        $units[] = new Unit(UnitKind::Text, trim($buf), $tok->count($buf));
                    }
                    $buf = $s;
                    $sum = $sTok;
                } else {
                    $buf = $buf === '' ? $s : $buf.' '.$s;
                    $sum += $sTok;
                }
            }
            if (trim($buf) !== '') {
                $units[] = new Unit(UnitKind::Text, trim($buf), $tok->count($buf));
            }
        }

        return $units ?: [new Unit(UnitKind::Text, $raw, $tok->count($raw))];
    }

    /** @return list<Unit> */
    private function splitByChars(string $s, Tokenizer $tok, int $target, int $hardCap): array
    {
        $out = [];
        $len = \mb_strlen($s);
        $start = 0;
        while ($start < $len) {
            $low = 1;
            $high = min(2000, $len - $start); // step search window
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
            $out[] = new Unit(UnitKind::Text, $piece, $tok->count($piece));
            $start += $best;
        }

        return $out;
    }
}
