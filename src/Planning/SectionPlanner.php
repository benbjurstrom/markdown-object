<?php

namespace BenBjurstrom\MarkdownObject\Planning;

use BenBjurstrom\MarkdownObject\Model\MarkdownCode;
use BenBjurstrom\MarkdownObject\Model\MarkdownHeading;
use BenBjurstrom\MarkdownObject\Model\MarkdownImage;
use BenBjurstrom\MarkdownObject\Model\MarkdownObject;
use BenBjurstrom\MarkdownObject\Model\MarkdownTable;
use BenBjurstrom\MarkdownObject\Model\MarkdownText;

final class SectionPlanner
{
    /** @return list<Section> */
    public function plan(MarkdownObject $doc): array
    {
        $sections = [];

        // 1) preamble: nodes before first heading
        $preamble = [];
        foreach ($doc->children as $node) {
            if ($node instanceof MarkdownHeading) {
                break;
            }
            if ($node instanceof MarkdownText || $node instanceof MarkdownCode || $node instanceof MarkdownImage || $node instanceof MarkdownTable) {
                $preamble[] = $node;
            }
        }
        if ($preamble !== []) {
            $sections[] = new Section([$doc->filename], $preamble, null);
        }

        // 2) headings depth-first → sections
        $i = 0;
        $n = \count($doc->children);
        while ($i < $n) {
            $node = $doc->children[$i];
            if ($node instanceof MarkdownHeading) {
                $this->flattenHeading($doc->filename, $node, [$doc->filename], $sections);
            }
            $i++;
        }

        return $sections;
    }

    /**
     * @param  list<string>  $crumb
     * @param  list<Section>  $out
     */
    private function flattenHeading(string $filename, MarkdownHeading $h, array $crumb, array &$out): void
    {
        $breadcrumb = array_merge($crumb, [$h->text]);

        // Blocks directly under this heading:
        $blocks = [];
        foreach ($h->children as $child) {
            if (! $child instanceof MarkdownHeading) {
                if ($child instanceof MarkdownText || $child instanceof MarkdownCode || $child instanceof MarkdownImage || $child instanceof MarkdownTable) {
                    $blocks[] = $child;
                }
            }
        }
        $out[] = new Section($breadcrumb, $blocks, $h->rawLine);

        // Recurse into sub-headings:
        foreach ($h->children as $child) {
            if ($child instanceof MarkdownHeading) {
                $this->flattenHeading($filename, $child, $breadcrumb, $out);
            }
        }
    }
}
