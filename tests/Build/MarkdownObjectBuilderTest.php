<?php

use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;
use BenBjurstrom\MarkdownObject\Model\MarkdownCode;
use BenBjurstrom\MarkdownObject\Model\MarkdownHeading;
use BenBjurstrom\MarkdownObject\Model\MarkdownImage;
use BenBjurstrom\MarkdownObject\Model\MarkdownObject;
use BenBjurstrom\MarkdownObject\Model\MarkdownTable;
use BenBjurstrom\MarkdownObject\Model\MarkdownText;
use BenBjurstrom\MarkdownObject\Model\Position;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\MarkdownParser;

beforeEach(function () {
    $this->env = new Environment;
    $this->env->addExtension(new CommonMarkCoreExtension);
    $this->env->addExtension(new TableExtension);
    $this->parser = new MarkdownParser($this->env);
    $this->builder = new MarkdownObjectBuilder;
});

it('builds a simple document with just text', closure: function () {
    $markdown = "This is a simple paragraph.\n\nThis is another paragraph.";
    $document = $this->parser->parse($markdown);

    $result = $this->builder->build($document, 'test.md', $markdown);
    // dd($result->toJson(JSON_PRETTY_PRINT));

    expect($result)
        ->toBeInstanceOf(MarkdownObject::class)
        ->filename->toBe('test.md')
        ->and($result->children)->toHaveCount(2)
        ->and($result->children[0])->toBeInstanceOf(MarkdownText::class)
        ->and($result->children[1])->toBeInstanceOf(MarkdownText::class);
});

it('builds a document with preamble and headings', function () {
    $markdown = <<<'MD'
This is preamble text before any headings.

More preamble content.

# Heading 1

Content under H1.

## Heading 2

Content under H2.
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    // Should have 2 preamble text blocks + 1 H1 heading
    expect($result->children)->toHaveCount(3)
        ->and($result->children[0])->toBeInstanceOf(MarkdownText::class)
        ->and($result->children[1])->toBeInstanceOf(MarkdownText::class)
        ->and($result->children[2])->toBeInstanceOf(MarkdownHeading::class);

    $h1 = $result->children[2];
    expect($h1->level)->toBe(1)
        ->and($h1->text)->toBe('Heading 1')
        ->and($h1->children)->toHaveCount(2); // MarkdownText + MarkdownHeading(H2)

    // H1 should have text content and H2 as children
    expect($h1->children[0])->toBeInstanceOf(MarkdownText::class);
    expect($h1->children[1])->toBeInstanceOf(MarkdownHeading::class);

    $h2 = $h1->children[1];
    expect($h2->level)->toBe(2)
        ->and($h2->text)->toBe('Heading 2')
        ->and($h2->children)->toHaveCount(1);
});

it('correctly nests heading hierarchy', function () {
    $markdown = <<<'MD'
# H1

H1 content.

## H2 under H1

H2 content.

### H3 under H2

H3 content.

## Another H2

More H2 content.

# Second H1

Second H1 content.
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    // Root should have 2 H1 headings
    expect($result->children)->toHaveCount(2);

    $firstH1 = $result->children[0];
    expect($firstH1)->toBeInstanceOf(MarkdownHeading::class)
        ->level->toBe(1)
        ->and($firstH1->text)->toBe('H1');

    // First H1 should have: text + H2 + H2
    expect($firstH1->children)->toHaveCount(3);
    expect($firstH1->children[0])->toBeInstanceOf(MarkdownText::class);
    expect($firstH1->children[1])->toBeInstanceOf(MarkdownHeading::class)->level->toBe(2);
    expect($firstH1->children[2])->toBeInstanceOf(MarkdownHeading::class)->level->toBe(2);

    // First H2 should have text + H3
    $firstH2 = $firstH1->children[1];
    expect($firstH2->children)->toHaveCount(2);
    expect($firstH2->children[0])->toBeInstanceOf(MarkdownText::class);
    expect($firstH2->children[1])->toBeInstanceOf(MarkdownHeading::class)->level->toBe(3);

    // Second H1
    $secondH1 = $result->children[1];
    expect($secondH1)->toBeInstanceOf(MarkdownHeading::class)
        ->level->toBe(1)
        ->and($secondH1->text)->toBe('Second H1')
        ->and($secondH1->children)->toHaveCount(1);
});

it('builds fenced code blocks', function () {
    $markdown = <<<'MD'
# Code Example

```php
function hello() {
    return "world";
}
```
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    $h1 = $result->children[0];
    expect($h1->children)->toHaveCount(1);

    $code = $h1->children[0];
    expect($code)->toBeInstanceOf(MarkdownCode::class)
        ->bodyRaw->toContain('function hello()')
        ->and($code->info)->toBe('php');
});

it('builds indented code blocks', function () {
    $markdown = <<<'MD'
# Example

Regular text.

    indented code
    more code
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    $h1 = $result->children[0];
    expect($h1->children)->toHaveCount(2);

    $code = $h1->children[1];
    expect($code)->toBeInstanceOf(MarkdownCode::class)
        ->bodyRaw->toContain('indented code')
        ->and($code->info)->toBeNull();
});

