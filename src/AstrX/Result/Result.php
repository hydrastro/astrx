<?php
declare(strict_types=1);

namespace AstrX\Result;

/**
 * @template-covariant T
 */
final class Result
{
    private static ?object $SENTINEL = null;

    /** @var T */
    private mixed $valueOrSentinel;

    /** Domain-defined error value (string/enum/object/etc.), or the sentinel when ok. */
    private mixed $error;

    private Diagnostics $diagnostics;

    /** Tracks ok/err state independently from $error to allow null as a valid error. */
    private bool $ok;

    private function __construct(mixed $valueOrSentinel, mixed $error, Diagnostics $diagnostics, bool $ok)
    {
        $this->valueOrSentinel = $valueOrSentinel;
        $this->error           = $error;
        $this->diagnostics     = $diagnostics;
        $this->ok              = $ok;
    }

    private static function sentinel(): object
    {
        return self::$SENTINEL ??= new class () {};
    }

    /**
     * @template TValue
     * @param TValue $value
     * @return self<TValue>
     */
    public static function ok(mixed $value, ?Diagnostics $diagnostics = null): self
    {
        return new self($value, self::sentinel(), $diagnostics ?? Diagnostics::empty(), true);
    }

    /** @return self<never> */
    public static function err(mixed $error, ?Diagnostics $diagnostics = null): self
    {
        return new self(self::sentinel(), $error, $diagnostics ?? Diagnostics::empty(), false);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isErr(): bool
    {
        return !$this->ok;
    }

    public function error(): mixed
    {
        return $this->error;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    /**
     * @return T
     * @throws \LogicException if the result is an error
     */
    /** @return T */
    public function unwrap(): mixed
    {
        if (!$this->ok) {
            throw new \LogicException('Called unwrap() on a failed Result.');
        }
        /** @var T $value */
        $value = $this->valueOrSentinel;
        return $value;
    }

    public function valueOr(mixed $default): mixed
    {
        return $this->ok ? $this->valueOrSentinel : $default;
    }

    /** @return self<T> */
    public function withDiagnostics(Diagnostics $more): self
    {
        $merged = $this->diagnostics->concat($more);
        return $this->ok
            ? self::ok($this->valueOrSentinel, $merged)
            : self::err($this->error, $merged);
    }

    /**
     * @template U
     * @param callable(T): U $f
     * @return self<U>
     */
    public function map(callable $f): self
    {
        if (!$this->ok) {
            /** @var self<U> */
            return self::err($this->error, $this->diagnostics);
        }

        /** @var self<U> */
        return self::ok($f($this->unwrap()), $this->diagnostics);
    }

    /**
     * @template U
     * @param callable(T): self<U> $f
     * @return self<U>
     */
    public function andThen(callable $f): self
    {
        if (!$this->ok) {
            /** @var self<U> */
            return self::err($this->error, $this->diagnostics);
        }

        /** @var self<U> $next */
        $next   = $f($this->unwrap());
        $merged = $this->diagnostics->concat($next->diagnostics());

        return $next->isOk()
            ? self::ok($next->unwrap(), $merged)
            : self::err($next->error(), $merged);
    }

    /** @return self<T> */
    public function drainTo(DiagnosticSinkInterface $sink): self
    {
        $sink->emitAll($this->diagnostics);
        return $this;
    }
}
