<?php

namespace BenBjurstrom\MarkdownObject\Planning;

final readonly class Budget
{
    public function __construct(
        public int $target,
        public int $hardCap,
        public int $earlyThreshold // floor(target*0.9)
    ) {}
}
