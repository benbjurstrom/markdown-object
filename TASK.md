# Task: Hierarchical Chunk Packing

## Problem Statement

The current chunking system creates one chunk per heading section, regardless of size. This leads to several issues:

### 1. Empty or Near-Empty Chunks

When a parent heading has little or no direct content, it creates a nearly useless chunk:

```md
## Chapter 2
### Section 1
{500 tokens of content}
```

**Current Behavior:** Creates 2 chunks
- Chunk 1: Just "## Chapter 2" (~5 tokens) ← **Useless for vector search!**
- Chunk 2: Section 1 content (500 tokens)

**Problem:** A chunk with only a heading provides no semantic value for retrieval. When embedded and searched, it won't match any meaningful queries.

### 2. Over-Fragmentation

Related content under the same parent heading gets split unnecessarily:

```md
## A Heading
{100 tokens}
### Subheading 1
{200 tokens}
### Subheading 2
{300 tokens}
### Subheading 3
{300 tokens}
## B Heading
{300 tokens}
```

**Current Behavior:** 5 chunks (one per heading)

**Problem:**
- Each subsection is isolated from its parent context
- Vector search loses the hierarchical relationship
- More chunks = more embeddings = higher cost
- Harder to retrieve semantically complete units

### 3. Loss of Context

When a heading's subsections are split into separate chunks, the parent context is lost in vector search results. A user searching for information might get a subsection chunk without understanding which major section it belongs to.

---

## Desired Behavior

### Goal: Maximize Semantic Coherence

Pack child sections into parent chunks whenever possible, only splitting when content exceeds the hard capacity limit.

**New Behavior for Example Above:** 3 chunks

```
[Chunk 1] - 600 tokens
Breadcrumb: ["filename.md", "A Heading"]

## A Heading
{100 tokens}

### Subheading 1
{200 tokens}

### Subheading 2
{300 tokens}
```

```
[Chunk 2] - 300 tokens
Breadcrumb: ["filename.md", "A Heading", "Subheading 3"]

### Subheading 3
{300 tokens}
```

```
[Chunk 3] - 300 tokens
Breadcrumb: ["filename.md", "B Heading"]

## B Heading
{300 tokens}
```

**Benefits:**
- ✅ No empty/near-empty chunks
- ✅ Related content stays together
- ✅ Fewer chunks = fewer embeddings = lower cost
- ✅ Better retrieval: chunks contain complete semantic units with full context
- ✅ Parent headings always appear with at least some child content

---

## Design Principles

### 1. Greedy Top-Down Packing

Start from the top of the document and try to fit as much as possible:
1. Can the entire document fit in one chunk? → Yes: 1 chunk with just filename breadcrumb
2. No? Split by top-level headings (H1 or H2)
3. For each top-level heading, try to pack all its children together
4. If a heading's subtree is too large, split by its children and recurse

### 2. Hard Cap for Hierarchy, Target for Content

- **Heading hierarchy decisions:** Only hardCap matters. If parent + all children ≤ hardCap, keep together.
- **Non-heading content:** Target still applies. Long text blocks, code blocks, and tables split at target boundaries.

**Example:**
```md
## Heading
{600-token text paragraph}
### Child 1
{300 tokens}
```

The 600-token paragraph would be split into 2 units (using target), but the heading structure would still try to pack the paragraph units + Child 1 together (using hardCap).

### 3. Per-Parent Decision Making

Each parent heading independently decides whether to pack its children. Some parents may keep all children together while others must split.

**Example:**
```md
## Section A
{700 tokens total - fits under hardCap}

## Section B
{1500 tokens total - exceeds hardCap, must split}

## Section C
{300 tokens total - fits under hardCap}
```

Result: Section A and C each become 1 chunk. Only Section B gets split into multiple chunks.

### 4. No Artificial Depth Limits

The algorithm works at any heading level (H1-H6) and handles any depth of nesting. If deeply nested content all fits under hardCap, keep it together.

---

## Success Criteria

### Before & After Comparison

**Small File (< hardCap):**
- Before: 5+ chunks (one per heading)
- After: 1 chunk (entire file together)

**Medium File (fits under top-level headings):**
- Before: 10+ chunks (one per heading/subheading)
- After: 3-5 chunks (one per major section with children packed in)

**Large File (some sections exceed hardCap):**
- Before: 20+ chunks (very fragmented)
- After: 8-12 chunks (split only where necessary)

### Quality Improvements

1. **No empty chunks:** Every chunk contains meaningful content for retrieval
2. **Better context:** Child content includes parent context, improving retrieval relevance
3. **Cost efficiency:** Fewer chunks = fewer embeddings = lower API costs
4. **Semantic coherence:** Chunks represent complete logical units, not arbitrary heading boundaries

---

## Example Use Cases

### Use Case 1: Documentation with Many Small Sections

**Before:** 50 chunks (many small, fragmented)
**After:** 15 chunks (packed by major topics)
**Benefit:** Better retrieval of complete explanations

### Use Case 2: API Documentation

```md
## Authentication
### JWT Tokens
{200 tokens}
### API Keys
{150 tokens}
### OAuth
{300 tokens}
```

**Before:** 4 chunks (1 parent + 3 children)
**After:** 1 chunk (650 tokens - all related auth info together)
**Benefit:** Users searching for "authentication" get complete auth overview, not fragments

### Use Case 3: Tutorial with Step Subsections

```md
## Getting Started
{100 tokens}
### Step 1: Installation
{400 tokens}
### Step 2: Configuration
{400 tokens}
```

**Before:** 3 chunks
**After:** 1 chunk (900 tokens - complete getting started guide)
**Benefit:** Complete tutorial in one retrievable unit

---

## Technical Requirements

### 1. Token Counting Accuracy

- Breadcrumbs must be accounted for in token counts
- Different breadcrumb depths = different token overhead
- All wrapper tokens (code fences, table headers) already handled by existing splitters

### 2. Breadcrumb Handling

When a child section must be split out separately:
- Breadcrumb includes full parent path: `["file.md", "Parent", "Child"]`
- Parent heading text is NOT duplicated in chunk content (breadcrumb provides context)

### 3. Backward Compatibility Considerations

This is a **breaking change** - chunk counts and boundaries will differ:
- Existing applications expecting specific chunk counts will need updates
- Embeddings will be different (content distribution changes)
- May require re-indexing vector databases

Consider:
- Major version bump (2.0.0)
- Clear migration documentation
- Optional: feature flag or separate method for gradual adoption

---

## Out of Scope

The following are explicitly NOT part of this task:

1. **Changing target/hardCap semantics for content:** Text, code, and table splitting logic remains unchanged
2. **New content types:** No new markdown elements supported
3. **Performance optimization:** Focus on correctness first
4. **Template/rendering changes:** ChunkTemplate behavior stays the same
5. **JSON serialization changes:** MarkdownObject structure unchanged

---

## Related Documentation

- See `EXAMPLES.md` for 13 detailed examples covering all edge cases
- See `ARCHITECTURE.md` for current system architecture
- All examples in `EXAMPLES.md` serve as acceptance criteria
