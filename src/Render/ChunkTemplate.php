<?php

namespace BenBjurstrom\MarkdownObject\Render;

final class ChunkTemplate
{
    public function __construct(
        public ?string $breadcrumbFmt = '> Path: %s',
        public string $breadcrumbJoin = ' › ',
        public bool $includeFilename = true,
        public bool $headingOnce = true,
        public string $joinWith = "\n\n",
        public bool $repeatTableHeaderOnSplit = true
    ) {}

    public static function default(): self
    {
        return new self;
    }

    /** @param list<string> $crumbs */
    public function renderBreadcrumb(array $crumbs): ?string
    {
        if ($this->breadcrumbFmt === null) {
            return null;
        }

        return sprintf($this->breadcrumbFmt, implode($this->breadcrumbJoin, $crumbs));
    }
}
