<?php

use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;
use BenBjurstrom\MarkdownObject\Model\MarkdownHeading;
use BenBjurstrom\MarkdownObject\Model\MarkdownObject;
use BenBjurstrom\MarkdownObject\Planning\Section;
use BenBjurstrom\MarkdownObject\Planning\SectionPlanner;
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
    $this->planner = new SectionPlanner;
});

// Helper to parse markdown into MarkdownObject
function buildMarkdownObject(string $markdown, string $filename = 'test.md'): MarkdownObject
{
    $env = new Environment;
    $env->addExtension(new CommonMarkCoreExtension);
    $env->addExtension(new TableExtension);
    $parser = new MarkdownParser($env);
    $builder = new MarkdownObjectBuilder;

    $document = $parser->parse($markdown);

    return $builder->build($document, $filename, $markdown);
}

it('handles preamble content before first heading', function () {
    $markdown = <<<'MD'
This is preamble text before any headings.

More preamble content.

# First Heading

Content under heading.
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(2)
        ->and($sections[0]->breadcrumb)->toBe(['test.md'])
        ->and($sections[0]->headingRawLine)->toBeNull()
        ->and($sections[0]->blocks)->toHaveCount(2) // Two preamble paragraphs
        ->and($sections[1]->breadcrumb)->toBe(['test.md', 'First Heading'])
        ->and($sections[1]->headingRawLine)->toBe('# First Heading')
        ->and($sections[1]->blocks)->toHaveCount(1); // One paragraph under heading
});

it('handles document with no headings (preamble only)', function () {
    $markdown = <<<'MD'
Just some text.

And another paragraph.
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->breadcrumb)->toBe(['test.md'])
        ->and($sections[0]->headingRawLine)->toBeNull()
        ->and($sections[0]->blocks)->toHaveCount(2);
});

it('handles single-level headings', function () {
    $markdown = <<<'MD'
# Heading 1

Content under H1.

# Heading 2

Content under H2.
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(2)
        ->and($sections[0]->breadcrumb)->toBe(['test.md', 'Heading 1'])
        ->and($sections[0]->blocks)->toHaveCount(1)
        ->and($sections[1]->breadcrumb)->toBe(['test.md', 'Heading 2'])
        ->and($sections[1]->blocks)->toHaveCount(1);
});

it('handles nested headings with correct breadcrumbs', function () {
    $markdown = <<<'MD'
# Chapter 1

Chapter intro.

## Section 1.1

Section content.

### Subsection 1.1.1

Deep content.

## Section 1.2

More content.
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(4)
        ->and($sections[0]->breadcrumb)->toBe(['test.md', 'Chapter 1'])
        ->and($sections[0]->blocks)->toHaveCount(1) // Just "Chapter intro"
        ->and($sections[1]->breadcrumb)->toBe(['test.md', 'Chapter 1', 'Section 1.1'])
        ->and($sections[1]->blocks)->toHaveCount(1) // Just "Section content"
        ->and($sections[2]->breadcrumb)->toBe(['test.md', 'Chapter 1', 'Section 1.1', 'Subsection 1.1.1'])
        ->and($sections[2]->blocks)->toHaveCount(1) // "Deep content"
        ->and($sections[3]->breadcrumb)->toBe(['test.md', 'Chapter 1', 'Section 1.2'])
        ->and($sections[3]->blocks)->toHaveCount(1); // "More content"
});

it('preserves heading formatting in rawLine', function () {
    $markdown = <<<'MD'
# This is **bold** and *italic*

Content.
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->breadcrumb)->toBe(['test.md', 'This is bold and italic']) // Plain text
        ->and($sections[0]->headingRawLine)->toBe('# This is **bold** and *italic*'); // Formatted
});

it('handles heading with no content (empty blocks)', function () {
    $markdown = <<<'MD'
# Empty Heading

# Next Heading

Some content.
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(2)
        ->and($sections[0]->breadcrumb)->toBe(['test.md', 'Empty Heading'])
        ->and($sections[0]->blocks)->toBeEmpty()
        ->and($sections[1]->breadcrumb)->toBe(['test.md', 'Next Heading'])
        ->and($sections[1]->blocks)->toHaveCount(1);
});

it('includes filename in breadcrumb', function () {
    $markdown = "# Heading\n\nContent.";

    $mdObj = buildMarkdownObject($markdown, 'docs/guide.md');
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->breadcrumb)->toBe(['docs/guide.md', 'Heading']);
});

it('handles mixed content types under heading', function () {
    $markdown = <<<'MD'
# Mixed Section

Text paragraph.

```php
code block
```

| Header |
|--------|
| Row    |

![alt](image.jpg)
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->blocks)->toHaveCount(4); // text, code, table, image
});

it('does not include sub-headings in parent section blocks', function () {
    $markdown = <<<'MD'
# Parent

Parent content.

## Child

Child content.
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(2)
        ->and($sections[0]->blocks)->toHaveCount(1) // Only "Parent content"
        ->and($sections[0]->blocks[0])->not->toBeInstanceOf(MarkdownHeading::class)
        ->and($sections[1]->blocks)->toHaveCount(1); // Only "Child content"
});

it('handles multiple top-level headings', function () {
    $markdown = <<<'MD'
# Top 1

Content 1.

# Top 2

Content 2.

# Top 3

Content 3.
MD;

    $mdObj = buildMarkdownObject($markdown);
    $sections = $this->planner->plan($mdObj);

    expect($sections)->toHaveCount(3)
        ->and($sections[0]->breadcrumb)->toBe(['test.md', 'Top 1'])
        ->and($sections[1]->breadcrumb)->toBe(['test.md', 'Top 2'])
        ->and($sections[2]->breadcrumb)->toBe(['test.md', 'Top 3']);
});
