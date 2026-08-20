<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Config\ConfigDomain;
use AstrX\Config\ConfigDomainResolver;
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
 *
 * Both sections live in Mail.config.php — that is what `AstrX\Mail\ImapClient`
 * and `AstrX\Mail\WebmailService` are configured from (ModuleLoader resolves the
 * section from the class short name, and reaches the file through the
 * parent-namespace fallback). This editor used to write `Imap.config.php`
 * instead: a file no code path loads. Saving reported success and wrote an audit
 * row while ImapClient kept its previous settings — so an operator who set
 * imap_socks5_host/imap_socks5_port to point IMAP at Tor got a "saved" flash and
 * a client that still connected to the IMAP server directly, off-Tor.
 *
 * The #[ConfigDomain] attributes below are the single declaration of that
 * section→file pairing: this controller derives its write target from them
 * ({@see domainFile()}), ConfigWriter independently routes each section to the
 * file that declares it, and tools/check_config.php fails CI if the declaration
 * and the on-disk layout ever diverge.
 */
#[ConfigDomain('ImapClient', file: 'Mail')]
#[ConfigDomain('WebmailService', file: 'Mail')]
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
        private readonly AuditLogger $audit,
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
            self::mStr($posted, '_csrf', '')
        );
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);

            return;
        }

        $section = self::mStr($posted, 'section', '');
        $result = match ($section) {
            'imap' => $this->saveImap($posted),
            'folders' => $this->saveFolders($posted),
            default => null,
        };

        if ($result !== null) {
            $result->drainTo($this->collector);
            if ($result->isOk()) {
                $this->flash->set('success', $this->t->t('admin.config.saved'));
                $this->audit->log('config.save', $this->domainFile() . '.config.php')
                    ->drainTo($this->collector);
            }
        }
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveImap(array $p)
    : Result {
        // Only the keys this form owns. ConfigWriter merges them over the file's
        // current contents, so imap_verify_ssl / imap_allow_preauth (owned by the
        // other form and by the env defaults) survive a save here.
        return $this->writer->write($this->domainFile(), [
            'ImapClient' => [
                'imap_host' => trim(self::mStr($p, 'imap_host', 'localhost')),
                'imap_port' => max(1, self::mInt($p, 'imap_port', 993)),
                'imap_encryption' => trim(self::mStr($p, 'imap_encryption', 'ssl')),
                'imap_timeout' => max(5, self::mInt($p, 'imap_timeout', 30)),
                'imap_socks5_host' => trim(self::mStr($p, 'imap_socks5_host', '')),
                'imap_socks5_port' => max(1, self::mInt($p, 'imap_socks5_port', 9050)),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveFolders(array $p)
    : Result {
        return $this->writer->write($this->domainFile(), [
            'WebmailService' => [
                'messages_per_page'           => max(5, min(200, self::mInt($p, 'messages_per_page', 25))),
                'trash_folder'                => trim(self::mStr($p, 'trash_folder', 'Trash')),
                'sent_folder'                 => trim(self::mStr($p, 'sent_folder', 'Sent')),
                'drafts_folder'               => trim(self::mStr($p, 'drafts_folder', 'Drafts')),
                'mail_domain'                 => trim(self::mStr($p, 'mail_domain', 'localhost')),
                'imap_login_use_full_address' => isset($p['imap_login_use_full_address']),
                'mailbox_is_username'         => isset($p['mailbox_is_username']),
            ],
            'ImapClient' => [
                'imap_verify_ssl' => isset($p['imap_verify_ssl']),
            ],
        ]);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(string $selfUrl)
    : void {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId = $this->prg->createId($selfUrl);

        $encOptions = $this->buildEncryptionOptions(
            $this->config->getConfigString(
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
            $this->config->getConfigString(
                'ImapClient',
                'imap_host',
                'dovecot'
            )
        );
        $this->ctx->set(
            'cfg_imap_port',
            $this->config->getConfigInt(
                'ImapClient',
                'imap_port',
                993
            )
        );
        $this->ctx->set(
            'cfg_imap_encryption',
            $this->config->getConfigString(
                'ImapClient',
                'imap_encryption',
                'ssl'
            )
        );
        $this->ctx->set(
            'cfg_imap_timeout',
            $this->config->getConfigInt(
                'ImapClient',
                'imap_timeout',
                30
            )
        );
        $this->ctx->set(
            'cfg_imap_socks5_host',
            $this->config->getConfigString(
                'ImapClient',
                'imap_socks5_host',
                ''
            )
        );
        $this->ctx->set(
            'cfg_imap_socks5_port',
            $this->config->getConfigInt(
                'ImapClient',
                'imap_socks5_port',
                9050
            )
        );
        $this->ctx->set('encryption_options', $encOptions);

        // WebmailService
        $this->ctx->set(
            'cfg_messages_per_page',
            $this->config->getConfigInt(
                'WebmailService',
                'messages_per_page',
                25
            )
        );
        $this->ctx->set(
            'cfg_trash_folder',
            $this->config->getConfigString(
                'WebmailService',
                'trash_folder',
                'Trash'
            )
        );
        $this->ctx->set(
            'cfg_sent_folder',
            $this->config->getConfigString(
                'WebmailService',
                'sent_folder',
                'Sent'
            )
        );
        $this->ctx->set('cfg_drafts_folder',
                        $this->config->getConfigString('WebmailService', 'drafts_folder', 'Drafts'));
        $this->ctx->set('cfg_mail_domain',
                        $this->config->getConfigString('WebmailService', 'mail_domain', 'localhost'));
        $this->ctx->set('cfg_imap_login_use_full_address',
                        $this->config->getConfigBool('WebmailService', 'imap_login_use_full_address', true));
        $this->ctx->set('cfg_imap_verify_ssl',
                        $this->config->getConfigBool('ImapClient', 'imap_verify_ssl', true));
        $this->ctx->set('cfg_mailbox_is_username',
                        $this->config->getConfigBool('WebmailService', 'mailbox_is_username', false));

        $this->setI18n();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The config file base name this editor persists to, taken from the
     * #[ConfigDomain] declarations on this class so the read side and the write
     * side quote one source. Falls back to the section name if the attribute is
     * ever removed — that is exactly the drift tools/check_config.php fails on.
     */
    private function domainFile()
    : string
    {
        foreach (ConfigDomainResolver::declaredOn(self::class) as $domain) {
            return $domain->fileBaseName();
        }

        return 'ImapClient';
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
