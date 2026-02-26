<?php
declare(strict_types=1);

namespace AstrX\Result;

/**
 * @template T
 */
final class Result
{
    private static ?object $SENTINEL = null;

    /** @var T|object */
    private mixed $valueOrSentinel;

    private mixed $error; // domain-defined (string/enum/object/etc) or null
    private Diagnostics $diagnostics;

    private function __construct(mixed $valueOrSentinel, mixed $error, Diagnostics $diagnostics)
    {
        $this->valueOrSentinel = $valueOrSentinel;
        $this->error = $error;
        $this->diagnostics = $diagnostics;
    }

    private static function sentinel(): object
    {
        return self::$SENTINEL ??= new class() {};
    }

    /** @template T @param T $value @return self */
    public static function ok(mixed $value, ?Diagnostics $diagnostics = null): self
    {
        return new self($value, null, $diagnostics ?? Diagnostics::empty());
    }

    public static function err(mixed $error, ?Diagnostics $diagnostics = null): self
    {
        return new self(self::sentinel(), $error, $diagnostics ?? Diagnostics::empty());
    }

    public function isOk(): bool
    {
        return $this->error === null;
    }

    public function error(): mixed
    {
        return $this->error;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    /** @return T */
    public function unwrap(): mixed
    {
        if ($this->error !== null) {
            throw new \LogicException('unwrap() on failed Result');
        }
        return $this->valueOrSentinel;
    }

    public function valueOr(mixed $default): mixed
    {
        return $this->error === null ? $this->valueOrSentinel : $default;
    }

    /** Attach more diagnostics (pure). */
    public function withDiagnostics(Diagnostics $more): self
    {
        $d = $this->diagnostics->concat($more);
        return $this->isOk() ? self::ok($this->valueOrSentinel, $d) : self::err($this->error, $d);
    }

    /**
     * @template U
     * @param callable(T): U $f
     * @return Result
     */
    public function map(callable $f): self
    {
        if (!$this->isOk()) {
            /** @var Result<U> */
            return self::err($this->error, $this->diagnostics);
        }

        /** @var Result<U> */
        return self::ok($f($this->unwrap()), $this->diagnostics);
    }

    /**
     * @template U
     * @param callable(T): Result $f
     * @return Result
     */
    public function andThen(callable $f): self
    {
        if (!$this->isOk()) {
            /** @var Result<U> */
            return self::err($this->error, $this->diagnostics);
        }

        /** @var Result<U> $next */
        $next = $f($this->unwrap());
        $merged = $this->diagnostics->concat($next->diagnostics());

        return $next->isOk()
            ? self::ok($next->unwrap(), $merged)
            : self::err($next->error(), $merged);
    }

    public function drainTo(DiagnosticSinkInterface $sink): self
    {
        $sink->emitAll($this->diagnostics);
        return $this;
    }
}
