<?php
declare(strict_types=1);

namespace AstrX\Mail;

use AstrX\Config\InjectConfig;
use AstrX\Mail\Diagnostic\ImapDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;

/**
 * Pure PHP IMAP client built on PHP streams — no ext-imap required.
 *
 * Supports:
 *   - IMAPS  (implicit TLS, port 993)   encryption = 'ssl'
 *   - IMAP + STARTTLS  (port 143)       encryption = 'tls'
 *   - Plain IMAP  (port 143)            encryption = ''
 *   - SOCKS5 proxy (for .onion access)
 *
 * Usage pattern per request:
 *   $client->connect($host, $port, $enc);
 *   $client->login($user, $password);
 *   … commands …
 *   $client->logout();
 *
 * All public methods return Result<T>. On error the Result carries an
 * ImapDiagnostic and the connection is left open so the caller can
 * decide whether to retry or close.
 *
 * IMAP sequence numbers vs UIDs: this client uses UIDs (IMAP UID FETCH
 * family) for all message operations so message references stay stable
 * across folder reorganisations.
 */
final class ImapClient
{
    // ── Config (injectable) ───────────────────────────────────────────────────

    private string $host       = 'localhost';
    private int    $port       = 993;
    private string $encryption = 'ssl';
    private int    $timeout    = 30;
    private string $socks5Host  = '';
    private int    $socks5Port  = 9050;
    /**
     * Whether to verify the server's SSL certificate.
     * Set to false for self-signed certs on private/test mail servers.
     */
    private bool   $verifySsl   = true;

    #[InjectConfig('imap_host')]
    public function setHost(string $v): void { $this->host = $v; }

    #[InjectConfig('imap_port')]
    public function setPort(int $v): void { $this->port = $v; }

    #[InjectConfig('imap_encryption')]
    public function setEncryption(string $v): void { $this->encryption = $v; }

    #[InjectConfig('imap_timeout')]
    public function setTimeout(int $v): void { $this->timeout = max(5, $v); }

    #[InjectConfig('imap_socks5_host')]
    public function setSocks5Host(string $v): void { $this->socks5Host = $v; }

    #[InjectConfig('imap_socks5_port')]
    public function setSocks5Port(int $v): void { $this->socks5Port = $v; }

    #[InjectConfig('imap_verify_ssl')]
    public function setVerifySsl(bool $v): void { $this->verifySsl = $v; }

    // ── State ─────────────────────────────────────────────────────────────────

