# Markdown Object

[![Latest Version on Packagist](https://img.shields.io/packagist/v/benbjurstrom/markdown-object.svg?style=flat-square)](https://packagist.org/packages/benbjurstrom/markdown-object)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions/workflows/run-tests.yml)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions/workflows/fix-php-code-style-issues.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/benbjurstrom/markdown-object.svg?style=flat-square)](https://packagist.org/packages/benbjurstrom/markdown-object)

Intelligent Markdown chunking that preserves document structure and semantic relationships. Creates token-aware chunks optimized for embedding model context windows. Built on [League CommonMark](https://github.com/thephpleague/commonmark) and [Yethee\Tiktoken](https://github.com/yethee/tiktoken-php).

## Try It Out

Clone the **[Interactive Demo](https://github.com/benbjurstrom/markdown-object-demo)** to experiment with chunking in real-time. Paste your Markdown, adjust parameters, and see how content gets split into semantic chunks.

<img width="1280" alt="markdown-object-demo" src="https://github.com/user-attachments/assets/2f69026a-24d3-4b44-a656-40b3a62af2be">

## Basic Usage

```php
use League\CommonMark\Environment\Environment;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;
use BenBjurstrom\MarkdownObject\Tokenizer\TikTokenizer;

// 1) Parse Markdown with CommonMark
$env = new Environment();
$env->addExtension(new CommonMarkCoreExtension());
$env->addExtension(new TableExtension());

$parser   = new MarkdownParser($env);
$filename = 'guide.md';
$markdown = file_get_contents($filename);
$doc      = $parser->parse($markdown);

// 2) Build the structured model
$builder   = new MarkdownObjectBuilder();
$tokenizer = TikTokenizer::forModel('gpt-3.5-turbo');
$mdObj     = $builder->build($doc, $filename, $markdown, $tokenizer);

// 3) Emit hierarchically-packed chunks
$chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024);

foreach ($chunks as $chunk) {
    echo "---\n";
    echo "Chunk: {$chunk->id} | {$chunk->tokenCount} tokens";

    // Source position tracking for finding chunks in original document
    $pos = $chunk->sourcePosition;
    if ($pos->lines !== null) {
        echo " | Line: {$pos->lines->startLine}";
    }
    echo "\n";
    echo implode(' › ', $chunk->breadcrumb) . "\n";
    echo "---\n\n";
    echo $chunk->markdown . "\n\n";
}

/*
---
Chunk: 1 | 163 tokens | Line: 1
demo.md › Getting Started
---

# Getting Started

Welcome to the Markdown Object demo! This tool helps you visualize how markdown is parsed and chunked.

## Features

### Real-time Processing

Type or paste markdown in the left pane and see the results instantly.

### Hierarchical Chunking

Content is automatically organized into semantic chunks that keep related information together…

---
Chunk: 2 | 287 tokens | Line: 18
demo.md › Getting Started › Advanced Options
---

## Advanced Options

Configure chunking parameters to see how different settings affect the output.

### Token Limits

Adjust the target and hard cap values to control chunk sizes…
*/
```

## Installation

You can install the package via composer:

```bash
composer require benbjurstrom/markdown-object
```

## Advanced Usage

### JSON Serialization

```php
// Serialize to JSON
$json = $mdObj->toJson(JSON_PRETTY_PRINT);

// Deserialize from JSON
$copy = \BenBjurstrom\MarkdownObject\Model\MarkdownObject::fromJson($json);
```

### Custom Tokenizer

```php
use BenBjurstrom\MarkdownObject\Tokenizer\TikTokenizer;

// Use a different model
$tokenizer = TikTokenizer::forModel('gpt-4');

// Or use a specific encoding
$tokenizer = TikTokenizer::forEncoding('p50k_base');

// Pass to both build() and toMarkdownChunks()
$mdObj = $builder->build($doc, $filename, $markdown, $tokenizer);
$chunks = $mdObj->toMarkdownChunks(
    target: 512,
    hardCap: 1024,
    tok: $tokenizer
);
```

### Custom Chunking Parameters

```php
$chunks = $mdObj->toMarkdownChunks(
    target: 256,                // Smaller target for content splitting
    hardCap: 512,               // Smaller hard cap for hierarchy
    tok: $customTokenizer,      // Optional: use different tokenizer
    repeatTableHeaders: false   // Optional: don't repeat headers in split tables
);
```

### Understanding Token Counts

Chunk token counts include separator tokens (`\n\n`) added when joining content pieces, so they may be slightly higher than the sum of individual node tokens. This is expected and ensures the count accurately reflects what will be embedded.

```php
// Build-time: sum of nodes (no separators)
echo $mdObj->tokenCount;  // e.g., 155

// Chunk: includes \n\n separators between elements
echo $chunks[0]->tokenCount;  // e.g., 163 (8 tokens higher)
```

### Source Position Tracking

Each chunk includes a `sourcePosition` property that maps it back to the original document, enabling efficient retrieval and navigation:

```php
foreach ($chunks as $chunk) {
    $pos = $chunk->sourcePosition;
    
    // Line-based access (human-readable, markdown-friendly)
    if ($pos->lines !== null) {
        echo "Lines {$pos->lines->startLine} to {$pos->lines->endLine}\n";
        // Extract using: sed -n '${start},${end}p' file.md
    }
    
    // Byte-based access (O(1) random access for large files)
    echo "Bytes {$pos->bytes->startByte} to {$pos->bytes->endByte}\n";
    // Extract using: dd if=file.md skip=$start count=$length bs=1
}
```

The hierarchical chunking algorithm ensures that chunks are always contiguous in the source document, making position tracking reliable and predictable.

## Chunking Strategy

The package uses **hierarchical greedy packing** to maximize semantic coherence:

### How It Works

1. **Try to fit everything** – if the entire document fits under hardCap, emit one chunk
2. **Split by top-level headings** – if too large, split by H1s (or H2s if no H1s)
3. **Greedy pack children** – for each heading, pack as many children as possible while staying under hardCap
4. **Recursive splitting** – if a child doesn't fit, recurse on it with a deeper breadcrumb
5. **Continue packing** – after recursion, remaining siblings continue greedy packing (minimizes orphan chunks)
6. **Content splitting** – large text blocks, code, and tables split at target boundaries

### Key Principles

- **HardCap for hierarchy** – when combining headings, only hardCap matters (maximizes coherence)
- **Target for content** – long text, code, and tables split at target boundaries (prevents oversized blocks)
- **All-or-nothing inlining** – child headings are either fully inlined (heading + all descendants) or recursed on separately
- **Greedy continuation** – after recursing, remaining siblings continue packing to minimize orphan chunks
- **Breadcrumbs as arrays** – structured path data (`['file.md', 'H1', 'H2']`) for flexible rendering
- **Headings included** – parent headings appear in chunk markdown, breadcrumb provides full path

## Testing

Run the tests with:

```bash
composer test
```

## Documentation

For detailed architecture documentation, see [ARCHITECTURE.md](ARCHITECTURE.md).

For examples of hierarchical packing behavior, see [EXAMPLES.md](EXAMPLES.md).

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
