<?php
final class Diagnostics
{
    /** @var list<Diagnostic> */
    private $items = array();

    public function add(Diagnostic $d)
    {
        $this->items[] = $d;
    }

    /**
     * Adds only non-fatal diagnostics (i.e., ignores OperationError).
     */
    public function addNonFatal(Diagnostic $d)
    {
        if ($d instanceof OperationError) {
            return;
        }
        $this->items[] = $d;
    }

    /**
     * @return list<Diagnostic>
     */
    public function all()
    {
        return $this->items;
    }

    public function mergeFrom(Diagnostics $other)
    {
        foreach ($other->items as $d) {
            $this->addNonFatal($d);
        }
    }

    /**
     * Filter diagnostics by minimum PSR-3 log level.
     *
     * @param string $minLevel One of Psr\Log\LogLevel::*
     * @return list<Diagnostic>
     */
    public function minLevel($minLevel)
    {
        $minRank = self::levelRank($minLevel);
        $out = array();

        foreach ($this->items as $d) {
            if (self::levelRank($d->level()) >= $minRank) {
                $out[] = $d;
            }
        }

        return $out;
    }

    private static function levelRank($level)
    {
        // PSR-3 levels, ordered low -> high
        if ($level === LogLevel::DEBUG) return 10;
        if ($level === LogLevel::INFO) return 20;
        if ($level === LogLevel::NOTICE) return 30;
        if ($level === LogLevel::WARNING) return 40;
        if ($level === LogLevel::ERROR) return 50;
        if ($level === LogLevel::CRITICAL) return 60;
        if ($level === LogLevel::ALERT) return 70;
        if ($level === LogLevel::EMERGENCY) return 80;

        // Unknown level: treat as lowest to avoid accidental over-filtering.
        return 0;
    }
}