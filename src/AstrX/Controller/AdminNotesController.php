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
 * Persistent admin scratchpad.
 * Content stored in site_config table under key 'admin_notes'.
 */
final class AdminNotesController extends AbstractController
{
    private const FORM      = 'admin_notes';
    private const CONFIG_KEY = 'admin_notes';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly PDO                   $pdo,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_NOTES)) {
            http_response_code(403);
            return $this->ok();
        }

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($this->request->uri()->path())
                ->send()->drainTo($this->collector);
            exit;
        }

        $notes     = $this->readNotes();
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('notes',      $notes);
        $this->ctx->set('csrf_token', $csrfToken);
        $this->ctx->set('prg_id',     $prgId);
        $this->ctx->set('admin_notes_heading', $this->t->t('admin.nav.notes'));
        $this->ctx->set('label_notes', $this->t->t('admin.notes.label'));
        $this->ctx->set('btn_save',    $this->t->t('admin.btn.save'));
        $this->ctx->set('btn_clear',   $this->t->t('admin.btn.clear'));
        return $this->ok();
    }

    private function processForm(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }
        $action = (string) ($posted['action'] ?? '');
        $content = $action === 'clear' ? '' : (string) ($posted['notes'] ?? '');
        $this->writeNotes($content);
        $this->flash->set('success', $this->t->t('admin.notes.saved'));
    }

    private function readNotes(): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT value FROM site_config WHERE `key` = :key LIMIT 1"
            );
            $stmt->execute([':key' => self::CONFIG_KEY]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? (string) $row['value'] : '';
        } catch (\PDOException) {
            return '';
        }
    }

    private function writeNotes(string $content): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO site_config (`key`, value) VALUES (:key, :val)
                 ON DUPLICATE KEY UPDATE value = :val2"
            );
            $stmt->execute([':key' => self::CONFIG_KEY, ':val' => $content, ':val2' => $content]);
        } catch (\PDOException) {
        }
    }
}
