<?php

namespace BenBjurstrom\MarkdownObject\Model;

final class MarkdownCode
{
    public function __construct(public string $bodyRaw, public ?string $info = null, public ?Position $pos = null) {}
}
