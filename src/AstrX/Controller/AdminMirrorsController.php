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
 * Admin editor for the onion / mirror anti-phishing data (/admin-mirrors).
 *
 * Sets the canonical .onion for the Onion-Location header, the mirror list shown
 * on /mirrors, and the offline-signed statement of those addresses (signed off
 * the server and pasted here). ADMIN-only (ADMIN_MIRRORS). Stored in site_config.
 */
final class AdminMirrorsController extends AbstractController
{
    private const FORM = 'admin_mirrors';

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
        if ($this->gate->cannot(Permission::ADMIN_MIRRORS)) {
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

        $this->ctx->set('mirrors_heading', $this->t->t('admin.mirrors.heading'));
        $this->ctx->set('mirrors_intro',   $this->t->t('admin.mirrors.intro'));
        $this->ctx->set('label_primary',   $this->t->t('admin.mirrors.primary'));
        $this->ctx->set('label_list',      $this->t->t('admin.mirrors.list'));
        $this->ctx->set('label_signed',    $this->t->t('admin.mirrors.signed'));
        $this->ctx->set('btn_save',        $this->t->t('admin.mirrors.save'));
        $this->ctx->set('primary',         $this->cfg('onion_primary'));
        $this->ctx->set('mirror_list',     $this->cfg('onion_mirrors'));
        $this->ctx->set('signed',          $this->cfg('onion_signed'));
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
        if (self::mStr($posted, 'action', '') !== 'save') {
            return;
        }

        // Only persist a canonical onion that is actually a .onion URL — the value
        // is emitted into the Onion-Location response header (ContentManager
        // re-validates too, but reject junk at the source).
        $primary = trim(self::mStr($posted, 'primary', ''));
        if ($primary !== '' && preg_match('#^https?://[a-z2-7]{16,90}\.onion(/\S*)?$#i', $primary) !== 1) {
            $this->flash->set('error', $this->t->t('admin.mirrors.bad_primary'));
            return;
        }

        $this->put('onion_primary', $primary);
        $this->put('onion_mirrors', mb_substr(self::mStr($posted, 'mirror_list', ''), 0, 8000));
        $this->put('onion_signed',  mb_substr(self::mStr($posted, 'signed', ''), 0, 8000));

        $this->flash->set('success', $this->t->t('admin.mirrors.saved'));
        $this->audit->log('mirrors.save', 'onion_mirrors')->drainTo($this->collector);
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
