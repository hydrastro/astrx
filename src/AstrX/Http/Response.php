<?php
declare(strict_types=1);

namespace AstrX\Http;

use InvalidArgumentException;
use JsonException;
// TODO: customize throw
final class Response
{
    private int $status;
    private string $body;
    private HeaderBag $headers;

    public function __construct(
        string $body = '',
        int $status = HttpStatus::OK,
        ?HeaderBag $headers = null,
    ) {
        if (!HttpStatus::isValid($status)) {
            throw new InvalidArgumentException(sprintf(
                                                   'Invalid HTTP status code "%d".',
                                                   $status,
                                               ));
        }

        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers ?? new HeaderBag();
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        if (!HttpStatus::isValid($status)) {
            throw new InvalidArgumentException(sprintf(
                                                   'Invalid HTTP status code "%d".',
                                                   $status,
                                               ));
        }

        $this->status = $status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function headers(): HeaderBag
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException(sprintf(
                                            'Cannot send response: headers already sent in %s on line %d.',
                                            $file,
                                            $line,
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
    }

    public static function text(
        string $body,
        int $status = HttpStatus::OK,
        ?HeaderBag $headers = null,
    ): self {
        $response = new self($body, $status, $headers);
        $response->headers()->set('Content-Type', 'text/plain; charset=utf-8');

        return $response;
    }

    public static function html(
        string $body,
        int $status = HttpStatus::OK,
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
        int $status = HttpStatus::OK,
        ?HeaderBag $headers = null,
        int $flags = JSON_THROW_ON_ERROR,
    ): self {
        $response = new self(
            body: json_encode($data, $flags),
            status: $status,
            headers: $headers,
        );

        $response->headers()->set('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }

    public static function redirect(
        string $location,
        int $status = HttpStatus::FOUND,
        ?HeaderBag $headers = null,
    ): self {
        $response = new self('', $status, $headers);
        $response->headers()->set('Location', $location);

        return $response;
    }

    public static function noContent(?HeaderBag $headers = null): self
    {
        return new self('', HttpStatus::NO_CONTENT, $headers);
    }
}
