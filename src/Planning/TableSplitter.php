<?php

namespace BenBjurstrom\MarkdownObject\Planning;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Model\MarkdownTable;

final class TableSplitter implements Splitter
{
    public function __construct(private bool $repeatHeader = true) {}

    public function split(object $node, Tokenizer $tok, int $target, int $hardCap): array
    {
        if (! $node instanceof MarkdownTable) {
            return [];
        }
        $lines = preg_split("/\R/", trim($node->raw)) ?: [''];
        if (count($lines) < 3) {
            $md = implode("\n", $lines);

            return [new Unit(UnitKind::Table, $md, $tok->count($md))];
        }

        $hdr = $lines[0];
        $div = $lines[1];
        $rows = array_slice($lines, 2);
        $units = [];
        $buf = $this->repeatHeader ? [$hdr, $div] : [];
        $bufTokens = $tok->count(implode("\n", $buf));

        $emit = function (array $buf) use ($tok): Unit {
            $md = implode("\n", $buf);

            return new Unit(UnitKind::Table, $md, $tok->count($md));
        };

        foreach ($rows as $r) {
            $trial = array_merge($buf, [$r]);
            $trialT = $tok->count(implode("\n", $trial));
            if ($trialT > $target && ! empty($buf)) {
                $units[] = $emit($buf);
                $buf = $this->repeatHeader ? [$hdr, $div, $r] : [$r];
            } else {
                $buf[] = $r;
            }
        }
        if (! empty($buf)) {
            $units[] = $emit($buf);
        }

        // Safety trim if > hardCap (rare)
        $safe = [];
        foreach ($units as $u) {
            if ($u->tokens <= $hardCap) {
                $safe[] = $u;

                continue;
            }
            // coarse split rows in half until under hardCap
            $bufLines = preg_split("/\R/", $u->markdown) ?: [''];
            if (count($bufLines) < 2) {
                $safe[] = $u;

                continue;
            }
            // At this point, bufLines has at least 2 elements
            $h = $bufLines[0];
            $d = $bufLines[1];
            $rs = array_slice($bufLines, 2);
            $chunk = [];
            foreach ($rs as $row) {
                $trial = $this->repeatHeader ? [$h, $d, ...$chunk, $row] : [...$chunk, $row];
                $trialMd = implode("\n", $trial);
                if ($tok->count($trialMd) > $hardCap && ! empty($chunk)) {
                    $safe[] = new Unit(UnitKind::Table, implode("\n", $this->repeatHeader ? [$h, $d, ...$chunk] : $chunk), $tok->count(implode("\n", $this->repeatHeader ? [$h, $d, ...$chunk] : $chunk)));
                    $chunk = [$row];
                } else {
                    $chunk[] = $row;
                }
            }
            if (! empty($chunk)) {
                $safe[] = new Unit(UnitKind::Table, implode("\n", $this->repeatHeader ? [$h, $d, ...$chunk] : $chunk), $tok->count(implode("\n", $this->repeatHeader ? [$h, $d, ...$chunk] : $chunk)));
            }
        }

        return $safe;
    }
}