    /** @var resource|null */
    private mixed $socket    = null;
    private int   $tagSeq    = 0;
    private bool  $loggedIn  = false;

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Open a connection using configured host/port/encryption.
     * @return Result<true>
     */
    public function connect(): Result
    {
        try {
            $this->socket  = $this->openSocket();
            $this->tagSeq  = 0;
            $this->loggedIn = false;
            $greeting = $this->readLine();
            if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
                return $this->err('connect', "Unexpected greeting: $greeting");
            }
            if (str_starts_with($greeting, '* PREAUTH')) {
                $this->loggedIn = true;
            }
            // STARTTLS upgrade
            if ($this->encryption === 'tls' && !$this->loggedIn) {
                $r = $this->starttls();
                if (!$r->isOk()) { return $r; }
            }
            return Result::ok(true);
        } catch (\Throwable $e) {
            return $this->err('connect', $e->getMessage());
        }
    }

    /**
     * Authenticate with IMAP LOGIN.
     * @return Result<true>
     */
    public function login(string $username, string $password): Result
    {
        if ($this->loggedIn) { return Result::ok(true); }
        $r = $this->command(
            'LOGIN ' . $this->quoteString($username) . ' ' . $this->quoteString($password)
        );
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }
        $this->loggedIn = true;
        return Result::ok(true);
    }

    /**
     * List all mailbox folders.
     * @return Result<list<array{name:string,display:string,delimiter:string,flags:list<string>}>>
     */
    public function listFolders(): Result
    {
        $r = $this->command('LIST "" "*"');
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }

        $folders = [];
        foreach ($this->untaggedLines() as $line) {
            if (!preg_match('/^\* LIST \(([^)]*)\) "([^"]*)" (.+)$/', $line, $m)) { continue; }
            $flags     = array_filter(array_map('trim', explode(' ', $m[1])));
            $delimiter = $m[2];
            $name      = trim($m[3], '"');
            if (in_array('\\Noselect', $flags, true)) { continue; }
            $folders[] = [
                'name'      => $name,
                'display'   => $this->folderDisplayName($name, $delimiter),
                'delimiter' => $delimiter,
                'flags'     => array_values($flags),
            ];
        }
        // Sort: INBOX first, then alphabetical
        usort($folders, function($a, $b) {
            if ($a['name'] === 'INBOX') return -1;
            if ($b['name'] === 'INBOX') return 1;
            return strcmp($a['name'], $b['name']);
        });
        return Result::ok($folders);
    }

    /**
     * Get status of a folder (total, unseen, recent).
     * @return Result<array{total:int,unseen:int,recent:int}>
     */
    public function folderStatus(string $folder): Result
    {
        $r = $this->command('STATUS ' . $this->quoteString($folder) . ' (MESSAGES UNSEEN RECENT)');
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }

        $result = ['total' => 0, 'unseen' => 0, 'recent' => 0];
        foreach ($this->untaggedLines() as $line) {
            if (!str_starts_with($line, '* STATUS')) { continue; }
            if (preg_match('/MESSAGES (\d+)/', $line, $m)) { $result['total']  = (int) $m[1]; }
            if (preg_match('/UNSEEN (\d+)/',   $line, $m)) { $result['unseen'] = (int) $m[1]; }
            if (preg_match('/RECENT (\d+)/',   $line, $m)) { $result['recent'] = (int) $m[1]; }
        }
        return Result::ok($result);
    }

    /**
     * Select a folder and return EXISTS count.
     * @return Result<int>  number of messages in the folder
     */
    public function selectFolder(string $folder): Result
    {
        $r = $this->command('SELECT ' . $this->quoteString($folder));
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }

        $exists = 0;
        foreach ($this->untaggedLines() as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) { $exists = (int) $m[1]; }
        }
        return Result::ok($exists);
    }

    /**
     * Fetch a page of message headers from the currently selected folder.
     * Messages are returned newest-first (highest UIDs first).
     * Each entry: [uid, flags, subject, from_display, date_str, date_ts, seen].
     *
     * @return Result<list<array{uid:int,flags:list<string>,subject:string,from_display:string,date_str:string,date_ts:int,seen:bool}>>
     */
    public function fetchHeadersPage(int $total, int $page, int $perPage): Result
    {
        if ($total === 0) { return Result::ok([]); }

        // Calculate sequence range (seq numbers, not UIDs, for the range query)
        // Newest messages have highest sequence numbers.
        $high = $total - (($page - 1) * $perPage);
        $low  = max(1, $high - $perPage + 1);
        $set  = "{$low}:{$high}";

        $r = $this->command("UID SEARCH {$low}:{$high} ALL");
        if ($r->isOk()) {
            // Get UIDs for this range
            $uids = [];
            foreach ($this->untaggedLines() as $line) {
                if (preg_match('/^\* SEARCH (.+)/', $line, $m)) {
                    $uids = array_map('intval', explode(' ', trim($m[1])));
                }
            }
        }

        // Fetch envelope+flags for the sequence range
        $r = $this->command("FETCH {$set} (UID FLAGS ENVELOPE)");
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }

        $messages = [];
        foreach ($this->untaggedLines() as $line) {
            if (!preg_match('/^\* \d+ FETCH \((.+)\)$/s', $line, $m)) { continue; }
            $data = $m[1];

            $uid     = $this->extractInt($data, 'UID');
            $flags   = $this->extractFlags($data);
            $seen    = in_array('\\Seen', $flags, true);
            $env     = $this->extractEnvelope($data);
            $subject = $this->mimeDecodeHeader($env['subject'] ?? '(no subject)');
            $from    = $this->formatAddress($env['from'] ?? '');
            $dateStr = $env['date'] ?? '';
            $dateTs  = $dateStr ? (int) @strtotime($dateStr) : 0;

            if ($uid <= 0) { continue; }

            $messages[] = [
                'uid'          => $uid,
                'flags'        => $flags,
                'subject'      => $subject ?: '(no subject)',
                'from_display' => $from,
                'date_str'     => $dateStr,
                'date_ts'      => $dateTs,
                'seen'         => $seen,
            ];
        }

        // Sort newest first by UID descending
        usort($messages, fn($a, $b) => $b['uid'] <=> $a['uid']);
        return Result::ok($messages);
    }

    /**
     * Fetch the full content of a message by UID.
     * Returns decoded headers + text/html body parts.
     *
     * @return Result<array{uid:int,subject:string,from:string,to:string,cc:string,date:string,body_text:string,body_html:string,flags:list<string>}>
     */
    public function fetchMessage(int $uid): Result
    {
        // Fetch flags + full RFC822 message
        $r = $this->command("UID FETCH {$uid} (FLAGS RFC822)");
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }

        // UID FETCH may return a literal. We need to read it.
        $raw   = $this->lastLiteralContent;
        $flags = $this->lastFlags;

        if ($raw === '') {
            return $this->err('fetch_message', "Message UID {$uid} not found or empty");
        }

        $parsed = $this->parseMimeMessage($raw);
        $parsed['uid']   = $uid;
        $parsed['flags'] = $flags;
        $parsed['seen']  = in_array('\\Seen', $flags, true);

        return Result::ok($parsed);
    }

    /**
     * Mark a message (by UID) as seen or unseen.
     * @return Result<true>
     */
    public function setSeenFlag(int $uid, bool $seen): Result
    {
        $op  = $seen ? '+FLAGS' : '-FLAGS';
        $r   = $this->command("UID STORE {$uid} {$op} (\\Seen)");
        return $r->isOk() ? Result::ok(true) : Result::err(false, $r->diagnostics());
    }

    /**
     * Move a message to another folder (COPY → DELETE → EXPUNGE).
     * @return Result<true>
     */
    public function moveMessage(int $uid, string $targetFolder): Result
    {
        // Try IMAP MOVE extension first
        $r = $this->command("UID MOVE {$uid} " . $this->quoteString($targetFolder));
        if ($r->isOk()) { return Result::ok(true); }

        // Fall back to COPY + mark deleted + expunge
        $r = $this->command("UID COPY {$uid} " . $this->quoteString($targetFolder));
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }

        $r = $this->command("UID STORE {$uid} +FLAGS (\\Deleted)");
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }

        $this->command("EXPUNGE");
        return Result::ok(true);
    }

    /**
     * Delete a message (marks deleted and expunges, or moves to Trash).
     * @return Result<true>
     */
    public function deleteMessage(int $uid, string $trashFolder = 'Trash'): Result
    {
        // Try to move to Trash first; if it fails just hard-delete
        $r = $this->command("UID COPY {$uid} " . $this->quoteString($trashFolder));
        if ($r->isOk()) {
            $this->command("UID STORE {$uid} +FLAGS (\\Deleted)");
            $this->command("EXPUNGE");
            return Result::ok(true);
        }
        // No Trash — just mark deleted
        $r = $this->command("UID STORE {$uid} +FLAGS (\\Deleted)");
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }
        $this->command("EXPUNGE");
        return Result::ok(true);
    }

    /**
     * Create a folder if it does not exist.
     * @return Result<true>
     */
    public function createFolder(string $name): Result
    {
        $r = $this->command("CREATE " . $this->quoteString($name));
        return $r->isOk() ? Result::ok(true) : Result::err(false, $r->diagnostics());
    }

    /**
     * Append a sent message to the Sent folder.
     * @return Result<true>
     */
    public function appendToSent(string $rawMessage, string $sentFolder = 'Sent'): Result
    {
        $len = strlen($rawMessage);
        $tag = $this->nextTag();
        $this->writeLine("{$tag} APPEND " . $this->quoteString($sentFolder) . " (\\Seen) {{$len}}");
        // Server should respond with + (continue)
        $cont = $this->readLine();
        if (!str_starts_with($cont, '+')) {
            // Try creating the folder and retrying once
            $this->createFolder($sentFolder);
            $this->writeLine("{$tag} APPEND " . $this->quoteString($sentFolder) . " (\\Seen) {{$len}}");
            $cont = $this->readLine();
            if (!str_starts_with($cont, '+')) {
                return $this->err('append', "Server rejected APPEND continuation: $cont");
            }
        }
        $this->writeLine($rawMessage);
        $resp = $this->readTaggedResponse($tag);
        return str_starts_with($resp, $tag . ' OK')
            ? Result::ok(true)
            : $this->err('append', $resp);
    }

    /** Send LOGOUT and close the socket. */
    public function logout(): void
    {
        if ($this->socket !== null) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {}
            @fclose($this->socket);
            $this->socket   = null;
            $this->loggedIn = false;
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && !feof($this->socket);
    }

    // =========================================================================
    // Connection internals
    // =========================================================================

    /** @return resource */
    private function openSocket(): mixed
    {
        if ($this->socks5Host !== '') {
            $sock = $this->connectViaSocks5();
        } elseif ($this->encryption === 'ssl') {
            $ctx  = stream_context_create(['ssl' => [
                'verify_peer'       => $this->verifySsl,
                'verify_peer_name'  => $this->verifySsl,
                'allow_self_signed' => !$this->verifySsl,
            ]]);
            $sock = @stream_socket_client(
                "ssl://{$this->host}:{$this->port}", $errno, $errstr,
                $this->timeout, STREAM_CLIENT_CONNECT, $ctx
            );
        } else {
            $sock = @stream_socket_client(
                "tcp://{$this->host}:{$this->port}", $errno, $errstr,
                $this->timeout
            );
        }
        if ($sock === false) {
            throw new \RuntimeException("IMAP connect to {$this->host}:{$this->port} failed: $errstr ($errno)");
        }
        stream_set_timeout($sock, $this->timeout);
        return $sock;
    }

    /** @return resource */
    private function connectViaSocks5(): mixed
    {
        $proxy = @stream_socket_client(
            "tcp://{$this->socks5Host}:{$this->socks5Port}",
            $errno, $errstr, $this->timeout
        );
        if ($proxy === false) {
            throw new \RuntimeException("SOCKS5 proxy unreachable: $errstr");
        }
        fwrite($proxy, "\x05\x01\x00");
        $resp = fread($proxy, 2);
        if ($resp === false || strlen($resp) < 2 || $resp[1] !== "\x00") {
            throw new \RuntimeException("SOCKS5 auth rejected");
        }
        $hostLen = strlen($this->host);
        fwrite($proxy, "\x05\x01\x00\x03" . chr($hostLen) . $this->host . pack('n', $this->port));
        $resp = fread($proxy, 10);
        if ($resp === false || strlen($resp) < 2 || $resp[1] !== "\x00") {
            throw new \RuntimeException("SOCKS5 CONNECT refused");
        }
        if ($this->encryption === 'ssl') {
            stream_socket_enable_crypto($proxy, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }
        return $proxy;
    }

    private function starttls(): Result
    {
        $r = $this->command('STARTTLS');
        if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }
        if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $this->err('starttls', 'TLS negotiation failed');
        }
        return Result::ok(true);
    }

    // =========================================================================
    // Protocol layer
    // =========================================================================

    /** Accumulated untagged lines from last command; cleared before each command. */
    private array  $untaggedBuffer   = [];
    private string $lastLiteralContent = '';
    private array  $lastFlags          = [];

    private function nextTag(): string
    {
        return 'A' . str_pad((string) ++$this->tagSeq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Send a command and read all responses until the tagged final response.
     * Returns the tagged response line (e.g. "A0001 OK ...").
     * On NO/BAD returns Result::err with the server message.
     * @return Result<string>
     */
    private function command(string $cmd): Result
    {
        $tag = $this->nextTag();
        $this->untaggedBuffer    = [];
        $this->lastLiteralContent = '';
        $this->lastFlags          = [];
        $this->writeLine("{$tag} {$cmd}");

        $tagged = $this->readTaggedResponse($tag);
        if (str_starts_with($tagged, $tag . ' OK')) {
            return Result::ok($tagged);
        }
        // NO or BAD
        return Result::err($tagged, Diagnostics::of(new ImapDiagnostic(
                                                        ImapDiagnostic::ID, ImapDiagnostic::LEVEL,
                                                        strtolower(explode(' ', $cmd)[0]),
                                                        $tagged
                                                    )));
    }

    /**
     * Read lines until the tagged final response for $tag.
     * Handles IMAP literals ({N} followed by N bytes).
     */
    private function readTaggedResponse(string $tag): string
    {
        while (true) {
            $line = $this->readLine();
            if ($line === false) {
                return $tag . ' BAD Connection closed';
            }

            // Literal: "* N FETCH (...{SIZE}"
            if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                $literalSize    = (int) $m[1];
                $literalContent = $this->readBytes($literalSize);
                // Read trailing CRLF
                $this->readLine();
                // For FETCH responses, store the literal content
                if (str_contains($line, 'FETCH') || str_contains($line, 'RFC822')) {
                    $this->lastLiteralContent = $literalContent;
                    // Also extract FLAGS from the surrounding line
                    $this->lastFlags = $this->extractFlags($line);
                }
                $this->untaggedBuffer[] = $line;
                continue;
            }

            // Tagged final response
            if (str_starts_with($line, $tag . ' ')) {
                return $line;
            }

            // Untagged response
            if (str_starts_with($line, '*') || str_starts_with($line, '+')) {
                $this->untaggedBuffer[] = $line;
            }
        }
    }

    private function writeLine(string $line): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException("Not connected");
        }
        fwrite($this->socket, $line . "\r\n");
    }

    private function readLine(): string|false
    {
        if ($this->socket === null) { return false; }
        $line = fgets($this->socket, 8192);
        return $line !== false ? rtrim($line, "\r\n") : false;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        $remaining = $n;
        while ($remaining > 0) {
            $chunk = fread($this->socket, min($remaining, 65536));
            if ($chunk === false || $chunk === '') { break; }
            $buf       .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $buf;
    }

    /** @return list<string> */
    private function untaggedLines(): array
    {
        return $this->untaggedBuffer;
    }

    // =========================================================================
    // Response parsing helpers
    // =========================================================================

    private function extractInt(string $data, string $keyword): int
    {
        if (preg_match('/' . preg_quote($keyword, '/') . ' (\d+)/i', $data, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /** @return list<string> */
    private function extractFlags(string $data): array
    {
        if (preg_match('/FLAGS \(([^)]*)\)/i', $data, $m)) {
            $raw = trim($m[1]);
            return $raw === '' ? [] : explode(' ', $raw);
        }
        return [];
    }

    /**
     * Extract ENVELOPE fields: date, subject, from, reply-to, to, cc, bcc, message-id.
     * ENVELOPE format: ("date" "subject" (from) (reply-to) (to) (cc) (bcc) (in-reply-to) "message-id")
     * This is a simplified parser for the common case.
     * @return array<string,string>
     */
    private function extractEnvelope(string $data): array
    {
        if (!preg_match('/ENVELOPE \((.+)\)\s*(?:FLAGS|$)/sU', $data, $m)) {
            return [];
        }
        $env = $m[1];

        // Parse the envelope as a sequence of atoms/strings
        $tokens = $this->tokeniseEnvelope($env);
        return [
            'date'       => $tokens[0] ?? '',
            'subject'    => $tokens[1] ?? '',
            'from'       => $this->parseAddressList($tokens[2] ?? ''),
            'reply-to'   => $this->parseAddressList($tokens[3] ?? ''),
            'to'         => $this->parseAddressList($tokens[4] ?? ''),
            'cc'         => $this->parseAddressList($tokens[5] ?? ''),
            'bcc'        => $this->parseAddressList($tokens[6] ?? ''),
            'message-id' => $tokens[8] ?? '',
        ];
    }

    /**
     * Tokenise top-level fields from an ENVELOPE string.
     * Returns a flat list of raw field strings (quoted strings or parenthesised groups).
     * @return list<string>
     */
    private function tokeniseEnvelope(string $s): array
    {
        $tokens = [];
        $i      = 0;
        $len    = strlen($s);
        while ($i < $len) {
            if ($s[$i] === ' ') { $i++; continue; }
            if ($s[$i] === '"') {
                // Quoted string
                $j = $i + 1;
                $val = '';
                while ($j < $len) {
                    if ($s[$j] === '\\') { $val .= $s[$j+1] ?? ''; $j += 2; continue; }
                    if ($s[$j] === '"')  { $j++; break; }
                    $val .= $s[$j++];
                }
                $tokens[] = $val;
                $i = $j;
            } elseif ($s[$i] === '(') {
                // Parenthesised group — find matching close
                $depth = 1; $j = $i + 1;
                while ($j < $len && $depth > 0) {
                    if ($s[$j] === '(' && ($j === 0 || $s[$j-1] !== '\\')) { $depth++; }
                    elseif ($s[$j] === ')' && ($j === 0 || $s[$j-1] !== '\\')) { $depth--; }
                    $j++;
                }
                $tokens[] = substr($s, $i + 1, $j - $i - 2);
                $i = $j;
            } elseif (strtoupper(substr($s, $i, 3)) === 'NIL') {
                $tokens[] = '';
                $i += 3;
            } else {
                // Unquoted atom
                $j = $i;
                while ($j < $len && $s[$j] !== ' ' && $s[$j] !== ')') { $j++; }
                $tokens[] = substr($s, $i, $j - $i);
                $i = $j;
            }
        }
        return $tokens;
    }

    /** Parse an IMAP address list "(name NIL localpart domain)" into a display string. */
    private function parseAddressList(string $raw): string
    {
        if ($raw === '' || strtoupper($raw) === 'NIL') { return ''; }
        // May contain multiple addresses: "(A)(B)"
        $addresses = [];
        if (preg_match_all('/\(([^)]*)\)/', $raw, $matches)) {
            foreach ($matches[1] as $addrStr) {
                $parts = $this->tokeniseEnvelope($addrStr);
                $name  = isset($parts[0]) && $parts[0] !== '' ? $this->mimeDecodeHeader($parts[0]) : '';
                $local = $parts[2] ?? '';
                $domain= $parts[3] ?? '';
                $email = $local !== '' && $domain !== '' ? "{$local}@{$domain}" : '';
                if ($name !== '' && $email !== '') {
                    $addresses[] = "{$name} <{$email}>";
                } elseif ($email !== '') {
                    $addresses[] = $email;
                }
            }
        }
        return implode(', ', $addresses);
    }

    private function formatAddress(string $raw): string
    {
        return $raw ?: '(unknown)';
    }

    // =========================================================================
    // MIME message parsing
    // =========================================================================

    /**
     * Parse a raw RFC 2822 message into structured fields.
     * Handles text/plain, text/html, multipart/alternative, multipart/mixed.
     * @return array<string,string>
     */
    private function parseMimeMessage(string $raw): array
    {
        [$rawHeaders, $rawBody] = $this->splitHeadersBody($raw);
        $headers  = $this->parseHeaders($rawHeaders);
        $ct       = strtolower($headers['content-type'] ?? 'text/plain');
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '7bit');

        $result = [
            'subject'   => $this->mimeDecodeHeader($headers['subject']           ?? '(no subject)'),
            'from'      => $this->mimeDecodeHeader($headers['from']              ?? ''),
            'to'        => $this->mimeDecodeHeader($headers['to']                ?? ''),
            'cc'        => $this->mimeDecodeHeader($headers['cc']                ?? ''),
            'date'      => $headers['date']                                       ?? '',
            'message_id'=> $headers['message-id']                                ?? '',
            'body_text'   => '',
            'body_html'   => '',
            'attachments' => [],
        ];

        if (str_starts_with($ct, 'multipart/')) {
            // Extract boundary
            if (preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'] ?? '', $m)) {
                $boundary    = $m[1];
                $attachments = [];
                [$result['body_text'], $result['body_html']] = $this->parseMultipart($rawBody, $boundary, $ct, $attachments);
                $result['attachments'] = $attachments;
            }
        } else {
            $decoded = $this->decodeBody($rawBody, $encoding);
            $charset  = $this->extractCharset($ct);
            $decoded  = $this->toUtf8($decoded, $charset);
            if (str_contains($ct, 'text/html')) {
                $result['body_html'] = $decoded;
            } else {
                $result['body_text'] = $decoded;
            }
        }

        return $result;
    }

    /**
     * @param  list<array{name:string,content_type:string,size:int,encoding:string,raw:string}> $attachments
     * @return array{0:string,1:string} [text_body, html_body]
     */
    private function parseMultipart(string $body, string $boundary, string $parentCt, array &$attachments = []): array
    {
        $textBody = '';
        $htmlBody = '';

        $parts = $this->splitMultipart($body, $boundary);
        foreach ($parts as $part) {
            [$partRawHeaders, $partBody] = $this->splitHeadersBody($part);
            $partHeaders     = $this->parseHeaders($partRawHeaders);
            $partCt          = strtolower($partHeaders['content-type'] ?? 'text/plain');
            $partEncoding    = strtolower($partHeaders['content-transfer-encoding'] ?? '7bit');
            $partDisposition = strtolower($partHeaders['content-disposition'] ?? '');

            if (str_starts_with($partCt, 'multipart/')) {
                if (preg_match('/boundary="?([^";]+)"?/i', $partHeaders['content-type'] ?? '', $m)) {
                    [$t, $h] = $this->parseMultipart($partBody, $m[1], $partCt, $attachments);
                    if ($textBody === '') { $textBody = $t; }
                    if ($htmlBody === '') { $htmlBody = $h; }
                }
            } elseif (
                str_starts_with($partDisposition, 'attachment') ||
                (!str_contains($partCt, 'text/plain') &&
                 !str_contains($partCt, 'text/html') &&
                 !str_starts_with($partCt, 'multipart/') &&
                 !str_contains($partCt, 'message/rfc822'))
            ) {
                $filename      = $this->extractFilename($partHeaders);
                $decoded       = $this->decodeBody($partBody, $partEncoding);
                $attachments[] = [
                    'name'         => $filename !== '' ? $filename : 'attachment',
                    'content_type' => (string) preg_replace('/;.*$/', '', $partCt),
                    'size'         => strlen($decoded),
                    'encoding'     => $partEncoding,
                    'raw'          => $partBody,
                ];
            } elseif (str_contains($partCt, 'text/plain')) {
                $decoded  = $this->decodeBody($partBody, $partEncoding);
                $charset  = $this->extractCharset($partCt);
                $textBody = $this->toUtf8($decoded, $charset);
            } elseif (str_contains($partCt, 'text/html')) {
                $decoded  = $this->decodeBody($partBody, $partEncoding);
                $charset  = $this->extractCharset($partCt);
                $htmlBody = $this->toUtf8($decoded, $charset);
            }
        }

        return [$textBody, $htmlBody];
    }

    /** Split a raw message into headers + body at the first blank line. */
    private function splitHeadersBody(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) { $pos = strpos($raw, "\n\n"); }
        if ($pos === false) { return [$raw, '']; }
        $sep = str_contains(substr($raw, $pos, 4), "\r") ? 4 : 2;
        return [substr($raw, 0, $pos), substr($raw, $pos + $sep)];
    }

    /**
     * Parse headers into a lowercase-key associative array.
     * Handles folded headers (continuation lines with leading whitespace).
     * @return array<string,string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        // Unfold: join lines that start with whitespace
        $raw = preg_replace("/\r\n([ \t])/", " $1", $raw);
        $raw = preg_replace("/\n([ \t])/", " $1", $raw);
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) { continue; }
            $name  = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = $value;
        }
        return $headers;
    }

    /** Split a multipart body into individual part strings. */
    private function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $parts     = [];
        $lines     = preg_split("/\r?\n/", $body);
        $current   = null;

        foreach ($lines as $line) {
            if (rtrim($line) === $delimiter) {
                if ($current !== null) { $parts[] = implode("\n", $current); }
                $current = [];
            } elseif (rtrim($line) === $delimiter . '--') {
                if ($current !== null) { $parts[] = implode("\n", $current); }
                break;
            } elseif ($current !== null) {
                $current[] = $line;
            }
        }
        return $parts;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'quoted-printable' => quoted_printable_decode($body),
            'base64'           => base64_decode(str_replace(["\r", "\n"], '', $body)),
            default            => $body,
        };
    }

    /**
     * Extract filename from Content-Disposition or Content-Type name parameter.
     * @param array<string,string> $headers
     */
    private function extractFilename(array $headers): string
    {
        $disposition = $headers['content-disposition'] ?? '';
        if (preg_match('/filename\*?=["\']?([^"\'\s;]+)/i', $disposition, $m)) {
            return $this->mimeDecodeHeader(trim($m[1], '"\''));
        }
        $ct = $headers['content-type'] ?? '';
        if (preg_match('/name=["\']?([^"\'\s;]+)/i', $ct, $m)) {
            return $this->mimeDecodeHeader(trim($m[1], '"\''));
        }
        return '';
    }

    private function extractCharset(string $contentType): string
    {
        if (preg_match('/charset="?([^";]+)"?/i', $contentType, $m)) {
            return strtoupper(trim($m[1]));
        }
        return 'UTF-8';
    }

    private function toUtf8(string $text, string $charset): string
    {
        if ($charset === 'UTF-8' || $charset === 'UTF8') { return $text; }
        $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }

    public function mimeDecodeHeader(string $value): string
    {
        // RFC 2047 encoded-words: =?charset?encoding?text?=
        return mb_decode_mimeheader($value);
    }

    // =========================================================================
    // Misc helpers
    // =========================================================================

    private function quoteString(string $s): string
    {
        // Use literal if string contains special chars; simple quote otherwise
        if (preg_match('/[\x00\r\n"\\\\]/', $s)) {
            $len = strlen($s);
            return "{{$len}}\r\n{$s}";
        }
        return '"' . addcslashes($s, '"\\') . '"';
    }

    private function folderDisplayName(string $name, string $delimiter): string
    {
        $parts = $delimiter !== '' ? explode($delimiter, $name) : [$name];
        return end($parts) ?: $name;
    }

    private function err(string $op, string $detail = ''): Result
    {
        return Result::err(false, Diagnostics::of(new ImapDiagnostic(
                                                      ImapDiagnostic::ID, ImapDiagnostic::LEVEL, $op, $detail
                                                  )));
    }
}