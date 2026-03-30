<?php

declare(strict_types=1);

namespace AstrX\Controller;

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
 * Admin personal notes scratchpad.
 *
 * Notes are stored in the `site_config` table under the key 'admin_notes'.
 * Only admins with ADMIN_ACCESS can read or write notes.
 *
 * Two POST actions (submitted via PRG):
 *   action=save  — persists the notes textarea content
 *   action=clear — clears notes (sets to empty string)
 */
final class AdminNotesController extends AbstractController
{
    private const SITE_CONFIG_KEY = 'admin_notes';
    private const FORM            = 'admin_notes';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly PDO                   $pdo,
        private readonly Gate                  $gate,
        private readonly Translator            $t,
        private readonly FlashBag              $flash,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_ACCESS)) {
            http_response_code(403);
            return $this->ok();
        }

        // ── PRG POST handling ─────────────────────────────────────────────────
        $selfUrl   = $this->request->uri()->path();
        $prgToken  = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $posted = $this->prg->pull($prgToken) ?? [];
            $this->processPost($posted);
            return Response::redirect($selfUrl); /** @phpstan-ignore return.type */
        }

        // ── Render ────────────────────────────────────────────────────────────
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $this->ctx->set('admin_notes_heading', $this->t->t('admin.nav.notes'));
        $this->ctx->set('label_notes',         $this->t->t('admin.notes.label'));
        $this->ctx->set('btn_save',            $this->t->t('admin.btn.save'));
        $this->ctx->set('btn_clear',           $this->t->t('admin.btn.clear'));
        $this->ctx->set('notes',               $this->loadNotes());
        $this->ctx->set('prg_id',              $prgId);
        $this->ctx->set('csrf_token',          $csrfToken);

        return $this->ok();
    }

    // =========================================================================

    /** @param array<string,mixed> $posted */
    private function processPost(array $posted): void
    {
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action = self::mStr($posted, 'action', '');
        if ($action === 'save') {
            $this->saveNotes(self::mStr($posted, 'notes', ''));
            $this->flash->set('success', $this->t->t('admin.notes.saved'));
        } elseif ($action === 'clear') {
            $this->saveNotes('');
            $this->flash->set('success', $this->t->t('admin.notes.saved'));
        }
    }

    // =========================================================================

    private function loadNotes(): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `value` FROM `site_config` WHERE `key` = :k LIMIT 1'
            );
            $stmt->execute([':k' => self::SITE_CONFIG_KEY]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || !is_array($row)) {
                return '';
            }
            /** @var array<string,mixed> $row */
            return is_string($row['value']) ? $row['value'] : '';
        } catch (\PDOException) {
            return '';
        }
    }

    private function saveNotes(string $notes): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `site_config` (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = :v2'
            );
            $stmt->execute([
                ':k'  => self::SITE_CONFIG_KEY,
                ':v'  => $notes,
                ':v2' => $notes,
            ]);
        } catch (\PDOException) {
            // Non-fatal — notes won't persist this request.
        }
    }
}
