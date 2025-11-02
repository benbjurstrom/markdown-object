<?php

namespace BenBjurstrom\MarkdownObject\Planning;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Model\MarkdownCode;

final class CodeSplitter implements Splitter
{
    public function split(object $node, Tokenizer $tok, int $target, int $hardCap): array
    {
        if (! $node instanceof MarkdownCode) {
            return [];
        }
        $info = $node->info ?? '';
        $lines = preg_split("/\R/", $node->bodyRaw) ?: [''];
        $units = [];
        $buf = [];
        $sum = 0;

        $wrap = function (array $lines) use ($tok, $info): Unit {
            $body = implode("\n", $lines);
            $md = "```{$info}\n".rtrim($body)."\n```";

            return new Unit(UnitKind::Code, $md, $tok->count($md));
        };

        foreach ($lines as $ln) {
            $trial = $wrap(array_merge($buf, [$ln]));
            if ($sum + $trial->tokens > $target && ! empty($buf)) {
                $units[] = $wrap($buf);
                $buf = [$ln];
                $sum = $wrap($buf)->tokens;
            } else {
                $buf[] = $ln;
                $sum = $trial->tokens;
            }
        }
        // $buf will always have at least one element after the loop
        $units[] = $wrap($buf);
        // Safety: ensure no unit exceeds $hardCap (rare, unless a single line is huge)
        $result = [];
        foreach ($units as $u) {
            if ($u->tokens <= $hardCap) {
                $result[] = $u;

                continue;
            }
            // hard split by lines even if it means many parts
            $body = preg_replace('/^```[^\n]*\n|\n```$/', '', $u->markdown);
            $splitLines = preg_split("/\R/", $body) ?: [''];
            foreach ($splitLines as $single) {
                $md = "```{$info}\n".rtrim($single)."\n```";
                $result[] = new Unit(UnitKind::Code, $md, $tok->count($md));
            }
        }

        return $result;
    }
}
