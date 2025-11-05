# Markdown Object

[![Latest Version on Packagist](https://img.shields.io/packagist/v/benbjurstrom/markdown-object.svg?style=flat-square)](https://packagist.org/packages/benbjurstrom/markdown-object)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions/workflows/run-tests.yml)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions/workflows/fix-php-code-style-issues.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/benbjurstrom/markdown-object.svg?style=flat-square)](https://packagist.org/packages/benbjurstrom/markdown-object)

**Structure-aware, token-smart Markdown → chunks for RAG.**
Turn Markdown into a typed object model, then emit hierarchically-packed chunks that keep related content together. Built on **League CommonMark** and **Yethee\Tiktoken** for accurate parsing and token counting.

### Why you'd use it

* **Hierarchical greedy packing** – keeps related content together at the highest possible level, maximizing semantic coherence
* **Smart chunking** – uses hardCap for hierarchy decisions, target for content splitting
* **Breadcrumb arrays** – `['file.md', 'Chapter 1', 'Section 1.1']` provide structured navigation context
* **Token-accurate** – tiktoken integration ensures precise token counts for your embedding model
* **No empty chunks** – parent headings always appear with content, never in isolation

---

## Basic Usage

From raw Markdown to RAG-ready chunks:

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

// 2) Build the structured model (tokenizer required)
$builder   = new MarkdownObjectBuilder();
$tokenizer = TikTokenizer::forModel('gpt-3.5-turbo');
$mdObj     = $builder->build($doc, $filename, $markdown, $tokenizer);

// 3) Emit hierarchically-packed chunks
$chunks = $mdObj->toMarkdownChunks(target: 512, hardCap: 1024);

foreach ($chunks as $chunk) {
    echo "ID: {$chunk->id}\n";
    echo "Path: " . implode(' › ', $chunk->breadcrumb) . "\n";
    echo "Tokens: {$chunk->tokenCount}\n";
    
    // Source position tracking for finding chunks in original document
    $pos = $chunk->sourcePosition;
    if ($pos->lines !== null) {
        echo "Lines: {$pos->lines->startLine}-{$pos->lines->endLine}\n";
    }
    echo "Bytes: {$pos->bytes->startByte}-{$pos->bytes->endByte}\n";
    
    echo "\n" . $chunk->markdown . "\n\n---\n\n";
}

/*
Example output:
ID: 1
Path: guide.md › Getting Started
Tokens: 421
Lines: 1-15
Bytes: 0-523

# Getting Started
Markdown Object turns Markdown into a typed model and emits
hierarchically-packed chunks for better retrieval…

---

ID: 2
Path: guide.md › Getting Started › Installation
Tokens: 503
Lines: 16-28
Bytes: 524-1247

## Installation
Run:
```bash
composer require benbjurstrom/markdown-object
```
…
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

The chunk's token count is what matters for embedding model limits and cost calculations.

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

**Use cases:**
- **LLM context retrieval** – quickly locate and extract surrounding context from the source document
- **Targeted edits** – make changes to specific sections based on retrieval results
- **Navigation** – jump to related sections in the original document
- **Debugging** – verify chunk content matches source material

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

### Example

```markdown
## Parent (100 tokens)
### Child 1 (400 tokens)
### Child 2 (400 tokens)
```

With `target: 512, hardCap: 1024`:
- **Result**: 1 chunk (900 tokens) – all related content stays together under the parent heading
- **Why**: Total tokens (900) < hardCap (1024), so semantic coherence is preserved

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
