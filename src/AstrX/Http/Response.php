<?php
declare(strict_types=1);

namespace AstrX\Http;

use AstrX\Http\Diagnostic\HeadersAlreadySentDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Http\Exception\InvalidStatusCodeException;
use JsonException;

final class Response
{
    public const string ID_HEADERS_ALREADY_SENT = 'astrx.http/headers_already_sent';
    public const DiagnosticLevel LVL_HEADERS_ALREADY_SENT = DiagnosticLevel::ERROR;

    private int $status;
    private string $body;
    private HeaderBag $headers;

    public function __construct(
        string $body = '',
        int $status = HttpStatus::OK->value,
        ?HeaderBag $headers = null,
    ) {
        if (!HttpStatus::isValid($status)) {
            throw new InvalidStatusCodeException($status);
        }

        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers ?? new HeaderBag();
    }

    public function status(): int      { return $this->status; }
    public function body(): string     { return $this->body; }
    public function headers(): HeaderBag { return $this->headers; }

    public function setStatus(int $status): void
    {
        if (!HttpStatus::isValid($status)) {
            throw new InvalidStatusCodeException($status);
        }
        $this->status = $status;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function withBody(string $body): self
    {
        $clone       = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function withStatus(int $status): self
    {
        if (!HttpStatus::isValid($status)) {
            throw new InvalidStatusCodeException($status);
        }
        $clone         = clone $this;
        $clone->status = $status;
        return $clone;
    }

    /** @return Result<bool> */
    public function send(): Result
    {
        if (headers_sent($file, $line)) {
            return Result::err(false, Diagnostics::of(
                new HeadersAlreadySentDiagnostic(
                    self::ID_HEADERS_ALREADY_SENT,
                    self::LVL_HEADERS_ALREADY_SENT,
                    (string) $file,
                    (int) $line,
                )
            ));
        }

        http_response_code($this->status);

        foreach ($this->headers->all() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), $first);
                $first = false;
            }
        }

        echo $this->body;

        return Result::ok(true);
    }

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    public static function text(
        string $body,
        int $status = HttpStatus::OK->value,
        ?HeaderBag $headers = null,
    ): self {
        $response = new self($body, $status, $headers);
        $response->headers()->set('Content-Type', 'text/plain; charset=utf-8');
        return $response;
    }

    public static function html(
        string $body,
        int $status = HttpStatus::OK->value,
        ?HeaderBag $headers = null,
    ): self {
        $response = new self($body, $status, $headers);
        $response->headers()->set('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }

    /**
     * @throws JsonException
     */
    public static function json(
        mixed $data,
        int $status = HttpStatus::OK->value,
        ?HeaderBag $headers = null,
        int $flags = JSON_THROW_ON_ERROR,
    ): self {
        // json_encode() returns string|false, but with JSON_THROW_ON_ERROR it
        // either returns a string or throws — never false. The cast satisfies
        // static analysis without adding a runtime branch.
        $response = new self(
            body:    (string) json_encode($data, $flags),
            status:  $status,
            headers: $headers,
        );
        $response->headers()->set('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }

    public static function redirect(
        string $location,
        int $status = HttpStatus::FOUND->value,
        ?HeaderBag $headers = null,
    ): self {
        $response = new self('', $status, $headers);
        $response->headers()->set('Location', $location);
        return $response;
    }

    public static function noContent(?HeaderBag $headers = null): self
    {
        return new self('', HttpStatus::NO_CONTENT->value, $headers);
    }
}