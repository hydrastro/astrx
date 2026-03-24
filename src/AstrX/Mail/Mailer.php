<?php

declare(strict_types = 1);

namespace AstrX\Mail;

use AstrX\Config\InjectConfig;
use AstrX\Mail\Diagnostic\MailDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;

/**
 * Simple SMTP mailer for transactional emails (registration, password reset, etc.).
 * Connects directly to an SMTP server using PHP streams — no external library
 * required. Supports STARTTLS, SMTPS (implicit TLS), and plain connections.
 * Configuration (Mail.config.php):
 *   host         SMTP server hostname or .onion address
 *   port         SMTP port (587 = submission STARTTLS, 465 = SMTPS, 25 = plain)
 *   username     SMTP auth username (leave empty to skip AUTH)
 *   password     SMTP auth password
 *   from_address The envelope From address
 *   from_name    Display name for the From header
 *   encryption   'tls' (STARTTLS), 'ssl' (implicit TLS), or '' (plain)
 *   timeout      Connection timeout in seconds (default 30)
 * When connecting to a .onion address, set a SOCKS5 proxy:
 *   socks5_host  Tor SOCKS5 proxy host (e.g. 'tor-client')
 *   socks5_port  Tor SOCKS5 proxy port (default 9050)
 */
final class Mailer
{
    public const string         ID_SEND_ERROR  = 'astrx.mail/send_error';
    public const DiagnosticLevel LVL_SEND_ERROR = DiagnosticLevel::ERROR;

    public const string ID_MAIL_ERROR = 'astrx.mail/error';
    private string $host = 'localhost';
    private int $port = 587;
    private string $username = '';
    private string $password = '';
    private string $fromAddress = '';
    private string $fromName = '';
    private string $encryption = 'tls';   // 'tls' | 'ssl' | ''
    private int $timeout = 30;
    private string $socks5Host = '';
    private int $socks5Port = 9050;

    #[InjectConfig('host')]
    public function setHost(string $v)
    : void {
        $this->host = $v;
    }

    #[InjectConfig('port')]
    public function setPort(int $v)
    : void {
        $this->port = $v;
    }

    #[InjectConfig('username')]
    public function setUsername(string $v)
    : void {
        $this->username = $v;
    }

    #[InjectConfig('password')]
    public function setPassword(string $v)
    : void {
        $this->password = $v;
    }

    #[InjectConfig('from_address')]
    public function setFromAddress(string $v)
    : void {
        $this->fromAddress = $v;
    }

    #[InjectConfig('from_name')]
    public function setFromName(string $v)
    : void {
        $this->fromName = $v;
    }

    #[InjectConfig('encryption')]
    public function setEncryption(string $v)
    : void {
        $this->encryption = $v;
    }

    #[InjectConfig('timeout')]
    public function setTimeout(int $v)
    : void {
        $this->timeout = max(1, $v);
    }

    #[InjectConfig('socks5_host')]
    public function setSocks5Host(string $v)
    : void {
        $this->socks5Host = $v;
    }

    #[InjectConfig('socks5_port')]
    public function setSocks5Port(int $v)
    : void {
        $this->socks5Port = $v;
    }

    // =========================================================================

