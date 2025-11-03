<?php

use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;
use BenBjurstrom\MarkdownObject\Planning\CodeSplitter;
use BenBjurstrom\MarkdownObject\Planning\Section;
use BenBjurstrom\MarkdownObject\Planning\SectionPlanner;
use BenBjurstrom\MarkdownObject\Planning\SplitterRegistry;
use BenBjurstrom\MarkdownObject\Planning\TableSplitter;
use BenBjurstrom\MarkdownObject\Planning\TextSplitter;
use BenBjurstrom\MarkdownObject\Planning\UnitKind;
use BenBjurstrom\MarkdownObject\Planning\UnitPlanner;
use BenBjurstrom\MarkdownObject\Tokenizer\TikTokenizer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Parser\MarkdownParser;

beforeEach(function () {
    $this->env = new Environment;
    $this->env->addExtension(new CommonMarkCoreExtension);
    $this->env->addExtension(new TableExtension);
    $this->parser = new MarkdownParser($this->env);
    $this->builder = new MarkdownObjectBuilder;
    $this->tokenizer = TikTokenizer::forModel('gpt-3.5-turbo-0301');
    $this->planner = new UnitPlanner;

    $this->splitters = new SplitterRegistry(
        text: new TextSplitter,
        code: new CodeSplitter,
        table: new TableSplitter(repeatHeader: true)
    );
});

// Helper to parse markdown and extract first section's blocks
function getSectionBlocks(string $markdown): Section
{
    $env = new Environment;
    $env->addExtension(new CommonMarkCoreExtension);
    $env->addExtension(new TableExtension);
    $parser = new MarkdownParser($env);
    $builder = new MarkdownObjectBuilder;
    $tokenizer = new class implements \BenBjurstrom\MarkdownObject\Contracts\Tokenizer {
        public function count(string $text): int {
            return strlen($text);
        }
    };
    $sectionPlanner = new SectionPlanner;

    $document = $parser->parse($markdown);
    $mdObj = $builder->build($document, 'test.md', $markdown, $tokenizer);
    $sections = $sectionPlanner->plan($mdObj);

    return $sections[0];
}

it('creates single unit for short text paragraph', function () {
    $markdown = 'This is a short paragraph that fits in one unit.';

    $section = getSectionBlocks($markdown);
    $units = $this->planner->planUnits($section, $this->splitters, $this->tokenizer, target: 100, hardCap: 200);

    expect($units)->toHaveCount(1)
        ->and($units[0]->kind)->toBe(UnitKind::Text)
        ->and($units[0]->markdown)->toBe('This is a short paragraph that fits in one unit.')
        ->and($units[0]->tokens)->toBeGreaterThan(0);
});

it('splits long text by sentences when exceeding target', function () {
    $markdown = <<<'MD'
This is the first sentence. This is the second sentence. This is the third sentence. This is the fourth sentence. This is the fifth sentence. This is the sixth sentence.
MD;

    $section = getSectionBlocks($markdown);
    $units = $this->planner->planUnits($section, $this->splitters, $this->tokenizer, target: 20, hardCap: 100);

    // Should split into multiple units (sentences are grouped to reach target)
    expect($units)->toBeGreaterThan(1)
        ->and($units[0]->kind)->toBe(UnitKind::Text);
});

it('wraps code blocks with fences and calculates tokens correctly', function () {
    $markdown = <<<'MD'
```php
function hello() {
    return "world";
}
```
MD;

    $section = getSectionBlocks($markdown);
    $units = $this->planner->planUnits($section, $this->splitters, $this->tokenizer, target: 100, hardCap: 200);

    expect($units)->toHaveCount(1)
        ->and($units[0]->kind)->toBe(UnitKind::Code)
        ->and($units[0]->markdown)->toContain('```php')
        ->and($units[0]->markdown)->toContain('```')
        ->and($units[0]->markdown)->toContain('function hello()')
        ->and($units[0]->tokens)->toBeGreaterThan(0);
});

it('splits large code blocks by line groups', function () {
    $lines = [];
    for ($i = 1; $i <= 50; $i++) {
        $lines[] = "line {$i}";
    }
    $codeBody = implode("\n", $lines);

    $markdown = "```php\n{$codeBody}\n```";

    $section = getSectionBlocks($markdown);
    $units = $this->planner->planUnits($section, $this->splitters, $this->tokenizer, target: 30, hardCap: 100);

    // Should split into multiple units
    expect($units)->toBeGreaterThan(1)
        ->and($units[0]->kind)->toBe(UnitKind::Code)
        ->and($units[0]->markdown)->toContain('```php')
        ->and($units[0]->markdown)->toContain('```');
});

it('handles table splitting with header repetition', function () {
    $markdown = <<<'MD'
| Header 1 | Header 2 |
|----------|----------|
| Row 1    | Data 1   |
| Row 2    | Data 2   |
| Row 3    | Data 3   |
MD;

    $section = getSectionBlocks($markdown);
    $units = $this->planner->planUnits($section, $this->splitters, $this->tokenizer, target: 100, hardCap: 200);

    expect($units)->toHaveCount(1)
        ->and($units[0]->kind)->toBe(UnitKind::Table)
        ->and($units[0]->markdown)->toContain('Header 1')
        ->and($units[0]->markdown)->toContain('Row 1');
});

it('handles image blocks', function () {
    $markdown = '![Alt text](image.jpg)';

    $section = getSectionBlocks($markdown);
    $units = $this->planner->planUnits($section, $this->splitters, $this->tokenizer, target: 100, hardCap: 200);

    expect($units)->toHaveCount(1)
        ->and($units[0]->kind)->toBe(UnitKind::Image)
        ->and($units[0]->markdown)->toBe('![Alt text](image.jpg)')
        ->and($units[0]->tokens)->toBeGreaterThan(0);
});

it('handles mixed block types in one section', function () {
    $markdown = <<<'MD'
# Section

Text paragraph.

```php
code block
```

| Header |
|--------|
| Row    |

![image](img.jpg)
MD;

    $env = new Environment;
    $env->addExtension(new CommonMarkCoreExtension);
    $env->addExtension(new TableExtension);
    $parser = new MarkdownParser($env);
    $builder = new MarkdownObjectBuilder;
    $tokenizer = new class implements \BenBjurstrom\MarkdownObject\Contracts\Tokenizer {
        public function count(string $text): int {
            return strlen($text);
        }
    };
    $sectionPlanner = new SectionPlanner;

    $document = $parser->parse($markdown);
    $mdObj = $builder->build($document, 'test.md', $markdown, $tokenizer);
    $sections = $sectionPlanner->plan($mdObj);

    $units = $this->planner->planUnits($sections[0], $this->splitters, $this->tokenizer, target: 100, hardCap: 200);

    expect($units)->toHaveCount(4)
        ->and($units[0]->kind)->toBe(UnitKind::Text)
        ->and($units[1]->kind)->toBe(UnitKind::Code)
        ->and($units[2]->kind)->toBe(UnitKind::Table)
        ->and($units[3]->kind)->toBe(UnitKind::Image);
});

it('ensures all units have positive token counts', function () {
    $markdown = <<<'MD'
Text paragraph.

```php
code
```

| H |
|---|
| R |
MD;

    $section = getSectionBlocks($markdown);
    $units = $this->planner->planUnits($section, $this->splitters, $this->tokenizer, target: 100, hardCap: 200);

    foreach ($units as $unit) {
        expect($unit->tokens)->toBeGreaterThan(0);
    }
});
