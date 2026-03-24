<?php
declare(strict_types=1);

namespace AstrX\Mail;

use AstrX\Config\InjectConfig;
use AstrX\Result\Result;

/**
 * Orchestrates IMAP (read) and SMTP (send) for the webmail UI.
 *
 * A single WebmailService instance exists per request. It opens the IMAP
 * connection lazily and keeps it alive for the duration of the request.
 * The caller is responsible for calling disconnect() when done (or let the
 * destructor handle it).
 *
 * Per-page configuration:
 *   messages_per_page   Number of messages to show per folder page (default 25).
 *   trash_folder        IMAP folder name used as Trash (default 'Trash').
 *   sent_folder         IMAP folder name used for sent messages (default 'Sent').
 *   drafts_folder       IMAP folder name for drafts (default 'Drafts').
 */
final class WebmailService
{
    private int    $messagesPerPage = 25;
    private string $trashFolder     = 'Trash';
    private string $sentFolder      = 'Sent';
    private string $draftsFolder             = 'Drafts';
    private string $mailDomain               = 'localhost';
    private bool   $imapLoginUseFullAddress  = true;

    #[InjectConfig('messages_per_page')]
    public function setMessagesPerPage(int $v): void { $this->messagesPerPage = max(5, min(200, $v)); }

    #[InjectConfig('trash_folder')]
    public function setTrashFolder(string $v): void { $this->trashFolder = $v; }

    #[InjectConfig('sent_folder')]
    public function setSentFolder(string $v): void { $this->sentFolder = $v; }

    #[InjectConfig('drafts_folder')]
    public function setDraftsFolder(string $v): void { $this->draftsFolder = $v; }

    /** Mail domain appended to the local-part to form a full address. */
    #[InjectConfig('mail_domain')]
    public function setMailDomain(string $v): void { $this->mailDomain = $v; }

    /** When true, IMAP LOGIN uses 'localpart@domain'; when false, just 'localpart'. */
    #[InjectConfig('imap_login_use_full_address')]
    public function setImapLoginUseFullAddress(bool $v): void { $this->imapLoginUseFullAddress = $v; }

    public function __construct(
        private readonly ImapClient $imap,
        private readonly Mailer     $mailer,
    ) {}

    public function __destruct()
    {
        $this->disconnect();
    }

    // =========================================================================
    // Connection management
    // =========================================================================

    /**
     * Connect and authenticate. Must be called before any other method.
     * @return Result<true>
     */
    /**
     * Connect using the mailbox local-part. The IMAP login identity is constructed
     * here based on the imap_login_use_full_address flag:
     *   true  → 'localpart@mail_domain'  (Dovecot virtual users, most modern servers)
     *   false → 'localpart'              (single-domain or older servers)
     *
     * @param string $mailboxLocalPart  The local-part only (from UserSession::mailbox())
     * @return Result<true>
     */
    public function connect(string $mailboxLocalPart, string $imapPassword): Result
    {
        if ($this->imap->isConnected()) { return Result::ok(true); }
        $r = $this->imap->connect();
        if (!$r->isOk()) { return $r; }
        $loginId = $this->imapLoginUseFullAddress
            ? $mailboxLocalPart . '@' . $this->mailDomain
            : $mailboxLocalPart;
        return $this->imap->login($loginId, $imapPassword);
    }

    /**
     * Save a draft to the Drafts folder.
     * @return Result<true>
     */
    public function saveDraft(
        string $fromAddress,
        string $fromName,
        string $toAddress,
        string $subject,
        string $bodyText,
        string $cc = '',
        string $bcc = '',
    ): Result {
        $draftFrom = $fromAddress !== '' && !str_contains($fromAddress, '@')
            ? $fromAddress . '@' . $this->mailDomain
            : $fromAddress;
        $raw = $this->buildRawMessage($draftFrom, $fromName, $toAddress, $subject, $bodyText, '', $cc, $bcc, '');
        return $this->imap->appendToSent($raw, $this->draftsFolder);
    }

