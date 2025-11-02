<?php

use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;
use BenBjurstrom\MarkdownObject\Model\MarkdownCode;
use BenBjurstrom\MarkdownObject\Model\MarkdownHeading;
use BenBjurstrom\MarkdownObject\Model\MarkdownObject;
use BenBjurstrom\MarkdownObject\Model\MarkdownText;
use BenBjurstrom\MarkdownObject\Render\ChunkTemplate;
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
});

it('serializes to JSON with correct structure', function () {
    $markdown = <<<'MD'
# Heading 1

Some text content.

## Heading 2

More text.
MD;

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'test.md', $markdown);

    $json = $mdObj->toJson();

    expect($json)->toBeString()
        ->and($json)->toContain('"schemaVersion":1')
        ->and($json)->toContain('"filename":"test.md"')
        ->and($json)->toContain('"__type"');
});

it('serializes to pretty JSON when flag provided', function () {
    $markdown = '# Heading

Text.';

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'test.md', $markdown);

    $json = $mdObj->toJson(JSON_PRETTY_PRINT);

    expect($json)->toBeString()
        ->and($json)->toContain("{\n")
        ->and($json)->toContain('    ');
});

it('deserializes from JSON correctly', function () {
    $markdown = <<<'MD'
# Heading

Paragraph text.
MD;

    $document = $this->parser->parse($markdown);
    $original = $this->builder->build($document, 'test.md', $markdown);

    $json = $original->toJson();
    $restored = MarkdownObject::fromJson($json);

    expect($restored)->toBeInstanceOf(MarkdownObject::class)
        ->filename->toBe('test.md')
        ->and($restored->children)->toHaveCount(1)
        ->and($restored->children[0])->toBeInstanceOf(MarkdownHeading::class);
});

it('round-trips JSON preserving structure', function () {
    $markdown = <<<'MD'
Preamble text.

# H1

Content under H1.

## H2

Content under H2.
MD;

    $document = $this->parser->parse($markdown);
    $original = $this->builder->build($document, 'doc.md', $markdown);

    $json = $original->toJson();
    $restored = MarkdownObject::fromJson($json);

    // Check root structure
    expect($restored->filename)->toBe('doc.md')
        ->and($restored->children)->toHaveCount(2); // Preamble + H1

    // Check preamble
    expect($restored->children[0])->toBeInstanceOf(MarkdownText::class);

    // Check H1 structure
    $h1 = $restored->children[1];
    expect($h1)->toBeInstanceOf(MarkdownHeading::class)
        ->level->toBe(1)
        ->and($h1->text)->toBe('H1')
        ->and($h1->rawLine)->toBe('# H1')
        ->and($h1->children)->toHaveCount(2); // Text + H2

    // Check H2 structure
    $h2 = $h1->children[1];
    expect($h2)->toBeInstanceOf(MarkdownHeading::class)
        ->level->toBe(2)
        ->and($h2->text)->toBe('H2');
});

it('preserves all node types through JSON round-trip', function () {
    $markdown = <<<'MD'
# Mixed Content

Text paragraph.

```php
code
```

| Col |
|-----|
| Val |
MD;

    $document = $this->parser->parse($markdown);
    $original = $this->builder->build($document, 'test.md', $markdown);

    $json = $original->toJson();
    $restored = MarkdownObject::fromJson($json);

    $h1 = $restored->children[0];
    expect($h1->children)->toHaveCount(3)
        ->and($h1->children[0])->toBeInstanceOf(MarkdownText::class)
        ->and($h1->children[1])->toBeInstanceOf(MarkdownCode::class)
        ->and($h1->children[2])->toBeInstanceOf(\BenBjurstrom\MarkdownObject\Model\MarkdownTable::class);
});

it('preserves position data through JSON round-trip', function () {
    $markdown = <<<'MD'
# Heading

Text.
MD;

    $document = $this->parser->parse($markdown);
    $original = $this->builder->build($document, 'test.md', $markdown);

    $json = $original->toJson();
    $restored = MarkdownObject::fromJson($json);

    $h1 = $restored->children[0];
    expect($h1->pos)->not->toBeNull()
        ->and($h1->pos->bytes)->not->toBeNull()
        ->and($h1->pos->lines)->not->toBeNull()
        ->and($h1->pos->bytes->startByte)->toBeGreaterThanOrEqual(0)
        ->and($h1->pos->lines->startLine)->toBe(1);
});

it('handles empty children array in JSON', function () {
    $markdown = '# Empty Heading';

    $document = $this->parser->parse($markdown);
    $original = $this->builder->build($document, 'test.md', $markdown);

    $json = $original->toJson();
    $restored = MarkdownObject::fromJson($json);

    $h1 = $restored->children[0];
    expect($h1->children)->toBeEmpty();
});

