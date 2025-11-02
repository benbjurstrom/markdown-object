<?php

namespace BenBjurstrom\MarkdownObject\Render;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Planning\Section;
use BenBjurstrom\MarkdownObject\Planning\Unit;

final class Renderer
{
    public function __construct(private ChunkTemplate $tpl, private Tokenizer $tok) {}

    /**
     * @param  list<Unit>  $units
     * @param  array{start:int,end:int}  $range
     */
    public function renderSectionChunk(Section $s, array $units, array $range, bool $isFirstChunkInSection): EmittedChunk
    {
        $slice = array_slice($units, $range['start'], $range['end'] - $range['start'] + 1);
        $body = implode($this->tpl->joinWith, array_map(fn (Unit $u) => $u->markdown, $slice));

        $heading = ($isFirstChunkInSection && $s->headingRawLine && $this->tpl->headingOnce)
                 ? $s->headingRawLine.$this->tpl->joinWith
                 : '';

        $crumbs = $s->breadcrumb;
        if (! $this->tpl->includeFilename) {
            $crumbs = array_slice($crumbs, 1);
        }
        $crumbLine = $this->tpl->renderBreadcrumb($crumbs);

        $md = ($crumbLine ? $crumbLine."\n\n" : '').$heading.$body;

        return new EmittedChunk(null, $s->breadcrumb, $md, $this->tok->count($md));
    }
}
