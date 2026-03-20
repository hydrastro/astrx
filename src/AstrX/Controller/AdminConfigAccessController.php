<?php
declare(strict_types=1);

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
 * Admin — Access configuration editor.
 *
 * Permission grants matrix with dynamic group support:
 *   - ADMIN is always '*' (read-only column).
 *   - All other groups are editable.
 *   - Groups can be added (section=add_group) or deleted (section=delete_group)
 *     via separate single-purpose forms that never touch the permission checkboxes.
 *
 * Route management has moved to AdminBanlistController.
 */
final class AdminConfigAccessController extends AbstractController
{
    private const FORM = 'admin_config_access';

    private const PREFIX_LABELS = [
        'news'    => 'admin.config.access.prefix_news',
        'comment' => 'admin.config.access.prefix_comment',
        'user'    => 'admin.config.access.prefix_user',
        'ban'     => 'admin.config.access.prefix_ban',
        'admin'   => 'admin.config.access.prefix_admin',
    ];

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly Config                $config,
        private readonly ConfigWriter          $writer,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Page                  $page,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_ACCESS)) {
            http_response_code(403);
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
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

    private function processForm(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $section = (string) ($posted['section'] ?? '');
        $result  = match ($section) {
            'grants'       => $this->saveGrants($posted),
            'add_group'    => $this->addGroup($posted),
            'delete_group' => $this->deleteGroup($posted),
            default        => null,
        };

        if ($result !== null) {
            $result->drainTo($this->collector);
            if ($result->isOk()) {
                $this->flash->set('success', $this->t->t('admin.config.saved'));
            }
        }
    }

    // ── Savers ────────────────────────────────────────────────────────────────

    /**
     * Save permission checkboxes for all existing groups.
     * Never adds or removes groups — that is handled by addGroup/deleteGroup.
     *
     * @param array<string, mixed> $p
     */
    private function saveGrants(array $p): Result
    {
        $existing = (array) $this->config->getConfig('Gate', 'grants', []);
        $grants   = ['ADMIN' => ['*']];

        $editableGroups = array_filter(array_keys($existing), fn($g) => $g !== 'ADMIN');
        foreach ($editableGroups as $group) {
            $perms = [];
            foreach (Permission::cases() as $perm) {
                $field = 'perm_' . $group . '_' . str_replace('.', '__', $perm->value);
                if (!empty($p[$field])) {
                    $perms[] = $perm->value;
                }
            }
            $grants[$group] = $perms;
        }

        return $this->writer->write('Auth', ['Gate' => ['grants' => $grants]]);
    }

    /**
     * Add a new group with empty permissions.
     * Preserves all existing group permissions unchanged.
     *
     * @param array<string, mixed> $p
     */
    private function addGroup(array $p): Result
    {
        $newGroup = strtoupper(trim((string) ($p['new_group_name'] ?? '')));
        if ($newGroup === '' || $newGroup === 'ADMIN') {
            return Result::ok(false); // no-op
        }

        $existing = (array) $this->config->getConfig('Gate', 'grants', []);
        if (isset($existing[$newGroup])) {
            return Result::ok(false); // already exists, no-op
        }

        $existing[$newGroup] = [];
        return $this->writer->write('Auth', ['Gate' => ['grants' => $existing]]);
    }

    /**
     * Delete a group entirely.
     * Preserves all remaining group permissions unchanged.
     *
     * @param array<string, mixed> $p
     */
    private function deleteGroup(array $p): Result
    {
        $group = strtoupper(trim((string) ($p['delete_group'] ?? '')));
        if ($group === '' || $group === 'ADMIN') {
            return Result::ok(false); // no-op
        }

        $existing = (array) $this->config->getConfig('Gate', 'grants', []);
        unset($existing[$group]);
        return $this->writer->write('Auth', ['Gate' => ['grants' => $existing]]);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(string $selfUrl): void
    {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $grants         = (array) $this->config->getConfig('Gate', 'grants', []);
        $editableGroups = array_values(
            array_filter(array_keys($grants), fn($g) => $g !== 'ADMIN')
        );

        // Dynamic column headers.
        $groupHeaders = array_map(fn($g) => ['name' => $g], $editableGroups);

        // Permission rows grouped by prefix.
        $prefixSections = [];
        foreach (self::PREFIX_LABELS as $prefix => $labelKey) {
            $rows = [];
            foreach (Permission::cases() as $perm) {
                if (!str_starts_with($perm->value, $prefix . '.')) { continue; }
                $cells = [];
                foreach ($editableGroups as $group) {
                    $groupGrants = (array) ($grants[$group] ?? []);
                    $granted     = in_array($perm->value, $groupGrants, true);
                    $field       = 'perm_' . $group . '_' . str_replace('.', '__', $perm->value);
                    $cells[]     = ['group' => $group, 'field' => $field, 'granted' => $granted];
                }
                $rows[] = [
                    'perm_value' => $perm->value,
                    'perm_name'  => $perm->name,
                    'cells'      => $cells,
                ];
            }
            if ($rows !== []) {
                $prefixSections[] = [
                    'prefix_label' => $this->t->t($labelKey, fallback: $prefix),
                    'rows'         => $rows,
                ];
            }
        }

        // Groups available for deletion (all editable groups).
        $deletableGroups = array_map(fn($g) => ['name' => $g], $editableGroups);

        $this->ctx->set('csrf_token',       $csrfToken);
        $this->ctx->set('prg_id',           $prgId);
        $this->ctx->set('base_url',         $selfUrl);
        $this->ctx->set('group_headers',    $groupHeaders);
        $this->ctx->set('prefix_sections',  $prefixSections);
        $this->ctx->set('deletable_groups', $deletableGroups);
        $this->setI18n();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function setI18n(): void
    {
        $this->ctx->set('heading',              $this->t->t('admin.config.access.heading'));
        $this->ctx->set('section_grants',       $this->t->t('admin.config.access.grants'));
        $this->ctx->set('label_permission',     $this->t->t('admin.config.access.permission'));
        $this->ctx->set('label_admin_note',     $this->t->t('admin.config.access.admin_note'));
        $this->ctx->set('label_new_group_name', $this->t->t('admin.config.access.new_group_name'));
        $this->ctx->set('label_delete_group',   $this->t->t('admin.config.access.delete_group'));
        $this->ctx->set('btn_save',             $this->t->t('admin.btn.save'));
        $this->ctx->set('btn_add_group',        $this->t->t('admin.btn.add'));
        $this->ctx->set('btn_delete_group',     $this->t->t('admin.btn.delete'));
        foreach (self::PREFIX_LABELS as $prefix => $key) {
            $this->ctx->set('prefix_' . $prefix, $this->t->t($key, fallback: $prefix));
        }
    }
}