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
use AstrX\User\UserGroup;
use AstrX\User\UserRepository;

final class AdminUsersController extends AbstractController
{
    private const FORM = 'admin_users';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly UserRepository        $userRepo,
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
        if ($this->gate->cannot(Permission::ADMIN_USERS)) {
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

        $editId     = (string) ($this->request->query()->get('edit') ?? '');
        $listResult = $this->userRepo->listAll();
        $listResult->drainTo($this->collector);

        $rawList  = $listResult->isOk() ? $listResult->unwrap() : [];
        $userList = [];
        foreach ($rawList as $row) {
            $isEditing = ($editId !== '' && $row['id'] === $editId);

            if ($isEditing) {
                // Load every column for the edit context.
                // IMPORTANT: row['editing'] must be an ARRAY (not bool) so the
                // Mustache engine sets $parent = that array inside {{#editing}}.
                // A bool true would replace $parent with `true`, losing all context.
                $full = $this->userRepo->adminFindById($editId);
                $full->drainTo($this->collector);
                $fd = ($full->isOk() && $full->unwrap() !== null)
                    ? $full->unwrap()
                    : $row; // fallback to list data
                $fd['type_options'] = $this->buildTypeOptions((int) $fd['type']);
                $row['editing'] = [$fd]; // nested array → Mustache context inside {{#editing}}
            } else {
                $row['editing'] = false; // falsy → {{#editing}} section skipped
            }
            $userList[] = $row;
        }

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('csrf_token',  $csrfToken);
        $this->ctx->set('prg_id',      $prgId);
        $this->ctx->set('user_list',   $userList);
        $this->ctx->set('base_url',    $this->request->uri()->path());
        $this->setI18n();
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

        $action = (string) ($posted['action']  ?? '');
        $hexId  = (string) ($posted['user_id'] ?? '');
        if ($hexId === '') { return; }

        // Gate check — even admins can't escalate above their own level (policy)
        $targetResult = $this->userRepo->findById($hexId);
        if ($targetResult->isOk() && $targetResult->unwrap() !== null) {
            $target = (object) $targetResult->unwrap();
            if ($this->gate->cannot(Permission::USER_EDIT_ANY, $target)) {
                $this->flash->set('error', $this->t->t('admin.users.permission_denied'));
                return;
            }
        }

        switch ($action) {
            case 'update':
                $rawPassword  = trim((string) ($posted['password']      ?? ''));
                $hashIt       = !empty($posted['hash_password']);
                if ($rawPassword !== '') {
                    $password = $hashIt
                        ? password_hash($rawPassword, PASSWORD_ARGON2ID)
                        : $rawPassword;
                } else {
                    $password = null;
                }
                $username      = trim((string) ($posted['username']      ?? ''));
                $mailbox       = ($posted['mailbox']      ?? '') !== '' ? trim((string) $posted['mailbox'])      : null;
                $email         = ($posted['email']        ?? '') !== '' ? trim((string) $posted['email'])        : null;
                $displayName   = ($posted['display_name'] ?? '') !== '' ? trim((string) $posted['display_name']) : null;
                $type          = (int) ($posted['type']           ?? 0);
                $birth         = ($posted['birth']        ?? '') !== '' ? trim((string) $posted['birth'])        : null;
                $loginAttempts = (int) ($posted['login_attempts'] ?? 0);
                $verified      = !empty($posted['verified']);
                $deleted       = !empty($posted['deleted']);
                $createdAt     = ($posted['created_at']   ?? '') !== '' ? trim((string) $posted['created_at'])   : null;
                $lastAccess    = ($posted['last_access']  ?? '') !== '' ? trim((string) $posted['last_access'])  : null;
                $r = $this->userRepo->adminUpdate(
                    $hexId, $username, $password, $mailbox, $email, $displayName,
                    $type, $birth, $loginAttempts, $verified, $deleted, $createdAt, $lastAccess
                );
                $r->drainTo($this->collector);
                if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.users.updated')); }
                break;

            case 'delete':
                $r = $this->userRepo->softDelete($hexId);
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('admin.users.deleted'));
                }
                break;
        }
    }

    // =========================================================================

    /** @return list<array{value:int,name:string,selected:bool}> */
    private function buildTypeOptions(int $current): array
    {
        return array_map(
            fn(UserGroup $g) => [
                'value'    => $g->value,
                'name'     => $g->name,
                'selected' => $g->value === $current,
            ],
            UserGroup::cases()
        );
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_users_heading',  $this->t->t('admin.nav.users'));
        $this->ctx->set('label_id',             $this->t->t('admin.field.id'));
        $this->ctx->set('label_username',       $this->t->t('admin.field.username'));
        $this->ctx->set('label_display_name',   $this->t->t('admin.users.display_name'));
        $this->ctx->set('label_mailbox',        $this->t->t('admin.users.mailbox'));
        $this->ctx->set('label_email',          $this->t->t('admin.users.email'));
        $this->ctx->set('label_password',       $this->t->t('admin.users.password'));
        $this->ctx->set('label_hash_password',  $this->t->t('admin.users.hash_password'));
        $this->ctx->set('label_password_hint',  $this->t->t('admin.users.password_hint'));
        $this->ctx->set('label_birth',          $this->t->t('admin.users.birth'));
        $this->ctx->set('label_type',           $this->t->t('admin.field.type'));
        $this->ctx->set('label_verified',       $this->t->t('admin.field.verified'));
        $this->ctx->set('label_deleted',        $this->t->t('admin.field.deleted'));
        $this->ctx->set('label_login_attempts', $this->t->t('admin.users.login_attempts'));
        $this->ctx->set('label_last_access',    $this->t->t('admin.users.last_access'));
        $this->ctx->set('label_created_at',     $this->t->t('admin.field.date'));
        $this->ctx->set('label_token_hash',     $this->t->t('admin.users.token_hash'));
        $this->ctx->set('label_token_type',     $this->t->t('admin.users.token_type'));
        $this->ctx->set('label_token_used',     $this->t->t('admin.users.token_used'));
        $this->ctx->set('label_token_expires',  $this->t->t('admin.users.token_expires'));
        $this->ctx->set('label_actions',        $this->t->t('admin.field.actions'));
        $this->ctx->set('btn_update',           $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_delete',           $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_edit',             $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_cancel',           $this->t->t('admin.btn.cancel'));
    }
}