<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Mail\HtmlEmailSanitizer;
use AstrX\Mail\WebmailService;
use AstrX\Mail\WebmailTrustedSenderRepository;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserSession;

/**
 * Webmail — read, compose, send and manage emails.
 *
 * This controller never touches $_SESSION directly. All session state is
 * routed through UserSession, which is backed by SecureSessionHandler
 * (AES-256-CTR encrypted, HMAC-authenticated session storage).
 *
 * IMAP password lifecycle:
 *   Login  → LoginController calls $session->storeImapPassword($password).
 *   Here   → $session->imapPassword() used to authenticate IMAP connection.
 *   Logout → UserSession::logout() calls clearImapPassword() automatically.
 *   Auth failure → clearImapPassword() here, user shown the login prompt.
 *
 * GET parameters:
 *   folder=<name>      select folder (default: INBOX)
 *   page=<n>           message list page number
 *   uid=<n>            view a single message
 *   compose=1          show compose form
 *   reply_uid=<n>      pre-fill compose as reply to this UID
 *   attachment=<n>     download attachment index n from ?uid=X&folder=Y
 *
 * POST actions (via PRG, all require CSRF except 'imap_login'):
 *   imap_login     store IMAP password in session (no CSRF — unauthenticated)
 *   send           send email (To/CC/BCC/Subject/Body)
 *   save_draft     save compose form to Drafts folder
 *   delete         delete message (uid + folder)
 *   move           move message to another folder (uid + folder + target_folder)
 *   mark_seen      mark message as read (uid + folder)
 *   mark_unseen    mark message as unread (uid + folder)
 *   trust_sender   add sender to trusted list
 *   untrust_sender remove sender from trusted list
 *   disconnect     clear IMAP password from session
 */
final class WebmailController extends AbstractController
{
    private const FORM  = 'webmail';
    private const INBOX = 'INBOX';

    public function __construct(
        DiagnosticsCollector                        $collector,
        private readonly DefaultTemplateContext      $ctx,
        private readonly Request                    $request,
        private readonly UserSession                $session,
        private readonly WebmailService             $webmail,
        private readonly WebmailTrustedSenderRepository $trustedSenders,
        private readonly HtmlEmailSanitizer         $sanitizer,
        private readonly Gate                       $gate,
        private readonly CsrfHandler                $csrf,
        private readonly PrgHandler                 $prg,
        private readonly FlashBag                   $flash,
        private readonly Page                       $page,
        private readonly UrlGenerator               $urlGen,
        private readonly Translator                 $t,
    ) {
        parent::__construct($collector);
    }

    // =========================================================================
    // Entry point
    // =========================================================================

    public function handle(): Result
    {
        if (!$this->session->isLoggedIn()) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        if ($this->gate->cannot(Permission::WEBMAIL_ACCESS)) {
            http_response_code(403);
            $this->ctx->set('webmail_forbidden', true);
            return $this->ok();
        }
        $this->ctx->set('webmail_forbidden', false);

        $selfUrl = $this->selfUrl();

        // ── PRG: handle POST mutations ────────────────────────────────────────
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $redirect = $this->processForm($prgToken, $selfUrl);
            Response::redirect($redirect)->send()->drainTo($this->collector);
            exit;
        }

        // ── Attachment download (GET, no PRG needed) ──────────────────────────
        $attachmentIndex = $this->request->query()->get('attachment');
        if ($attachmentIndex !== null) {
            $this->handleAttachmentDownload($selfUrl);
            // handleAttachmentDownload exits; if it returns we fell through
            return $this->ok();
        }

        // ── Require IMAP password ─────────────────────────────────────────────
        $imapPass = $this->session->imapPassword();
        if ($imapPass === '') {
            $this->buildLoginContext($selfUrl, imapError: false);
            return $this->ok();
        }

        // ── Connect to IMAP ───────────────────────────────────────────────────
        $mailbox  = $this->session->mailbox();
        $connectR = $this->webmail->connect($mailbox, $imapPass);
        if (!$connectR->isOk()) {
            $connectR->drainTo($this->collector);
            $this->session->clearImapPassword();
            $this->buildLoginContext($selfUrl, imapError: true);
            return $this->ok();
        }

