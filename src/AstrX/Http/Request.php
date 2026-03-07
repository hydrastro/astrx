<?php
declare(strict_types=1);

namespace AstrX\Http;

final class Request
{
    public function __construct(
        private RequestMethod $method,
        private Uri $uri,
        private HeaderBag $headers,
        private ParameterBag $query,
        private ParameterBag $body,
        private ParameterBag $cookies,
        private ParameterBag $server,
        private ParameterBag $attributes,
        private FileBag $files,
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;

        return new self(
            method: RequestMethod::fromString((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            uri: self::buildUriFromServer($server),
            headers: new HeaderBag(self::extractHeadersFromServer($server)),
            query: new ParameterBag($_GET),
            body: new ParameterBag($_POST),
            cookies: new ParameterBag($_COOKIE),
            server: new ParameterBag($server),
            attributes: new ParameterBag(),
            files: new FileBag(self::normalizeFiles($_FILES)),
        );
    }

    public function method(): RequestMethod
    {
        return $this->method;
    }

    public function uri(): Uri
    {
        return $this->uri;
    }

    public function headers(): HeaderBag
    {
        return $this->headers;
    }

    public function query(): ParameterBag
    {
        return $this->query;
    }

    public function body(): ParameterBag
    {
        return $this->body;
    }

    public function cookies(): ParameterBag
    {
        return $this->cookies;
    }

    public function server(): ParameterBag
    {
        return $this->server;
    }

    public function attributes(): ParameterBag
    {
        return $this->attributes;
    }

    public function files(): FileBag
    {
        return $this->files;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if ($this->body->has($key)) {
            return $this->body->get($key);
        }

        if ($this->query->has($key)) {
            return $this->query->get($key);
        }

        return $default;
    }

    public function contentType(): ?string
    {
        return $this->headers->contentType();
    }

    public function bearerToken(): ?string
    {
        return $this->headers->bearerToken();
    }

    public function host(): string
    {
        return $this->uri->host();
    }

    public function scheme(): string
    {
        return $this->uri->scheme();
    }

    public function path(): string
    {
        return $this->uri->path();
    }

    public function isSecure(): bool
    {
        if ($this->uri->scheme() === 'https') {
            return true;
        }

        $https = $this->server->get('HTTPS');
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return (string) $this->server->get('SERVER_PORT', '') === '443';
    }

    public function ip(bool $trustProxyHeaders = false): string
    {
        $remoteAddr = (string) $this->server->get('REMOTE_ADDR', '0.0.0.0');

        if (!$trustProxyHeaders) {
            return $remoteAddr;
        }

        foreach ([
                     'HTTP_CLIENT_IP',
                     'HTTP_X_FORWARDED_FOR',
                     'HTTP_X_FORWARDED',
                     'HTTP_X_CLUSTER_CLIENT_IP',
                     'HTTP_FORWARDED_FOR',
                     'HTTP_FORWARDED',
                     'REMOTE_ADDR',
                 ] as $key) {
            $value = $this->server->get($key);

            if (!is_string($value) || $value === '') {
                continue;
            }

            foreach (array_map('trim', explode(',', $value)) as $ip) {
                if (
                    filter_var(
                        $ip,
                        FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                    ) !== false
                ) {
                    return $ip;
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string|array<int, string>>
     */
    private static function extractHeadersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
                continue;
            }

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function buildUriFromServer(array $server): Uri
    {
        $scheme = 'http';

        $https = $server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            $scheme = 'https';
        } elseif (($server['SERVER_PORT'] ?? null) === '443' || ($server['SERVER_PORT'] ?? null) === 443) {
            $scheme = 'https';
        }

        $host = 'localhost';
        if (isset($server['HTTP_HOST']) && is_string($server['HTTP_HOST']) && $server['HTTP_HOST'] !== '') {
            $host = $server['HTTP_HOST'];
        } elseif (isset($server['SERVER_NAME']) && is_string($server['SERVER_NAME']) && $server['SERVER_NAME'] !== '') {
            $host = $server['SERVER_NAME'];
        }

        $port = null;
        $hostOnly = $host;

        if (str_contains($host, ':')) {
            $parts = explode(':', $host, 2);
            $hostOnly = $parts[0];

            if (isset($parts[1]) && preg_match('/^\d+$/', $parts[1]) === 1) {
                $port = (int) $parts[1];
            }
        } elseif (isset($server['SERVER_PORT']) && is_scalar($server['SERVER_PORT'])) {
            $port = (int) $server['SERVER_PORT'];
        }

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $requestUri = '/';
        if (isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI']) && $server['REQUEST_URI'] !== '') {
            $requestUri = $server['REQUEST_URI'];
        }

        $parts = parse_url($requestUri);

        return new Uri(
            scheme: $scheme,
            host: $hostOnly,
            port: $port,
            path: isset($parts['path']) ? (string) $parts['path'] : '/',
            query: isset($parts['query']) ? (string) $parts['query'] : '',
            fragment: isset($parts['fragment']) ? (string) $parts['fragment'] : '',
        );
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, UploadedFile|array>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $file) {
            if (!is_array($file)) {
                continue;
            }

            $normalized[$key] = self::normalizeFileNode($file);
        }

        return $normalized;
    }

    private static function normalizeFileNode(array $node): UploadedFile|array
    {
        $isLeaf =
            array_key_exists('name', $node) &&
            array_key_exists('type', $node) &&
            array_key_exists('tmp_name', $node) &&
            array_key_exists('error', $node) &&
            array_key_exists('size', $node);

        if ($isLeaf) {
            if (is_array($node['name'])) {
                $result = [];

                foreach (array_keys($node['name']) as $index) {
                    $result[$index] = self::normalizeFileNode([
                                                                  'name' => $node['name'][$index],
                                                                  'type' => $node['type'][$index],
                                                                  'tmp_name' => $node['tmp_name'][$index],
                                                                  'error' => $node['error'][$index],
                                                                  'size' => $node['size'][$index],
                                                              ]);
                }

                return $result;
            }

            return new UploadedFile(
                clientFilename: (string) $node['name'],
                clientMediaType: (string) $node['type'],
                tempPath: (string) $node['tmp_name'],
                size: (int) $node['size'],
                error: (int) $node['error'],
            );
        }

        $result = [];

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::normalizeFileNode($value);
            }
        }

        return $result;
    }
}