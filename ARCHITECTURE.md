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
- [Key Takeaways](#key-takeaways-for-coding-agents)
- [Worked Example: Packer Behavior](#worked-example-packer-behavior)
- [Quick Reference](#quick-reference)

---

## Overview

### Purpose
This package transforms Markdown documents into **structured object models** and intelligently **chunks them for embedding/vectorization**. It's designed for RAG (Retrieval-Augmented Generation) systems that need semantically coherent, properly sized chunks with contextual breadcrumbs.

### What Problem Does It Solve?
- **Naive chunking** (fixed character/token counts) breaks semantic units mid-sentence or mid-code-block
- **Flat chunking** loses document structure (headings, hierarchy)
- **Context loss** when chunks lack breadcrumb paths (where in the document am I?)
- **Token miscounting** when wrapper tokens (code fences +6, table headers +variable, breadcrumbs +variable) aren't accounted for

### Key Features
- ✅ Preserves Markdown structure with proper heading nesting
- ✅ Chunks at semantic boundaries (headings, paragraphs, sentences)
- ✅ Filename-first breadcrumbs (`docs.md › Chapter 1 › Section 1.1`)
- ✅ Accurate token counting using tiktoken (required at build time and during chunking)
- ✅ Configurable target/hard-cap limits with smart packing
- ✅ JSON serialization for persistence (includes token counts)
- ✅ Position tracking (byte/line spans) for source mapping

---

## Core Philosophy

### Three-Phase Architecture

The package follows a **clean separation of concerns** across three phases:

```
┌─────────────────────────────────────────────────────────────┐
│ Phase 1: BUILD                                              │
│ Convert Markdown → Structured Object Model                 │
│ Responsibility: Parse & preserve structure                 │
│ Location: src/Build/                                        │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Phase 2: PLAN                                               │
│ Organize, Split, Pack                                      │
│ Responsibility: Strategy for chunking                      │
│ Location: src/Planning/                                    │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Phase 3: RENDER                                             │
│ Format & Emit Chunks                                       │
│ Responsibility: Apply templates, generate output           │
│ Location: src/Render/                                      │
└─────────────────────────────────────────────────────────────┘
```

**Why This Matters:**
- Build phase has **no opinions** about token limits or chunking strategy
- Planning phase can change strategy without touching parser or renderer
- Render phase can change formatting without affecting structure or packing
- Each phase is independently testable

---

## Directory Structure

```
src/
├── Build/
│   └── MarkdownObjectBuilder.php      # Converts CommonMark → MarkdownObject
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
├── Planning/                           # Chunking strategy
│   ├── Section.php                     # Heading subtree + breadcrumb
│   ├── SectionPlanner.php              # Flattens tree → sections
│   ├── Unit.php                        # Atomic chunk piece (with tokens)
│   ├── UnitKind.php                    # Enum: Text|Code|Table|Image
│   ├── UnitPlanner.php                 # Blocks → Units
│   ├── Splitter.php                    # Interface for splitting strategies
│   ├── TextSplitter.php                # Para → Sentence → Char fallback
│   ├── CodeSplitter.php                # Line groups (adds fences)
│   ├── TableSplitter.php               # Row groups (repeats headers)
│   ├── SplitterRegistry.php            # Routes nodes to splitters
│   ├── Budget.php                      # Target/hardCap/earlyThreshold
│   └── Packer.php                      # Greedy bin-packing algorithm
│
├── Render/
│   ├── ChunkTemplate.php               # Configuration: format, separators, options
│   ├── Renderer.php                    # Assembly: breadcrumb + heading + units → markdown
│   └── EmittedChunk.php                # Output: final chunk with markdown + token count
│
└── Tokenizer/
    └── TikTokenizer.php                # Yethee\Tiktoken wrapper

tests/
├── Build/
│   └── MarkdownObjectBuilderTest.php  # Parser tests
├── Model/
│   └── MarkdownObjectTest.php         # JSON + chunking integration tests
├── Planning/
│   ├── SectionPlannerTest.php         # Section flattening tests
│   ├── UnitPlannerTest.php            # Block→Unit transformation tests
│   └── PackerTest.php                 # Bin-packing algorithm tests
└── Render/
    └── RendererTest.php               # Chunk assembly + template tests
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
- For code blocks: Count full fenced block (```` ```lang\nbody\n``` ````)
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

Orchestrates the planning and rendering phases.

#### Step 5: Planning Phase - SectionPlanner::plan()

Flattens the tree into a list of Sections:
- One Section per heading subtree
- Preamble (pre-heading content) = separate section
- Each Section has:
  - `breadcrumb`: `['filename', 'H1', 'H2']`
  - `blocks`: Direct child nodes (not sub-headings)
  - `headingRawLine`: Exact heading text with formatting

#### Step 6: For Each Section - Process and Pack

**a) Calculate Breadcrumb Cost**
- Render breadcrumb: "filename › H1 › H2"
- Count tokens
- Subtract from target/hardCap
- Different sections = different breadcrumb depths

**b) UnitPlanner + SplitterRegistry → Units**

For each block in section:
- Route to appropriate Splitter based on block type
- **TextSplitter**: Greedily groups sentences to reach target; splits paragraphs first, then sentences, then characters if needed
- **CodeSplitter**: Groups lines to reach target; ADDS fence wrappers (````lang\n...\n````) which add ~6 tokens
- **TableSplitter**: Groups rows to reach target; REPEATS header in each unit

Each Unit has:
- `kind`: Text|Code|Table|Image
- `markdown`: Ready-to-render string (with wrappers already added)
- `tokens`: Pre-calculated count (including wrapper tokens)

**c) Packer::pack()**

Greedy bin-packing algorithm that groups Units into chunk ranges:

1. **Accumulate** units while total ≤ target
2. When next unit would **exceed target**:
   - If it's the **last unit** and total ≤ hardCap: include it (final stretch)
   - Otherwise: flush accumulated units and start new chunk
3. **Early threshold** (90% of target): If a single unit alone ≥ threshold, emit it immediately
4. **Final flush**: Emit any remaining units

Returns: Array of index ranges `[{start:0, end:2}, {start:3, end:5}, ...]`

**Key behavior:** The last unit in a section can stretch beyond target (up to hardCap), keeping content together.

**d) Renderer::renderSectionChunk()**

Simple string assembly for each packer range:

1. **Slice units**: Extract units[range.start ... range.end]
2. **Format breadcrumb**: Apply template format (e.g., "> Path: file › H1 › H2")
3. **Add heading**: Include heading raw line (first chunk only, if configured)
4. **Join units**: Concatenate unit markdown with template separator (default: `\n\n`)
5. **Count tokens**: ONE final count of fully-assembled markdown string
6. **Package**: Return EmittedChunk with breadcrumb array, markdown, and token count

**Key insight:** Units already have their content and tokens. The Renderer just assembles them with breadcrumb/heading wrapper and counts the final result. Breadcrumb tokens vary by section depth, so the final count includes this overhead.

#### Step 7: Output - Array of EmittedChunk Objects

Example output structure:
```php
[
  EmittedChunk {
    id: "c1",
    breadcrumb: ["docs.md", "Chapter 1", "Section 1.1"],
    markdown: "> Path: docs.md › Chapter 1 › ...\n\n# ...",
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
- Separate from Unit tokens (which include wrapper overhead like breadcrumbs)

### 3. SectionPlanner (`src/Planning/SectionPlanner.php`)

**Responsibility:** Flatten tree → list of Sections

**Algorithm:**
1. Collect preamble (nodes before first heading) → Section
2. For each heading:
   - Create Section with breadcrumb path
   - Include direct child blocks (not sub-headings)
   - Recurse for sub-headings

**Output:** `Section[]` where each has:
- `breadcrumb`: `['filename', 'H1', 'H2', ...]`
- `blocks`: Direct child nodes
- `headingRawLine`: Original heading with formatting

### 4. Splitters (`src/Planning/*Splitter.php`)

**Responsibility:** Transform Blocks → Units (with ready-to-render markdown + pre-calculated tokens)

**TextSplitter:**
- **Strategy:** Greedily pack sentences to reach target
- Split paragraphs by `\n\n`, then split by sentences if needed
- If a single sentence > hardCap: binary search character split
- **Key insight:** Sentences are grouped together, not emitted individually

**CodeSplitter:**
- **Critical:** Adds fence wrappers (````lang\n...\n````) around bodyRaw
- Wrapper adds ~6 tokens that must be counted
- Input: `bodyRaw = "function foo() {}"` (no fences)
- Output: `markdown = "```php\nfunction foo() {}\n```"` (fences added)
- Groups lines to reach target while staying under hardCap

**TableSplitter:**
- Groups rows to reach target
- Repeats header in each Unit (configurable via `repeatHeader` param)
- Header repetition adds tokens to each split unit

### 5. Packer (`src/Planning/Packer.php`)

**Responsibility:** Greedy bin-packing of Units into chunk ranges

**Core Logic:**
```
Accumulate units while sum ≤ target
When next unit would exceed target:
  - If last unit AND sum+unit ≤ hardCap → include (final stretch)
  - Otherwise → flush accumulated, start new chunk

If single unit ≥ earlyThreshold (90% target):
  - Emit immediately (unit is "good enough")
```

**Parameters:**
- `units`: Array of Units with pre-calculated tokens
- `budget`: {target, hardCap, earlyThreshold}
- `allowFinalStretchToHardCap`: Enable/disable stretch (default: true)

**Returns:** `[{start: 0, end: 2}, {start: 3, end: 5}, ...]` (index ranges)

**Key Behavior:** Final unit can stretch beyond target up to hardCap, preventing tiny trailing chunks.

### 6. Renderer (`src/Render/Renderer.php`)

**Responsibility:** Assemble final chunks from pre-computed Units

**The Renderer is the simplest component** - it's purely a string assembly function with no complex logic.

**Input:**
- Section (breadcrumb array + heading raw line)
- Units array (already split, with markdown ready to render)
- Range (which units to include: `{start: 0, end: 2}`)
- ChunkTemplate (formatting configuration)
- Tokenizer (for final count)

**Output:** EmittedChunk (breadcrumb array, markdown string, token count)

**Assembly Process:**
```php
// Pseudocode
function renderSectionChunk(section, units, range) {
    slice = units[range.start ... range.end]

    breadcrumb = template.renderBreadcrumb(section.breadcrumb)
    heading = isFirstChunk ? section.headingRawLine : ""
    body = join(slice.markdown, template.separator)

    markdown = breadcrumb + "\n\n" + heading + body
    tokens = tokenizer.count(markdown)  // ONE final count

    return EmittedChunk(section.breadcrumb, markdown, tokens)
}
```

**Key Points:**
- **No business logic** - Just string concatenation and formatting
- **Units are pre-computed** - Renderer doesn't split or calculate unit tokens
- **Template controls format** - Breadcrumb format, separators, heading inclusion
- **Final token count** - Counts assembled markdown (includes breadcrumb overhead)
- **Stateless** - Each call is independent

---

## Design Decisions

### Token Counting: Build-Time vs. Chunking-Time

**Decision:** Model nodes have REQUIRED token counts (non-nullable int) calculated at build time, representing their base markdown representation. Units also have REQUIRED token counts calculated during chunking, representing their final rendered form with wrappers.

**Two Different Token Counts:**

#### Build-Time Token Counts (on Models)
- **When**: Always calculated during `build()` (tokenizer required)
- **What**: Tokens in the node's markdown representation as-is
- **Purpose**: Intrinsic document metrics, analysis, debugging, or pre-chunking optimization
- **Example**:
  ```php
  // MarkdownCode model
  bodyRaw = "function foo() {}\nreturn bar;"
  tokenCount = 26  // Tokens in: ```php\nfunction foo() {}\nreturn bar;\n```
  ```

#### Chunking-Time Token Counts (on Units)
- **When**: Calculated during splitting/rendering
- **What**: Tokens in final rendered chunk (with breadcrumbs, repeated headers, etc.)
- **Purpose**: Actual chunking decisions, budget calculations
- **Example**:
  ```php
  // Unit after splitting + breadcrumb added
  markdown = "> Path: file.md › H1\n\n```php\nfunction foo() {}\nreturn bar;\n```"
  tokens = 38  // Includes breadcrumb overhead (~12 tokens)
  ```

**Why Both?**

1. **Build-time counts are stable:**
   - Don't change based on chunking context (breadcrumb depth, table header repetition)
   - Always serialized with JSON
   - Useful for understanding document size before chunking
   - Enable pre-chunking analysis and optimization

2. **Chunking-time counts are contextual:**
   - Include breadcrumb overhead (varies by section depth)
   - Include table header repetition (varies by split)
   - Include fence wrappers for code (always added by splitter)
   - **This is what actually matters for budget decisions**

3. **Separation of concerns:**
   - **Models** = document structure + intrinsic token cost
   - **Units** = rendering strategy + contextual token cost

**Key Insight:** Both token counts are *required* but serve different purposes. Build-time counts represent the document as-is. Chunking-time counts represent the document as-chunked with wrappers.

### Why Three Phases (Build, Plan, Render)?

**Decision:** Strict separation of parsing, chunking strategy, and formatting.

**Benefits:**
1. **Testability:** Each phase tested independently
2. **Flexibility:** Change chunking strategy without touching parser
3. **Reusability:** Same MarkdownObject can generate different chunk sets
4. **Clarity:** Clear responsibility boundaries

**Trade-offs:**
- More classes, but each is simpler
- Data passes through multiple transformations, but each is well-defined

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
raw: "![**Important** diagram](img.jpg)"    // Full markdown
```

**Why:**
- Screen readers and browsers expect plain alt text
- `alt="**Important** diagram"` would be spoken as "asterisk asterisk Important..."
- Raw version preserves original markdown for reconstruction

#### 3. Everything Else (Raw Only)

Text, Code, and Table nodes only store `raw` content - no plain text extraction needed.

**Key Point:** Breadcrumbs **never** include non-heading content. They are exclusively the heading hierarchy path through the document.

### Why Breadcrumb-First Navigation?

**Decision:** Breadcrumbs start with filename, not just heading path.

**Format:** `filename.md › Chapter 1 › Section 1.1`

**Reasoning:**
- Multiple docs in same vector store
- Filename provides critical context
- Mirrors file system navigation (familiar)

### Why Greedy Packing with Final Stretch?

**Decision:** Pack units greedily to target, but allow the final unit to stretch beyond target (up to hardCap).

**Example:**
```
Units: [300, 200, 400]
Target: 500, hardCap: 1000

Iteration 1: 300 < 500 → accumulate
Iteration 2: 300+200=500 ≤ 500 → accumulate
Iteration 3: 500+400=900 > 500 BUT is last unit AND 900 ≤ 1000
Decision: Include all three in one chunk (final stretch)
Result: One 900-token chunk instead of [500] + [400]
```

**Reasoning:**
1. **Prevents tiny trailing chunks** - Last unit often creates small leftover chunks
2. **Semantic coherence** - Keeps section content together when possible
3. **Efficient packing** - Maximizes chunk utilization without breaking semantic boundaries
4. **Configurable safety** - Hard cap prevents oversized chunks

**Early Threshold (90%):** If a single unit alone ≥ 450 tokens (with 500 target), it's "good enough" to emit immediately rather than trying to pack more content. This prevents under-filled chunks when dealing with naturally large units.

---

## Testing Strategy

### Test Organization

```
tests/
├── Build/
│   └── MarkdownObjectBuilderTest.php   # 13 tests - Parser correctness
├── Model/
│   └── MarkdownObjectTest.php          # 14 tests - JSON + chunking integration
├── Planning/
│   ├── SectionPlannerTest.php          # 10 tests - Section flattening
│   ├── UnitPlannerTest.php             # 8 tests - Block→Unit transformation
│   └── PackerTest.php                  # 15 tests - Bin-packing algorithm
└── Render/
    └── RendererTest.php                # 15 tests - Chunk assembly + formatting
```

### Test Coverage

**Build Tests:** Focus on structure and token counting
- Heading nesting
- Block type recognition
- Position tracking
- Raw content preservation
- Token count calculation (with and without tokenizer)
- Recursive token aggregation for headings

**Model Tests:** Focus on behavior
- JSON serialization/deserialization
- Chunk generation
- Breadcrumb handling
- Template application

**Planning Tests:** Focus on entry points and outputs
- **SectionPlanner:** Breadcrumb generation, preamble handling, tree flattening
- **UnitPlanner:** Splitter integration, token counting, mixed content types
- **Packer:** Greedy algorithm, final stretch, early threshold, edge cases

**Render Tests:** Focus on assembly and formatting
- **Renderer:** Breadcrumb rendering, heading inclusion, unit joining, token counting, template application

**Philosophy:** Test the public API (entry points) and verify outputs, not internal implementation details.

### Running Tests

```bash
# All tests
vendor/bin/pest

# Specific suite
vendor/bin/pest tests/Build/
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

### Custom Template

```php
use BenBjurstrom\MarkdownObject\Render\ChunkTemplate;

$template = new ChunkTemplate(
    breadcrumbFmt: '### Location: %s',  // Custom format
    breadcrumbJoin: ' > ',               // Custom separator
    includeFilename: true,               // Include filename
    headingOnce: true,                   // Heading in first chunk only
    joinWith: "\n\n",                    // Block separator
    repeatTableHeaderOnSplit: true       // Repeat table headers
);

$chunks = $mdObj->toMarkdownChunks(tpl: $template);
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

---

## Key Takeaways for Coding Agents

1. **Two levels of token counting** - Build-time (required, on models, stable) vs. Chunking-time (required, on Units, contextual with wrappers)
2. **Build-time tokens always calculated** - Tokenizer is required for `build()`; represents intrinsic markdown token cost; always serializable
3. **Chunking-time tokens include wrappers** - Code fences (~6 tokens), breadcrumbs (variable by depth), table headers (repeated) - this drives packing
4. **Greedy algorithms throughout** - TextSplitter groups sentences; Packer accumulates units to target
5. **Final stretch behavior** - Last unit can exceed target (up to hardCap) to prevent tiny trailing chunks
6. **Three-phase architecture** - Build (structure + tokens), Plan (strategy), Render (presentation) - each testable independently
7. **Breadcrumb tokens vary by depth** - `file.md` vs `file.md › H1 › H2` - subtracted per-section from budget at chunking time
8. **Plain text extraction is selective** - Only headings (for breadcrumbs) and images (for alt text); everything else stays raw
9. **Semantic boundaries matter** - Chunks respect document structure (headings, paragraphs, sentences) not arbitrary character counts
10. **Early threshold (90%)** - Single units ≥ threshold emit immediately; prevents under-filled chunks
11. **Position tracking** - Byte/line spans enable source mapping for future features
12. **Heading token counts are recursive** - Include heading line + sum of all children's tokens (including nested headings)

---

## Worked Example: Packer Behavior

To understand how the Packer works in practice, let's trace through a concrete example:

**Input:**
```
Section units: [200 tokens, 250 tokens, 150 tokens, 400 tokens]
Target: 500 tokens
HardCap: 1000 tokens
EarlyThreshold: 450 tokens (90% of target)
```

**Packing Process:**

```
i=0: unit=200, sum=0
     next=0+200=200 ≤ 500 → accumulate
     sum=200

i=1: unit=250, sum=200
     next=200+250=450 ≤ 500 → accumulate
     sum=450

i=2: unit=150, sum=450
     next=450+150=600 > 500 → exceeds target!
     NOT last unit → flush [0..1] (450 tokens)
     Emit chunk: {start: 0, end: 1}
     Start new: sum=150

i=3: unit=400, sum=150
     next=150+400=550 > 500 → exceeds target!
     IS last unit AND 550 ≤ 1000 → final stretch!
     Include it: {start: 2, end: 3}
```

**Result:** 2 chunks
- Chunk 1: Units [0,1] = 450 tokens
- Chunk 2: Units [2,3] = 550 tokens (stretched beyond target)

**Key Insight:** Without final stretch, Chunk 2 would split into [150] + [400], creating an under-filled chunk. The algorithm keeps them together for better semantic coherence.

---

## Quick Reference

| Need to... | Look in... |
|------------|------------|
| Add new block type | `src/Build/MarkdownObjectBuilder.php` + `src/Model/` |
| Change chunking strategy | `src/Planning/Packer.php` |
| Customize chunk format | `src/Render/ChunkTemplate.php` |
| Adjust splitting logic | `src/Planning/*Splitter.php` |
| Debug token counting | `src/Planning/UnitPlanner.php` (creates Units) |
| Understand JSON structure | `src/Model/MarkdownObject.php` (serNode/deSerNodes) |
| See data flow | This document, "Data Flow Pipeline" |

---

## Version

This architecture guide is current as of the implementation that:
- Uses `MarkdownNode` abstract base class with centralized serialization/deserialization
- Added optional `tokenCount` property to all model classes (nullable, calculated at build time)
- Two-level token counting: build-time (models) and chunking-time (Units)
- Uses PHP 8.2+ features (readonly classes, enums, promoted properties)
- Integrates League CommonMark 2.7+ and Yethee Tiktoken
- Includes comprehensive test coverage across all three phases:
  - Build (16 tests) - Parser correctness, token counting
  - Planning (33 tests) - SectionPlanner, UnitPlanner, Packer
  - Render (15 tests) - Chunk assembly and formatting
- Type-safe polymorphic deserialization via `__type` field

**Test Status:** 80 tests passing - PHPStan level 10 clean

For implementation details, see the code. For rationale, see "Design Decisions" above.
