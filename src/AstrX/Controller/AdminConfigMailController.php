<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use function AstrX\Support\configDir;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Mail\Mailer;
use AstrX\Mail\MailboxManager;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin — Mail configuration editor.
 * Two sections:
 *   1. Mailer         — SMTP host/port/auth/encryption/socks5
 *   2. MailboxManager — mailbox_domain, mailapi_url, mailapi_secret
 * A "send test" action sends a test message to the configured test_recipient
 * (stored in Mail.config.php) using the CURRENT saved SMTP settings.
 * It does NOT save any changes — it just fires and reports the result.
 * Writes Mail.config.php atomically.
 */
final class AdminConfigMailController extends AbstractController
{
    private const FORM = 'admin_config_mail';

    public function __construct(
        DiagnosticsCollector $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request $request,
        private readonly Config $config,
        private readonly ConfigWriter $writer,
        private readonly Mailer $mailer,
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
            self::mStr($posted, '_csrf', '')
        );
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);

            return;
        }

        $section = self::mStr($posted, 'section', '');

        switch ($section) {
            case 'mailer':
                $result = $this->saveMailer($posted);
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    $this->flash->set(
                        'success',
                        $this->t->t('admin.config.saved')
                    );
                }
                break;

            case 'mailbox':
                $result = $this->saveMailbox($posted);
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    $this->flash->set(
                        'success',
                        $this->t->t('admin.config.saved')
                    );
                }
                break;

            case 'test':
                $recipient = trim(self::mStr($posted, 'test_recipient', ''));
                if ($recipient === '') {
                    break;
                }
                $result = $this->mailer->send(
                    toAddress: $recipient,
                    toName:    $recipient,
                    subject:   $this->t->t('admin.config.mail.test_subject'),
                    bodyText:  $this->t->t('admin.config.mail.test_body'),
                );
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    $this->flash->set(
                        'success',
                        $this->t->t('admin.config.mail.test_sent')
                    );
                } else {
                    $this->flash->set(
                        'error',
                        $this->t->t(
                            'admin.config.mail.test_failed'
                        )
                    );
                }
                break;

            case 'test_recipient':
                // Save just the test_recipient address — stored under a
                // dedicated key in Mailer section so it travels with the file.
                $full = $this->loadFullMailConfig();
                $full['Mailer']['test_recipient'] = trim(
                    self::mStr($posted, 'test_recipient', '')
                );
                $this->writer->write('Mail', $full)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.config.saved'));
                break;
        }
    }

    // ── Savers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveMailer(array $p)
    : Result {
        $full = $this->loadFullMailConfig();
        $full['Mailer'] = array_merge($full['Mailer'] ?? [], [
            'host'         => trim(self::mStr($p, 'host', 'localhost')),
            'port'         => max(1, self::mInt($p, 'port', 587)),
            'username'     => trim(self::mStr($p, 'username', '')),
            'from_address' => trim(self::mStr($p, 'from_address', '')),
            'from_name'    => trim(self::mStr($p, 'from_name', '')),
            'encryption'   => trim(self::mStr($p, 'encryption', 'tls')),
            'timeout'      => max(5, self::mInt($p, 'timeout', 30)),
            'socks5_host'  => trim(self::mStr($p, 'socks5_host', '')),
            'socks5_port'  => max(1, self::mInt($p, 'socks5_port', 9050)),
        ]);
        // Preserve existing password when blank is submitted (placeholder = keep current).
        $newPw = trim(self::mStr($p, 'password', ''));
        if ($newPw !== '') {
            $full['Mailer']['password'] = $newPw;
        } elseif (!array_key_exists('password', $full['Mailer'])) {
            $full['Mailer']['password'] = '';
        }

        return $this->writer->write('Mail', $full);
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveMailbox(array $p)
    : Result {
        $full = $this->loadFullMailConfig();
        $full['MailboxManager'] = array_merge($full['MailboxManager'] ?? [], [
            'mailbox_domain' => trim(self::mStr($p, 'mailbox_domain', '')),
            'mailapi_url'    => trim(self::mStr($p, 'mailapi_url', '')),
        ]);
        // Preserve existing secret when blank is submitted.
        $newSecret = trim(self::mStr($p, 'mailapi_secret', ''));
        if ($newSecret !== '') {
            $full['MailboxManager']['mailapi_secret'] = $newSecret;
        } elseif (!array_key_exists('mailapi_secret', $full['MailboxManager'])) {
            $full['MailboxManager']['mailapi_secret'] = '';
        }

        return $this->writer->write('Mail', $full);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(string $selfUrl)
    : void {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId = $this->prg->createId($selfUrl);

        $encOptions = [
            ['value' => 'tls', 'label' => 'STARTTLS'],
            ['value' => 'ssl', 'label' => 'Implicit TLS (SMTPS)'],
            ['value' => '', 'label' => 'Plain (none)'],
        ];
        $currentEnc = $this->config->getConfigString(
            'Mailer',
            'encryption',
            'tls'
        );
        $encOptions = array_map(
            fn($o) => array_merge(
                $o,
                ['selected' => $o['value'] === $currentEnc]
            ),
            $encOptions
        );

        $this->ctx->set('csrf_token', $csrfToken);
        $this->ctx->set('prg_id', $prgId);

        // Mailer
        $this->ctx->set(
            'cfg_host',
            $this->config->getConfigString(
                'Mailer',
                'host',
                'localhost'
            )
        );
        $this->ctx->set(
            'cfg_port',
            $this->config->getConfigInt('Mailer', 'port', 587)
        );
        $this->ctx->set(
            'cfg_username',
            $this->config->getConfigString(
                'Mailer',
                'username',
                ''
            )
        );
        // Never expose the stored password in the form — show placeholder instead.
        $this->ctx->set(
            'cfg_password_set',
            $this->config->getConfigString(
                'Mailer',
                'password',
                ''
            ) !== ''
        );
        $this->ctx->set(
            'cfg_from_address',
            $this->config->getConfigString(
                'Mailer',
                'from_address',
                ''
            )
        );
        $this->ctx->set(
            'cfg_from_name',
            $this->config->getConfigString(
                'Mailer',
                'from_name',
                ''
            )
        );
        $this->ctx->set('cfg_encryption', $currentEnc);
        $this->ctx->set(
            'cfg_timeout',
            $this->config->getConfigInt('Mailer', 'timeout', 30)
        );
        $this->ctx->set(
            'cfg_socks5_host',
            $this->config->getConfigString(
                'Mailer',
                'socks5_host',
                ''
            )
        );
        $this->ctx->set(
            'cfg_socks5_port',
            $this->config->getConfigInt(
                'Mailer',
                'socks5_port',
                9050
            )
        );
        $this->ctx->set(
            'cfg_test_recipient',
            $this->config->getConfigString(
                'Mailer',
                'test_recipient',
                ''
            )
        );
        $this->ctx->set('encryption_options', $encOptions);

        // MailboxManager
        $this->ctx->set(
            'cfg_mailbox_domain',
            $this->config->getConfigString(
                'MailboxManager',
                'mailbox_domain',
                ''
            )
        );
        $this->ctx->set(
            'cfg_mailapi_url',
            $this->config->getConfigString(
                'MailboxManager',
                'mailapi_url',
                ''
            )
        );
        // Never expose the API secret — show a "set/not set" indicator.
        $this->ctx->set(
            'cfg_mailapi_secret_set',
            $this->config->getConfigString(
                'MailboxManager',
                'mailapi_secret',
                ''
            ) !== ''
        );

        $this->setI18n();
    }

    /** @return array<string, array<string, mixed>> */
    private function loadFullMailConfig()
    : array
    {
        $path = (configDir() . 'Mail.config.php');
        if (!is_file($path)) {
            return [];
        }
        $loaded = @include $path;

        if (!is_array($loaded)) { return []; }
        /** @var array<string,array<string,mixed>> $loaded */
        return $loaded;
    }

    private function setI18n()
    : void
    {
        $this->ctx->set('heading', $this->t->t('admin.config.mail.heading'));
        $this->ctx->set(
            'section_mailer',
            $this->t->t('admin.config.mail.mailer')
        );
        $this->ctx->set(
            'section_mailbox',
            $this->t->t('admin.config.mail.mailbox')
        );
        $this->ctx->set('section_test', $this->t->t('admin.config.mail.test'));
        $this->ctx->set(
            'label_host',
            $this->t->t('admin.config.field.mail_host')
        );
        $this->ctx->set(
            'label_port',
            $this->t->t('admin.config.field.mail_port')
        );
        $this->ctx->set(
            'label_username',
            $this->t->t('admin.config.field.mail_username')
        );
        $this->ctx->set(
            'label_password',
            $this->t->t('admin.config.field.mail_password')
        );
        $this->ctx->set(
            'label_from_address',
            $this->t->t('admin.config.field.from_address')
        );
        $this->ctx->set(
            'label_from_name',
            $this->t->t('admin.config.field.from_name')
        );
        $this->ctx->set(
            'label_encryption',
            $this->t->t('admin.config.field.encryption')
        );
        $this->ctx->set(
            'label_timeout',
            $this->t->t('admin.config.field.mail_timeout')
        );
        $this->ctx->set(
            'label_socks5_host',
            $this->t->t('admin.config.field.socks5_host')
        );
        $this->ctx->set(
            'label_socks5_port',
            $this->t->t('admin.config.field.socks5_port')
        );
        $this->ctx->set(
            'label_test_recipient',
            $this->t->t('admin.config.field.test_recipient')
        );
        $this->ctx->set(
            'label_mailbox_domain',
            $this->t->t('admin.config.field.mailbox_domain')
        );
        $this->ctx->set(
            'label_mailapi_url',
            $this->t->t('admin.config.field.mailapi_url')
        );
        $this->ctx->set(
            'label_mailapi_secret',
            $this->t->t('admin.config.field.mailapi_secret')
        );
        $this->ctx->set(
            'label_password_set',
            $this->t->t('admin.config.mail.password_set')
        );
        $this->ctx->set(
            'label_password_not_set',
            $this->t->t('admin.config.mail.password_not_set')
        );
        $this->ctx->set(
            'label_secret_set',
            $this->t->t('admin.config.mail.secret_set')
        );
        $this->ctx->set(
            'label_secret_not_set',
            $this->t->t('admin.config.mail.secret_not_set')
        );
        $this->ctx->set('btn_save', $this->t->t('admin.btn.save'));
        $this->ctx->set(
            'btn_send_test',
            $this->t->t('admin.config.mail.btn_send_test')
        );
        $this->ctx->set(
            'btn_save_recipient',
            $this->t->t('admin.config.mail.btn_save_recipient')
        );
    }
}
