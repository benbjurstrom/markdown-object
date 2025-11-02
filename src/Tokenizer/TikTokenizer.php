<?php

namespace BenBjurstrom\MarkdownObject\Tokenizer;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use Yethee\Tiktoken\EncoderProvider;

final class TikTokenizer implements Tokenizer
{
    /** @var array<string, self> */
    private static array $modelCache = [];

    /** @var array<string, self> */
    private static array $encodingCache = [];

    private static ?EncoderProvider $provider = null;

    private function __construct(
        private \Yethee\Tiktoken\Encoder $encoder
    ) {}

    public static function forModel(string $model = 'gpt-3.5-turbo-0301'): self
    {
        if ($model === '') {
            throw new \InvalidArgumentException('Model name cannot be empty');
        }

        return self::$modelCache[$model] ??= new self(self::provider()->getForModel($model));
    }

    public static function forEncoding(string $encoding = 'p50k_base'): self
    {
        if ($encoding === '') {
            throw new \InvalidArgumentException('Encoding name cannot be empty');
        }

        return self::$encodingCache[$encoding] ??= new self(self::provider()->get($encoding));
    }

    private static function provider(): EncoderProvider
    {
        return self::$provider ??= new EncoderProvider;
    }

    public function count(string $text): int
    {
        /** @var list<int> $ids */
        $ids = $this->encoder->encode($text);

        return \count($ids);
    }
}
