<?php
declare(strict_types=1);

namespace AstrX\Routing;

final class Request
{
    private string $ip;

    public function __construct(
        private bool $urlRewrite = false,
        private ?CurrentUrl $currentUrl = null
    ) {
        $this->ip = $this->computeIp();
    }

    public function configureRewrite(bool $urlRewrite, ?CurrentUrl $currentUrl): void
    {
        $this->urlRewrite = $urlRewrite;
        $this->currentUrl = $currentUrl;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    private function computeIp(bool $trustClient = false): string
    {
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!$trustClient) return $currentIp;

        foreach ([
                     'HTTP_CLIENT_IP',
                     'HTTP_X_FORWARDED_FOR',
                     'HTTP_X_FORWARDED',
                     'HTTP_X_CLUSTER_CLIENT_IP',
                     'HTTP_FORWARDED_FOR',
                     'HTTP_FORWARDED',
                     'REMOTE_ADDR',
                 ] as $key) {
            if (!isset($_SERVER[$key])) continue;

            foreach (array_map('trim', explode(',', (string)$_SERVER[$key])) as $ip) {
                if (filter_var(
                        $ip,
                        FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                    ) !== false) {
                    return $ip;
                }
            }
        }

        return $currentIp;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        if ($this->urlRewrite) {
            // invariant: rewrite mode requires currentUrl
            assert($this->currentUrl !== null);
            return $this->currentUrl->get($key, $fallback);
        }

        return $_GET[$key] ?? $fallback;
    }

    public function post(string $key, mixed $fallback = null): mixed
    {
        return $_POST[$key] ?? $fallback;
    }

    public function files(string $key, mixed $fallback = null): mixed
    {
        return $_FILES[$key] ?? $fallback;
    }

    public function request(string $key, mixed $fallback = null): mixed
    {
        return $_REQUEST[$key] ?? $fallback;
    }
}