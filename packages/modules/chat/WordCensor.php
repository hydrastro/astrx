<?php
declare(strict_types=1);

namespace AstrX\Chat;

/**
 * Applies the admin-configured word censor to a message.
 *
 * Two modes (from ChatConfig): 'replace' swaps each matched term for the
 * configured replacement; 'block' rejects the whole message if any term matches.
 * Matching is case-insensitive and Unicode-aware. Terms are treated as literals
 * (preg_quote), never as user-supplied regex.
 */
final class WordCensor
{
    public function __construct(private readonly ChatConfig $config) {}

    /**
     * @return array{blocked: bool, text: string}
     */
    public function apply(string $text): array
    {
        $words = [];
        foreach ($this->config->censorWords() as $w) {
            $w = trim($w);
            if ($w !== '') {
                $words[] = $w;
            }
        }
        if ($words === []) {
            return ['blocked' => false, 'text' => $text];
        }

        $block = $this->config->censorMode() === 'block';
        $repl  = $this->config->censorReplacement();
        $out   = $text;

        foreach ($words as $word) {
            $pattern = '/' . preg_quote($word, '/') . '/iu';
            if ($block) {
                if (preg_match($pattern, $out) === 1) {
                    return ['blocked' => true, 'text' => $text];
                }
                continue;
            }
            $replaced = preg_replace($pattern, $repl, $out);
            if (is_string($replaced)) {
                $out = $replaced;
            }
        }

        return ['blocked' => false, 'text' => $out];
    }
}
