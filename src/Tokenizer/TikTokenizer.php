<?php

namespace BenBjurstrom\MarkdownObject\Tokenizer;

use BenBjurstrom\MarkdownObject\Contracts\Tokenizer;
use Yethee\Tiktoken\EncoderProvider;

final class TikTokenizer implements Tokenizer
{
    private function __construct(
        private \Yethee\Tiktoken\Encoder $encoder
    ) {}

    public static function forModel(string $model = 'gpt-3.5-turbo-0301'): self
    {
        $provider = new EncoderProvider;

        return new self($provider->getForModel($model));
    }

    public static function forEncoding(string $encoding = 'p50k_base'): self
    {
        $provider = new EncoderProvider;

        return new self($provider->get($encoding));
    }

    public function count(string $text): int
    {
        /** @var list<int> $ids */
        $ids = $this->encoder->encode($text);

        return \count($ids);
    }
}