it('generates markdown chunks with default settings', function () {
    $markdown = <<<'MD'
# Introduction

This is a short introduction paragraph.

# Another Section

More content here.
MD;

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'guide.md', $markdown);

    $chunks = $mdObj->toMarkdownChunks();

    expect($chunks)->toBeArray()
        ->and($chunks)->not->toBeEmpty()
        ->and($chunks[0])->toBeInstanceOf(\BenBjurstrom\MarkdownObject\Render\EmittedChunk::class)
        ->and($chunks[0]->id)->not->toBeNull()
        ->and($chunks[0]->tokenCount)->toBeGreaterThan(0)
        ->and($chunks[0]->markdown)->toBeString();
});

it('generates chunks with breadcrumbs', function () {
    $markdown = <<<'MD'
# Main Topic

Content.

## Subtopic

More content.
MD;

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'docs.md', $markdown);

    $chunks = $mdObj->toMarkdownChunks();

    // Find a chunk with breadcrumbs
    $found = false;
    foreach ($chunks as $chunk) {
        if (str_contains($chunk->markdown, '> Path:')) {
            $found = true;
            expect($chunk->markdown)->toContain('docs.md');
            break;
        }
    }

    expect($found)->toBeTrue();
});

it('generates chunks with custom target size', function () {
    $markdown = <<<'MD'
# Section

This is a paragraph with some content that should be chunked.

This is another paragraph with more content to test chunking behavior.

And yet another paragraph to make sure we have enough content for multiple chunks.
MD;

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'test.md', $markdown);

    $chunks = $mdObj->toMarkdownChunks(target: 50, hardCap: 100);

    expect($chunks)->toBeArray()
        ->and($chunks)->not->toBeEmpty();

    // Each chunk should respect the limits
    foreach ($chunks as $chunk) {
        expect($chunk->tokenCount)->toBeLessThanOrEqual(100);
    }
});

it('generates chunks with custom template', function () {
    $markdown = <<<'MD'
# Heading

Content here.
MD;

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'custom.md', $markdown);

    $template = new ChunkTemplate(
        breadcrumbFmt: '### Location: %s',
        breadcrumbJoin: ' > ',
        includeFilename: true,
        headingOnce: true
    );

    $chunks = $mdObj->toMarkdownChunks(tpl: $template);

    expect($chunks)->not->toBeEmpty();
    $firstChunk = $chunks[0];
    expect($firstChunk->markdown)->toContain('### Location:');
});

it('assigns sequential IDs to chunks', function () {
    $markdown = <<<'MD'
# Section 1

Content 1.

# Section 2

Content 2.

# Section 3

Content 3.
MD;

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'test.md', $markdown);

    $chunks = $mdObj->toMarkdownChunks();

    expect($chunks)->not->toBeEmpty();

    // Check IDs are sequential
    for ($i = 0; $i < count($chunks); $i++) {
        expect($chunks[$i]->id)->toBe('c'.($i + 1));
    }
});

it('includes heading in first chunk of section only', function () {
    $markdown = <<<'MD'
# Long Section

First paragraph with some content that needs to be long enough to force chunking into multiple pieces.

Second paragraph with more content that should help create multiple chunks if needed. Adding more words here.

Third paragraph to ensure we have enough content for testing the heading-once behavior with additional text.

Fourth paragraph with additional content for chunking purposes and more words to make it longer still.
MD;

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'test.md', $markdown);

    // Use small target to force multiple chunks
    $chunks = $mdObj->toMarkdownChunks(target: 50, hardCap: 100);

    // Should create multiple chunks
    expect($chunks)->not->toBeEmpty();

    if (count($chunks) > 1) {
        // First chunk should have the heading
        expect($chunks[0]->markdown)->toContain('# Long Section');

        // Second chunk should NOT repeat the heading
        expect($chunks[1]->markdown)->not->toContain('# Long Section');
    } else {
        // If only one chunk, it should still have the heading
        expect($chunks[0]->markdown)->toContain('# Long Section');
    }
});

it('preserves breadcrumb hierarchy in chunks', function () {
    $markdown = <<<'MD'
# Chapter 1

Intro.

## Section 1.1

Details.

### Subsection 1.1.1

Deep content.
MD;

    $document = $this->parser->parse($markdown);
    $mdObj = $this->builder->build($document, 'book.md', $markdown);

    $chunks = $mdObj->toMarkdownChunks();

    // Find deepest chunk
    $deepestChunk = null;
    foreach ($chunks as $chunk) {
        if (count($chunk->breadcrumb) > 3) { // filename + H1 + H2 + H3
            $deepestChunk = $chunk;
            break;
        }
    }

    if ($deepestChunk) {
        expect($deepestChunk->breadcrumb)->toContain('book.md')
            ->and($deepestChunk->breadcrumb)->toContain('Chapter 1')
            ->and($deepestChunk->breadcrumb)->toContain('Section 1.1')
            ->and($deepestChunk->breadcrumb)->toContain('Subsection 1.1.1');
    }
});
