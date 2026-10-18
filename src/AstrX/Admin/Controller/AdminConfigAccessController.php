<?php
declare(strict_types=1);

namespace AstrX\Admin\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Admin\DiagnosticLevelOverrideRepository;
use AstrX\Admin\DiagnosticVisibilityRepository;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Auth\PermissionRegistry;
use AstrX\Config\Config;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticLevel;
use AstrX\I18n\DiagnosticRenderer;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\TemplateVars;
use AstrX\Kernel\AbstractController;
use AstrX\Admin\AdminPermission;

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
        'board'   => 'admin.config.access.prefix_board',
        'chat'    => 'admin.config.access.prefix_chat',
        'webmail' => 'admin.config.access.prefix_webmail',
        'api'     => 'admin.config.access.prefix_api',
        'admin'   => 'admin.config.access.prefix_admin',
    ];

    /**
     * Section order and headings for the grants matrix: the labelled prefixes
     * above first, then any other prefix a feature declares (heading = the
     * prefix itself). EVERY registered permission gets a row — saveGrants()
     * relies on that, since an unshown permission would read as "unchecked".
     *
     * @return array<string, string> prefix => heading
     */
    private function grantSections(): array
    {
        $sections = [];
        foreach (self::PREFIX_LABELS as $prefix => $labelKey) {
            $sections[$prefix] = $this->t->t($labelKey, fallback: $prefix);
        }
        foreach ($this->permissions->all() as $perm) {
            $prefix = explode('.', $perm->id(), 2)[0];
            $sections[$prefix] ??= $prefix;
        }
        return $sections;
    }

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly TemplateVars $ctx,
        private readonly Request               $request,
        private readonly Config                $config,
        private readonly ConfigWriter          $writer,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Page                  $page,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator                    $t,
        private readonly DiagnosticRenderer            $renderer,
        private readonly DiagnosticVisibilityRepository $visibilityRepo,
        private readonly DiagnosticLevelOverrideRepository $levelRepo,
        private readonly AuditLogger $audit,
        private readonly PermissionRegistry $permissions,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(AdminPermission::CONFIG_ACCESS)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
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
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $section = self::mStr($posted, 'section', '');
        $result  = match ($section) {
            'grants'           => $this->saveGrants($posted),
            'add_group'        => $this->addGroup($posted),
            'delete_group'     => $this->deleteGroup($posted),
            'diag_visibility'  => $this->saveDiagVisibility($posted),
            'diag_levels'      => $this->saveDiagLevels($posted),
            default            => null,
        };

        if ($result !== null) {
            $result->drainTo($this->collector);
            if ($result->isOk()) {
                $this->flash->set('success', $this->t->t('admin.config.saved'));
                $this->audit->log('config.save', 'Auth.config.php')->drainTo($this->collector);
            }
        }
    }

    // ── Savers ────────────────────────────────────────────────────────────────

    /**
     * Save permission checkboxes for all existing groups.
     * Never adds or removes groups — that is handled by addGroup/deleteGroup.
     *
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveGrants(array $p): Result
    {
        $existing = (array) $this->config->getConfig('Gate', 'grants', []);
        $grants   = ['ADMIN' => ['*']];

        $editableGroups = array_filter(array_keys($existing), fn($g) => $g !== 'ADMIN');
        $shown = array_map(static fn(Permission $perm): string => $perm->id(), $this->permissions->all());
        foreach ($editableGroups as $group) {
            // Never drop what the form did not show: wildcard patterns
            // ('comment.*') and ids no feature declares have no checkbox, so
            // their absence from $p is not a revocation. (Rebuilding from the
            // checkboxes alone used to silently revoke every chat/board/api/
            // webmail grant, whose rows the matrix did not render.)
            $perms = [];
            foreach ((array) ($existing[$group] ?? []) as $kept) {
                if (is_string($kept) && !in_array($kept, $shown, true)) {
                    $perms[] = $kept;
                }
            }
            foreach ($this->permissions->all() as $perm) {
                $field = 'perm_' . $group . '_' . str_replace('.', '__', $perm->id());
                if (!empty($p[$field])) {
                    $perms[] = $perm->id();
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
     * @return Result<mixed>
     */
    private function addGroup(array $p): Result
    {
        $newGroup = strtoupper(trim(self::mStr($p, 'new_group_name', '')));
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
     * @return Result<mixed>
     */
    private function deleteGroup(array $p): Result
    {
        $group = strtoupper(trim(self::mStr($p, 'delete_group', '')));
        if ($group === '' || $group === 'ADMIN') {
            return Result::ok(false); // no-op
        }

        $existing = (array) $this->config->getConfig('Gate', 'grants', []);
        unset($existing[$group]);
        return $this->writer->write('Auth', ['Gate' => ['grants' => $existing]]);
    }

    // ── Diagnostic visibility saver ──────────────────────────────────────────

    /**
     * Save diagnostic visibility checkboxes for all non-admin groups.
     * Each checkbox field is named: diag_vis_{GROUP}_{CODE_ESCAPED}
     * where CODE_ESCAPED replaces '/' and '.' with '__'.
     *
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveDiagVisibility(array $p): Result
    {
        $grants         = (array) $this->config->getConfig('Gate', 'grants', []);
        $editableGroups = array_filter(array_keys($grants), fn($g) => $g !== 'ADMIN');
        $codes          = $this->renderer->knownCodes();

        foreach ($editableGroups as $group) {
            $visible = [];
            foreach ($codes as $code) {
                $field = 'diag_vis_' . $group . '_' . $this->escapeCode($code);
                if (!empty($p[$field])) {
                    $visible[] = $code;
                }
            }
            $r = $this->visibilityRepo->setForGroup($group, $visible);
            if (!$r->isOk()) {
                $r->drainTo($this->collector);
                return Result::err(false);
            }
        }
        return Result::ok(true);
    }

    /**
     * Save per-code level overrides.
     * Field name: diag_level_{CODE_ESCAPED} — value is DiagnosticLevel int or '' for none.
     *
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveDiagLevels(array $p): Result
    {
        $overrides = [];
        foreach ($this->renderer->knownCodes() as $code) {
            $field = 'diag_level_' . $this->escapeCode($code);
            $rawV  = $p[$field] ?? ''; $raw = is_scalar($rawV) ? (string)$rawV : '';
            if ($raw === '') { continue; }
            $level = DiagnosticLevel::tryFrom((int) $raw);
            if ($level !== null) {
                $overrides[$code] = $level->value;
            }
        }
        return $this->levelRepo->replaceAll($overrides);
    }

    private function escapeCode(string $code): string
    {
        return str_replace(['/', '.', '-'], '__', $code);
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
        foreach ($this->grantSections() as $prefix => $heading) {
            $rows = [];
            foreach ($this->permissions->all() as $perm) {
                if (!str_starts_with($perm->id(), $prefix . '.')) { continue; }
                $cells = [];
                foreach ($editableGroups as $group) {
                    $groupGrants = (array) ($grants[$group] ?? []);
                    $granted     = in_array($perm->id(), $groupGrants, true);
                    $field       = 'perm_' . $group . '_' . str_replace('.', '__', $perm->id());
                    $cells[]     = ['group' => $group, 'field' => $field, 'granted' => $granted];
                }
                $rows[] = [
                    'perm_value' => $perm->id(),
                    // 'admin.config.chat' → 'ADMIN_CONFIG_CHAT': the label the
                    // single Permission enum's case names used to give.
                    'perm_name'  => strtoupper(str_replace('.', '_', $perm->id())),
                    'cells'      => $cells,
                ];
            }
            if ($rows !== []) {
                $prefixSections[] = [
                    'prefix_label' => $heading,
                    'rows'         => $rows,
                ];
            }
        }

        // Groups available for deletion (all editable groups).
        $deletableGroups = array_map(fn($g) => ['name' => $g], $editableGroups);

        // Diagnostic visibility matrix
        $allCodes    = $this->renderer->knownCodes();
        sort($allCodes);
        $visMap      = $this->visibilityRepo->all();
        $visMap      = $visMap->isOk() ? $visMap->unwrap() : [];
        $levelOvr    = $this->levelRepo->all();
        $levelOvr    = $levelOvr->isOk() ? $levelOvr->unwrap() : [];
        $diagLevels  = DiagnosticLevel::cases();

        // Group codes by prefix (before the first '/')
        $diagSections = [];
        foreach ($allCodes as $code) {
            $prefix = explode('/', $code)[0];
            $visibleGroups = $visMap[$code] ?? [];
            $currentLevel  = $levelOvr[$code] ?? null;
            $cells = [];
            foreach ($editableGroups as $group) {
                $cells[] = [
                    'group'   => $group,
                    'field'   => 'diag_vis_' . $group . '_' . $this->escapeCode($code),
                    'visible' => in_array($group, $visibleGroups, true),
                ];
            }
            $levelOptions = [];
            foreach ($diagLevels as $lv) {
                $levelOptions[] = [
                    'value'    => $lv->value,
                    'name'     => $lv->name,
                    'selected' => $currentLevel !== null && $currentLevel === $lv,
                ];
            }
            $diagSections[$prefix][] = [
                'code'          => $code,
                'field_escaped' => $this->escapeCode($code),
                'cells'         => $cells,
                'level_field'   => 'diag_level_' . $this->escapeCode($code),
                'level_options' => $levelOptions,
                'has_override'  => $currentLevel !== null,
            ];
        }
        // Convert to indexed array for Mustache
        $diagSectionsList = [];
        foreach ($diagSections as $prefix => $rows) {
            $diagSectionsList[] = ['prefix' => $prefix, 'rows' => $rows];
        }

        $this->ctx->set('csrf_token',       $csrfToken);
        $this->ctx->set('prg_id',           $prgId);
        $this->ctx->set('base_url',         $selfUrl);
        $this->ctx->set('group_headers',    $groupHeaders);
        $this->ctx->set('prefix_sections',  $prefixSections);
        $this->ctx->set('deletable_groups',   $deletableGroups);
        $this->ctx->set('diag_sections',      $diagSectionsList);
        $this->ctx->set('has_diag_sections',  $diagSectionsList !== []);
        $this->ctx->set('diag_group_headers', $groupHeaders);
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
        $this->ctx->set('section_diag_visibility', $this->t->t('admin.config.access.diag_visibility'));
        $this->ctx->set('section_diag_levels',     $this->t->t('admin.config.access.diag_levels'));
        $this->ctx->set('label_diag_code',         $this->t->t('admin.config.access.diag_code'));
        $this->ctx->set('label_diag_level',        $this->t->t('admin.config.access.diag_level'));
        $this->ctx->set('label_diag_admin_note',   $this->t->t('admin.config.access.diag_admin_note'));
        $this->ctx->set('label_diag_level_default',$this->t->t('admin.config.access.diag_level_default'));
    }
}