    /**
     * Send a plain-text email.
     * @return Result<true>
     */
    public function send(
        string $toAddress,
        string $toName,
        string $subject,
        string $bodyText,
        string $bodyHtml = '',
    )
    : Result {
        try {
            return $this->doSend(
                $toAddress,
                $toName,
                $subject,
                $bodyText,
                $bodyHtml
            );
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /** @return Result<true> */
    private function doSend(
        string $toAddress,
        string $toName,
        string $subject,
        string $bodyText,
        string $bodyHtml,
    )
    : Result {
        $sock = $this->connect();

        $this->read($sock);                         // 220 greeting

        // EHLO
        $domain = gethostname() ?: 'localhost';
        $caps = $this->ehlo($sock, $domain);

        // STARTTLS upgrade (encryption='tls')
        if ($this->encryption === 'tls') {
            if (!in_array('STARTTLS', $caps, true)) {
                return $this->err("Server does not support STARTTLS");
            }
            $this->cmd($sock, "STARTTLS");
            $this->read($sock, '220');
            if (!stream_socket_enable_crypto(
                $sock,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                return $this->err("STARTTLS negotiation failed");
            }
            // Re-EHLO after TLS handshake
            $caps = $this->ehlo($sock, $domain);
        }

        // AUTH PLAIN
        if ($this->username !== '') {
            if (!in_array('AUTH', $caps, true) &&
                !in_array('AUTH PLAIN', $caps, true) &&
                !in_array('AUTH LOGIN', $caps, true)) {
                return $this->err("Server does not support AUTH");
            }
            $auth = base64_encode(
                "\0" . $this->username . "\0" . $this->password
            );
            $this->cmd($sock, "AUTH PLAIN {$auth}");
            $this->read($sock, '235');
        }

        // Envelope
        $this->cmd($sock, "MAIL FROM:<{$this->fromAddress}>");
        $this->read($sock, '250');

        $this->cmd($sock, "RCPT TO:<{$toAddress}>");
        $this->read($sock, '25');   // 250 or 251

        $this->cmd($sock, "DATA");
        $this->read($sock, '354');

        // Build message
        $boundary = bin2hex(random_bytes(12));
        $headers = $this->buildHeaders(
            $toAddress,
            $toName,
            $subject,
            $boundary,
            $bodyHtml !== ''
        );
        $body = $this->buildBody($bodyText, $bodyHtml, $boundary);

        fwrite($sock, $headers . "\r\n" . $body . "\r\n.\r\n");
        $this->read($sock, '250');

        $this->cmd($sock, "QUIT");
        fclose($sock);

        return Result::ok(true);
    }

    /** @return resource */
    private function connect()
    : mixed
    {
        if ($this->socks5Host !== '') {
            $sock = $this->connectViaSocks5();
        } elseif ($this->encryption === 'ssl') {
            $ctx = stream_context_create(['ssl' => ['verify_peer' => true]]);
            $sock = stream_socket_client(
                "ssl://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $ctx
            );
        } else {
            $sock = stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                $this->timeout
            );
        }

        if ($sock === false) {
            throw new \RuntimeException(
                "Cannot connect to {$this->host}:{$this->port} — $errstr ($errno)"
            );
        }

        stream_set_timeout($sock, $this->timeout);

        if ($this->encryption === 'ssl' && $this->socks5Host !== '') {
            // Wrap in TLS after SOCKS5 tunnel is open (implicit TLS)
            if (!stream_socket_enable_crypto(
                $sock,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                throw new \RuntimeException(
                    "TLS handshake failed after SOCKS5 tunnel"
                );
            }
        }

        return $sock;
    }

    /**
     * Open a TCP connection via a SOCKS5 proxy.
     * Used when sending to .onion addresses or routing through Tor.
     * @return resource
     */
    private function connectViaSocks5()
    : mixed
    {
        $sock = stream_socket_client(
            "tcp://{$this->socks5Host}:{$this->socks5Port}",
            $errno,
            $errstr,
            $this->timeout
        );
        if ($sock === false) {
            throw new \RuntimeException(
                "Cannot connect to SOCKS5 proxy: $errstr ($errno)"
            );
        }

        // SOCKS5 handshake: no auth
        fwrite(
            $sock,
            "\x05\x01\x00"
        );              // version=5, nmethods=1, method=noauth
        $resp = fread($sock, 2);
        if ($resp === false || $resp[1] !== "\x00") {
            throw new \RuntimeException("SOCKS5 proxy rejected no-auth method");
        }

        // CONNECT request
        $host = $this->host;
        $port = $this->port;
        $hostLen = strlen($host);
        $req = "\x05\x01\x00"                   // VER CMD RSV
               . "\x03"                            // ATYP: domain name
               . chr($hostLen) . $host             // DST.ADDR
               . pack('n', $port);                 // DST.PORT
        fwrite($sock, $req);

        // Response: VER REP RSV ATYP BNDADDR BNDPORT
        $resp = fread($sock, 4);
        if ($resp === false || strlen($resp) < 4) {
            throw new \RuntimeException(
                "SOCKS5 proxy returned truncated response"
            );
        }
        if ($resp[1] !== "\x00") {
            $code = ord($resp[1]);
            throw new \RuntimeException(
                "SOCKS5 proxy refused connection (code {$code})"
            );
        }
        // Read the bound address (variable length) and port to drain the response
        $atyp = ord($resp[3]);
        if ($atyp === 1) {
            fread($sock, 4);
        }     // IPv4
        elseif ($atyp === 3) {
            fread($sock, ord((string)fread($sock, 1)));
        } // domain
        elseif ($atyp === 4) {
            fread($sock, 16);
        }    // IPv6
        fread($sock, 2);       // port

        return $sock;
    }

    /** Send EHLO and return list of capability keywords. @return list<string> */
    private function ehlo(mixed $sock, string $domain)
    : array {
        $this->cmd($sock, "EHLO {$domain}");
        $caps = [];
        while (true) {
            $line = fgets($sock, 512);
            if ($line === false) {
                break;
            }
            $line = rtrim($line);
            // "250-KEYWORD" or "250 KEYWORD" — extract keyword
            if (preg_match('/^250[-\s]+(.+)$/', $line, $m)) {
                $parts = explode(' ', strtoupper(trim($m[1])));
                $caps[] = $parts[0];
            }
            // Last line has a space: "250 OK" or "250 KEYWORD"
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        return $caps;
    }

    private function cmd(mixed $sock, string $line)
    : void {
        fwrite($sock, $line . "\r\n");
    }

    private function read(mixed $sock, string $expectedCode = '')
    : string {
        $response = '';
        while (true) {
            $line = fgets($sock, 512);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // Continuation lines: "250-text" — final line has a space: "250 text"
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ($expectedCode !== '' &&
            !str_starts_with($response, $expectedCode)) {
            throw new \RuntimeException(
                "Expected {$expectedCode}, got: " . rtrim($response)
            );
        }

        return $response;
    }

    private function buildHeaders(
        string $toAddress,
        string $toName,
        string $subject,
        string $boundary,
        bool $hasHtml,
    )
    : string {
        $from = $this->fromName !== '' ?
            '"' . $this->fromName . '" <' . $this->fromAddress . '>' :
            '<' . $this->fromAddress . '>';
        $to = $toName !== '' ? '"' . $toName . '" <' . $toAddress . '>' :
            '<' . $toAddress . '>';
        $msgId = '<' .
                 bin2hex(random_bytes(12)) .
                 '@' .
                 (gethostname() ?: 'localhost') .
                 '>';
        $date = date('r');
        $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $h = "From: {$from}\r\n";
        $h .= "To: {$to}\r\n";
        $h .= "Subject: {$subject}\r\n";
        $h .= "Date: {$date}\r\n";
        $h .= "Message-ID: {$msgId}\r\n";
        $h .= "MIME-Version: 1.0\r\n";

        if ($hasHtml) {
            $h .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        } else {
            $h .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $h .= "Content-Transfer-Encoding: quoted-printable\r\n";
        }

        return $h;
    }

    private function buildBody(string $text, string $html, string $boundary)
    : string {
        if ($html === '') {
            return quoted_printable_encode($text);
        }

        return "--{$boundary}\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n" .
               "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
               quoted_printable_encode($text) .
               "\r\n" .
               "--{$boundary}\r\n" .
               "Content-Type: text/html; charset=UTF-8\r\n" .
               "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
               quoted_printable_encode($html) .
               "\r\n" .
               "--{$boundary}--";
    }

    private function err(string $detail): Result
    {
        return Result::err(false, Diagnostics::of(new MailDiagnostic(
                                                      self::ID_SEND_ERROR, self::LVL_SEND_ERROR, 'send_failed', $detail
                                                  )));
    }
}