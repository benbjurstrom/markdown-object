# Hierarchical Chunking Examples

> Design philosophy for packing child sections into parent chunks

## Core Design Philosophy

**Greedy Top-Down Fitting Strategy:**

1. **Try to fit everything in one chunk** (breadcrumb = filename only)
2. If too large, **split by H1s** - try to fit all content under each H1 into single chunks
3. If an H1 is too large, **split by H2s** - try to fit all content under each H2 into single chunks
4. Continue recursively through hierarchy (H3, H4, etc.)
5. **No depth limit** - inline children at any depth if they fit
6. **Hard cap is the only constraint for heading hierarchy** - if content fits under hardCap, keep it together
7. **Target is for non-heading content** - long text blocks, code, tables split at target boundaries

**Key Principles:**
- **Maximize semantic coherence** by keeping related content together at the highest possible hierarchy level
- **HardCap for hierarchy, target for content** - when combining headings, only hardCap matters. When splitting text/code/tables, target matters.
- **Breadcrumb provides context** - no need to include parent heading text in child chunks

---

## Example 1: Small File (Everything Fits)

**Input Markdown:**
```md
# Introduction
This is a small document.

## Section 1
Some content here.

### Subsection 1.1
More content.

## Section 2
Final content.
```

**Token Counts:**
- Total: 300 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 1 chunk

```
[Chunk 1] - 300 tokens
Breadcrumb: ["filename.md"]

# Introduction
This is a small document.

## Section 1
Some content here.

### Subsection 1.1
More content.

## Section 2
Final content.
```

**Rationale:** Entire document fits in hardCap, so keep it all together with just filename breadcrumb.

---

## Example 2: Fits Under H1s

**Input Markdown:**
```md
# Chapter 1
{400 tokens}

## Section 1.1
{300 tokens}

## Section 1.2
{200 tokens}

# Chapter 2
{500 tokens}

## Section 2.1
{400 tokens}
```

**Token Counts:**
- Chapter 1 total: 900 tokens
- Chapter 2 total: 900 tokens
- Total: 1800 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 2 chunks

```
[Chunk 1] - 900 tokens
Breadcrumb: ["filename.md", "Chapter 1"]

# Chapter 1
{400 tokens}

## Section 1.1
{300 tokens}

## Section 1.2
{200 tokens}
```

```
[Chunk 2] - 900 tokens
Breadcrumb: ["filename.md", "Chapter 2"]

# Chapter 2
{500 tokens}

## Section 2.1
{400 tokens}
```

**Rationale:** Total doesn't fit in one chunk, but each H1 subtree fits under hardCap, so pack everything under each H1.

---

## Example 3: User's Original Example

**Input Markdown:**
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

**Token Counts:**
- A Heading total: 900 tokens
- B Heading total: 300 tokens
- Total: 1200 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 3 chunks

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

**Rationale:**
- A Heading (900 tokens) exceeds hardCap, so can't keep all together
- Pack greedily: A Heading + Subheading 1 + Subheading 2 = 600 tokens (fits target)
- Subheading 3 (300 tokens) becomes separate chunk with deeper breadcrumb
- B Heading fits alone in one chunk

---

## Example 4: Deep Nesting (All Fits)

**Input Markdown:**
```md
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
```

**Token Counts:**
- Total: 600 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 1 chunk

```
[Chunk 1] - 600 tokens
Breadcrumb: ["filename.md", "H1"]

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
```

**Rationale:** All content fits under hardCap, so keep together. Breadcrumb shows highest-level heading (H1).

---

## Example 5: Mixed Sizes - Greedy Packing

**Input Markdown:**
```md
## Parent
{50 tokens}

### Child 1
{400 tokens}

### Child 2
{450 tokens}

### Child 3
{50 tokens}

### Child 4
{50 tokens}
```

**Token Counts:**
- Parent total: 1000 tokens
- Total: 1000 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 1 chunk

```
[Chunk 1] - 1000 tokens
Breadcrumb: ["filename.md", "Parent"]

## Parent
{50 tokens}

### Child 1
{400 tokens}

### Child 2
{450 tokens}

### Child 3
{50 tokens}

### Child 4
{50 tokens}
```

