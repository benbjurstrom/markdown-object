<?php

namespace BenBjurstrom\MarkdownObject\Planning;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;

final class UnitPlanner
{
    /** @return list<Unit> */
    public function planUnits(Section $section, SplitterRegistry $splitters, Tokenizer $tok, int $target, int $hardCap): array
    {
        $units = [];
        foreach ($section->blocks as $b) {
            $units = array_merge($units, $splitters->split($b, $tok, $target, $hardCap));
        }

        // assign part indices for blocks that yielded multiple Units (optional)
        // (left simple for now; renderer doesn't need indexes)
        return $units;
    }
}