it('builds tables', function () {
    $markdown = <<<'MD'
# Table Example

| Header 1 | Header 2 |
|----------|----------|
| Cell 1   | Cell 2   |
| Cell 3   | Cell 4   |
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    $h1 = $result->children[0];
    expect($h1->children)->toHaveCount(1);

    $table = $h1->children[0];
    expect($table)->toBeInstanceOf(MarkdownTable::class)
        ->raw->toContain('Header 1')
        ->and($table->raw)->toContain('Cell 1');
});

it('extracts image-only paragraphs as MarkdownImage', function () {
    $markdown = <<<'MD'
# Images

![Alt text](image.jpg "Title")

This is text with ![inline](inline.jpg) image.
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    $h1 = $result->children[0];
    expect($h1->children)->toHaveCount(2);

    // First should be image-only paragraph (MarkdownImage)
    $image = $h1->children[0];
    expect($image)->toBeInstanceOf(MarkdownImage::class)
        ->alt->toBe('Alt text')
        ->and($image->src)->toBe('image.jpg')
        ->and($image->title)->toBe('Title');

    // Second should be text with inline image (MarkdownText)
    expect($h1->children[1])->toBeInstanceOf(MarkdownText::class);
});

it('uses the next line start when a block lacks an end line', function () {
    $ref = new ReflectionClass(MarkdownObjectBuilder::class);
    $compute = $ref->getMethod('computeLineStarts');
    $compute->setAccessible(true);
    $lineStarts = $compute->invoke($this->builder, "foo\nbar\nbaz");

    $posMethod = $ref->getMethod('pos');
    $posMethod->setAccessible(true);

    $block = new class extends AbstractBlock
    {
        public function getStartLine(): int
        {
            return 2;
        }

        public function getEndLine(): ?int
        {
            return null;
        }
    };

    /** @var Position $position */
    $position = $posMethod->invoke($this->builder, $block, $lineStarts);

    expect($position->bytes->startByte)->toBe($lineStarts[1])
        ->and($position->bytes->endByte)->toBe($lineStarts[2])
        ->and($position->lines->endLine)->toBe(2);
});

it('preserves heading raw line', function () {
    $markdown = <<<'MD'
# Main Heading

Content here.
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    $h1 = $result->children[0];
    expect($h1)->toBeInstanceOf(MarkdownHeading::class)
        ->rawLine->toBe('# Main Heading');
});

it('tracks positions for blocks', function () {
    $markdown = <<<'MD'
# Heading

Paragraph text.
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    $h1 = $result->children[0];
    expect($h1->pos)->not->toBeNull()
        ->and($h1->pos->bytes)->not->toBeNull()
        ->and($h1->pos->lines)->not->toBeNull()
        ->and($h1->pos->bytes->startByte)->toBeGreaterThanOrEqual(0)
        ->and($h1->pos->lines->startLine)->toBe(1);

    $text = $h1->children[0];
    expect($text->pos)->not->toBeNull()
        ->and($text->pos->lines->startLine)->toBe(3);
});

it('handles mixed content under headings', function () {
    $markdown = <<<'MD'
# Mixed Content

Some text.

```js
const x = 1;
```

| Col 1 | Col 2 |
|-------|-------|
| A     | B     |

More text.

![Image](pic.jpg)
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    $h1 = $result->children[0];
    expect($h1->children)->toHaveCount(5);

    expect($h1->children[0])->toBeInstanceOf(MarkdownText::class);
    expect($h1->children[1])->toBeInstanceOf(MarkdownCode::class);
    expect($h1->children[2])->toBeInstanceOf(MarkdownTable::class);
    expect($h1->children[3])->toBeInstanceOf(MarkdownText::class);
    expect($h1->children[4])->toBeInstanceOf(MarkdownImage::class);
});

it('handles empty document', function () {
    $markdown = '';
    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    expect($result)->toBeInstanceOf(MarkdownObject::class)
        ->filename->toBe('test.md')
        ->and($result->children)->toBeEmpty();
});

it('handles document with only headings (no content)', function () {
    $markdown = <<<'MD'
# Heading 1
## Heading 2
### Heading 3
MD;

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    expect($result->children)->toHaveCount(1);
    $h1 = $result->children[0];
    expect($h1)->toBeInstanceOf(MarkdownHeading::class)
        ->children->toHaveCount(1);

    $h2 = $h1->children[0];
    expect($h2)->toBeInstanceOf(MarkdownHeading::class)
        ->children->toHaveCount(1);

    $h3 = $h2->children[0];
    expect($h3)->toBeInstanceOf(MarkdownHeading::class)
        ->children->toBeEmpty();
});

it('extracts heading text correctly with inline formatting', function () {
    $markdown = '# This is **bold** and *italic* text';

    $document = $this->parser->parse($markdown);
    $result = $this->builder->build($document, 'test.md', $markdown);

    $h1 = $result->children[0];
    expect($h1->text)->toBe('This is bold and italic text');
});
