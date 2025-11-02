<?php

namespace BenBjurstrom\MarkdownObject\Model;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Planning\CodeSplitter;
use BenBjurstrom\MarkdownObject\Planning\Packer;
use BenBjurstrom\MarkdownObject\Planning\SectionPlanner;
use BenBjurstrom\MarkdownObject\Planning\SplitterRegistry;
use BenBjurstrom\MarkdownObject\Planning\TableSplitter;
use BenBjurstrom\MarkdownObject\Planning\TextSplitter;
use BenBjurstrom\MarkdownObject\Planning\UnitPlanner;
use BenBjurstrom\MarkdownObject\Render\ChunkTemplate;
use BenBjurstrom\MarkdownObject\Render\Renderer;
use BenBjurstrom\MarkdownObject\Tokenizer\TikTokenizer;
use InvalidArgumentException;

final class MarkdownObject implements \JsonSerializable
{
    /** @param list<MarkdownNode> $children */
    public function __construct(
        public string $filename,
        public array $children = []
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'schemaVersion' => 1,
            'filename' => $this->filename,
            'children' => array_map(
                static function (MarkdownNode $child): array {
                    return $child->serialize();
                },
                $this->children
            ),
        ];
    }

    public function toJson(int $flags = 0): string
    {
        $json = json_encode($this, $flags | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MarkdownObject to JSON');
        }

        return $json;
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Decoded markdown object must be an array.');
        }

        $filenameValue = $data['filename'] ?? null;
        $filename = is_string($filenameValue) ? $filenameValue : '(unknown)';

        $childrenValue = $data['children'] ?? null;
        if ($childrenValue !== null && ! is_array($childrenValue)) {
            throw new InvalidArgumentException('Markdown object children must be an array when provided.');
        }

        /** @var array<int|string, mixed> $childrenArray */
        $childrenArray = is_array($childrenValue) ? $childrenValue : [];

        $obj = new self($filename);
        $obj->children = self::deserializeNodes($childrenArray);

        return $obj;
    }

    /**
     * @param  array<int|string, mixed>  $arr
     * @return list<MarkdownNode>
     */
    private static function deserializeNodes(array $arr): array
    {
        $nodes = [];
        foreach ($arr as $nodePayload) {
            if (! is_array($nodePayload)) {
                throw new InvalidArgumentException('Markdown node payload must be an array.');
            }

            MarkdownNode::assertStringKeys($nodePayload, 'Markdown node payload');

            /** @var array<string, mixed> $node */
            $node = $nodePayload;
            $nodes[] = MarkdownNode::hydrate($node);
        }

        return $nodes;
    }

    /** @return list<\BenBjurstrom\MarkdownObject\Render\EmittedChunk> */
    public function toMarkdownChunks(
        int $target = 512,
        int $hardCap = 1024,
        ?ChunkTemplate $tpl = null,
        ?Tokenizer $tok = null,
        ?SplitterRegistry $splitters = null
    ): array {
        $tpl ??= ChunkTemplate::default();
        $tok ??= TikTokenizer::forModel('gpt-3.5-turbo-0301');
        $splitters ??= new SplitterRegistry(
            new TextSplitter,
            new CodeSplitter,
            new TableSplitter($tpl->repeatTableHeaderOnSplit)
        );

        $sections = (new SectionPlanner)->plan($this);
        $renderer = new Renderer($tpl, $tok);
        $unitPlanner = new UnitPlanner;
        $packer = new Packer;

        $chunks = [];

        foreach ($sections as $section) {
            // Budget reduced by breadcrumb tokens
            $crumbs = $section->breadcrumb;
            if (! $tpl->includeFilename) {
                $crumbs = array_slice($crumbs, 1);
            }
            $crumbLine = $tpl->renderBreadcrumb($crumbs);
            $crumbTokens = $crumbLine ? $tok->count($crumbLine."\n\n") : 0;

            $budgetTarget = max(1, $target - $crumbTokens);
            $budgetHard = max(1, $hardCap - $crumbTokens);
            $budget = new \BenBjurstrom\MarkdownObject\Planning\Budget($budgetTarget, $budgetHard, (int) floor($budgetTarget * 0.90));

            $units = $unitPlanner->planUnits($section, $splitters, $tok, $budgetTarget, $budgetHard);
            $ranges = $packer->pack($units, $budget, true);

            foreach ($ranges as $k => $r) {
                $chunks[] = $renderer->renderSectionChunk($section, $units, $r, $k === 0);
            }
        }

        foreach ($chunks as $i => $c) {
            $c->id = 'c'.($i + 1);
        }

        return $chunks;
    }
}
