<?php

namespace BenBjurstrom\MarkdownObject\Chunking;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Model\MarkdownCode;

/**
 * Splits code blocks at target boundaries.
 * Adds fence wrappers and groups lines.
 */
final class CodeSplitter
{
    /**
     * @return list<ContentPiece>
     */
    public function split(MarkdownCode $node, Tokenizer $tok, int $target, int $hardCap): array
    {
        $info = $node->info ?? '';
        $lines = preg_split("/\R/", $node->bodyRaw) ?: [''];
        $pieces = [];
        $buf = [];
        $currentPiece = null;

        $wrap = function (array $lines) use ($tok, $info): ContentPiece {
            $body = implode("\n", $lines);
            $md = "```{$info}\n".rtrim($body)."\n```";

            return new ContentPiece($md, $tok->count($md));
        };

        foreach ($lines as $ln) {
            $candidateLines = [...$buf, $ln];
            $candidatePiece = $wrap($candidateLines);
            if ($candidatePiece->tokens > $target && $buf !== []) {
                $pieces[] = $currentPiece;
                $buf = [$ln];
                $currentPiece = $wrap($buf);

                continue;
            }

            $buf = $candidateLines;
            $currentPiece = $candidatePiece;
        }
        $pieces[] = $currentPiece;

        // Safety: ensure no piece exceeds hardCap
        $result = [];
        foreach ($pieces as $piece) {
            if ($piece->tokens <= $hardCap) {
                $result[] = $piece;

                continue;
            }
            // Hard split by lines even if it means many parts
            $body = preg_replace('/^```[^\n]*\n|\n```$/', '', $piece->markdown);
            $splitLines = preg_split("/\R/", $body ?? '') ?: [''];
            foreach ($splitLines as $single) {
                $md = "```{$info}\n".rtrim($single)."\n```";
                $result[] = new ContentPiece($md, $tok->count($md));
            }
        }

        return $result;
    }
}
