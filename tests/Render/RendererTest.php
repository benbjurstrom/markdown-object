<?php

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Planning\Section;
use BenBjurstrom\MarkdownObject\Planning\Unit;
use BenBjurstrom\MarkdownObject\Planning\UnitKind;
use BenBjurstrom\MarkdownObject\Render\ChunkTemplate;
use BenBjurstrom\MarkdownObject\Render\Renderer;

// Helper to create a simple tokenizer mock
function mockTokenizer(): Tokenizer
{
    return new class implements Tokenizer
    {
        public function count(string $text): int
        {
            // Simple mock: 1 token per word
            return count(array_filter(preg_split('/\s+/', $text)));
        }
    };
}

// Helper to create test Units
function makeTestUnit(string $markdown, int $tokens): Unit
{
    return new Unit(
        kind: UnitKind::Text,
        markdown: $markdown,
        tokens: $tokens
    );
}

// Helper to create test Section
function makeSection(array $breadcrumb, ?string $headingRawLine = null): Section
{
    return new Section(
        breadcrumb: $breadcrumb,
        blocks: [], // Not used in rendering
        headingRawLine: $headingRawLine
    );
}

describe('Breadcrumb rendering', function () {
    it('renders breadcrumb with default format', function () {
        $tpl = new ChunkTemplate;
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1', 'Section 1.1']);
        $units = [makeTestUnit('Some content', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        expect($chunk->markdown)->toContain('> Path: docs.md › Chapter 1 › Section 1.1');
    });

    it('renders breadcrumb with custom format', function () {
        $tpl = new ChunkTemplate(breadcrumbFmt: '### Location: %s');
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1']);
        $units = [makeTestUnit('Some content', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        expect($chunk->markdown)->toContain('### Location: docs.md › Chapter 1');
    });

    it('renders breadcrumb with custom separator', function () {
        $tpl = new ChunkTemplate(breadcrumbJoin: ' > ');
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1', 'Section 1.1']);
        $units = [makeTestUnit('Some content', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        expect($chunk->markdown)->toContain('docs.md > Chapter 1 > Section 1.1');
    });

    it('excludes filename when includeFilename is false', function () {
        $tpl = new ChunkTemplate(includeFilename: false);
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1', 'Section 1.1']);
        $units = [makeTestUnit('Some content', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        expect($chunk->markdown)->toContain('> Path: Chapter 1 › Section 1.1')
            ->and($chunk->markdown)->not->toContain('docs.md');
    });

    it('omits breadcrumb when breadcrumbFmt is null', function () {
        $tpl = new ChunkTemplate(breadcrumbFmt: null);
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1']);
        $units = [makeTestUnit('Some content', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        expect($chunk->markdown)->not->toContain('Path:')
            ->and($chunk->markdown)->toBe('Some content');
    });
});

describe('Heading inclusion', function () {
    it('includes heading in first chunk when headingOnce is true', function () {
        $tpl = new ChunkTemplate(headingOnce: true);
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1'], '# Chapter 1');
        $units = [makeTestUnit('First paragraph', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        expect($chunk->markdown)->toContain('# Chapter 1')
            ->and($chunk->markdown)->toContain('First paragraph');
    });

    it('excludes heading in subsequent chunks when headingOnce is true', function () {
        $tpl = new ChunkTemplate(headingOnce: true);
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1'], '# Chapter 1');
        $units = [makeTestUnit('Second paragraph', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], false);

        expect($chunk->markdown)->not->toContain('# Chapter 1')
            ->and($chunk->markdown)->toContain('Second paragraph');
    });

    it('handles sections without headings (preamble)', function () {
        $tpl = new ChunkTemplate(headingOnce: true);
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md'], null); // No heading
        $units = [makeTestUnit('Preamble content', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        expect($chunk->markdown)->toContain('Preamble content')
            ->and($chunk->markdown)->not->toContain('#');
    });
});

describe('Unit joining', function () {
    it('joins multiple units with default separator', function () {
        $tpl = new ChunkTemplate(breadcrumbFmt: null); // Disable breadcrumb for clarity
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md']);
        $units = [
            makeTestUnit('First unit', 10),
            makeTestUnit('Second unit', 10),
            makeTestUnit('Third unit', 10),
        ];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 2], true);

        expect($chunk->markdown)->toBe("First unit\n\nSecond unit\n\nThird unit");
    });

    it('joins units with custom separator', function () {
        $tpl = new ChunkTemplate(breadcrumbFmt: null, joinWith: "\n---\n");
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md']);
        $units = [
            makeTestUnit('First unit', 10),
            makeTestUnit('Second unit', 10),
        ];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 1], true);

        expect($chunk->markdown)->toBe("First unit\n---\nSecond unit");
    });

    it('correctly slices units by range', function () {
        $tpl = new ChunkTemplate(breadcrumbFmt: null);
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md']);
        $units = [
            makeTestUnit('Unit 0', 10),
            makeTestUnit('Unit 1', 10),
            makeTestUnit('Unit 2', 10),
            makeTestUnit('Unit 3', 10),
        ];

        // Render middle slice
        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 1, 'end' => 2], false);

        expect($chunk->markdown)->toBe("Unit 1\n\nUnit 2")
            ->and($chunk->markdown)->not->toContain('Unit 0')
            ->and($chunk->markdown)->not->toContain('Unit 3');
    });
});

describe('Complete output structure', function () {
    it('assembles full chunk with breadcrumb, heading, and units', function () {
        $tpl = new ChunkTemplate;
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1'], '# Chapter 1');
        $units = [
            makeTestUnit('First paragraph.', 10),
            makeTestUnit('Second paragraph.', 10),
        ];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 1], true);

        $expected = "> Path: docs.md › Chapter 1\n\n# Chapter 1\n\nFirst paragraph.\n\nSecond paragraph.";
        expect($chunk->markdown)->toBe($expected);
    });

    it('returns EmittedChunk with correct properties', function () {
        $tpl = new ChunkTemplate;
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1', 'Section 1.1']);
        $units = [makeTestUnit('Content here', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        expect($chunk->breadcrumb)->toBe(['docs.md', 'Chapter 1', 'Section 1.1'])
            ->and($chunk->tokenCount)->toBeGreaterThan(0)
            ->and($chunk->markdown)->toBeString()
            ->and($chunk->id)->toBeNull(); // ID is set null by renderer
    });
});

describe('Token counting', function () {
    it('counts tokens in final rendered markdown including breadcrumb', function () {
        $tpl = new ChunkTemplate;
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md', 'Chapter 1']);
        $units = [makeTestUnit('Content here', 10)];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 0], true);

        // Mock tokenizer counts words, so breadcrumb + content should be counted
        // "> Path: docs.md › Chapter 1" = 6 words
        // "Content here" = 2 words
        // Total = 8 words (with our mock)
        expect($chunk->tokenCount)->toBe(8);
    });

    it('token count reflects actual markdown content', function () {
        $tpl = new ChunkTemplate(breadcrumbFmt: null);
        $renderer = new Renderer($tpl, mockTokenizer());
        $section = makeSection(['docs.md']);
        $units = [
            makeTestUnit('One two three', 3),
            makeTestUnit('Four five', 2),
        ];

        $chunk = $renderer->renderSectionChunk($section, $units, ['start' => 0, 'end' => 1], true);

        // "One two three\n\nFour five" = 5 words
        expect($chunk->tokenCount)->toBe(5);
    });
});
