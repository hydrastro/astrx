<?php

declare(strict_types = 1);

namespace AstrX\Mail;

use AstrX\Config\InjectConfig;
use AstrX\Mail\Diagnostic\MailSendFailedDiagnostic;
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
     * @return Result<bool>
     */
    /**
     * Send an email.
     *
     * @param string $fromAddressOverride  When non-empty, overrides the configured
     *                                     from_address for this message only.
     *                                     Used by webmail to send as the logged-in user.
     * @param string $fromNameOverride     Paired display name override.
     */
/** @return Result<bool> */
    /**
     * Send an email.
     *
     * @param list<array{filename:string,content_type:string,data:string}> $attachments
     *         Optional attachments. Each entry has:
     *         - filename     : displayed name in the receiving client
     *         - content_type : MIME type (e.g. 'application/pdf')
     *         - data         : RAW bytes (not base64; we encode internally)
     */
    public function send(
        string $toAddress,
        string $toName,
        string $subject,
        string $bodyText,
        string $bodyHtml = '',
        string $fromAddressOverride = '',
        string $fromNameOverride    = '',
        string $priority            = 'normal',  // 'high', 'normal', 'low'
        bool   $readReceipt         = false,
        array  $attachments         = [],
    )
    : Result {
        try {
            return $this->doSend(
                $toAddress,
                $toName,
                $subject,
                $bodyText,
                $bodyHtml,
                $fromAddressOverride,
                $fromNameOverride,
                $priority,
                $readReceipt,
                $attachments,
            );
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /** @return Result<bool> */
    private function doSend(
        string $toAddress,
        string $toName,
        string $subject,
        string $bodyText,
        string $bodyHtml,
        string $fromAddressOverride = '',
        string $fromNameOverride    = '',
        string $priority            = 'normal',
        bool   $readReceipt         = false,
        array  $attachments         = [],
    )
    : Result {
        $effectiveFromAddress = $fromAddressOverride !== '' ? $fromAddressOverride : $this->fromAddress;
        $effectiveFromName    = $fromAddressOverride !== '' ? $fromNameOverride    : $this->fromName;
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

        // AUTH — prefer PLAIN, fall back to LOGIN when the server doesn't
        // advertise PLAIN. Older MS Exchange, some shared-hosting MTAs, and a
        // few legacy mail providers only support AUTH LOGIN. Both methods
        // send credentials in cleartext (base64 isn't encryption) so STARTTLS
        // or SMTPS is still required for any non-localhost connection.
        if ($this->username !== '') {
            $supportsAny = false;
            $supportsPlain = false;
            $supportsLogin = false;
            foreach ($caps as $cap) {
                if ($cap === 'AUTH PLAIN' || str_starts_with($cap, 'AUTH PLAIN ')) {
                    $supportsAny = $supportsPlain = true;
                }
                if ($cap === 'AUTH LOGIN' || str_starts_with($cap, 'AUTH LOGIN ')
                 || str_contains($cap, ' PLAIN') || str_contains($cap, ' LOGIN')) {
                    $supportsAny = true;
                    if (str_contains($cap, 'LOGIN')) { $supportsLogin = true; }
                    if (str_contains($cap, 'PLAIN')) { $supportsPlain = true; }
                }
                // Tolerate 'AUTH' alone (very old servers); assume both methods.
                if ($cap === 'AUTH') { $supportsAny = $supportsPlain = $supportsLogin = true; }
            }
            if (!$supportsAny) {
                return $this->err("Server does not support AUTH");
            }

            if ($supportsPlain) {
                $auth = base64_encode("\0" . $this->username . "\0" . $this->password);
                $this->cmd($sock, "AUTH PLAIN {$auth}");
                $this->read($sock, '235');
            } elseif ($supportsLogin) {
                // AUTH LOGIN: server prompts "Username:" then "Password:" both
                // base64-encoded, and we reply with base64 of each in turn.
                $this->cmd($sock, "AUTH LOGIN");
                $this->read($sock, '334');                            // base64('Username:')
                $this->cmd($sock, base64_encode($this->username));
                $this->read($sock, '334');                            // base64('Password:')
                $this->cmd($sock, base64_encode($this->password));
                $this->read($sock, '235');                            // auth ok
            } else {
                return $this->err("Server advertises AUTH but neither PLAIN nor LOGIN");
            }
        }

        // Envelope
        $this->cmd($sock, "MAIL FROM:<{$effectiveFromAddress}>");
        $this->read($sock, '250');

        $this->cmd($sock, "RCPT TO:<{$toAddress}>");
        $this->read($sock, '25');   // 250 or 251

        $this->cmd($sock, "DATA");
        $this->read($sock, '354');

        // Build message
        $boundary = bin2hex(random_bytes(12));
        $hasAttachments = $attachments !== [];
        $headers = $this->buildHeaders(
            $toAddress,
            $toName,
            $subject,
            $boundary,
            $bodyHtml !== '',
            $effectiveFromAddress,
            $effectiveFromName,
            $priority,
            $readReceipt,
            $hasAttachments,
        );
        $body = $this->buildBody($bodyText, $bodyHtml, $boundary, $attachments);

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
        $errno  = 0;
        $errstr = '';
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
            fread($sock, max(1, ord((string)fread($sock, 1))));
        } // domain
        elseif ($atyp === 4) {
            fread($sock, 16);
        }    // IPv6
        fread($sock, 2);       // port

        return $sock;
    }

    /**
     * Send EHLO and return list of capability keywords.
     * @return list<string>
     */
    private function ehlo(mixed $sock, string $domain)
    : array {
        assert(is_resource($sock));
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

    /** @param resource $sock */
    /** @param resource $sock */
    private function cmd(mixed $sock, string $line)
    : void {
        fwrite($sock, $line . "\r\n");
    }

    /** @param resource $sock */
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
        bool   $hasHtml,
        string $fromAddress  = '',
        string $fromName     = '',
        string $priority     = 'normal',
        bool   $readReceipt  = false,
        bool   $hasAttachments = false,
    )
    : string {
        if ($fromAddress === '') { $fromAddress = $this->fromAddress; }
        if ($fromName    === '') { $fromName    = $this->fromName; }
        $from = $fromName !== '' ?
            '"' . $fromName . '" <' . $fromAddress . '>' :
            '<' . $fromAddress . '>';
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
        // Priority headers (X-Priority / Importance / X-MS-Exchange-Message-Class)
        if ($priority === 'high') {
            $h .= "X-Priority: 1\r\n";
            $h .= "Importance: High\r\n";
        } elseif ($priority === 'low') {
            $h .= "X-Priority: 5\r\n";
            $h .= "Importance: Low\r\n";
        }
        // Read receipt (Disposition-Notification-To)
        if ($readReceipt) {
            $h .= "Disposition-Notification-To: {$fromAddress}\r\n";
        }
        $h .= "MIME-Version: 1.0\r\n";

        // Outermost Content-Type:
        //   - With attachments → multipart/mixed (wraps an alternative block + each attachment)
        //   - HTML, no atts    → multipart/alternative (text + html)
        //   - No HTML, no atts → text/plain
        if ($hasAttachments) {
            $h .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        } elseif ($hasHtml) {
            $h .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        } else {
            $h .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $h .= "Content-Transfer-Encoding: quoted-printable\r\n";
        }

        return $h;
    }

    /**
     * Build the MIME body.
     *
     * Layout:
     *   - No html, no attachments  → bare quoted-printable text
     *   - Html present, no atts    → multipart/alternative (text + html)
     *   - Attachments present      → multipart/mixed with the alt-block first,
     *                                then each attachment as a part with
     *                                base64 transfer-encoding.
     *
     * @param list<array{filename:string,content_type:string,data:string}> $attachments
     */
    private function buildBody(string $text, string $html, string $boundary, array $attachments = [])
    : string {
        // Fast path — no html, no attachments.
        if ($html === '' && $attachments === []) {
            return quoted_printable_encode($text);
        }

        // Body container — the text+html alternative block (with no attachments
        // we use this directly; with attachments it becomes a child of the
        // multipart/mixed wrapper).
        $altBoundary = $attachments === [] ? $boundary : 'alt_' . bin2hex(random_bytes(8));

        $altBody = '';
        if ($html !== '') {
            $altBody = "--{$altBoundary}\r\n" .
                       "Content-Type: text/plain; charset=UTF-8\r\n" .
                       "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
                       quoted_printable_encode($text) .
                       "\r\n" .
                       "--{$altBoundary}\r\n" .
                       "Content-Type: text/html; charset=UTF-8\r\n" .
                       "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
                       quoted_printable_encode($html) .
                       "\r\n" .
                       "--{$altBoundary}--";
        } else {
            // Attachments present but no html — single text part.
            $altBody = "--{$altBoundary}\r\n" .
                       "Content-Type: text/plain; charset=UTF-8\r\n" .
                       "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
                       quoted_printable_encode($text) .
                       "\r\n--{$altBoundary}--";
        }

        // No attachments — the alt block IS the body.
        if ($attachments === []) {
            return $altBody;
        }

        // Multipart/mixed: alt block as first part, attachments after.
        $out  = "--{$boundary}\r\n";
        $out .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        $out .= $altBody . "\r\n";

        foreach ($attachments as $att) {
            $filename    = isset($att['filename']) && is_scalar($att['filename'])
                ? (string) $att['filename'] : 'attachment';
            $contentType = isset($att['content_type']) && is_scalar($att['content_type'])
                ? (string) $att['content_type'] : 'application/octet-stream';
            $data        = isset($att['data']) && is_string($att['data']) ? $att['data'] : '';
            if ($data === '') { continue; }

            // Sanitise filename — strip path components and CRLF (header
            // injection defence).
            $safeName = preg_replace('/[\r\n]/', '', basename($filename)) ?? 'attachment';

            $out .= "--{$boundary}\r\n";
            $out .= "Content-Type: {$contentType}; name=\"{$safeName}\"\r\n";
            $out .= "Content-Transfer-Encoding: base64\r\n";
            $out .= "Content-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n";
            // RFC 2045 — base64 lines max 76 chars.
            $out .= chunk_split(base64_encode($data), 76, "\r\n");
        }

        $out .= "--{$boundary}--";
        return $out;
    }

    /** @return Result<never> */
    private function err(string $detail): Result
    {
        return Result::err(null, Diagnostics::of(
            new MailSendFailedDiagnostic('astrx.mail/send_failed', DiagnosticLevel::ERROR, $detail)
        ));
    }
}