        // ── Route to the appropriate view ─────────────────────────────────────
        $folder   = (string)  ($this->request->query()->get('folder')    ?? self::INBOX);
        $uid      = (int)     ($this->request->query()->get('uid')       ?? 0);
        $page     = (int)     ($this->request->query()->get('page')      ?? 1);
        $compose  = ($this->request->query()->get('compose') !== null);
        $replyUid = (int)     ($this->request->query()->get('reply_uid') ?? 0);

        if ($compose || $replyUid > 0) {
            $this->buildComposeContext($selfUrl, $folder, $replyUid);
        } elseif ($uid > 0) {
            $this->buildMessageContext($selfUrl, $folder, $uid, $page);
        } else {
            $this->buildFolderContext($selfUrl, $folder, $page);
        }

        return $this->ok();
    }

    // =========================================================================
    // Form processing
    // =========================================================================

    private function processForm(string $prgToken, string $selfUrl): string
    {
        $posted = $this->prg->pull($prgToken) ?? [];
        $action = (string) ($posted['action'] ?? '');

        // ── IMAP credential submission (no CSRF — user not yet authenticated) ─
        if ($action === 'imap_login') {
            $password = (string) ($posted['imap_password'] ?? '');
            if ($password !== '') {
                $this->session->storeImapPassword($password);
            }
            return $selfUrl;
        }

        // ── All other actions require CSRF ───────────────────────────────────
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return $selfUrl;
        }

        // Establish IMAP connection for all mutation actions
        $imapPass = $this->session->imapPassword();
        if ($imapPass !== '') {
            $this->webmail->connect($this->session->mailbox(), $imapPass)
                ->drainTo($this->collector);
        }

        $folder = (string) ($posted['folder'] ?? self::INBOX);
        $uid    = (int)    ($posted['uid']    ?? 0);

        switch ($action) {
            case 'send':
                return $this->handleSend($posted, $selfUrl, $folder);

            case 'save_draft':
                return $this->handleSaveDraft($posted, $selfUrl, $folder);

            case 'delete':
                if ($uid > 0) {
                    $r = $this->webmail->deleteMessage($folder, $uid);
                    $r->drainTo($this->collector);
                    if ($r->isOk()) {
                        $this->flash->set('success', $this->t->t('webmail.message_deleted'));
                    }
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder]);

            case 'move':
                $target = trim((string) ($posted['target_folder'] ?? ''));
                if ($uid > 0 && $target !== '') {
                    $r = $this->webmail->moveMessage($folder, $uid, $target);
                    $r->drainTo($this->collector);
                    if ($r->isOk()) {
                        $this->flash->set('success', $this->t->t('webmail.message_moved'));
                    }
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder]);

            case 'mark_seen':
                if ($uid > 0) {
                    $this->webmail->setSeenFlag($folder, $uid, true)->drainTo($this->collector);
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder, 'uid' => $uid]);

            case 'mark_unseen':
                if ($uid > 0) {
                    $this->webmail->setSeenFlag($folder, $uid, false)->drainTo($this->collector);
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder, 'uid' => $uid]);

            case 'trust_sender':
                $email = trim((string) ($posted['sender_email'] ?? ''));
                if ($email !== '') {
                    $this->trustedSenders->trust($this->session->userId(), $email)
                        ->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('webmail.sender_trusted'));
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder, 'uid' => $uid]);

            case 'untrust_sender':
                $email = trim((string) ($posted['sender_email'] ?? ''));
                if ($email !== '') {
                    $this->trustedSenders->untrust($this->session->userId(), $email)
                        ->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('webmail.sender_untrusted'));
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder, 'uid' => $uid]);

            case 'disconnect':
                $this->session->clearImapPassword();
                return $selfUrl;
        }

        return $selfUrl;
    }

    // ── Send ──────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $posted */
    private function handleSend(array $posted, string $selfUrl, string $folder): string
    {
        $to        = trim((string) ($posted['to']         ?? ''));
        $cc        = trim((string) ($posted['cc']         ?? ''));
        $bcc       = trim((string) ($posted['bcc']        ?? ''));
        $subject   = trim((string) ($posted['subject']    ?? ''));
        $body      = trim((string) ($posted['body']       ?? ''));
        $inReplyTo = trim((string) ($posted['in_reply_to']?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            $this->flash->set('error', $this->t->t('webmail.compose_fields_required'));
            return $selfUrl . '?compose=1&folder=' . rawurlencode($folder);
        }

        $mailbox  = $this->session->mailbox();
        $username = $this->session->username();

        $r = $this->webmail->sendMessage(
            fromAddress: $mailbox,
            fromName:    $username,
            toAddress:   $to,
            subject:     $subject,
            bodyText:    $body,
            cc:          $cc,
            bcc:         $bcc,
            inReplyTo:   $inReplyTo,
        );
        $r->drainTo($this->collector);

        if ($r->isOk()) {
            $this->flash->set('success', $this->t->t('webmail.message_sent'));
            return $selfUrl . '?' . http_build_query(['folder' => $folder]);
        }

        return $selfUrl . '?compose=1&folder=' . rawurlencode($folder);
    }

    // ── Save draft ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $posted */
    private function handleSaveDraft(array $posted, string $selfUrl, string $folder): string
    {
        $to      = trim((string) ($posted['to']      ?? ''));
        $cc      = trim((string) ($posted['cc']      ?? ''));
        $bcc     = trim((string) ($posted['bcc']     ?? ''));
        $subject = trim((string) ($posted['subject'] ?? ''));
        $body    = trim((string) ($posted['body']    ?? ''));

        $mailbox  = $this->session->mailbox();
        $username = $this->session->username();

        $r = $this->webmail->saveDraft(
            fromAddress: $mailbox,
            fromName:    $username,
            toAddress:   $to,
            subject:     $subject,
            bodyText:    $body,
            cc:          $cc,
            bcc:         $bcc,
        );
        $r->drainTo($this->collector);

        if ($r->isOk()) {
            $this->flash->set('success', $this->t->t('webmail.draft_saved'));
        }

        // Return to compose with form values preserved in query string (no JS needed)
        return $selfUrl . '?' . http_build_query([
                                                     'compose'  => '1',
                                                     'folder'   => $folder,
                                                 ]);
    }

    // ── Attachment download ───────────────────────────────────────────────────

    private function handleAttachmentDownload(string $selfUrl): void
    {
        $imapPass = $this->session->imapPassword();
        if ($imapPass === '') { return; }

        $folder  = (string) ($this->request->query()->get('folder') ?? self::INBOX);
        $uid     = (int)    ($this->request->query()->get('uid')    ?? 0);
        $index   = (int)    ($this->request->query()->get('attachment') ?? 0);

        if ($uid <= 0) { return; }

        $this->webmail->connect($this->session->mailbox(), $imapPass);
        $r = $this->webmail->getAttachment($folder, $uid, $index);
        if (!$r->isOk()) {
            $r->drainTo($this->collector);
            return;
        }

        $att = $r->unwrap();
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $att['name']);

        // Output directly — no template rendering
        if (!headers_sent()) {
            header('Content-Type: ' . ($att['content_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
            header('Content-Length: ' . strlen($att['data']));
            header('Cache-Control: private, no-cache');
            header('X-Content-Type-Options: nosniff');
        }
        echo $att['data'];
        exit;
    }

    // =========================================================================
    // Context builders
    // =========================================================================

    private function buildLoginContext(string $selfUrl, bool $imapError): void
    {
        $prgId = $this->prg->createId($selfUrl);
        $this->ctx->set('webmail_view',       'login');
        $this->ctx->set('webmail_view_login',   true);
        $this->ctx->set('webmail_view_list',    false);
        $this->ctx->set('webmail_view_message', false);
        $this->ctx->set('webmail_view_compose', false);
        $this->ctx->set('webmail_imap_error', $imapError);
        $this->ctx->set('prg_id',             $prgId);
        $this->ctx->set('base_url',           $selfUrl);
        $this->setI18n();
    }

    private function buildFolderContext(string $selfUrl, string $folder, int $page): void
    {
        $folders = $this->getFolders($selfUrl, $folder);
        $listR   = $this->webmail->listMessages($folder, $page);
        $listR->drainTo($this->collector);
        $list = $listR->isOk() ? $listR->unwrap()
            : ['messages' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'per_page' => 25];

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $this->ctx->set('webmail_view',    'list');
        $this->ctx->set('webmail_view_login',   false);
        $this->ctx->set('webmail_view_list',    true);
        $this->ctx->set('webmail_view_message', false);
        $this->ctx->set('webmail_view_compose', false);
        $this->ctx->set('folders',         $folders);
        $this->ctx->set('current_folder',  $folder);
        $this->ctx->set('messages',        $this->decorateMessages($list['messages'], $folder, $selfUrl));
        $this->ctx->set('msg_total',       $list['total']);
        $this->ctx->set('msg_pages',       $list['pages']);
        $this->ctx->set('msg_page',        $list['page']);
        $this->ctx->set('has_prev',        $list['page'] > 1);
        $this->ctx->set('has_next',        $list['page'] < $list['pages']);
        $this->ctx->set('prev_url',        $selfUrl . '?' . http_build_query(['folder' => $folder, 'page' => $list['page'] - 1]));
        $this->ctx->set('next_url',        $selfUrl . '?' . http_build_query(['folder' => $folder, 'page' => $list['page'] + 1]));
        $this->ctx->set('compose_url',     $selfUrl . '?compose=1&folder=' . rawurlencode($folder));
        $this->ctx->set('csrf_token',      $csrfToken);
        $this->ctx->set('prg_id',          $prgId);
        $this->ctx->set('base_url',        $selfUrl);
        $this->setI18n();
    }

    private function buildMessageContext(string $selfUrl, string $folder, int $uid, int $page): void
    {
        $folders = $this->getFolders($selfUrl, $folder);

        $msgR = $this->webmail->getMessage($folder, $uid);
        $msgR->drainTo($this->collector);
        $message = $msgR->isOk() ? $msgR->unwrap() : null;

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        // Trusted sender check
        $senderEmail  = '';
        $senderTrusted = false;
        if ($message !== null) {
            $from        = $message['from'] ?? '';
            $senderEmail = $this->extractEmailAddress($from);
            if ($senderEmail !== '') {
                $trustR = $this->trustedSenders->isTrusted($this->session->userId(), $senderEmail);
                $senderTrusted = $trustR->isOk() && $trustR->unwrap();
            }
        }

        // Sanitise HTML body
        $safeHtml = '';
        $hasHtml  = false;
        if ($message !== null && ($message['body_html'] ?? '') !== '') {
            $hasHtml  = true;
            $safeHtml = $this->sanitizer->sanitise($message['body_html'], $senderTrusted);
        }

        // Decorate attachments for template
        $attachments = [];
        foreach ($message['attachments'] ?? [] as $idx => $att) {
            $attachments[] = [
                'index'        => $idx,
                'name'         => $att['name'],
                'content_type' => $att['content_type'],
                'size_fmt'     => $this->formatBytes($att['size']),
                'download_url' => $selfUrl . '?' . http_build_query([
                                                                        'folder'     => $folder,
                                                                        'uid'        => $uid,
                                                                        'attachment' => $idx,
                                                                    ]),
            ];
        }

        $moveOptions = [];
        foreach ($this->webmail->getFolders()->unwrap() ?? [] as $f) {
            if ($f['name'] !== $folder) {
                $moveOptions[] = ['name' => $f['name'], 'display' => $f['display']];
            }
        }

        $this->ctx->set('webmail_view',      'message');
        $this->ctx->set('webmail_view_login',   false);
        $this->ctx->set('webmail_view_list',    false);
        $this->ctx->set('webmail_view_message', true);
        $this->ctx->set('webmail_view_compose', false);
        $this->ctx->set('folders',           $folders);
        $this->ctx->set('current_folder',    $folder);
        $this->ctx->set('message',           $message);
        $this->ctx->set('message_found',     $message !== null);
        $this->ctx->set('body_html_safe',    $safeHtml);
        $this->ctx->set('has_html_body',     $hasHtml);
        $this->ctx->set('images_blocked',    $hasHtml && !$senderTrusted);
        $this->ctx->set('sender_email',      $senderEmail);
        $this->ctx->set('sender_trusted',    $senderTrusted);
        $this->ctx->set('attachments',       $attachments);
        $this->ctx->set('has_attachments',   $attachments !== []);
        $this->ctx->set('move_options',      $moveOptions);
        $this->ctx->set('msg_uid',           $uid);
        $this->ctx->set('msg_folder',        $folder);
        $this->ctx->set('msg_page',          $page);
        $this->ctx->set('back_url',          $selfUrl . '?' . http_build_query(['folder' => $folder, 'page' => $page]));
        $this->ctx->set('reply_url',         $selfUrl . '?' . http_build_query(['compose' => 1, 'reply_uid' => $uid, 'folder' => $folder]));
        $this->ctx->set('csrf_token',        $csrfToken);
        $this->ctx->set('prg_id',            $prgId);
        $this->ctx->set('base_url',          $selfUrl);
        $this->setI18n();
    }

    private function buildComposeContext(string $selfUrl, string $folder, int $replyUid): void
    {
        $folders   = $this->getFolders($selfUrl, $folder);
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $composeTo      = '';
        $composeSubject = '';
        $composeBody    = '';
        $inReplyTo      = '';

        if ($replyUid > 0) {
            $msgR = $this->webmail->getMessage($folder, $replyUid);
            if ($msgR->isOk()) {
                $msg            = $msgR->unwrap();
                $composeTo      = $msg['from']    ?? '';
                $rawSubject     = $msg['subject'] ?? '';
                $composeSubject = 'Re: ' . preg_replace('/^Re:\s*/i', '', $rawSubject);
                $inReplyTo      = $msg['message_id'] ?? '';
                $origBody       = $msg['body_text'] ?? '';
                $origDate       = $msg['date']      ?? '';
                if ($origBody !== '') {
                    $composeBody = "\n\nOn {$origDate}, {$composeTo} wrote:\n"
                                   . $this->quoteBody($origBody);
                }
            }
        }

        $this->ctx->set('webmail_view',        'compose');
        $this->ctx->set('webmail_view_login',   false);
        $this->ctx->set('webmail_view_list',    false);
        $this->ctx->set('webmail_view_message', false);
        $this->ctx->set('webmail_view_compose', true);
        $this->ctx->set('folders',             $folders);
        $this->ctx->set('current_folder',      $folder);
        $this->ctx->set('compose_to',          $composeTo);
        $this->ctx->set('compose_cc',           '');
        $this->ctx->set('compose_bcc',          '');
        $this->ctx->set('compose_subject',     $composeSubject);
        $this->ctx->set('compose_body',        $composeBody);
        $this->ctx->set('compose_in_reply_to', $inReplyTo);
        $this->ctx->set('user_mailbox',        $this->session->mailbox());
        $this->ctx->set('back_url',            $selfUrl . '?' . http_build_query(['folder' => $folder]));
        $this->ctx->set('csrf_token',          $csrfToken);
        $this->ctx->set('prg_id',              $prgId);
        $this->ctx->set('base_url',            $selfUrl);
        $this->setI18n();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function selfUrl(): string
    {
        $urlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        return $this->urlGen->toPage($urlId);
    }

    /**
     * Retrieve decorated folder list. Failure is non-fatal — returns empty list.
     * @return list<array<string, mixed>>
     */
    private function getFolders(string $selfUrl, string $currentFolder): array
    {
        $r = $this->webmail->getFolders();
        if (!$r->isOk()) { return []; }
        return array_map(function ($f) use ($selfUrl, $currentFolder) {
            $f['url']        = $selfUrl . '?' . http_build_query(['folder' => $f['name']]);
            $f['is_current'] = $f['name'] === $currentFolder;
            $f['has_unseen'] = $f['unseen'] > 0;
            return $f;
        }, $r->unwrap());
    }

    /**
     * @param  list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function decorateMessages(array $messages, string $folder, string $selfUrl): array
    {
        return array_map(function ($m) use ($folder, $selfUrl) {
            $m['view_url'] = $selfUrl . '?' . http_build_query(['folder' => $folder, 'uid' => $m['uid']]);
            $m['date_fmt'] = $m['date_ts'] > 0 ? date('d M Y H:i', $m['date_ts']) : $m['date_str'];
            $m['unread']   = !$m['seen'];
            return $m;
        }, $messages);
    }

    private function quoteBody(string $body): string
    {
        return implode("\n", array_map(
            static fn($line) => '> ' . $line,
            explode("\n", $body)
        ));
    }

    /** Extract bare email address from a display string like "Name <email@host>" */
    private function extractEmailAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return strtolower(trim($m[1]));
        }
        // Plain address with no display name
        $bare = trim($from);
        return str_contains($bare, '@') ? strtolower($bare) : '';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024)          { return $bytes . ' B'; }
        if ($bytes < 1048576)       { return round($bytes / 1024, 1) . ' KB'; }
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function setI18n(): void
    {
        $map = [
            'webmail_heading'                => 'webmail.heading',
            'webmail_login_heading'          => 'webmail.login.heading',
            'webmail_login_body'             => 'webmail.login.body',
            'webmail_login_password'         => 'webmail.login.password',
            'webmail_login_btn'              => 'webmail.login.btn',
            'webmail_login_error'            => 'webmail.login.error',
            'webmail_compose_heading'        => 'webmail.compose.heading',
            'webmail_compose_from'           => 'webmail.compose.from',
            'webmail_compose_to'             => 'webmail.compose.to',
            'webmail_compose_cc'             => 'webmail.compose.cc',
            'webmail_compose_bcc'            => 'webmail.compose.bcc',
            'webmail_compose_subject'        => 'webmail.compose.subject',
            'webmail_compose_body'           => 'webmail.compose.body',
            'webmail_compose_send'           => 'webmail.compose.send',
            'webmail_compose_save_draft'     => 'webmail.compose.save_draft',
            'webmail_compose_cancel'         => 'webmail.compose.cancel',
            'label_folders'                  => 'webmail.folders',
            'label_from'                     => 'webmail.from',
            'label_to'                       => 'webmail.to',
            'label_subject'                  => 'webmail.subject',
            'label_date'                     => 'webmail.date',
            'label_actions'                  => 'webmail.actions',
            'label_no_messages'              => 'webmail.no_messages',
            'label_attachments'              => 'webmail.attachments',
            'label_download'                 => 'webmail.download',
            'btn_compose'                    => 'webmail.btn_compose',
            'btn_reply'                      => 'webmail.btn_reply',
            'btn_delete'                     => 'webmail.btn_delete',
            'btn_move'                       => 'webmail.btn_move',
            'btn_mark_read'                  => 'webmail.btn_mark_read',
            'btn_mark_unread'                => 'webmail.btn_mark_unread',
            'btn_back'                       => 'webmail.btn_back',
            'btn_disconnect'                 => 'webmail.btn_disconnect',
            'label_move_to'                  => 'webmail.move_to',
            'label_prev'                     => 'webmail.prev',
            'label_next'                     => 'webmail.next',
            'label_page'                     => 'webmail.page',
            'label_of'                       => 'webmail.of',
            'label_total'                    => 'webmail.total',
            'label_images_blocked'           => 'webmail.images_blocked',
            'label_trust_sender'             => 'webmail.trust_sender',
            'label_untrust_sender'           => 'webmail.untrust_sender',
            'label_sender_trusted'           => 'webmail.sender_trusted_notice',
        ];
        foreach ($map as $ctxKey => $langKey) {
            $this->ctx->set($ctxKey, $this->t->t($langKey, fallback: $ctxKey));
        }
    }
}