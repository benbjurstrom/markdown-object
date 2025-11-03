<?php

namespace BenBjurstrom\MarkdownObject\Model;

use BenBjurstrom\MarkdownObject\Chunking\CodeSplitter;
use BenBjurstrom\MarkdownObject\Chunking\HierarchicalChunker;
use BenBjurstrom\MarkdownObject\Chunking\TableSplitter;
use BenBjurstrom\MarkdownObject\Chunking\TextSplitter;
use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use BenBjurstrom\MarkdownObject\Tokenizer\TikTokenizer;
use InvalidArgumentException;

final class MarkdownObject implements \JsonSerializable
{
    /** @param list<MarkdownNode> $children */
    public function __construct(
        public string $filename,
        public array $children = [],
        public int $tokenCount = 0
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'schemaVersion' => 1,
            'filename' => $this->filename,
            'tokenCount' => $this->tokenCount,
            'children' => array_map(
                static function (MarkdownNode $child): array {
                    return $child->serialize();
                },
                $this->children
            ),
        ];
    }

    public function toJson(int $flags = 0): string
    {
        $json = json_encode($this, $flags | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MarkdownObject to JSON');
        }

        return $json;
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Decoded markdown object must be an array.');
        }

        $filenameValue = $data['filename'] ?? null;
        $filename = is_string($filenameValue) ? $filenameValue : '(unknown)';

        $tokenCountValue = $data['tokenCount'] ?? null;
        $tokenCount = is_int($tokenCountValue) ? $tokenCountValue : 0;

        $childrenValue = $data['children'] ?? null;
        if ($childrenValue !== null && ! is_array($childrenValue)) {
            throw new InvalidArgumentException('Markdown object children must be an array when provided.');
        }

        /** @var array<int|string, mixed> $childrenArray */
        $childrenArray = is_array($childrenValue) ? $childrenValue : [];

        $obj = new self($filename, [], $tokenCount);
        $obj->children = self::deserializeNodes($childrenArray);

        return $obj;
    }

    /**
     * @param  array<int|string, mixed>  $arr
     * @return list<MarkdownNode>
     */
    private static function deserializeNodes(array $arr): array
    {
        $nodes = [];
        foreach ($arr as $nodePayload) {
            if (! is_array($nodePayload)) {
                throw new InvalidArgumentException('Markdown node payload must be an array.');
            }

            MarkdownNode::assertStringKeys($nodePayload, 'Markdown node payload');

            /** @var array<string, mixed> $node */
            $node = $nodePayload;
            $nodes[] = MarkdownNode::hydrate($node);
        }

        return $nodes;
    }

    /** @return list<\BenBjurstrom\MarkdownObject\Chunking\EmittedChunk> */
    public function toMarkdownChunks(
        int $target = 512,
        int $hardCap = 1024,
        ?Tokenizer $tok = null,
        bool $repeatTableHeaders = true
    ): array {
        $tok ??= TikTokenizer::forModel('gpt-3.5-turbo-0301');

        $chunker = new HierarchicalChunker(
            tokenizer: $tok,
            target: $target,
            hardCap: $hardCap,
            textSplitter: new TextSplitter,
            codeSplitter: new CodeSplitter,
            tableSplitter: new TableSplitter($repeatTableHeaders)
        );

        return $chunker->chunk($this);
    }
}
