<?php
declare(strict_types=1);

namespace AstrX\Result;

final class Diagnostics implements \IteratorAggregate, \Countable
{
    /** @var list<DiagnosticInterface> */
    private array $items;

    private static ?self $EMPTY = null;

    /** @param list<DiagnosticInterface> $items */
    private function __construct(array $items)
    {
        $this->items = $items;
    }

    public static function empty(): self
    {
        return self::$EMPTY ??= new self([]);
    }

    public static function of(DiagnosticInterface ...$items): self
    {
        return $items === [] ? self::empty() : new self($items);
    }

    public function with(DiagnosticInterface $d): self
    {
        $items = $this->items;
        $items[] = $d;
        return new self($items);
    }

    public function concat(self $other): self
    {
        if ($this->items === []) return $other;
        if ($other->items === []) return $this;
        return new self(array_merge($this->items, $other->items));
    }

    /** @param callable(DiagnosticInterface): bool $predicate */
    public function filter(callable $predicate): self
    {
        if ($this->items === []) return $this;

        $out = [];
        foreach ($this->items as $d) {
            if ($predicate($d)) $out[] = $d;
        }
        return $out === [] ? self::empty() : new self($out);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /** @return list<DiagnosticInterface> */
    public function toArray(): array
    {
        return $this->items;
    }
}
