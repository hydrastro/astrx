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
 * Webmail — read, compose, send, manage emails.
 *
 * URL convention — all sub-params use query-string keys that do NOT conflict
 * with the routing 'page' key. Specifically 'pn' (page number) mirrors what
 * the news system uses.  The folder name is passed as 'folder'.
 *
 * GET parameters:
 *   folder=<name>      select IMAP folder (default: INBOX)
 *   pn=<n>             message list page number (default: 1)
 *   show=<n>           messages per page override (default: config value)
 *   sort=newest|oldest sort order (default: newest)
 *   uid=<n>            view a single message
 *   headers=1          view raw headers for ?uid=N
 *   compose=1          show compose form
 *   reply_uid=<n>      pre-fill compose as reply to message N
 *   attachment=<n>     download attachment index N from ?uid=X&folder=Y
 *
 * POST actions (via PRG, all require CSRF except 'imap_login'):
 *   imap_login     store IMAP password in encrypted session
 *   send           send message (To/CC/BCC/Subject/Body)
 *   save_draft     save draft to Drafts folder
 *   delete         delete single message (uid + folder)
 *   delete_bulk    delete selected messages (uid[] + folder)
 *   move           move single message (uid + folder + target_folder)
 *   move_bulk      move selected messages (uid[] + folder + target_folder)
 *   mark_seen      mark single message read (uid + folder)
 *   mark_unseen    mark single message unread (uid + folder)
 *   mark_seen_bulk  mark selected messages read (uid[] + folder)
 *   mark_unseen_bulk mark selected messages unread (uid[] + folder)
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

        // ── Attachment download (GET, bypasses template entirely) ─────────────
        if ($this->request->query()->get('attachment') !== null) {
            $this->handleAttachmentDownload($selfUrl);
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
        $username = $this->session->username();
        $connectR = $this->webmail->connect($mailbox, $imapPass, $username);
        if (!$connectR->isOk()) {
            $connectR->drainTo($this->collector);
            $this->session->clearImapPassword();
            $this->buildLoginContext($selfUrl, imapError: true);
            return $this->ok();
        }

        // ── Read GET params ───────────────────────────────────────────────────
        $folder   = (string)  ($this->request->query()->get('folder')    ?? self::INBOX);
        $uid      = (int)     ($this->request->query()->get('uid')       ?? 0);
        $pn       = (int)     ($this->request->query()->get('pn')        ?? 1);  // 'pn' not 'page'
        $show     = (int)     ($this->request->query()->get('show')      ?? 0);  // 0 = use config
        $sort     = (string)  ($this->request->query()->get('sort')      ?? 'newest');
        $compose  = ($this->request->query()->get('compose') !== null);
        $replyUid = (int)     ($this->request->query()->get('reply_uid') ?? 0);
        $viewHdrs = ($this->request->query()->get('headers') !== null);

        // ── Route to the appropriate view ─────────────────────────────────────
        if ($compose || $replyUid > 0) {
            $this->buildComposeContext($selfUrl, $folder, $replyUid);
        } elseif ($uid > 0 && $viewHdrs) {
            $this->buildRawHeadersContext($selfUrl, $folder, $uid, $pn);
        } elseif ($uid > 0) {
            $this->buildMessageContext($selfUrl, $folder, $uid, $pn);
        } else {
            $this->buildFolderContext($selfUrl, $folder, $pn, $show, $sort);
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

        // IMAP login — no CSRF, not yet authenticated
        if ($action === 'imap_login') {
            $password = (string) ($posted['imap_password'] ?? '');
            if ($password !== '') {
                $this->session->storeImapPassword($password);
            }
            return $selfUrl;
        }

        // All other actions require CSRF
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return $selfUrl;
        }

        // Establish IMAP for mutation actions
        $imapPass = $this->session->imapPassword();
        if ($imapPass !== '') {
            $this->webmail->connect($this->session->mailbox(), $imapPass)
                ->drainTo($this->collector);
        }

        $folder = (string) ($posted['folder'] ?? self::INBOX);
        $uid    = (int)    ($posted['uid']    ?? 0);
        // Bulk: uid[] array of selected message UIDs
        $uids   = array_map('intval', (array) ($posted['uid'] ?? []));
        $uids   = array_filter($uids, fn($u) => $u > 0);

        switch ($action) {
            case 'send':
                return $this->handleSend($posted, $selfUrl, $folder);

            case 'save_draft':
                return $this->handleSaveDraft($posted, $selfUrl, $folder);

            case 'delete':
                if ($uid > 0) {
                    $this->webmail->deleteMessage($folder, $uid)->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('webmail.message_deleted'));
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder]);

            case 'delete_bulk':
                foreach ($uids as $u) {
                    $this->webmail->deleteMessage($folder, $u)->drainTo($this->collector);
                }
                if ($uids !== []) {
                    $this->flash->set('success', $this->t->t('webmail.messages_deleted'));
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder]);

            case 'move':
                $target = trim((string) ($posted['target_folder'] ?? ''));
                if ($uid > 0 && $target !== '') {
                    $this->webmail->moveMessage($folder, $uid, $target)->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('webmail.message_moved'));
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder]);

            case 'move_bulk':
                $target = trim((string) ($posted['target_folder'] ?? ''));
                if ($uids !== [] && $target !== '') {
                    foreach ($uids as $u) {
                        $this->webmail->moveMessage($folder, $u, $target)->drainTo($this->collector);
                    }
                    $this->flash->set('success', $this->t->t('webmail.messages_moved'));
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

            case 'mark_seen_bulk':
                foreach ($uids as $u) {
                    $this->webmail->setSeenFlag($folder, $u, true)->drainTo($this->collector);
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder]);

            case 'mark_unseen_bulk':
                foreach ($uids as $u) {
                    $this->webmail->setSeenFlag($folder, $u, false)->drainTo($this->collector);
                }
                return $selfUrl . '?' . http_build_query(['folder' => $folder]);

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
        $to        = trim((string) ($posted['to']          ?? ''));
        $cc        = trim((string) ($posted['cc']          ?? ''));
        $bcc       = trim((string) ($posted['bcc']         ?? ''));
        $subject   = trim((string) ($posted['subject']     ?? ''));
        $body      = trim((string) ($posted['body']        ?? ''));
        $inReplyTo = trim((string) ($posted['in_reply_to'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            $this->flash->set('error', $this->t->t('webmail.compose_fields_required'));
            return $selfUrl . '?compose=1&folder=' . rawurlencode($folder);
        }

        $fromAddress = $this->webmail->resolveLocalPart(
            $this->session->mailbox(), $this->session->username()
        );
        $r = $this->webmail->sendMessage(
            fromAddress: $fromAddress,
            fromName:    $this->session->username(),
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

    /** @param array<string, mixed> $posted */
    private function handleSaveDraft(array $posted, string $selfUrl, string $folder): string
    {
        $fromAddress = $this->webmail->resolveLocalPart(
            $this->session->mailbox(), $this->session->username()
        );
        $r = $this->webmail->saveDraft(
            fromAddress: $fromAddress,
            fromName:    $this->session->username(),
            toAddress:   trim((string) ($posted['to']      ?? '')),
            subject:     trim((string) ($posted['subject'] ?? '')),
            bodyText:    trim((string) ($posted['body']    ?? '')),
            cc:          trim((string) ($posted['cc']      ?? '')),
            bcc:         trim((string) ($posted['bcc']     ?? '')),
        );
        $r->drainTo($this->collector);
        if ($r->isOk()) {
            $this->flash->set('success', $this->t->t('webmail.draft_saved'));
        }
        return $selfUrl . '?compose=1&folder=' . rawurlencode($folder);
    }

    // ── Attachment download ───────────────────────────────────────────────────

    private function handleAttachmentDownload(string $selfUrl): void
    {
        $imapPass = $this->session->imapPassword();
        if ($imapPass === '') { return; }

        $folder = (string) ($this->request->query()->get('folder') ?? self::INBOX);
        $uid    = (int)    ($this->request->query()->get('uid')    ?? 0);
        $index  = (int)    ($this->request->query()->get('attachment') ?? 0);
        if ($uid <= 0) { return; }

        $this->webmail->connect($this->session->mailbox(), $imapPass);
        $r = $this->webmail->getAttachment($folder, $uid, $index);
        if (!$r->isOk()) { $r->drainTo($this->collector); return; }

        $att      = $r->unwrap();
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $att['name']);

        if (!headers_sent()) {
            header('Content-Type: '        . ($att['content_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
            header('Content-Length: '      . strlen($att['data']));
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
        $this->ctx->set('webmail_view',         'login');
        $this->ctx->set('webmail_view_login',    true);
        $this->ctx->set('webmail_view_list',     false);
        $this->ctx->set('webmail_view_message',  false);
        $this->ctx->set('webmail_view_compose',  false);
        $this->ctx->set('webmail_view_headers',  false);
        $this->ctx->set('webmail_imap_error',    $imapError);
        $this->ctx->set('prg_id',                $this->prg->createId($selfUrl));
        $this->ctx->set('base_url',              $selfUrl);
        $this->setI18n();
    }

    private function buildFolderContext(
        string $selfUrl,
        string $folder,
        int    $pn,
        int    $show,
        string $sort,
    ): void {
        $folders = $this->getFolders($selfUrl, $folder);
        $listR   = $this->webmail->listMessages($folder, $pn, $show);
        $listR->drainTo($this->collector);
        $list = $listR->isOk() ? $listR->unwrap()
            : ['messages' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'per_page' => 25];

        if ($sort === 'oldest') {
            $list['messages'] = array_reverse($list['messages']);
        }

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $effectivePerPage = $show > 0 ? $show : $list['per_page'];
        $msgFrom = $list['total'] > 0 ? ($list['page'] - 1) * $effectivePerPage + 1 : 0;
        $msgTo   = min($list['page'] * $effectivePerPage, $list['total']);

        // Build pagination URLs preserving all sub-params
        // Use 'pn' not 'page' to avoid conflicting with the routing 'page' key
        $baseParams = array_filter(['folder' => $folder,
                                    'show' => $show ?: null,
                                    'sort' => $sort !== 'newest' ? $sort : null]);
        $prevUrl = $selfUrl . '?' . http_build_query(array_merge($baseParams, ['pn' => $list['page'] - 1]));
        $nextUrl = $selfUrl . '?' . http_build_query(array_merge($baseParams, ['pn' => $list['page'] + 1]));

        // Move-to options for bulk bar
        $moveOptions = [];
        $foldersR    = $this->webmail->getFolders();
        if ($foldersR->isOk()) {
            foreach ($foldersR->unwrap() as $f) {
                if ($f['name'] !== $folder) {
                    $moveOptions[] = ['name' => $f['name'], 'display' => $f['display']];
                }
            }
        }

        $this->ctx->set('webmail_view',          'list');
        $this->ctx->set('webmail_view_login',     false);
        $this->ctx->set('webmail_view_list',      true);
        $this->ctx->set('webmail_view_message',   false);
        $this->ctx->set('webmail_view_compose',   false);
        $this->ctx->set('webmail_view_headers',   false);
        $this->ctx->set('folders',                $folders);
        $this->ctx->set('current_folder',         $folder);
        $this->ctx->set('messages',               $this->decorateMessages($list['messages'], $folder, $selfUrl));
        $this->ctx->set('has_messages',           $list['messages'] !== []);
        $this->ctx->set('msg_total',              $list['total']);
        $this->ctx->set('msg_pages',              $list['pages']);
        $this->ctx->set('msg_page',               $list['page']);
        $this->ctx->set('msg_from',               $msgFrom);
        $this->ctx->set('msg_to',                 $msgTo);
        $this->ctx->set('has_prev',               $list['page'] > 1);
        $this->ctx->set('has_next',               $list['page'] < $list['pages']);
        $this->ctx->set('prev_url',               $prevUrl);
        $this->ctx->set('next_url',               $nextUrl);
        $this->ctx->set('compose_url',            $selfUrl . '?compose=1&folder=' . rawurlencode($folder));
        $this->ctx->set('move_options',           $moveOptions);
        $this->ctx->set('sort_newest',            $sort !== 'oldest');
        $this->ctx->set('sort_oldest',            $sort === 'oldest');
        $this->ctx->set('current_per_page',       $effectivePerPage);
        $this->ctx->set('current_sort',           $sort);
        $this->ctx->set('filter_action',          $selfUrl);
        $this->ctx->set('csrf_token',             $csrfToken);
        $this->ctx->set('prg_id',                 $prgId);
        $this->ctx->set('base_url',               $selfUrl);
        $this->setI18n();
    }

    private function buildMessageContext(
        string $selfUrl,
        string $folder,
        int    $uid,
        int    $pn,
    ): void {
        $folders   = $this->getFolders($selfUrl, $folder);
        $msgR      = $this->webmail->getMessage($folder, $uid);
        $msgR->drainTo($this->collector);
        $message   = $msgR->isOk() ? $msgR->unwrap() : null;
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $senderEmail   = '';
        $senderTrusted = false;
        if ($message !== null) {
            $senderEmail = $this->extractEmailAddress($message['from'] ?? '');
            if ($senderEmail !== '') {
                $trustR = $this->trustedSenders->isTrusted($this->session->userId(), $senderEmail);
                $senderTrusted = $trustR->isOk() && $trustR->unwrap();
            }
        }

        $safeHtml = '';
        $hasHtml  = false;
        if ($message !== null && ($message['body_html'] ?? '') !== '') {
            $hasHtml  = true;
            $safeHtml = $this->sanitizer->sanitise($message['body_html'], $senderTrusted);
        }

        $attachments = [];
        foreach ($message['attachments'] ?? [] as $idx => $att) {
            $attachments[] = [
                'index'        => $idx,
                'name'         => $att['name'],
                'content_type' => $att['content_type'],
                'size_fmt'     => $this->formatBytes($att['size']),
                'download_url' => $selfUrl . '?' . http_build_query([
                                                                        'folder' => $folder, 'uid' => $uid, 'attachment' => $idx,
                                                                    ]),
            ];
        }

        $moveOptions = [];
        foreach ($this->getFolderList() as $f) {
            if ($f['name'] !== $folder) {
                $moveOptions[] = ['name' => $f['name'], 'display' => $f['display']];
            }
        }

        $backUrl     = $selfUrl . '?' . http_build_query(['folder' => $folder, 'pn' => $pn]);
        $headersUrl  = $selfUrl . '?' . http_build_query(['folder' => $folder, 'uid' => $uid, 'headers' => 1, 'pn' => $pn]);
        $replyUrl    = $selfUrl . '?' . http_build_query(['compose' => 1, 'reply_uid' => $uid, 'folder' => $folder]);

        $this->ctx->set('webmail_view',          'message');
        $this->ctx->set('webmail_view_login',     false);
        $this->ctx->set('webmail_view_list',      false);
        $this->ctx->set('webmail_view_message',   true);
        $this->ctx->set('webmail_view_compose',   false);
        $this->ctx->set('webmail_view_headers',   false);
        $this->ctx->set('folders',                $folders);
        $this->ctx->set('current_folder',         $folder);
        $this->ctx->set('message',                $message);
        $this->ctx->set('message_found',          $message !== null);
        $this->ctx->set('body_html_safe',         $safeHtml);
        $this->ctx->set('has_html_body',          $hasHtml);
        $this->ctx->set('images_blocked',         $hasHtml && !$senderTrusted);
        $this->ctx->set('sender_email',           $senderEmail);
        $this->ctx->set('sender_trusted',         $senderTrusted);
        $this->ctx->set('attachments',            $attachments);
        $this->ctx->set('has_attachments',        $attachments !== []);
        $this->ctx->set('move_options',           $moveOptions);
        $this->ctx->set('msg_uid',                $uid);
        $this->ctx->set('msg_folder',             $folder);
        $this->ctx->set('msg_pn',                 $pn);
        $this->ctx->set('back_url',               $backUrl);
        $this->ctx->set('headers_url',            $headersUrl);
        $this->ctx->set('reply_url',              $replyUrl);
        $this->ctx->set('csrf_token',             $csrfToken);
        $this->ctx->set('prg_id',                 $prgId);
        $this->ctx->set('base_url',               $selfUrl);
        $this->setI18n();
    }

    private function buildRawHeadersContext(
        string $selfUrl,
        string $folder,
        int    $uid,
        int    $pn,
    ): void {
        $folders   = $this->getFolders($selfUrl, $folder);
        $rawR      = $this->webmail->fetchRawHeaders($folder, $uid);
        $rawR->drainTo($this->collector);
        $rawHeaders = $rawR->isOk() ? $rawR->unwrap() : '(could not fetch headers)';
        $csrfToken  = $this->csrf->generate(self::FORM);
        $prgId      = $this->prg->createId($selfUrl);

        $backUrl = $selfUrl . '?' . http_build_query(['folder' => $folder, 'uid' => $uid, 'pn' => $pn]);

        $this->ctx->set('webmail_view',          'headers');
        $this->ctx->set('webmail_view_login',     false);
        $this->ctx->set('webmail_view_list',      false);
        $this->ctx->set('webmail_view_message',   false);
        $this->ctx->set('webmail_view_compose',   false);
        $this->ctx->set('webmail_view_headers',   true);
        $this->ctx->set('folders',                $folders);
        $this->ctx->set('current_folder',         $folder);
        $this->ctx->set('raw_headers',            $rawHeaders);
        $this->ctx->set('msg_uid',                $uid);
        $this->ctx->set('msg_folder',             $folder);
        $this->ctx->set('back_url',               $backUrl);
        $this->ctx->set('csrf_token',             $csrfToken);
        $this->ctx->set('prg_id',                 $prgId);
        $this->ctx->set('base_url',               $selfUrl);
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
                $composeSubject = 'Re: ' . preg_replace('/^Re:\s*/i', '', $msg['subject'] ?? '');
                $inReplyTo      = $msg['message_id'] ?? '';
                $origBody       = $msg['body_text'] ?? '';
                $origDate       = $msg['date']      ?? '';
                if ($origBody !== '') {
                    $composeBody = "\n\nOn {$origDate}, {$composeTo} wrote:\n"
                                   . implode("\n", array_map(fn($l) => '> ' . $l, explode("\n", $origBody)));
                }
            }
        }

        $this->ctx->set('webmail_view',          'compose');
        $this->ctx->set('webmail_view_login',     false);
        $this->ctx->set('webmail_view_list',      false);
        $this->ctx->set('webmail_view_message',   false);
        $this->ctx->set('webmail_view_compose',   true);
        $this->ctx->set('webmail_view_headers',   false);
        $this->ctx->set('folders',                $folders);
        $this->ctx->set('current_folder',         $folder);
        $this->ctx->set('compose_to',             $composeTo);
        $this->ctx->set('compose_cc',             '');
        $this->ctx->set('compose_bcc',            '');
        $this->ctx->set('compose_subject',        $composeSubject);
        $this->ctx->set('compose_body',           $composeBody);
        $this->ctx->set('compose_in_reply_to',    $inReplyTo);
        $this->ctx->set('user_mailbox',           $this->session->mailbox());
        $this->ctx->set('back_url',               $selfUrl . '?' . http_build_query(['folder' => $folder]));
        $this->ctx->set('csrf_token',             $csrfToken);
        $this->ctx->set('prg_id',                 $prgId);
        $this->ctx->set('base_url',               $selfUrl);
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

    /** @return list<array<string, mixed>> */
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

    /** @return list<array{name:string,display:string}> */
    private function getFolderList(): array
    {
        $r = $this->webmail->getFolders();
        return $r->isOk()
            ? array_map(fn($f) => ['name' => $f['name'], 'display' => $f['display']], $r->unwrap())
            : [];
    }

    /** @param list<array<string, mixed>> $messages */
    private function decorateMessages(array $messages, string $folder, string $selfUrl): array
    {
        $even = false;
        return array_map(function ($m) use ($folder, $selfUrl, &$even) {
            $m['view_url']  = $selfUrl . '?' . http_build_query(['folder' => $folder, 'uid' => $m['uid']]);
            $m['date_fmt']  = $m['date_ts'] > 0 ? date('d M Y H:i', $m['date_ts']) : $m['date_str'];
            $m['unread']    = !$m['seen'];
            $m['row_even']  = $even;
            $even           = !$even;
            return $m;
        }, $messages);
    }

    private function extractEmailAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) { return strtolower(trim($m[1])); }
        $bare = trim($from);
        return str_contains($bare, '@') ? strtolower($bare) : '';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024)    { return $bytes . ' B'; }
        if ($bytes < 1048576) { return round($bytes / 1024, 1) . ' KB'; }
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
            'label_no_messages'              => 'webmail.no_messages',
            'label_attachments'              => 'webmail.attachments',
            'label_download'                 => 'webmail.download',
            'label_total'                    => 'webmail.total',
            'label_viewing'                  => 'webmail.viewing',
            'label_transform'                => 'webmail.transform',
            'label_move_selected'            => 'webmail.move_selected',
            'label_sort_newest'              => 'webmail.sort_newest',
            'label_sort_oldest'              => 'webmail.sort_oldest',
            'label_sort'                     => 'webmail.sort',
            'label_show'                     => 'webmail.show',
            'label_per_page'                 => 'webmail.per_page',
            'label_view_headers'             => 'webmail.view_headers',
            'label_raw_headers_heading'      => 'webmail.raw_headers_heading',
            'btn_mark_read_bulk'             => 'webmail.btn_mark_read_bulk',
            'btn_mark_unread_bulk'           => 'webmail.btn_mark_unread_bulk',
            'btn_delete_bulk'                => 'webmail.btn_delete_bulk',
            'btn_move_bulk'                  => 'webmail.btn_move_bulk',
            'btn_forward'                    => 'webmail.btn_forward',
            'label_priority'                 => 'webmail.compose.priority',
            'label_priority_high'            => 'webmail.compose.priority_high',
            'label_priority_normal'          => 'webmail.compose.priority_normal',
            'label_priority_low'             => 'webmail.compose.priority_low',
            'label_read_receipt'             => 'webmail.compose.read_receipt',
            'label_reply_to_field'           => 'webmail.compose.reply_to',
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
            'label_images_blocked'           => 'webmail.images_blocked',
            'label_trust_sender'             => 'webmail.trust_sender',
            'label_untrust_sender'           => 'webmail.untrust_sender',
            'label_sender_trusted'           => 'webmail.sender_trusted_notice',
            'label_select_all'               => 'webmail.select_all',
            'label_go'                       => 'webmail.go',
        ];
        foreach ($map as $ctxKey => $langKey) {
            $this->ctx->set($ctxKey, $this->t->t($langKey, fallback: $ctxKey));
        }
    }
}