    /**
     * Retrieve the decoded bytes of an attachment by its index within a message.
     * @return Result<array{name:string,content_type:string,data:string}>
     */
    public function getAttachment(string $folder, int $uid, int $attachmentIndex): Result
    {
        $r = $this->getMessage($folder, $uid);
        if (!$r->isOk()) { return $r; }
        $msg         = $r->unwrap();
        $attachments = $msg['attachments'] ?? [];
        if (!isset($attachments[$attachmentIndex])) {
            return Result::err(false);
        }
        $att  = $attachments[$attachmentIndex];
        // Decode the stored raw (encoded) bytes on demand
        $data = match ($att['encoding']) {
            'base64'           => base64_decode(str_replace(["\r", "\n"], '', $att['raw'])),
            'quoted-printable' => quoted_printable_decode($att['raw']),
            default            => $att['raw'],
        };
        return Result::ok([
                              'name'         => $att['name'],
                              'content_type' => $att['content_type'],
                              'data'         => $data,
                          ]);
    }

    public function disconnect(): void
    {
        $this->imap->logout();
    }

    // =========================================================================
    // Folder operations
    // =========================================================================

    /**
     * Get all folders with their status (message count, unread count).
     * @return Result<list<array{name:string,display:string,total:int,unseen:int,is_special:bool,special_type:string}>>
     */
    public function getFolders(): Result
    {
        $r = $this->imap->listFolders();
        if (!$r->isOk()) { return $r; }

        $specialFolders = [
            'INBOX'              => 'inbox',
            $this->sentFolder    => 'sent',
            $this->draftsFolder  => 'drafts',
            $this->trashFolder   => 'trash',
        ];

        $folders = [];
        foreach ($r->unwrap() as $folder) {
            $statusR = $this->imap->folderStatus($folder['name']);
            $status  = $statusR->isOk() ? $statusR->unwrap() : ['total' => 0, 'unseen' => 0, 'recent' => 0];
            $special = isset($specialFolders[$folder['name']]);
            $folders[] = [
                'name'         => $folder['name'],
                'display'      => $folder['display'],
                'total'        => $status['total'],
                'unseen'       => $status['unseen'],
                'is_special'   => $special,
                'special_type' => $special ? $specialFolders[$folder['name']] : '',
            ];
        }
        return Result::ok($folders);
    }

    // =========================================================================
    // Message listing
    // =========================================================================

    /**
     * List messages in a folder with pagination.
     * @return Result<array{messages:list<array<string,mixed>>,total:int,pages:int,page:int,per_page:int}>
     */
    public function listMessages(string $folder, int $page = 1): Result
    {
        $page    = max(1, $page);
        $perPage = $this->messagesPerPage;

        $total = $this->imap->selectFolder($folder);
        if (!$total->isOk()) { return $total; }
        $totalMessages = $total->unwrap();

        $pages    = $totalMessages > 0 ? (int) ceil($totalMessages / $perPage) : 1;
        $page     = min($page, $pages);

        if ($totalMessages === 0) {
            return Result::ok([
                                  'messages' => [], 'total' => 0,
                                  'pages' => 1, 'page' => 1, 'per_page' => $perPage,
                              ]);
        }

        $r = $this->imap->fetchHeadersPage($totalMessages, $page, $perPage);
        if (!$r->isOk()) { return $r; }

        return Result::ok([
                              'messages' => $r->unwrap(),
                              'total'    => $totalMessages,
                              'pages'    => $pages,
                              'page'     => $page,
                              'per_page' => $perPage,
                          ]);
    }

    // =========================================================================
    // Message viewing
    // =========================================================================

    /**
     * Fetch and parse a single message, marking it as seen.
     * @return Result<array<string,mixed>>
     */
    public function getMessage(string $folder, int $uid): Result
    {
        $r = $this->imap->selectFolder($folder);
        if (!$r->isOk()) { return $r; }

        $r = $this->imap->fetchMessage($uid);
        if (!$r->isOk()) { return $r; }

        // Mark as seen
        $this->imap->setSeenFlag($uid, true);

        return $r;
    }

    // =========================================================================
    // Sending
    // =========================================================================