**Rationale:**
- Total (1000) fits under hardCap (1024) ✓
- When combining headings hierarchically, we maximize semantic coherence
- Keep all related content together under the parent heading
- Target is used for splitting non-heading content (text blocks, code, etc.), not for heading hierarchy decisions

---

## Example 6: Must Split at H2 Level

**Input Markdown:**
```md
# Chapter 1
{100 tokens}

## Section 1.1
{600 tokens}

## Section 1.2
{700 tokens}

## Section 1.3
{500 tokens}
```

**Token Counts:**
- Chapter 1 total: 1900 tokens
- Section 1.1: 600 tokens
- Section 1.2: 700 tokens
- Section 1.3: 500 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 3 chunks

```
[Chunk 1] - 700 tokens
Breadcrumb: ["filename.md", "Chapter 1"]

# Chapter 1
{100 tokens}

## Section 1.1
{600 tokens}
```

```
[Chunk 2] - 700 tokens
Breadcrumb: ["filename.md", "Chapter 1", "Section 1.2"]

## Section 1.2
{700 tokens}
```

```
[Chunk 3] - 500 tokens
Breadcrumb: ["filename.md", "Chapter 1", "Section 1.3"]

## Section 1.3
{500 tokens}
```

**Rationale:**
- Chapter 1 (1900 tokens) exceeds hardCap, so must split
- Try to fit: Chapter 1 + Section 1.1 = 700 tokens ✓ (fits hardCap)
- Section 1.2 (700) becomes separate chunk
- Section 1.3 (500) becomes separate chunk

---

## Example 7: Exceeds HardCap - Must Split Further

**Input Markdown:**
```md
# Chapter 1
{200 tokens}

## Section 1.1
{900 tokens}

### Subsection 1.1.1
{400 tokens}

### Subsection 1.1.2
{400 tokens}
```

**Token Counts:**
- Chapter 1 + Section 1.1 total: 1100 tokens (exceeds hardCap)
- Section 1.1 alone: 900 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 3 chunks

```
[Chunk 1] - 200 tokens
Breadcrumb: ["filename.md", "Chapter 1"]

# Chapter 1
{200 tokens}
```

```
[Chunk 2] - 400 tokens
Breadcrumb: ["filename.md", "Chapter 1", "Section 1.1", "Subsection 1.1.1"]

### Subsection 1.1.1
{400 tokens}
```

```
[Chunk 3] - 400 tokens
Breadcrumb: ["filename.md", "Chapter 1", "Section 1.1", "Subsection 1.1.2"]

### Subsection 1.1.2
{400 tokens}
```

**Rationale:**
- Chapter 1 + Section 1.1 = 1100 > hardCap, can't fit
- Chapter 1 alone (200 tokens) becomes chunk
- Section 1.1 (900 tokens) exceeds hardCap even alone, must split further
- Split by H3s: each subsection becomes separate chunk
- Parent headings (Chapter 1, Section 1.1) are NOT included in child chunks - breadcrumb provides the path

---

## Example 8: Preamble Handling

**Input Markdown:**
```md
This is preamble content before any headings.
{200 tokens}

# First Heading
{300 tokens}

## Subheading
{400 tokens}
```

**Token Counts:**
- Preamble: 200 tokens
- First Heading total: 700 tokens
- Total: 900 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 2 chunks

```
[Chunk 1] - 200 tokens
Breadcrumb: ["filename.md"]

This is preamble content before any headings.
{200 tokens}
```

```
[Chunk 2] - 700 tokens
Breadcrumb: ["filename.md", "First Heading"]

# First Heading
{300 tokens}

## Subheading
{400 tokens}
```

**Rationale:**
- Preamble cannot be inlined into headings (headings come after)
- Preamble gets its own chunk with just filename breadcrumb
- First Heading + Subheading fit together in second chunk

---

## Example 9: Multiple H1s with Varying Sizes

**Input Markdown:**
```md
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
```

**Token Counts:**
- Small Chapter: 100 tokens
- Medium Chapter: 800 tokens (500 + 300)
- Large Chapter: 1300 tokens (600 + 400 + 300)

**Config:** target=512, hardCap=1024

