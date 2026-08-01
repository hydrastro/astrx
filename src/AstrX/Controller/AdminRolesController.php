<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin — roles / sensitive-levers viewer (/admin-roles).
 *
 * A READ-ONLY audit of the permission model: for each role (ADMIN / MOD / USER /
 * GUEST) it lists the granted permission patterns and flags the SENSITIVE ones —
 * the system-level levers whose scope has been the recurring theme of these
 * reviews (`*`, `admin.*`, `api.*`, any `.any`-scope grant, `user.promote`). The
 * grant *change* (tightening MOD) shipped in R12; this is the surface that makes
 * "which role can reach what" visible so the operator can audit it themselves.
 * Nothing is editable here (editing role grants means rewriting PHP in
 * Auth.config.php — a deliberate, out-of-band action). ADMIN-only (ADMIN_ROLES).
 */
final class AdminRolesController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_ROLES)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $roles = [];
        foreach ($this->gate->grants() as $roleName => $patterns) {
            $perms = [];
            foreach ($patterns as $pattern) {
                $perms[] = ['pattern' => $pattern, 'sensitive' => self::isSensitive($pattern)];
            }
            $roles[] = [
                'name'        => $roleName,
                'perms'       => $perms,
                'count'       => count($patterns),
                'is_wildcard' => in_array('*', $patterns, true),
            ];
        }

        $this->ctx->set('roles_heading',  $this->t->t('admin.roles.heading'));
        $this->ctx->set('roles_intro',    $this->t->t('admin.roles.intro'));
        $this->ctx->set('roles_legend',   $this->t->t('admin.roles.legend'));
        $this->ctx->set('label_count',    $this->t->t('admin.roles.count'));
        $this->ctx->set('label_wildcard', $this->t->t('admin.roles.wildcard'));
        $this->ctx->set('sensitive_tag',  $this->t->t('admin.roles.sensitive'));
        $this->ctx->set('roles',          $roles);

        return $this->ok();
    }

    /**
     * A grant pattern is "sensitive" when it reaches a system-level or
     * cross-user lever: the wildcard, any admin.* or api.* permission, any
     * `.any`-scoped action, or the promote-others permission.
     */
    private static function isSensitive(string $pattern): bool
    {
        return $pattern === '*'
            || str_starts_with($pattern, 'admin.')
            || str_starts_with($pattern, 'api.')
            || str_ends_with($pattern, '.any')
            || $pattern === 'user.promote';
    }
}
