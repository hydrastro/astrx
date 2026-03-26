<?php

interface Diagnostic
{

    public function level() : LogLevel;

    /**
     * Stable programmatic identifier (int, suitable for match/switch and storage).
     *
     * @return int
     */
    public function code() : int;

    /**
     * Structured payload. Keep it mostly scalar/Stringable/arrays for portability.
     *
     * Recommended value domain (recursive):
     * scalar|null|\Stringable|array
     *
     * @return array<string, mixed>
     */
    public function context() : array;
}
