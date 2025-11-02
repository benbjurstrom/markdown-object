# Markdown Object

[![Latest Version on Packagist](https://img.shields.io/packagist/v/benbjurstrom/markdown-object.svg?style=flat-square)](https://packagist.org/packages/benbjurstrom/markdown-object)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/benbjurstrom/markdown-object.svg?style=flat-square)](https://packagist.org/packages/benbjurstrom/markdown-object)

A powerful PHP library for parsing Markdown documents into structured object models and intelligently chunking them for embedding or processing. Built with **League CommonMark** and **Yethee\Tiktoken** for accurate token counting.

## Features

- 🔍 **Parse Markdown → Structured Objects**: Convert Markdown files into a hierarchical object model that preserves ordering and nesting under headings
- 📦 **Smart Chunking**: Intelligent chunking with heading-aware grouping, variable target sizes, and hard caps
- 🎯 **Token-Aware**: Accurate token counting using tiktoken for optimal embedding preparation
- 🏷️ **Breadcrumb Support**: Filename-first breadcrumbs (`filename.md › H1 › H2 …`) for better context
- 🔄 **JSON Round-Trip**: Serialize and deserialize `MarkdownObject` instances for persistence and testing
- 🎨 **Customizable Rendering**: Flexible chunk templates with configurable breadcrumbs, headings, and formatting

## Requirements

- PHP 8.2+
- League CommonMark 2.7+
- Yethee\Tiktoken 1.0+

## Installation

You can install the package via composer:

```bash
composer require benbjurstrom/markdown-object
```

### Dependencies

The package requires the following dependencies (already included in composer.json):

```bash
composer require league/commonmark:^2.7
composer require league/commonmark-ext-table:^2.7  # Optional: if tables not bundled
composer require yethee/tiktoken:^1.0
```

## Quick Start

### Basic Usage

```php
use League\CommonMark\Environment\Environment;
use League\CommonMark\MarkdownParser;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;
use BenBjurstrom\MarkdownObject\Render\ChunkTemplate;

// 1) Parse with CommonMark
$env = new Environment();
$env->addExtension(new CommonMarkCoreExtension());
$env->addExtension(new TableExtension());

$parser = new MarkdownParser($env);

$filename = 'guide.md';
$markdown = file_get_contents('guide.md');
$document = $parser->parse($markdown);

// 2) Build MarkdownObject
$builder = new MarkdownObjectBuilder();
$mdObj = $builder->build($document, $filename, $markdown);

// 3) Generate chunks
$chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024);

// 4) Use chunks
foreach ($chunks as $chunk) {
    echo "Chunk ID: {$chunk->id}\n";
    echo "Breadcrumb: " . implode(' › ', $chunk->breadcrumb) . "\n";
    echo "Tokens: {$chunk->tokenCount}\n";
    echo "Content:\n{$chunk->markdown}\n\n";
}
```

### Custom Chunk Template

```php
use BenBjurstrom\MarkdownObject\Render\ChunkTemplate;

$tpl = new ChunkTemplate(
    breadcrumbFmt: '> Path: %s',
    breadcrumbJoin: ' › ',
    includeFilename: true,
    headingOnce: true,
    joinWith: "\n\n",
    repeatTableHeaderOnSplit: true
);

$chunks = $mdObj->toMarkdownChunks(
    target: 512,
    hardCap: 1024,
    tpl: $tpl
);
```

### JSON Serialization

```php
// Serialize to JSON
$json = $mdObj->toJson(JSON_PRETTY_PRINT);

// Deserialize from JSON
$copy = \BenBjurstrom\MarkdownObject\Model\MarkdownObject::fromJson($json);
```

## API Reference

### MarkdownObject

The main model class representing a parsed Markdown document.

#### Methods

##### `toMarkdownChunks()`

Generate intelligent chunks from the markdown document.

```php
public function toMarkdownChunks(
    int $target = 512,
    int $hardCap = 1024,
    ?ChunkTemplate $tpl = null,
    ?Tokenizer $tok = null,
    ?SplitterRegistry $splitters = null
): array
```

**Parameters:**
- `$target` (int): Target token count per chunk (default: 512)
- `$hardCap` (int): Maximum token count per chunk (default: 1024)
- `$tpl` (ChunkTemplate|null): Custom chunk template (default: uses default template)
- `$tok` (Tokenizer|null): Custom tokenizer (default: TikTokenizer for gpt-3.5-turbo-0301)
- `$splitters` (SplitterRegistry|null): Custom splitters (default: standard splitters)

**Returns:** Array of `EmittedChunk` objects

**Features:**
- Chunks are created primarily at heading boundaries
- Breadcrumb tokens are subtracted from the budget automatically
- Early finish at 90% of target when at the last unit of a section
- Final stretch up to `hardCap` allowed only for the last unit
- Oversized blocks (Text/Code/Table) are split under their same heading

##### `toJson()`

Serialize the markdown object to JSON.

```php
public function toJson(int $flags = 0): string
```

##### `fromJson()`

Deserialize a markdown object from JSON.

```php
public static function fromJson(string $json): self
```

### MarkdownObjectBuilder

Builds a `MarkdownObject` from a CommonMark `Document`.

```php
$builder = new MarkdownObjectBuilder();
$mdObj = $builder->build(Document $document, string $filename, string $source);
```

**Supported Elements:**
- Headings (H1-H6) with nested structure
- Paragraphs
- Fenced and indented code blocks
- Tables
- Images (image-only paragraphs are converted to `MarkdownImage`)

### ChunkTemplate

Configures how chunks are rendered.

**Properties:**
- `breadcrumbFmt` (string|null): Format string for breadcrumbs (default: `'> Path: %s'`)
- `breadcrumbJoin` (string): Join character for breadcrumb items (default: `' › '`)
- `includeFilename` (bool): Include filename in breadcrumb (default: `true`)
- `headingOnce` (bool): Include heading line only in first chunk of section (default: `true`)
- `joinWith` (string): String to join block bodies (default: `"\n\n"`)
- `repeatTableHeaderOnSplit` (bool): Repeat table header when splitting (default: `true`)

### EmittedChunk

Represents a single chunk ready for embedding.

**Properties:**
- `id` (string|null): Unique chunk identifier
- `breadcrumb` (array): Breadcrumb path for the chunk
- `markdown` (string): The markdown content of the chunk
- `tokenCount` (int): Number of tokens in the chunk
- `partIndex` (int|null): Part index if split from a larger block
- `partOf` (int|null): Total parts if split from a larger block

## Architecture

The package is organized into several key components:

### Model Layer
- `MarkdownObject`: Root document model
- `MarkdownHeading`, `MarkdownText`, `MarkdownCode`, `MarkdownImage`, `MarkdownTable`: Content node types
- `Position`, `ByteSpan`, `LineSpan`: Position tracking

### Build Layer
- `MarkdownObjectBuilder`: Converts CommonMark `Document` to `MarkdownObject`

### Planning Layer
- `SectionPlanner`: Organizes content into sections by headings
- `SplitterRegistry`, `TextSplitter`, `CodeSplitter`, `TableSplitter`: Splits blocks into units
- `UnitPlanner`: Plans units from sections
- `Packer`: Packs units into chunks respecting token budgets

### Render Layer
- `ChunkTemplate`: Configures chunk rendering
- `Renderer`: Renders chunks from sections and units

### Tokenization
- `Tokenizer` interface
- `TikTokenizer`: Tiktoken implementation

## Advanced Usage

### Custom Tokenizer

```php
use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Tokenizer\TikTokenizer;

// Use a different model
$tokenizer = TikTokenizer::forModel('gpt-4');

// Or use a specific encoding
$tokenizer = TikTokenizer::forEncoding('p50k_base');

$chunks = $mdObj->toMarkdownChunks(
    target: 512,
    hardCap: 1024,
    tok: $tokenizer
);
```

### Custom Splitters

```php
use BenBjurstrom\MarkdownObject\Planning\{SplitterRegistry, TextSplitter, CodeSplitter, TableSplitter};

$splitters = new SplitterRegistry(
    new TextSplitter(),
    new CodeSplitter(),
    new TableSplitter(repeatHeader: false) // Don't repeat headers
);

$chunks = $mdObj->toMarkdownChunks(
    target: 512,
    hardCap: 1024,
    splitters: $splitters
);
```

## Chunking Strategy

The chunking algorithm follows these rules:

1. **Section Planning**: Content is organized into sections, one per heading subtree
2. **Block Splitting**: Each block (text, code, table) is split into units respecting the target token size
3. **Packing**: Units are packed into chunks with:
   - Target size: Aim for `target` tokens per chunk
   - Early finish: Stop at 90% of target when at the last unit of a section
   - Hard cap: Allow up to `hardCap` tokens only for the final unit
   - Breadcrumb budget: Breadcrumb tokens are subtracted from the available budget

## Testing

Run the tests with:

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Ben Bjurstrom](https://github.com/benbjurstrom)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
