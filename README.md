# Markdown Object

[![Latest Version on Packagist](https://img.shields.io/packagist/v/benbjurstrom/markdown-object.svg?style=flat-square)](https://packagist.org/packages/benbjurstrom/markdown-object)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/benbjurstrom/markdown-object/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/benbjurstrom/markdown-object/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/benbjurstrom/markdown-object.svg?style=flat-square)](https://packagist.org/packages/benbjurstrom/markdown-object)

**Structure-aware, token-smart Markdown → chunks for RAG.**
Turn Markdown into a typed object model, then emit breadcrumbed chunks that align to headings and respect your token budget. The output ideal for embeddings and retrieval. Built on **League CommonMark** and **Yethee\Tiktoken** for accurate parsing and counts. 

### Why you’d use it (in practice)

* **Heading-aligned chunks** that keep paragraphs, code blocks, and tables intact—no mid-sentence or mid-fence cuts. 
* **Breadcrumbs** like `file.md › H1 › H2` baked into each chunk for stronger retrieval context, with their token cost automatically budgeted.  
* **Token-aware planning & packing** (target + hard cap, early finish, final stretch) so chunks land in the sweet spot for your model. 

---

## Basic Usage

From raw Markdown to RAG-ready chunks:

```php
use League\CommonMark\Environment\Environment;
use League\CommonMark\MarkdownParser;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;

use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;

// 1) Parse Markdown with CommonMark
$env = new Environment();
$env->addExtension(new CommonMarkCoreExtension());
$env->addExtension(new TableExtension());

$parser   = new MarkdownParser($env);
$filename = 'guide.md';
$markdown = file_get_contents($filename);
$doc      = $parser->parse($markdown);

// 2) Build the structured model
$builder = new MarkdownObjectBuilder();
$md      = $builder->build($doc, $filename, $markdown);

// 3) Emit breadcrumbed, token-budgeted chunks
$chunks = $md->toMarkdownChunks(target: 512, hardCap: 1024);

foreach ($chunks as $i => $chunk) {
    echo "Chunk #$i\n";
    echo "Path: " . implode(' › ', $chunk->breadcrumb) . "\n";
    echo "Tokens: {$chunk->tokenCount}\n\n";
    echo $chunk->markdown . "\n\n---\n\n";
}

foreach ($chunks as $i => $chunk) {
    echo "Chunk #$i\n";
    echo "Path: " . implode(' › ', $chunk->breadcrumb) . "\n";
    echo "Tokens: {$chunk->tokenCount}\n\n";
    echo $chunk->markdown . "\n\n---\n\n";
}

/*
Example output (truncated for brevity):
Chunk #0
Path: guide.md › Getting Started
Tokens: 421

# Getting Started
Markdown Object turns Markdown into a typed model and emits
heading-aligned chunks with breadcrumbs for better retrieval…
- Structure-aware splitting (paragraphs, tables, code)
- Token-aware packing with target/hard cap
…

---

Chunk #1
Path: guide.md › Usage › Installation
Tokens: 503

## Installation
Run:

    composer require benbjurstrom/markdown-object

Then initialize your pipeline and configure your tokenizer…
    php artisan vendor:publish
    php artisan markdown-object:test
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