    /**
     * Send an email and append it to the Sent folder.
     *
     * @param string $fromAddress  Sender's full email address
     * @param string $fromName     Sender's display name
     * @param string $toAddress    Recipient's email address
     * @param string $subject      Subject line
     * @param string $bodyText     Plain-text body
     * @param string $inReplyTo    Message-ID being replied to ('' if none)
     * @return Result<true>
     */
    public function sendMessage(
        string $fromAddress,
        string $fromName,
        string $toAddress,
        string $subject,
        string $bodyText,
        string $bodyHtml    = '',
        string $cc          = '',
        string $bcc         = '',
        string $inReplyTo   = '',
        string $priority    = 'normal',
        bool   $readReceipt = false,
        string $replyTo     = '',
    ): Result {
        // Resolve the sender's full email address from the local-part + domain.
        $resolvedFrom = $fromAddress !== '' && !str_contains($fromAddress, '@')
            ? $fromAddress . '@' . $this->mailDomain
            : $fromAddress;

        // Send via SMTP (Mailer only accepts a single To: for now)
        $r = $this->mailer->send($toAddress, '', $subject, $bodyText, $bodyHtml, $resolvedFrom, $fromName, $priority, $readReceipt);
        if (!$r->isOk()) { return $r; }

        // Build raw message for IMAP APPEND
        $raw = $this->buildRawMessage($resolvedFrom, $fromName, $toAddress, $subject, $bodyText, $bodyHtml, $cc, $bcc, $inReplyTo, $priority, $replyTo);

        // Append to Sent folder (non-fatal if it fails)
        $this->imap->appendToSent($raw, $this->sentFolder);

        return Result::ok(true);
    }

    // =========================================================================
    // Message actions
    // =========================================================================

    /** @return Result<true> */
    public function deleteMessage(string $folder, int $uid): Result
    {
        $r = $this->imap->selectFolder($folder);
        if (!$r->isOk()) { return $r; }
        return $this->imap->deleteMessage($uid, $this->trashFolder);
    }

    /** @return Result<true> */
    public function moveMessage(string $folder, int $uid, string $targetFolder): Result
    {
        $r = $this->imap->selectFolder($folder);
        if (!$r->isOk()) { return $r; }
        return $this->imap->moveMessage($uid, $targetFolder);
    }

    /** @return Result<true> */
    public function setSeenFlag(string $folder, int $uid, bool $seen): Result
    {
        $r = $this->imap->selectFolder($folder);
        if (!$r->isOk()) { return $r; }
        return $this->imap->setSeenFlag($uid, $seen);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function buildRawMessage(
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $body,
        string $bodyHtml    = '',
        string $cc          = '',
        string $bcc         = '',
        string $inReplyTo   = '',
        string $priority    = 'normal',
        string $replyTo     = '',
    ): string {
        $date    = date('r');
        $msgId   = '<' . bin2hex(random_bytes(12)) . '@' . (gethostname() ?: 'localhost') . '>';
        $fromHdr = $fromName !== '' ? '"' . addcslashes($fromName, '"\\') . '" <' . $from . '>' : $from;
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers  = "From: {$fromHdr}\r\n";
        $headers .= "To: {$to}\r\n";
        if ($cc !== '') { $headers .= "Cc: {$cc}\r\n"; }
        if ($bcc !== '') { $headers .= "Bcc: {$bcc}\r\n"; }
        $headers .= "Subject: {$subjectEncoded}\r\n";
        $headers .= "Date: {$date}\r\n";
        $headers .= "Message-ID: {$msgId}\r\n";
        if ($inReplyTo !== '') {
            $headers .= "In-Reply-To: {$inReplyTo}\r\n";
        }
        if ($replyTo !== '') {
            $headers .= "Reply-To: {$replyTo}\r\n";
        }
        if ($priority === 'high') {
            $headers .= "X-Priority: 1\r\n";
            $headers .= "Importance: High\r\n";
        } elseif ($priority === 'low') {
            $headers .= "X-Priority: 5\r\n";
            $headers .= "Importance: Low\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\n";

        if ($bodyHtml !== '') {
            $boundary  = bin2hex(random_bytes(12));
            $headers  .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $bodyPart  = "--{$boundary}\r\n"
                         . "Content-Type: text/plain; charset=UTF-8\r\n"
                         . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                         . quoted_printable_encode($body) . "\r\n"
                         . "--{$boundary}\r\n"
                         . "Content-Type: text/html; charset=UTF-8\r\n"
                         . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                         . quoted_printable_encode($bodyHtml) . "\r\n"
                         . "--{$boundary}--";
            return $headers . "\r\n" . $bodyPart;
        }

        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: quoted-printable\r\n";
        return $headers . "\r\n" . quoted_printable_encode($body);
    }

    public function mailDomain(): string   { return $this->mailDomain; }
    public function trashFolder(): string  { return $this->trashFolder; }
    public function sentFolder(): string   { return $this->sentFolder; }
    public function draftsFolder(): string { return $this->draftsFolder; }
    public function messagesPerPage(): int { return $this->messagesPerPage; }
}