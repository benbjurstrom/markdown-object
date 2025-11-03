<?php

namespace Tests\Chunking;

use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;
use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Parser\MarkdownParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for hierarchical chunking based on EXAMPLES.md
 */
final class HierarchicalChunkerTest extends TestCase
{
    private MarkdownParser $parser;

    private MarkdownObjectBuilder $builder;

    private Tokenizer $tokenizer;

    protected function setUp(): void
    {
        $env = new Environment;
        $env->addExtension(new CommonMarkCoreExtension);
        $env->addExtension(new TableExtension);
        $this->parser = new MarkdownParser($env);
        $this->builder = new MarkdownObjectBuilder;

        // Mock tokenizer that counts based on placeholder format {N tokens}
        $this->tokenizer = new class implements Tokenizer
        {
            public function count(string $text): int
            {
                // Extract {N tokens} placeholders and sum them
                preg_match_all('/\{(\d+)\s+tokens?\}/', $text, $matches);
                $total = array_sum(array_map('intval', $matches[1]));

                // Also count actual content (rough estimate: 1 token per 4 chars)
                $stripped = preg_replace('/\{(\d+)\s+tokens?\}/', '', $text);
                $contentTokens = max(1, (int) ceil(strlen(trim($stripped)) / 4));

                return max($total, $contentTokens);
            }

            public function encode(string $text): array
            {
                return [];
            }
        };
    }

    /** Example 1: Small file (everything fits) */
    public function test_small_file_everything_fits(): void
    {
        $markdown = <<<'MD'
# Introduction
This is a small document.

## Section 1
Some content here.

### Subsection 1.1
More content.

## Section 2
Final content.
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // When there's a top-level heading and everything fits, breadcrumb includes that heading
        $this->assertCount(1, $chunks);
        $this->assertEquals(['filename.md', 'Introduction'], $chunks[0]->breadcrumb);
        $this->assertStringContainsString('# Introduction', $chunks[0]->markdown);
        $this->assertStringContainsString('## Section 2', $chunks[0]->markdown);
    }

