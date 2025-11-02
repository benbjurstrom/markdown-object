<?php

namespace BenBjurstrom\MarkdownObject\Model;

use InvalidArgumentException;

final readonly class Position
{
    public function __construct(
        public ByteSpan $bytes,
        public ?LineSpan $lines = null
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        $bytes = $data['bytes'] ?? null;
        if (! is_array($bytes)) {
            throw new InvalidArgumentException('Position bytes must be an array.');
        }

        $startByte = $bytes['startByte'] ?? null;
        $endByte = $bytes['endByte'] ?? null;

        if (! is_int($startByte) || ! is_int($endByte)) {
            throw new InvalidArgumentException('Position bytes must contain integer startByte and endByte.');
        }

        $lineSpan = null;
        if (array_key_exists('lines', $data)) {
            $lines = $data['lines'];
            if ($lines !== null && ! is_array($lines)) {
                throw new InvalidArgumentException('Position lines must be an array when provided.');
            }

            if (is_array($lines)) {
                $startLine = $lines['startLine'] ?? null;
                $endLine = $lines['endLine'] ?? null;

                if (! is_int($startLine) || ! is_int($endLine)) {
                    throw new InvalidArgumentException('Position lines must contain integer startLine and endLine.');
                }

                $lineSpan = new LineSpan($startLine, $endLine);
            }
        }

        return new self(new ByteSpan($startByte, $endByte), $lineSpan);
    }

    /**
     * @return array{
     *     bytes: array{startByte: int, endByte: int},
     *     lines?: array{startLine: int, endLine: int}
     * }
     */
    public function toArray(): array
    {
        $data = [
            'bytes' => [
                'startByte' => $this->bytes->startByte,
                'endByte' => $this->bytes->endByte,
            ],
        ];

        if ($this->lines !== null) {
            $data['lines'] = [
                'startLine' => $this->lines->startLine,
                'endLine' => $this->lines->endLine,
            ];
        }

        return $data;
    }
}
