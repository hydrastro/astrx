<?php
/**
 * @template T
 */
final class Outcome
{
    /** @var mixed */
    private $value;

    /** @var Diagnostics */
    private $diagnostics;

    /** @var OperationError|null */
    private $error;

    private function __construct($value, Diagnostics $diagnostics, $error)
    {
        $this->value = $value;
        $this->diagnostics = $diagnostics;
        $this->error = $error;
    }

    /**
     * @template T
     * @param T $value
     * @return self
     */
    public static function ok($value, Diagnostics $diagnostics = null)
    {
        if ($diagnostics === null) {
            $diagnostics = new Diagnostics();
        }
        return new self($value, $diagnostics, null);
    }

    public static function fail(OperationError $error, Diagnostics $diagnostics = null)
    {
        if ($diagnostics === null) {
            $diagnostics = new Diagnostics();
        }

        // Enforce: bag holds non-fatal diagnostics only.
        $clean = new Diagnostics();
        $clean->mergeFrom($diagnostics);

        return new self(null, $clean, $error);
    }

    public function isOk()
    {
        return $this->error === null;
    }

    /**
     * @return Diagnostics
     */
    public function diagnostics()
    {
        return $this->diagnostics;
    }

    /**
     * @return OperationError|null
     */
    public function error()
    {
        return $this->error;
    }

    /**
     * @return mixed
     */
    public function value()
    {
        return $this->value;
    }

    /**
     * @return mixed
     */
    public function unwrap()
    {
        if ($this->error !== null) {
            throw new \LogicException('Tried to unwrap failed Outcome; error code = ' . $this->error->code());
        }
        return $this->value;
    }
}