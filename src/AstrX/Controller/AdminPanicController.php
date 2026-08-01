<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use PDO;

/**
 * Panic / lockdown control (/admin-panic).
 *
 * One switch seals the whole site: while armed, every non-admin request gets a
 * 503 lockdown page and no user-generated mutation runs (the gate lives in
 * ContentManager, at the settling-GET / dispatch point, so it cannot be walked
 * around via a crafted PRG landing — the bug that sank the first attempt). Login
 * + captcha stay open so an admin can still sign in here and disarm it. The
 * operator can also set the message shown to visitors. ADMIN-only (ADMIN_PANIC).
 * State lives in `site_config` (panic_active / panic_message).
 */
final class AdminPanicController extends AbstractController
{
    private const FORM = 'admin_panic';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly PDO                    $pdo,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly FlashBag               $flash,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly AuditLogger            $audit,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_PANIC)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $selfUrl  = $this->request->uri()->path();
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processPost($this->prg->pull($prgToken) ?? []);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $active = $this->cfg('panic_active') === '1';

        $this->ctx->set('panic_heading',   $this->t->t('admin.panic.heading'));
        $this->ctx->set('panic_intro',     $this->t->t('admin.panic.intro'));
        $this->ctx->set('panic_active',    $active);
        $this->ctx->set('status_on',       $this->t->t('admin.panic.status_on'));
        $this->ctx->set('status_off',      $this->t->t('admin.panic.status_off'));
        $this->ctx->set('btn_enable',      $this->t->t('admin.panic.enable'));
        $this->ctx->set('btn_disable',     $this->t->t('admin.panic.disable'));
        $this->ctx->set('label_message',   $this->t->t('admin.panic.message'));
        $this->ctx->set('message_hint',    $this->t->t('admin.panic.message_hint'));
        $this->ctx->set('btn_save',        $this->t->t('admin.panic.save'));
        $this->ctx->set('panic_message',   $this->cfg('panic_message'));
        $this->ctx->set('prg_id',          $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token',      $this->csrf->generate(self::FORM));

        return $this->ok();
    }

    /** @param array<string,mixed> $posted */
    private function processPost(array $posted): void
    {
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        switch (self::mStr($posted, 'action', '')) {
            case 'enable':
                $this->put('panic_active', '1');
                $this->flash->set('success', $this->t->t('admin.panic.enabled'));
                $this->audit->log('panic.enable', 'lockdown')->drainTo($this->collector);
                return;

            case 'disable':
                $this->put('panic_active', '0');
                $this->flash->set('success', $this->t->t('admin.panic.disabled'));
                $this->audit->log('panic.disable', 'lockdown')->drainTo($this->collector);
                return;

            case 'save_message':
                $this->put('panic_message', mb_substr(self::mStr($posted, 'message', ''), 0, 2000));
                $this->flash->set('success', $this->t->t('admin.panic.saved'));
                $this->audit->log('panic.message', 'lockdown')->drainTo($this->collector);
                return;
        }
    }

    private function cfg(string $key): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT `value` FROM `site_config` WHERE `key` = :k LIMIT 1');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) { return ''; }
            /** @var array<string,mixed> $row */
            return is_scalar($row['value'] ?? null) ? (string) $row['value'] : '';
        } catch (\PDOException) {
            return '';
        }
    }

    private function put(string $key, string $value): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `site_config` (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = :v2'
            );
            $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
        } catch (\PDOException) {
            // Non-fatal.
        }
    }
}
