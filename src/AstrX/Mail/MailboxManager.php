<?php

declare(strict_types = 1);

namespace AstrX\Mail;

use AstrX\Config\InjectConfig;
use AstrX\Mail\Diagnostic\MailDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;

/**
 * Creates and removes mailboxes on the Dovecot server.
 * When a user registers on the web app, UserService calls createMailbox()
 * to provision a mailbox on Dovecot and a virtual alias in Postfix.
 * This class communicates with a small management API running inside the
 * mail containers (see docker/mailapi/). The API is only reachable within
 * the Docker network — never exposed to the internet.
 * Alternatively, if the web app runs on the SAME Docker network as Dovecot,
 * it can write directly to the Dovecot passwd file.
 * Config (Mail.config.php):
 *   mailbox_domain   The mail domain for new mailboxes (e.g. 'xyz.onion')
 *   mailapi_url      URL of the management API (e.g. 'http://mailapi:8080')
 *   mailapi_secret   Shared secret for API auth
 */
final class MailboxManager
{
    private string $mailboxDomain = '';
    private string $mailapiUrl = '';
    private string $mailapiSecret = '';

    #[InjectConfig('mailbox_domain')]
    public function setMailboxDomain(string $v)
    : void {
        $this->mailboxDomain = $v;
    }

    #[InjectConfig('mailapi_url')]
    public function setMailapiUrl(string $v)
    : void {
        $this->mailapiUrl = $v;
    }

    #[InjectConfig('mailapi_secret')]
    public function setMailapiSecret(string $v)
    : void {
        $this->mailapiSecret = $v;
    }

    // =========================================================================

    /**
     * Create a mailbox for a new user.
     * Called by UserController after successful registration.
     * @return Result<array{address: string}>
     */
    public function createMailbox(string $username, string $password)
    : Result {
        $address = $username . '@' . $this->mailboxDomain;

        $payload = json_encode([
                                   'action' => 'create',
                                   'username' => $username,
                                   'password' => $password,
                                   'address' => $address,
                               ]);

        $result = $this->apiCall('POST', '/mailbox', (string)$payload);
        if (!$result->isOk()) {
            return $result;
        }

        return Result::ok(['address' => $address]);
    }

    /**
     * Delete a mailbox when a user deletes their account.
     * @return Result<true>
     */
    public function deleteMailbox(string $username)
    : Result {
        $payload = json_encode([
                                   'action' => 'delete',
                                   'username' => $username,
                               ]);

        return $this->apiCall(
            'DELETE',
            '/mailbox/' . urlencode($username),
            (string)$payload
        );
    }

    /**
     * Change the IMAP/SMTP password for a mailbox.
     * Called when the user changes their password in settings.
     * @return Result<true>
     */
    public function changePassword(string $username, string $newPassword)
    : Result {
        $payload = json_encode([
                                   'action' => 'passwd',
                                   'username' => $username,
                                   'password' => $newPassword,
                               ]);

        return $this->apiCall(
            'PATCH',
            '/mailbox/' . urlencode($username),
            (string)$payload
        );
    }

    // =========================================================================

    /** @return Result<mixed> */
    private function apiCall(string $method, string $path, string $body)
    : Result {
        if ($this->mailapiUrl === '') {
            // No API configured — write directly to Dovecot passwd file.
            // Only works when the web app shares the dovecot_auth volume.
            return $this->directWrite($body);
        }

        $ctx = stream_context_create([
                                         'http' => [
                                             'method' => $method,
                                             'header' => implode("\r\n", [
                                                 'Content-Type: application/json',
                                                 'X-Api-Secret: ' .
                                                 $this->mailapiSecret,
                                                 'Content-Length: ' .
                                                 strlen($body),
                                             ]),
                                             'content' => $body,
                                             'timeout' => 10,
                                         ],
                                     ]);

        $response = @file_get_contents($this->mailapiUrl . $path, false, $ctx);
        if ($response === false) {
            return $this->err('mailapi_unreachable');
        }

        $data = json_decode($response, true);
        if (!is_array($data) || ($data['ok']??false) !== true) {
            return $this->err('mailapi_error', $data['error']??'');
        }

        return Result::ok($data);
    }

    /**
     * Direct write to Dovecot passwd/userdb files.
     * Used when the web app shares the dovecot_auth Docker volume.
     * Password is hashed with ARGON2ID (supported by Dovecot ≥ 2.3.11).
     */
    private function directWrite(string $jsonBody)
    : Result {
        $data = json_decode($jsonBody, true);
        if (!is_array($data)) {
            return $this->err('invalid_payload');
        }

        $action = (string)($data['action']??'');
        $username = (string)($data['username']??'');
        $domain = $this->mailboxDomain;
        $address = $username . '@' . $domain;

        $passwdFile = '/etc/dovecot/auth/passwd';
        $userdbFile = '/etc/dovecot/auth/userdb';

        if ($action === 'create') {
            $hash = password_hash(
                (string)($data['password']??''),
                PASSWORD_ARGON2ID
            );
            $passwdLine = "{$address}:{ARGON2ID}{$hash}\n";
            $userdbLine
                = "{$address}:vmail:vmail::/var/mail/vhosts/{$domain}/{$username}::\n";

            if (file_put_contents(
                    $passwdFile,
                    $passwdLine,
                    FILE_APPEND | LOCK_EX
                ) === false) {
                return $this->err('mailbox_write_failed', $passwdFile);
            }
            if (file_put_contents(
                    $userdbFile,
                    $userdbLine,
                    FILE_APPEND | LOCK_EX
                ) === false) {
                return $this->err('mailbox_write_failed', $userdbFile);
            }

            // Create the maildir
            $maildir = "/var/mail/vhosts/{$domain}/{$username}";
            foreach (['/cur', '/new', '/tmp'] as $sub) {
                if (!is_dir($maildir . $sub)) {
                    mkdir($maildir . $sub, 0700, true);
                }
            }
        } elseif ($action === 'delete') {
            // Remove lines matching the address from both files
            foreach ([$passwdFile, $userdbFile] as $file) {
                $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
                $lines = array_filter(
                    $lines,
                    fn($l) => !str_starts_with($l, $address . ':')
                );
                file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX);
            }
        } elseif ($action === 'passwd') {
            $hash = password_hash(
                (string)($data['password']??''),
                PASSWORD_ARGON2ID
            );
            $lines = file($passwdFile, FILE_IGNORE_NEW_LINES) ?: [];
            $lines = array_map(function ($l) use ($address, $hash) {
                return str_starts_with($l, $address . ':') ?
                    "{$address}:{ARGON2ID}{$hash}" : $l;
            }, $lines);
            file_put_contents(
                $passwdFile,
                implode("\n", $lines) . "\n",
                LOCK_EX
            );
        }

        return Result::ok(true);
    }

    private function err(string $op, string $detail = ''): Result
    {
        return Result::err(false, Diagnostics::of(new MailDiagnostic($op, $detail)));
    }
}