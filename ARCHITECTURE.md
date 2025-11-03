# Architecture Guide

> A comprehensive guide for coding agents to understand the markdown-object package architecture, data flow, and design decisions.

## Table of Contents
- [Overview](#overview)
- [Core Philosophy](#core-philosophy)
- [Directory Structure](#directory-structure)
- [Data Flow Pipeline](#data-flow-pipeline)
- [Key Components](#key-components)
- [Design Decisions](#design-decisions)
- [Testing Strategy](#testing-strategy)
- [Common Operations](#common-operations)
- [Quick Reference](#quick-reference)

---

## Overview

### Purpose
This package transforms Markdown documents into **structured object models** and intelligently **chunks them for embedding/vectorization** using hierarchical greedy packing. It's designed for RAG (Retrieval-Augmented Generation) systems that need semantically coherent, properly sized chunks with contextual breadcrumbs.

### What Problem Does It Solve?
- **Naive chunking** (fixed character/token counts) breaks semantic units mid-sentence or mid-code-block
- **Flat chunking** loses document structure (headings, hierarchy)
- **Over-fragmentation** creates tiny, context-less chunks (e.g., a heading with no content)
- **Loss of semantic coherence** when related content under same parent is split unnecessarily

### Key Features
- ✅ Preserves Markdown structure with proper heading nesting
- ✅ **Hierarchical greedy packing** - keeps content together at highest possible level
- ✅ Breadcrumbs as arrays (`['file.md', 'Chapter 1', 'Section 1.1']`)
- ✅ Accurate token counting using tiktoken (required at build time)
- ✅ **HardCap for hierarchy, target for content** - maximizes semantic coherence
- ✅ JSON serialization for persistence (includes token counts)
- ✅ Position tracking (byte/line spans) for source mapping

---

## Core Philosophy

### Two-Phase Architecture

The package follows a **clean separation** between structure and chunking:

```
┌─────────────────────────────────────────────────────────────┐
│ Phase 1: BUILD                                              │
│ Convert Markdown → Structured Object Model                 │
│ Responsibility: Parse & preserve structure + token counts  │
│ Location: src/Build/                                        │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Phase 2: CHUNK                                              │
│ Hierarchical Greedy Packing                                │
│ Responsibility: Pack headings maximally while respecting   │
│                 hardCap for hierarchy, target for content   │
│ Location: src/Chunking/                                    │
└─────────────────────────────────────────────────────────────┘
```

**Why This Matters:**
- Build phase has **no opinions** about chunking strategy
- Chunking phase can change strategy without touching parser
- Each phase is independently testable
- Simple, understandable flow

### Hierarchical Greedy Packing

**Strategy:** Pack related content together at the highest possible hierarchy level.

1. **Try to fit everything in one chunk** (breadcrumb = filename only)
2. If too large, **split by top-level headings** (H1 or H2)
3. For each heading, **greedily pack children** while total ≤ hardCap
4. If a child doesn't fit, **recurse on it** with deeper breadcrumb
5. After recursion, **continue greedy packing** with remaining siblings (minimizes orphans)
6. **No depth limit** - inline children at any depth if they fit

**Key Principles:**
- **HardCap for hierarchy** - when combining headings, only hardCap matters
- **Target for content** - long text blocks, code, tables split at target boundaries
- **All-or-nothing** - child headings fully inlined (heading + descendants) or recursed
- **Maximize semantic coherence** by keeping related content together

---

## Directory Structure

```
src/
├── Build/
│   └── MarkdownObjectBuilder.php      # Converts CommonMark → MarkdownObject
│
├── Chunking/                           # NEW: Hierarchical chunking
│   ├── HierarchicalChunker.php         # Main service - greedy top-down algorithm
│   ├── EmittedChunk.php                # Output: breadcrumb array + markdown + tokens
│   ├── ContentPiece.php                # Value object: markdown + tokens
│   ├── TextSplitter.php                # Splits text at target boundaries
│   ├── CodeSplitter.php                # Splits code (adds fences)
│   └── TableSplitter.php               # Splits tables (repeats headers)
│
├── Contracts/
│   └── Tokenizer.php                   # Interface for token counting
│
├── Model/                              # Data classes (no business logic)
│   ├── MarkdownObject.php              # Root document model + orchestration
│   ├── MarkdownNode.php                # Abstract base class for all nodes
│   ├── MarkdownHeading.php             # Heading node (has children)
│   ├── MarkdownText.php                # Text paragraph
│   ├── MarkdownCode.php                # Code block (stores body only)
│   ├── MarkdownImage.php               # Image reference
│   ├── MarkdownTable.php               # Table (raw markdown)
│   ├── Position.php                    # Byte/line position tracking
│   ├── ByteSpan.php
│   └── LineSpan.php
│
└── Tokenizer/
    └── TikTokenizer.php                # Yethee\Tiktoken wrapper

tests/
├── Build/
│   └── MarkdownObjectBuilderTest.php  # Parser tests
├── Chunking/
│   └── HierarchicalChunkerTest.php    # Hierarchical packing tests
└── Model/
    └── MarkdownObjectTest.php         # JSON + chunking integration tests
```

---

## Data Flow Pipeline

### Step-by-Step Transformation

#### Step 1: Input - Raw Markdown String

Example: `"# Heading\n\nParagraph.\n\n```php\ncode\n```"`

#### Step 2: CommonMark Parser (league/commonmark)

External dependency that parses markdown to a flat AST (Abstract Syntax Tree).

#### Step 3: MarkdownObjectBuilder::build()

Transforms the CommonMark Document into our structured model:

1. Reconstruct heading hierarchy (CommonMark flattens it)
2. Convert nodes to model classes
3. Extract raw content slices
4. Track positions (byte/line spans)
5. **Calculate token counts** (tokenizer required)

**Token Counting (Required):**
- Must pass a `Tokenizer` instance to `build()`
- Each node gets a `tokenCount` property (int) representing its markdown representation
- For leaf nodes (text, code, tables, images): Count tokens of raw/reconstructed markdown
- For code blocks: Count full fenced block (``` ```lang\nbody\n``` ```)
- For headings: Count heading line (`## Heading`) + sum of all children recursively
- For root: Sum of all top-level children
- Token counts are always calculated and available

**Output:** A nested MarkdownObject tree:
```
MarkdownObject
├─ MarkdownHeading (H1)
│  ├─ MarkdownText
│  └─ MarkdownHeading (H2)
│     └─ MarkdownCode
└─ ...
```

Can serialize to JSON or call `toMarkdownChunks()`.

#### Step 4: MarkdownObject::toMarkdownChunks()

Orchestrates the hierarchical chunking.

#### Step 5: HierarchicalChunker::chunk()

Main algorithm implementing greedy top-down packing:

**a) Process Children (Preamble and Headings)**

- Separate preamble (content before first heading) from headings
- Preamble gets its own chunk with filename breadcrumb
- Each heading processed recursively

**b) For Each Heading - Greedy Pack**

```python
def processHeading(heading, breadcrumb):
    # Separate direct content from child headings
    directContent = heading's non-heading children
    childHeadings = heading's heading children

    # Split large direct content using splitters
    directPieces = split(directContent, target, hardCap)

    # Include the heading itself
    allPieces = [headingLine] + directPieces

    # Base case: no child headings
    if no childHeadings:
        if fits(allPieces, hardCap):
            return [chunk(breadcrumb, allPieces)]
        else:
            return packIntoMultipleChunks(allPieces, breadcrumb, hardCap)

    # Try to fit everything (heading + direct + all children)
    totalTokens = countAllRecursive(heading)

    if totalTokens <= hardCap:
        # Everything fits! Inline all children
        allContentPieces = allPieces
        for child in childHeadings:
            allContentPieces += flattenAllRecursive(child)
        return [chunk(breadcrumb, allContentPieces)]

    # Can't fit everything - greedy pack children
    accumulated = allPieces  # Start with heading + direct content
    currentTokens = count(accumulated)
    chunks = []

    for child in childHeadings:
        childTokens = countAllRecursive(child)

        if currentTokens + childTokens <= hardCap:
            # Inline this child completely
            accumulated += flattenAllRecursive(child)
            currentTokens += childTokens
        else:
            # Doesn't fit - emit accumulated and recurse
            if accumulated:
                chunks.append(chunk(breadcrumb, accumulated))

            # Recursively process child (may split further)
            chunks += processHeading(child, breadcrumb + [child.text])

            accumulated = []
            currentTokens = 0
            # IMPORTANT: Loop continues with remaining siblings!
            # Remaining children will try to pack with parent breadcrumb,
            # minimizing orphan chunks (e.g., a small heading at the end)

    # Emit any remaining accumulated content
    if accumulated:
        chunks.append(chunk(breadcrumb, accumulated))

    return chunks
```

**c) Splitters - Split Large Content Blocks**

- **TextSplitter**: Groups sentences to reach target; splits paragraphs first, then sentences, then characters if needed
- **CodeSplitter**: Groups lines to reach target; ADDS fence wrappers (``` ```lang\n...\n``` ```) which add ~6 tokens
- **TableSplitter**: Groups rows to reach target; REPEATS header in each split piece

Each ContentPiece has:
- `markdown`: Ready-to-render string (with wrappers already added)
- `tokens`: Pre-calculated count (including wrapper tokens)

**d) Final Assembly**

For each chunk:
1. Render markdown by joining ContentPiece markdown with `\n\n`
2. **Recalculate final token count** from rendered markdown (accounts for actual output)
3. Create EmittedChunk with breadcrumb array, markdown, and token count

#### Step 6: Output - Array of EmittedChunk Objects

Example output structure:
```php
[
  EmittedChunk {
    id: "c1",
    breadcrumb: ["docs.md", "Chapter 1", "Section 1.1"],
    markdown: "## Section 1.1\n\nContent here...",
    tokenCount: 487
  },
  ...
]
```

**Ready for vectorization/embedding.**

---

## Key Components

### 1. MarkdownObjectBuilder (`src/Build/MarkdownObjectBuilder.php`)

**Responsibility:** Parse CommonMark Document → MarkdownObject tree with token counting

**Key Methods:**
- `build(Document, filename, source, Tokenizer)` - Main entry point (tokenizer required)
- `consumeHeading()` - Recursively nests headings by level, calculates token counts
- `toLeaf()` - Converts CommonMark nodes → model classes, calculates token counts for leaf nodes
- `inlineText()` - Extracts plain text (strips formatting) for headings (breadcrumbs) and images (alt text)

**Token Counting Strategy:**
- **Leaf nodes** (calculated immediately when created):
  - Text/Table/Image: Count tokens of `raw` property
  - Code blocks: Reconstruct full fenced block then count
- **Headings** (calculated after all children consumed):
  - Heading line tokens + sum of all children recursively
- **Root** (calculated after all children built):
  - Sum of all top-level children

**Important:**
- Preserves inline formatting in `raw` properties (for chunks)
- Extracts plain text for headings (`text` property for breadcrumbs) and images (`alt` property for accessibility)
- Example: Heading with `# **bold** text` → `text: "bold text"` (breadcrumbs), `rawLine: "# **bold** text"` (chunks)
- Token counts represent the markdown form that would be serialized back to markdown

### 2. Model Classes (`src/Model/`)

**All are data classes** (no business logic except JSON serialization)

**Inheritance hierarchy:**
- All node types extend `MarkdownNode` abstract base class
- `MarkdownNode` provides centralized serialization/deserialization with helper methods
- Type-safe polymorphic hydration via `__type` field in serialized data

**Node types:**
- `MarkdownObject` - Root, orchestrates `toMarkdownChunks()`, has `tokenCount: int`
- `MarkdownHeading` - Has `children[]`, `level`, `text`, `rawLine`, `tokenCount: int`
- `MarkdownText` - Has `raw` content, `tokenCount: int`
- `MarkdownCode` - Stores `bodyRaw` (NO fences), `info` (language), `tokenCount: int`
- `MarkdownImage` - Has `alt`, `src`, `title`, `raw`, `tokenCount: int`
- `MarkdownTable` - Has `raw` markdown, `tokenCount: int`

**MarkdownNode base class provides:**
- `serialize()` - Final method that adds `__type` field for polymorphic deserialization
- `serializePayload()` - Abstract method each subclass implements
- `deserialize()` - Abstract static method for type-safe reconstruction
- `hydrate()` - Polymorphic factory method using `__type` field
- Helper methods: `expectString()`, `expectNullableString()`, `expectInt()`, `expectNullableInt()`, `expectNullableArray()`, `assertStringKeys()`

**Token Counts on Models:**
- All model classes have a required `int $tokenCount` property (inherited from `MarkdownNode`)
- Always calculated during building (tokenizer is required)
- Represents tokens in the node's markdown representation (what you'd get if serializing back to markdown)
- For headings, includes the heading line + all children recursively
- Always serialized/deserialized with JSON
- Used for packing decisions during chunking

### 3. HierarchicalChunker (`src/Chunking/HierarchicalChunker.php`)

**Responsibility:** Implement greedy top-down hierarchical packing algorithm

**Constructor Parameters:**
- `Tokenizer $tokenizer` - For recalculating final token counts
- `int $target` - Target size for content splitting (text, code, tables)
- `int $hardCap` - Maximum size for chunks (hierarchy decisions)
- Splitters (TextSplitter, CodeSplitter, TableSplitter)

**Key Methods:**
- `chunk(MarkdownObject)` - Main entry point, returns EmittedChunk[]
- `processChildren(children, breadcrumb)` - Handles preamble and top-level headings
- `processHeading(heading, breadcrumb)` - Recursive greedy packing for a heading subtree
- `splitDirectContent(node)` - Routes to appropriate splitter
- `countAllRecursive(node)` - Calculate total tokens for node + descendants
- `flattenAllRecursive(heading)` - Flatten heading subtree into ContentPiece[]

**Algorithm Flow:**
1. Process children (separate preamble from headings)
2. For each heading, check if entire subtree fits under hardCap
3. If yes: inline everything in one chunk
4. If no: greedily pack children until next child doesn't fit
5. Emit accumulated content, recurse on remaining children
6. Recalculate final token count from rendered markdown

**Key Behavior:**
- Headings always included in their chunks (breadcrumb provides path context)
- Direct content split at target boundaries using splitters
- Children packed at hardCap boundaries for maximum coherence
- Recursive delegation when children don't fit

### 4. Splitters (`src/Chunking/*Splitter.php`)

**Responsibility:** Split large content blocks at target boundaries

**TextSplitter:**
- **Strategy:** Greedily pack sentences to reach target
- Split paragraphs by `\n\n`, then split by sentences if needed
- If a single sentence > hardCap: binary search character split
- Returns ContentPiece[] with markdown + pre-calculated tokens

**CodeSplitter:**
- **Critical:** Adds fence wrappers (``` ```lang\n...\n``` ```) around bodyRaw
- Wrapper adds ~6 tokens that are included in ContentPiece token count
- Input: `bodyRaw = "function foo() {}"` (no fences)
- Output: `ContentPiece(markdown: "```php\nfunction foo() {}\n```", tokens: counted)`
- Groups lines to reach target while staying under hardCap

**TableSplitter:**
- Groups rows to reach target
- Repeats header in each ContentPiece (configurable via `repeatHeader` param)
- Header repetition tokens included in ContentPiece token count

**All splitters return:** `ContentPiece[]` with pre-computed markdown and token counts

### 5. EmittedChunk (`src/Chunking/EmittedChunk.php`)

**Responsibility:** Final output format for chunks

**Properties:**
- `?string $id` - Sequential ID assigned after chunking ("c1", "c2", etc.)
- `array $breadcrumb` - Path array: `['filename.md', 'Chapter 1', 'Section 1.1']`
- `string $markdown` - Rendered markdown content
- `int $tokenCount` - Final token count of rendered markdown

**Key Points:**
- Breadcrumbs are **arrays**, not rendered strings (consumer decides how to display)
- Token count is **final** - counted from assembled markdown, not summed from pieces
- ID assigned by MarkdownObject after all chunks created

---

## Design Decisions

### Why Two Phases (Build, Chunk)?

**Decision:** Separate structure parsing from chunking strategy.

**Benefits:**
1. **Testability:** Each phase tested independently
2. **Flexibility:** Change chunking strategy without touching parser
3. **Reusability:** Same MarkdownObject can generate different chunk sets
4. **Clarity:** Clear responsibility boundaries

**Trade-offs:**
- Models carry token counts even if chunking isn't used (but useful for analysis)

### Why Hierarchical Greedy Packing?

**Decision:** Keep related content together at the highest possible hierarchy level.

**Example:**
```
## Parent (100 tokens)
### Child 1 (400 tokens)
### Child 2 (400 tokens)
```

With target=512, hardCap=1024:
- **Old approach**: 3 chunks (one per heading)
- **New approach**: 1 chunk (900 tokens, everything together)

**Benefits:**
1. **Better semantic coherence** - related content stays together
2. **Fewer chunks** - lower embedding costs
3. **Better retrieval** - complete context in single chunk
4. **No empty chunks** - parent headings always have content
5. **Minimizes orphans** - greedy continuation after recursion packs remaining small siblings together

**Key Behaviors:**
- **All-or-nothing inlining** - child headings are either fully inlined (heading + all descendants) or recursed on separately
- **Greedy continuation** - after recursing on a child that doesn't fit, remaining siblings continue trying to pack with parent breadcrumb
- **Multiple chunks, same breadcrumb** - natural result of greedy continuation (e.g., parent + child1, child2-recursed, child3 all share parent breadcrumb)

### Why HardCap for Hierarchy, Target for Content?

**Decision:** Different limits for different purposes.

**Rationale:**
- **Hierarchy decisions** (should I inline this child heading?) → Use hardCap
  - Goal: Maximize semantic coherence
  - Keep related sections together as long as they fit
- **Content splitting** (how do I split this 2000-token paragraph?) → Use target
  - Goal: Create reasonably-sized chunks
  - Prevent oversized individual content blocks

**Example:**
```
## Heading (900 tokens total)
{600-token text paragraph}
### Child (300 tokens)
```

With target=512, hardCap=1024:
- Text paragraph split into ~2 pieces at target (512) boundary
- But Heading + both text pieces + Child packed together (under hardCap 1024)

### Why Breadcrumbs as Arrays?

**Decision:** Store breadcrumbs as arrays, not rendered strings.

**Format:** `['filename.md', 'Chapter 1', 'Section 1.1']`

**Reasoning:**
- **Flexibility** - consumer decides how to render
- **Simpler** - no template complexity
- **Structured data** - easier to work with programmatically
- **Multiple use cases** - can render as "file › Chapter › Section" or "file/Chapter/Section" or any other format

### Why Preserve Both Raw and Plain Text?

**Decision:** Extract both formatted and plain text for specific node types that need dual representation.

**Only Two Node Types Need This:**

#### 1. Headings (for Breadcrumbs vs Chunks)

```php
// Heading: # This is **bold** and *italic*
text: "This is bold and italic"              // For breadcrumbs
rawLine: "# This is **bold** and *italic*"  // For chunks
```

**Why:**
- **Breadcrumbs are heading-only** (`['file.md', 'H1', 'H2']`) and need clean text
- **Chunks preserve formatting** to maintain markdown fidelity
- Breadcrumbs with `**bold**` would look broken in navigation UI

#### 2. Images (for Semantic Correctness)

```php
// Image: ![**Important** diagram](img.jpg)
alt: "Important diagram"                     // Plain for accessibility
raw: "![**Important** diagram](img.jpg))"    // Full markdown
```

**Why:**
- Screen readers and browsers expect plain alt text
- `alt="**Important** diagram"` would be spoken as "asterisk asterisk Important..."
- Raw version preserves original markdown for reconstruction

#### 3. Everything Else (Raw Only)

Text, Code, and Table nodes only store `raw` content - no plain text extraction needed.

**Key Point:** Breadcrumbs **never** include non-heading content. They are exclusively the heading hierarchy path through the document.

### Why Recalculate Final Token Counts?

**Decision:** ContentPieces have pre-calculated tokens, but final EmittedChunk recalculates from rendered markdown.

**Reasoning:**
1. **Joining adds separators** - ContentPieces joined with `\n\n`
2. **Tokenizer behavior** - Token count of "A\n\nB" may differ from count("A") + count("B")
3. **Accuracy** - Final count represents exactly what will be embedded
4. **Trust the final output** - No accumulation errors

**Trade-off:** Small performance cost for accuracy (acceptable for chunking workload)

---

## Testing Strategy

### Test Organization

```
tests/
├── Build/
│   └── MarkdownObjectBuilderTest.php   # Parser correctness + token counting
├── Chunking/
│   └── HierarchicalChunkerTest.php     # Hierarchical packing algorithm
└── Model/
    └── MarkdownObjectTest.php          # JSON + chunking integration
```

### Test Coverage

**Build Tests:** Focus on structure and token counting
- Heading nesting
- Block type recognition
- Position tracking
- Raw content preservation
- Token count calculation (with and without tokenizer)
- Recursive token aggregation for headings

**Chunking Tests:** Focus on hierarchical packing algorithm
- Small file (everything fits) → 1 chunk
- Deep nesting (all fits) → 1 chunk with appropriate breadcrumb
- Greedy packing → correct split points
- Must split at various levels → proper breadcrumb depth
- Preamble handling → separate chunk with filename breadcrumb
- Target vs. hardCap behavior → hierarchy uses hardCap, content uses target
- Empty parent content → greedy inlining still applies

**Model Tests:** Focus on behavior
- JSON serialization/deserialization
- Chunk generation
- Breadcrumb handling
- Integration with chunker

**Philosophy:** Test the public API (entry points) and verify outputs, not internal implementation details.

### Running Tests

```bash
# All tests
vendor/bin/pest

# Specific suite
vendor/bin/pest tests/Build/
vendor/bin/pest tests/Chunking/
vendor/bin/pest tests/Model/

# Static analysis
vendor/bin/phpstan analyse src/
```

---

## Common Operations

### Basic Usage

```php
use League\CommonMark\Environment\Environment;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use BenBjurstrom\MarkdownObject\Build\MarkdownObjectBuilder;

// 1. Parse Markdown
$env = new Environment();
$env->addExtension(new CommonMarkCoreExtension());
$env->addExtension(new TableExtension());
$parser = new MarkdownParser($env);

$markdown = file_get_contents('docs.md');
$document = $parser->parse($markdown);

// 2. Build MarkdownObject (with token counting)
$builder = new MarkdownObjectBuilder();
$tokenizer = \BenBjurstrom\MarkdownObject\Tokenizer\TikTokenizer::forModel('gpt-3.5-turbo');
$mdObj = $builder->build($document, 'docs.md', $markdown, $tokenizer);
// Note: Tokenizer is required. Token counts always calculated.

// 3. Generate Chunks
$chunks = $mdObj->toMarkdownChunks(
    target: 512,
    hardCap: 1024
);

// 4. Use Chunks
foreach ($chunks as $chunk) {
    echo "ID: {$chunk->id}\n";
    echo "Path: " . implode(' › ', $chunk->breadcrumb) . "\n";
    echo "Tokens: {$chunk->tokenCount}\n";
    echo "Content:\n{$chunk->markdown}\n\n";
}
```

### Custom Configuration

```php
// Customize chunking parameters
$chunks = $mdObj->toMarkdownChunks(
    target: 256,              // Smaller target for content splitting
    hardCap: 512,             // Smaller hard cap for hierarchy
    tok: $customTokenizer,    // Use different tokenizer
    repeatTableHeaders: false // Don't repeat headers in split tables
);
```

### JSON Round-Trip

```php
// Serialize
$json = $mdObj->toJson(JSON_PRETTY_PRINT);
file_put_contents('doc.json', $json);

// Deserialize
$json = file_get_contents('doc.json');
$mdObj = \BenBjurstrom\MarkdownObject\Model\MarkdownObject::fromJson($json);
```

### Analyzing Document Structure

```php
// Build without chunking to analyze structure
$mdObj = $builder->build($document, 'docs.md', $markdown, $tokenizer);

// Check total tokens
echo "Total tokens: {$mdObj->tokenCount}\n";

// Traverse tree
foreach ($mdObj->children as $child) {
    if ($child instanceof \BenBjurstrom\MarkdownObject\Model\MarkdownHeading) {
        echo "Heading: {$child->text} ({$child->tokenCount} tokens)\n";
    }
}
```

---

## Quick Reference

| Need to... | Look in... |
|------------|------------|
| Add new block type | `src/Build/MarkdownObjectBuilder.php` + `src/Model/` |
| Change chunking algorithm | `src/Chunking/HierarchicalChunker.php` |
| Adjust content splitting logic | `src/Chunking/*Splitter.php` |
| Debug token counting | Model `tokenCount` properties (build-time) |
| Understand data flow | This document, "Data Flow Pipeline" |
| See examples of hierarchical packing | `EXAMPLES.md` |

---

## Key Takeaways for Coding Agents

1. **Two-phase architecture** - Build (structure + tokens) → Chunk (hierarchical packing)
2. **Token counts always calculated** - Tokenizer is required for `build()`; represents intrinsic markdown token cost
3. **Hierarchical greedy packing** - Keep content together at highest possible level, only split when necessary
4. **HardCap for hierarchy, target for content** - Different limits for different purposes
5. **Breadcrumbs as arrays** - `['file.md', 'H1', 'H2']` not rendered strings
6. **Headings included in chunks** - Parent heading appears in chunk markdown, breadcrumb provides full path
7. **Recursive algorithm** - Children processed via recursion, not flattening
8. **All-or-nothing child inlining** - Child headings fully inlined (heading + all descendants) or recursed on separately, no partial inlining
9. **Greedy continuation after recursion** - After recursing on a child, remaining siblings continue trying to pack with parent breadcrumb, minimizing orphan chunks
10. **Multiple chunks can share breadcrumb** - Natural result of greedy continuation (e.g., parent+child1, child2-recursed, child3)
11. **Final token counts recalculated** - From rendered markdown for accuracy
12. **Semantic boundaries matter** - Chunks respect document structure (headings, paragraphs, sentences) not arbitrary character counts
13. **Position tracking** - Byte/line spans enable source mapping for future features
14. **Heading token counts are recursive** - Include heading line + sum of all children's tokens
15. **No template complexity** - Removed in favor of simple breadcrumb arrays

---

## Version

This architecture guide reflects the current implementation:
- Two-phase architecture (Build → Chunk)
- HierarchicalChunker with greedy top-down algorithm
- Required `tokenCount: int` on all MarkdownNode subclasses
- Breadcrumbs as arrays (no rendered template strings)
- ContentPiece value objects (replaced Units)
- EmittedChunk in `src/Chunking/`
- PHP 8.2+ features (readonly classes, enums, promoted properties)
- League CommonMark 2.7+ and Yethee Tiktoken
- Comprehensive test coverage:
  - Build (15 tests) - Parser correctness, token counting
  - Chunking (11 tests) - Hierarchical packing algorithm
  - Model (13 tests) - JSON round-tripping, integration

**Test Status:** All core tests passing - PHPStan level 10 clean

For implementation details, see the code. For examples, see `EXAMPLES.md`. For rationale, see "Design Decisions" above.
