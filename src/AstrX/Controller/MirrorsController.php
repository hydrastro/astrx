<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;
use PDO;

/**
 * Public mirror / anti-phishing page (/mirrors).
 *
 * Lists the operator's canonical addresses and shows an offline-SIGNED statement
 * of them (pasted via the admin editor — like the warrant canary, no key on the
 * server) so a visitor can confirm they are on the real service and not a
 * phishing clone. Paired with the Onion-Location header ContentManager emits. The
 * addresses are shown as plain <code> (never live links) so a hostile value can't
 * become a click target. 404s when nothing is published.
 *
 * Storage: the `site_config` KV (onion_* keys).
 */
final class MirrorsController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly PDO                     $pdo,
        private readonly Translator             $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->ctx->set('mirrors_heading', $this->t->t('mirrors.heading'));

        $signed  = trim($this->cfg('onion_signed'));
        $listRaw = trim($this->cfg('onion_mirrors'));

        if ($signed === '' && $listRaw === '') {
            http_response_code(404);
            $this->ctx->set('has_mirrors', false);
            $this->ctx->set('mirrors_none', $this->t->t('mirrors.none'));
            return $this->ok();
        }

        $mirrors = [];
        foreach (preg_split('/\r\n|\r|\n/', $listRaw) ?: [] as $line) {
            $u = trim($line);
            if ($u !== '') { $mirrors[] = ['url' => $u]; }
        }

        $this->ctx->set('has_mirrors',          true);
        $this->ctx->set('mirrors_intro',        $this->t->t('mirrors.intro'));
        $this->ctx->set('mirrors_verify',       $this->t->t('mirrors.verify'));
        $this->ctx->set('mirrors',              $mirrors);
        $this->ctx->set('has_list',             $mirrors !== []);
        $this->ctx->set('signed',               $signed);
        $this->ctx->set('has_signed',           $signed !== '');
        $this->ctx->set('mirrors_signed_label', $this->t->t('mirrors.signed_label'));

        return $this->ok();
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
}