    /** Example 8: Preamble handling */
    public function test_preamble_handling(): void
    {
        $markdown = <<<'MD'
This is preamble content before any headings.
{200 tokens}

# First Heading
{300 tokens}

## Subheading
{400 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        $this->assertCount(2, $chunks);

        // Chunk 1: Preamble with filename breadcrumb
        $this->assertEquals(['filename.md'], $chunks[0]->breadcrumb);
        $this->assertStringContainsString('preamble content', $chunks[0]->markdown);
        $this->assertStringNotContainsString('# First Heading', $chunks[0]->markdown);

        // Chunk 2: First Heading + Subheading
        $this->assertEquals(['filename.md', 'First Heading'], $chunks[1]->breadcrumb);
        $this->assertStringContainsString('# First Heading', $chunks[1]->markdown);
        $this->assertStringContainsString('## Subheading', $chunks[1]->markdown);
    }

    /** Example 10: Target vs HardCap - use hardCap for hierarchy */
    public function test_target_vs_hard_cap_uses_hard_cap_for_hierarchy(): void
    {
        $markdown = <<<'MD'
## Heading
{100 tokens}

### Sub 1
{450 tokens}

### Sub 2
{450 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // Total is 1000 tokens which is under hardCap (1024), so everything fits in one chunk
        $this->assertCount(1, $chunks);
        $this->assertEquals(['filename.md', 'Heading'], $chunks[0]->breadcrumb);
        $this->assertStringContainsString('### Sub 1', $chunks[0]->markdown);
        $this->assertStringContainsString('### Sub 2', $chunks[0]->markdown);
    }

    /** Test greedy packing with multiple children */
    public function test_greedy_packing_multiple_children(): void
    {
        $markdown = <<<'MD'
## Section A
{400 tokens}

### Subsection A.1
{300 tokens}

## Section B
{500 tokens}

### Subsection B.1
{300 tokens}

### Subsection B.2
{400 tokens}

### Subsection B.3
{300 tokens}

## Section C
{200 tokens}

### Subsection C.1
{100 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // Section A (700 total) -> 1 chunk
        // Section B (1500 total > hardCap) -> must split greedily
        //   - B + B.1 = 800 -> 1 chunk
        //   - B.2 + B.3 = 700 -> 1 chunk
        // Section C (300 total) -> 1 chunk
        $this->assertCount(4, $chunks);

        // Verify breadcrumbs
        $this->assertEquals(['filename.md', 'Section A'], $chunks[0]->breadcrumb);
        $this->assertEquals(['filename.md', 'Section B'], $chunks[1]->breadcrumb);
        $this->assertEquals(['filename.md', 'Section B', 'Subsection B.2'], $chunks[2]->breadcrumb);
        $this->assertEquals(['filename.md', 'Section C'], $chunks[3]->breadcrumb);
    }

    /** Test deep nesting all fits */
    public function test_deep_nesting_all_fits(): void
    {
        $markdown = <<<'MD'
# H1
{100 tokens}

## H2
{100 tokens}

### H3
{100 tokens}

#### H4
{100 tokens}

##### H5
{100 tokens}

###### H6
{100 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // All 600 tokens fit under hardCap
        $this->assertCount(1, $chunks);
        $this->assertEquals(['filename.md', 'H1'], $chunks[0]->breadcrumb);
        $this->assertStringContainsString('###### H6', $chunks[0]->markdown);
    }

    /** Test when parent has no direct content */
    public function test_empty_parent_content_fits_under_hard_cap(): void
    {
        $markdown = <<<'MD'
## Parent Heading

### Child 1
{300 tokens}

### Child 2
{300 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // Total ~600 tokens fits under hardCap
        $this->assertCount(1, $chunks);
        $this->assertEquals(['filename.md', 'Parent Heading'], $chunks[0]->breadcrumb);
        $this->assertStringContainsString('## Parent Heading', $chunks[0]->markdown);
        $this->assertStringContainsString('### Child 1', $chunks[0]->markdown);
        $this->assertStringContainsString('### Child 2', $chunks[0]->markdown);
    }

    /** Test when parent has no direct content but must split */
    public function test_empty_parent_content_must_split(): void
    {
        $markdown = <<<'MD'
## Parent Heading

### Child 1
{300 tokens}

### Child 2
{800 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // Total ~1100 exceeds hardCap, must split
        $this->assertCount(2, $chunks);

        // Chunk 1: Parent + Child 1 (fits under hardCap)
        $this->assertEquals(['filename.md', 'Parent Heading'], $chunks[0]->breadcrumb);
        $this->assertStringContainsString('### Child 1', $chunks[0]->markdown);
        $this->assertStringNotContainsString('### Child 2', $chunks[0]->markdown);

        // Chunk 2: Child 2 alone with deeper breadcrumb
        $this->assertEquals(['filename.md', 'Parent Heading', 'Child 2'], $chunks[1]->breadcrumb);
        $this->assertStringContainsString('### Child 2', $chunks[1]->markdown);
    }

    /** Test no H1 headings - H2s as top level */
    public function test_no_h1_headings_h2s_as_top_level(): void
    {
        $markdown = <<<'MD'
## Introduction
{300 tokens}

### Background
{400 tokens}

### Motivation
{200 tokens}

## Methods
{500 tokens}

### Approach 1
{400 tokens}

### Approach 2
{600 tokens}

## Conclusion
{200 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // Introduction (900) -> fits
        // Methods (1500) -> must split: Methods+A1 (900), A2 (600)
        // Conclusion (200) -> fits
        $this->assertCount(4, $chunks);

        $this->assertEquals(['filename.md', 'Introduction'], $chunks[0]->breadcrumb);
        $this->assertEquals(['filename.md', 'Methods'], $chunks[1]->breadcrumb);
        $this->assertEquals(['filename.md', 'Methods', 'Approach 2'], $chunks[2]->breadcrumb);
        $this->assertEquals(['filename.md', 'Conclusion'], $chunks[3]->breadcrumb);
    }

    /** Test chunk IDs are assigned */
    public function test_chunk_i_ds_assigned(): void
    {
        $markdown = <<<'MD'
# Heading 1
{600 tokens}

# Heading 2
{600 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        $this->assertCount(2, $chunks);
        $this->assertEquals('c1', $chunks[0]->id);
        $this->assertEquals('c2', $chunks[1]->id);
    }

    /** Test when heading itself exceeds hardCap and must split children */
    public function test_heading_exceeds_hard_cap_must_split_children(): void
    {
        $markdown = <<<'MD'
# Chapter 1
{200 tokens}

## Section 1.1
{900 tokens}

### Subsection 1.1.1
{400 tokens}

### Subsection 1.1.2
{400 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // Chapter 1 + Section 1.1 = 1100 > hardCap
        // So Chapter 1 alone becomes chunk
        // Section 1.1 (900) also > hardCap, must split its children
        $this->assertCount(3, $chunks);

        // Chunk 1: Just Chapter 1 heading
        $this->assertEquals(['filename.md', 'Chapter 1'], $chunks[0]->breadcrumb);
        $this->assertStringContainsString('# Chapter 1', $chunks[0]->markdown);
        $this->assertStringNotContainsString('## Section', $chunks[0]->markdown);

        // Chunk 2 & 3: The subsections
        $this->assertEquals(['filename.md', 'Chapter 1', 'Section 1.1', 'Subsection 1.1.1'], $chunks[1]->breadcrumb);
        $this->assertEquals(['filename.md', 'Chapter 1', 'Section 1.1', 'Subsection 1.1.2'], $chunks[2]->breadcrumb);
    }

    /** Test mixed content (headings at different levels) */
    public function test_mixed_h1s_with_varying_sizes(): void
    {
        $markdown = <<<'MD'
# Small Chapter
{100 tokens}

# Medium Chapter
{500 tokens}

## Section 1
{300 tokens}

# Large Chapter
{600 tokens}

## Section A
{400 tokens}

### Subsection A1
{300 tokens}
MD;

        $doc = $this->parser->parse($markdown);
        $mdObj = $this->builder->build($doc, 'filename.md', $markdown, $this->tokenizer);
        $chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024, tok: $this->tokenizer);

        // Small: 100 -> 1 chunk
        // Medium: 800 -> 1 chunk
        // Large: 1300 > hardCap -> Large+SectionA = 1000, SubsectionA1 = 300
        $this->assertCount(4, $chunks);

        $this->assertEquals(['filename.md', 'Small Chapter'], $chunks[0]->breadcrumb);
        $this->assertEquals(['filename.md', 'Medium Chapter'], $chunks[1]->breadcrumb);
        $this->assertEquals(['filename.md', 'Large Chapter'], $chunks[2]->breadcrumb);
        $this->assertEquals(['filename.md', 'Large Chapter', 'Section A', 'Subsection A1'], $chunks[3]->breadcrumb);
    }
}
