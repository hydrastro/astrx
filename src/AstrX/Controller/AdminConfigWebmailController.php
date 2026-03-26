<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin — Webmail / IMAP configuration editor.
 * Two sections on one page:
 *   imap     — ImapClient settings (host, port, encryption, timeout, socks5)
 *   folders  — WebmailService folder names (trash, sent, drafts) + per-page count
 * Writes Imap.config.php atomically.
 */
final class AdminConfigWebmailController extends AbstractController
{
    private const FORM = 'admin_config_webmail';

    public function __construct(
        DiagnosticsCollector $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request $request,
        private readonly Config $config,
        private readonly ConfigWriter $writer,
        private readonly Gate $gate,
        private readonly CsrfHandler $csrf,
        private readonly PrgHandler $prg,
        private readonly FlashBag $flash,
        private readonly Page $page,
        private readonly UrlGenerator $urlGen,
        private readonly Translator $t,
    ) {
        parent::__construct($collector);
    }

    public function handle()
    : Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_MAIL)) {
            http_response_code(403);

            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n ?
            $this->t->t($this->page->urlId, fallback: $this->page->urlId) :
            $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext($selfUrl);

        return $this->ok();
    }

    // =========================================================================

    private function processForm(string $prgToken)
    : void {
        $posted = $this->prg->pull($prgToken)??[];
        $csrfResult = $this->csrf->verify(
            self::FORM,
            (string)($posted['_csrf']??'')
        );
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);

            return;
        }

        $section = (string)($posted['section']??'');
        $result = match ($section) {
            'imap' => $this->saveImap($posted),
            'folders' => $this->saveFolders($posted),
            default => null,
        };

        if ($result !== null) {
            $result->drainTo($this->collector);
            if ($result->isOk()) {
                $this->flash->set('success', $this->t->t('admin.config.saved'));
            }
        }
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveImap(array $p)
    : Result {
        $full = $this->loadFullImapConfig();
        $full['ImapClient'] = [
            'imap_host' => trim((string)($p['imap_host']??'localhost')),
            'imap_port' => max(1, (int)($p['imap_port']??993)),
            'imap_encryption' => trim((string)($p['imap_encryption']??'ssl')),
            'imap_timeout' => max(5, (int)($p['imap_timeout']??30)),
            'imap_socks5_host' => trim((string)($p['imap_socks5_host']??'')),
            'imap_socks5_port' => max(1, (int)($p['imap_socks5_port']??9050)),
        ];

        return $this->writer->write('Imap', $full);
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveFolders(array $p)
    : Result {
        $full = $this->loadFullImapConfig();
        $full['WebmailService'] = [
            'messages_per_page'           => max(5, min(200, (int)($p['messages_per_page'] ?? 25))),
            'trash_folder'                => trim((string)($p['trash_folder']   ?? 'Trash')),
            'sent_folder'                 => trim((string)($p['sent_folder']    ?? 'Sent')),
            'drafts_folder'               => trim((string)($p['drafts_folder']  ?? 'Drafts')),
            'mail_domain'                 => trim((string)($p['mail_domain']    ?? 'localhost')),
            'imap_login_use_full_address' => isset($p['imap_login_use_full_address']),
            'mailbox_is_username'         => isset($p['mailbox_is_username']),
        ];
        $full['ImapClient']['imap_verify_ssl'] = isset($p['imap_verify_ssl']);

        return $this->writer->write('Imap', $full);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(string $selfUrl)
    : void {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId = $this->prg->createId($selfUrl);

        $encOptions = $this->buildEncryptionOptions(
            (string)$this->config->getConfig(
                'ImapClient',
                'imap_encryption',
                'ssl'
            )
        );

        $this->ctx->set('csrf_token', $csrfToken);
        $this->ctx->set('prg_id', $prgId);

        // ImapClient
        $this->ctx->set(
            'cfg_imap_host',
            (string)$this->config->getConfig(
                'ImapClient',
                'imap_host',
                'dovecot'
            )
        );
        $this->ctx->set(
            'cfg_imap_port',
            (int)$this->config->getConfig(
                'ImapClient',
                'imap_port',
                993
            )
        );
        $this->ctx->set(
            'cfg_imap_encryption',
            (string)$this->config->getConfig(
                'ImapClient',
                'imap_encryption',
                'ssl'
            )
        );
        $this->ctx->set(
            'cfg_imap_timeout',
            (int)$this->config->getConfig(
                'ImapClient',
                'imap_timeout',
                30
            )
        );
        $this->ctx->set(
            'cfg_imap_socks5_host',
            (string)$this->config->getConfig(
                'ImapClient',
                'imap_socks5_host',
                ''
            )
        );
        $this->ctx->set(
            'cfg_imap_socks5_port',
            (int)$this->config->getConfig(
                'ImapClient',
                'imap_socks5_port',
                9050
            )
        );
        $this->ctx->set('encryption_options', $encOptions);

        // WebmailService
        $this->ctx->set(
            'cfg_messages_per_page',
            (int)$this->config->getConfig(
                'WebmailService',
                'messages_per_page',
                25
            )
        );
        $this->ctx->set(
            'cfg_trash_folder',
            (string)$this->config->getConfig(
                'WebmailService',
                'trash_folder',
                'Trash'
            )
        );
        $this->ctx->set(
            'cfg_sent_folder',
            (string)$this->config->getConfig(
                'WebmailService',
                'sent_folder',
                'Sent'
            )
        );
        $this->ctx->set('cfg_drafts_folder',
                        (string) $this->config->getConfig('WebmailService', 'drafts_folder', 'Drafts'));
        $this->ctx->set('cfg_mail_domain',
                        (string) $this->config->getConfig('WebmailService', 'mail_domain', 'localhost'));
        $this->ctx->set('cfg_imap_login_use_full_address',
                        (bool) $this->config->getConfig('WebmailService', 'imap_login_use_full_address', true));
        $this->ctx->set('cfg_imap_verify_ssl',
                        (bool) $this->config->getConfig('ImapClient', 'imap_verify_ssl', true));
        $this->ctx->set('cfg_mailbox_is_username',
                        (bool) $this->config->getConfig('WebmailService', 'mailbox_is_username', false));

        $this->setI18n();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function loadFullImapConfig()
    : array
    {
        $path = (defined('CONFIG_DIR') ? CONFIG_DIR : '') . 'Imap.config.php';
        if (!is_file($path)) {
            return [];
        }
        $loaded = @include $path;

        return is_array($loaded) ? $loaded : [];
    }

    /** @return list<array{value:string,label:string,selected:bool}> */
    private function buildEncryptionOptions(string $current)
    : array {
        $options = [
            ['value' => 'ssl', 'label' => 'SSL/TLS (IMAPS, port 993)'],
            ['value' => 'tls', 'label' => 'STARTTLS (port 143)'],
            ['value' => '', 'label' => 'None (plain, port 143)'],
        ];

        return array_map(
            fn($o) => array_merge($o, ['selected' => $o['value'] === $current]),
            $options
        );
    }

    private function setI18n()
    : void
    {
        $this->ctx->set('heading', $this->t->t('admin.config.webmail.heading'));
        $this->ctx->set(
            'section_imap',
            $this->t->t('admin.config.webmail.imap')
        );
        $this->ctx->set(
            'section_folders',
            $this->t->t('admin.config.webmail.folders')
        );
        $this->ctx->set(
            'label_imap_host',
            $this->t->t('admin.config.field.imap_host')
        );
        $this->ctx->set(
            'label_imap_port',
            $this->t->t('admin.config.field.imap_port')
        );
        $this->ctx->set(
            'label_imap_encryption',
            $this->t->t('admin.config.field.imap_encryption')
        );
        $this->ctx->set(
            'label_imap_timeout',
            $this->t->t('admin.config.field.imap_timeout')
        );
        $this->ctx->set(
            'label_imap_socks5_host',
            $this->t->t('admin.config.field.imap_socks5_host')
        );
        $this->ctx->set(
            'label_imap_socks5_port',
            $this->t->t('admin.config.field.imap_socks5_port')
        );
        $this->ctx->set(
            'label_messages_per_page',
            $this->t->t('admin.config.field.messages_per_page')
        );
        $this->ctx->set(
            'label_trash_folder',
            $this->t->t('admin.config.field.trash_folder')
        );
        $this->ctx->set(
            'label_sent_folder',
            $this->t->t('admin.config.field.sent_folder')
        );
        $this->ctx->set('label_drafts_folder',
                        $this->t->t('admin.config.field.drafts_folder'));
        $this->ctx->set('label_mail_domain',
                        $this->t->t('admin.config.field.mail_domain'));
        $this->ctx->set('label_imap_login_use_full_address',
                        $this->t->t('admin.config.field.imap_login_use_full_address'));
        $this->ctx->set('label_imap_verify_ssl',
                        $this->t->t('admin.config.field.imap_verify_ssl'));
        $this->ctx->set('label_mailbox_is_username',
                        $this->t->t('admin.config.field.mailbox_is_username'));
        $this->ctx->set('btn_save', $this->t->t('admin.btn.save'));
    }
}
