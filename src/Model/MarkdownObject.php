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

final class MarkdownObject implements \JsonSerializable
{
    /** @param list<object> $children */
    public function __construct(
        public string $filename,
        public array $children = []
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'schemaVersion' => 1,
            'filename' => $this->filename,
            'children' => array_map([$this, 'serNode'], $this->children),
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
        $obj = new self($data['filename'] ?? '(unknown)');
        $obj->children = self::deSerNodes($data['children'] ?? []);

        return $obj;
    }

    /** @return array<string, mixed> */
    private function serNode(object $n): array
    {
        $base = get_object_vars($n);
        $base['__type'] = $n::class;
        if ($n instanceof MarkdownHeading) {
            $base['children'] = array_map([$this, 'serNode'], $n->children);
        }

        return $base;
    }

    /**
     * @param  array<int, array<string, mixed>>  $arr
     * @return list<object>
     */
    private static function deSerNodes(array $arr): array
    {
        $nodes = [];
        foreach ($arr as $n) {
            $type = $n['__type'] ?? '';
            switch ($type) {
                case MarkdownHeading::class:
                    $h = new MarkdownHeading(
                        $n['level'],
                        $n['text'],
                        $n['rawLine'] ?? null,
                        self::posFromArr($n['pos'] ?? null)
                    );
                    $h->children = self::deSerNodes($n['children'] ?? []);
                    $nodes[] = $h;
                    break;

                case MarkdownText::class:
                    $t = new MarkdownText($n['raw'], self::posFromArr($n['pos'] ?? null));
                    $nodes[] = $t;
                    break;

                case MarkdownCode::class:
                    $c = new MarkdownCode($n['bodyRaw'], $n['info'] ?? null, self::posFromArr($n['pos'] ?? null));
                    $nodes[] = $c;
                    break;

                case MarkdownImage::class:
                    $i = new MarkdownImage($n['alt'], $n['src'], $n['title'] ?? null, $n['raw'], self::posFromArr($n['pos'] ?? null));
                    $nodes[] = $i;
                    break;

                case MarkdownTable::class:
                    $tb = new MarkdownTable($n['raw'], self::posFromArr($n['pos'] ?? null));
                    $nodes[] = $tb;
                    break;
            }
        }

        return $nodes;
    }

    /** @param array<string, mixed>|null $a */
    private static function posFromArr(?array $a): ?Position
    {
        if (! $a) {
            return null;
        }

        return new Position(
            new ByteSpan($a['bytes']['startByte'], $a['bytes']['endByte']),
            isset($a['lines']) ? new LineSpan($a['lines']['startLine'], $a['lines']['endLine']) : null
        );
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
