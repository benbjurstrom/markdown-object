<?php

namespace BenBjurstrom\MarkdownObject\Planning;

enum UnitKind: string
{
    case Text = 'text';
    case Code = 'code';
    case Table = 'table';
    case Image = 'image';
}