**Expected Output:** 4 chunks

```
[Chunk 1] - 100 tokens
Breadcrumb: ["filename.md", "Small Chapter"]

# Small Chapter
{100 tokens}
```

```
[Chunk 2] - 800 tokens
Breadcrumb: ["filename.md", "Medium Chapter"]

# Medium Chapter
{500 tokens}

## Section 1
{300 tokens}
```

```
[Chunk 3] - 1000 tokens
Breadcrumb: ["filename.md", "Large Chapter"]

# Large Chapter
{600 tokens}

## Section A
{400 tokens}
```

```
[Chunk 4] - 300 tokens
Breadcrumb: ["filename.md", "Large Chapter", "Section A", "Subsection A1"]

### Subsection A1
{300 tokens}
```

**Rationale:**
- Small Chapter: fits alone
- Medium Chapter: 800 tokens, fits with its H2
- Large Chapter: 1300 total exceeds hardCap
  - Try: Large Chapter + Section A = 1000 tokens ✓ (fits hardCap exactly)
  - Subsection A1 becomes separate chunk (couldn't fit in parent)

---

## Example 10: Target vs. HardCap - CLARIFIED

**Input Markdown:**
```md
## Heading
{100 tokens}

### Sub 1
{450 tokens}

### Sub 2
{450 tokens}
```

**Token Counts:**
- Total: 1000 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 1 chunk

```
[Chunk 1] - 1000 tokens
Breadcrumb: ["filename.md", "Heading"]

## Heading
{100 tokens}

### Sub 1
{450 tokens}

### Sub 2
{450 tokens}
```

**Rationale:**
- Everything fits under hardCap (1000 ≤ 1024) ✓
- When combining headings hierarchically, only hardCap matters
- Keep all related content together for semantic coherence
- Target (512) is used for splitting non-heading content (text, code, tables), not heading hierarchy

**Contrast:** If "Heading" had a 600-token text paragraph, that paragraph would be split at target boundaries (512), but the heading structure would still try to fit all children under hardCap.

---

## Algorithm Pseudocode (FINALIZED)

```python
def pack_hierarchically(node, breadcrumb, target, hardCap):
    """
    Pack a heading node and its children into chunks.
    Uses hardCap for hierarchical decisions, target for content splitting.
    Returns list of chunks.
    """
    # Get this node's direct content (non-heading children: text, code, tables)
    direct_content = get_direct_content(node)

    # Split direct content using target (existing splitters handle this)
    # Text blocks, code, tables split at target boundaries
    direct_units = split_content(direct_content, target, hardCap)
    direct_tokens = sum(unit.tokens for unit in direct_units)

    # Get all child heading nodes
    child_headings = get_child_headings(node)

    # Base case: no children, just emit content
    if not child_headings:
        if direct_tokens <= hardCap:
            return [Chunk(breadcrumb, direct_units)]
        else:
            # Direct content already split by splitters, pack into chunks
            return pack_units_into_chunks(direct_units, breadcrumb, target, hardCap)

    # Try to fit everything under this heading
    total_tokens = direct_tokens + sum(count_all_recursive(child) for child in child_headings)

    if total_tokens <= hardCap:
        # Everything fits! Pack it all together
        # Inline all child headings recursively
        all_content = direct_units
        for child in child_headings:
            all_content.append(child.heading_unit)  # The heading itself
            all_content.extend(flatten_all_recursive(child))
        return [Chunk(breadcrumb, all_content)]

    # Can't fit everything. Use greedy packing with hardCap.
    chunks = []
    accumulated = direct_units.copy()
    current_tokens = direct_tokens

    for child in child_headings:
        child_tokens = count_all_recursive(child)
        child_breadcrumb = breadcrumb + [child.text]

        # Can we fit this child in current accumulation?
        if current_tokens + child_tokens <= hardCap:
            # Yes, inline it completely
            accumulated.append(child.heading_unit)
            accumulated.extend(flatten_all_recursive(child))
            current_tokens += child_tokens
        else:
            # Doesn't fit under hardCap
            # Emit accumulated (if any), then recurse on child
            if accumulated:
                chunks.append(Chunk(breadcrumb, accumulated))

            # Recursively process child (may split further)
            chunks.extend(pack_hierarchically(child, child_breadcrumb, target, hardCap))

            accumulated = []
            current_tokens = 0

    # Emit any remaining accumulated content
    if accumulated:
        chunks.append(Chunk(breadcrumb, accumulated))

    return chunks


def count_all_recursive(heading_node):
    """Count tokens for a heading and ALL its descendants."""
    # Heading itself
    total = count_tokens(heading_node.heading_line)

    # Direct content (text, code, tables - already split by splitters)
    direct_units = split_content(heading_node.direct_content, target, hardCap)
    total += sum(unit.tokens for unit in direct_units)

    # Child headings (recursive)
    for child in heading_node.child_headings:
        total += count_all_recursive(child)

    return total


def flatten_all_recursive(heading_node):
    """Flatten a heading and all descendants into a list of units."""
    units = []

    # Direct content
    units.extend(split_content(heading_node.direct_content, target, hardCap))

    # Child headings
    for child in heading_node.child_headings:
        units.append(child.heading_unit)  # Child heading itself
        units.extend(flatten_all_recursive(child))  # Recurse

    return units
```

**Key Points:**
1. **Target for content, hardCap for hierarchy:** Direct content (text, code, tables) split at target boundaries via existing splitters. Heading hierarchy decisions use only hardCap.
2. **Greedy with hardCap:** Accumulate children while total ≤ hardCap. When next child doesn't fit, emit and recurse.
3. **Recursive splitting:** If a child heading's subtree is too large, recursively apply the algorithm to that subtree.
4. **No parent heading duplication:** Child chunks don't include parent heading text - breadcrumb provides context.

---

## Example 11: Empty Parent Content - CLARIFIED

**Input Markdown (fits under hardCap):**
```md
## Parent Heading
### Child 1
{300 tokens}

### Child 2
{300 tokens}
```

**Token Counts:**
- Total: 600 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 1 chunk

```
[Chunk 1] - 600 tokens
Breadcrumb: ["filename.md", "Parent Heading"]

## Parent Heading

### Child 1
{300 tokens}

### Child 2
{300 tokens}
```

**Rationale:** Everything fits under hardCap, keep together under parent breadcrumb.

---

**Input Markdown (one child too large):**
```md
## Parent Heading
### Child 1
{300 tokens}

### Child 2
{800 tokens}
```

**Token Counts:**
- Total: 1100 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 2 chunks

```
[Chunk 1] - 300 tokens
Breadcrumb: ["filename.md", "Parent Heading"]

## Parent Heading

### Child 1
{300 tokens}
```

```
[Chunk 2] - 800 tokens
Breadcrumb: ["filename.md", "Parent Heading", "Child 2"]

### Child 2
{800 tokens}
```

**Rationale:**
- Total (1100) exceeds hardCap, must split
- Greedily pack: Parent Heading + Child 1 = 300 tokens (fits hardCap) ✓
- Child 2 (800 tokens) becomes separate chunk with deeper breadcrumb

---

## Design Decisions - FINALIZED

1. ✅ **Target vs. HardCap:** Maximize semantic coherence. When combining headings hierarchically, only hardCap matters. Target is used for splitting non-heading content (text blocks, code, tables).

2. ✅ **Breadcrumb Strategy:** Child sections always include full parent path in breadcrumb.

3. ✅ **Parent Heading in Child Chunks:** Do NOT include parent heading text in child chunks - breadcrumb provides the path.

4. ✅ **Empty Parent Content:** Still try to inline children greedily under the parent heading. The parent heading serves as a container even with no direct content.

5. ✅ **Preamble Special Case:** Preamble (content before any headings) gets its own chunk with just filename breadcrumb. Cannot be inlined into headings that come after.

---

## Example 12: Mixed H2s - Only Some Need Splitting

**Input Markdown:**
```md
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
```

**Token Counts:**
- Section A total: 700 tokens (A + A.1)
- Section B total: 1500 tokens (B + B.1 + B.2 + B.3) ← **exceeds hardCap!**
- Section C total: 300 tokens (C + C.1)

**Config:** target=512, hardCap=1024

**Expected Output:** 4 chunks

```
[Chunk 1] - 700 tokens
Breadcrumb: ["filename.md", "Section A"]

## Section A
{400 tokens}

### Subsection A.1
{300 tokens}
```

```
[Chunk 2] - 800 tokens
Breadcrumb: ["filename.md", "Section B"]

## Section B
{500 tokens}

### Subsection B.1
{300 tokens}
```

```
[Chunk 3] - 700 tokens
Breadcrumb: ["filename.md", "Section B", "Subsection B.2"]

### Subsection B.2
{400 tokens}

### Subsection B.3
{300 tokens}
```

```
[Chunk 4] - 300 tokens
Breadcrumb: ["filename.md", "Section C"]

## Section C
{200 tokens}

### Subsection C.1
{100 tokens}
```

**Rationale:**
- **Section A:** 700 tokens ≤ hardCap → keep all together in 1 chunk ✓
- **Section B:** 1500 tokens > hardCap → must split
  - Greedily pack: Section B + Subsection B.1 = 800 tokens ≤ hardCap ✓
  - Subsection B.2 + B.3 = 700 tokens → combine in second chunk ✓
- **Section C:** 300 tokens ≤ hardCap → keep all together in 1 chunk ✓

**Key Insight:** Splitting decisions are made **per parent heading**, not globally. Section A and C stay intact even though Section B needed splitting.

---

## Example 13: No H1 - H2s as Direct Children of File

**Input Markdown:**
```md
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
```

**Token Counts:**
- Introduction total: 900 tokens (Intro + Background + Motivation)
- Methods total: 1500 tokens (Methods + Approach 1 + Approach 2)
- Conclusion: 200 tokens

**Config:** target=512, hardCap=1024

**Expected Output:** 4 chunks

```
[Chunk 1] - 900 tokens
Breadcrumb: ["filename.md", "Introduction"]

## Introduction
{300 tokens}

### Background
{400 tokens}

### Motivation
{200 tokens}
```

```
[Chunk 2] - 900 tokens
Breadcrumb: ["filename.md", "Methods"]

## Methods
{500 tokens}

### Approach 1
{400 tokens}
```

```
[Chunk 3] - 600 tokens
Breadcrumb: ["filename.md", "Methods", "Approach 2"]

### Approach 2
{600 tokens}
```

```
[Chunk 4] - 200 tokens
Breadcrumb: ["filename.md", "Conclusion"]

## Conclusion
{200 tokens}
```

**Rationale:**
- **No H1 headings in file** - H2s are top-level headings
- **File acts as root container** - each H2 is a direct child
- **Introduction:** 900 tokens ≤ hardCap → pack all together ✓
- **Methods:** 1500 tokens > hardCap → must split
  - Methods + Approach 1 = 900 tokens ≤ hardCap ✓
  - Approach 2 = 600 tokens becomes separate chunk
- **Conclusion:** Fits alone

**Key Insight:** The hierarchical packing algorithm works the same whether headings start at H1, H2, or any level. The file itself is the root container.

---

## Testing Checklist

- [ ] Small file (everything fits) → 1 chunk with filename breadcrumb (Example 1)
- [ ] Medium file (fits under H1s) → N chunks, one per H1 subtree (Example 2)
- [ ] User's original example → 3 chunks as specified (Example 3)
- [ ] Deep nesting (all fits) → 1 chunk with appropriate breadcrumb (Example 4)
- [ ] Mixed sizes with greedy packing → correct split points (Example 5)
- [ ] Must split at H2 level → proper breadcrumb depth (Example 6)
- [ ] Exceeds hardCap (must split further) → recursive splitting (Example 7)
- [ ] Preamble handling → separate chunk with filename breadcrumb (Example 8)
- [ ] Multiple H1s with varying sizes → correct per-subtree packing (Example 9)
- [ ] Target vs. hardCap behavior → consistent with chosen interpretation (Example 10)
- [ ] Empty parent content → greedy inlining still applies (Example 11)
- [ ] **Mixed H2s - only some need splitting** → per-parent decisions (Example 12)
- [ ] **No H1 - H2s as direct children** → file as root container (Example 13)
