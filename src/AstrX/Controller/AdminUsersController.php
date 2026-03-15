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
use AstrX\Routing\UrlGenerator;
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
        private readonly UrlGenerator          $urlGen,
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

        $editId = (string) ($this->request->query()->get('edit') ?? '');
        $editing = null;
        if ($editId !== '') {
            $r = $this->userRepo->findById($editId);
            $r->drainTo($this->collector);
            $editing = $r->isOk() ? $r->unwrap() : null;
        }

        $listResult = $this->userRepo->listAll();
        $listResult->drainTo($this->collector);

        $groups = array_map(fn(UserGroup $g) => ['value' => $g->value, 'name' => $g->name],
                            UserGroup::cases());

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('csrf_token',  $csrfToken);
        $this->ctx->set('prg_id',      $prgId);
        $this->ctx->set('user_list',   $listResult->isOk() ? $listResult->unwrap() : []);
        $this->ctx->set('editing',     $editing);
        $this->ctx->set('user_groups', $groups);
        $this->setI18n();
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
        $hexId  = (string) ($posted['user_id'] ?? '');

        // Policy: cannot change other admins unless you're also admin
        $target = null;
        if ($hexId !== '') {
            $r = $this->userRepo->findById($hexId);
            if ($r->isOk()) {
                $row = $r->unwrap();
                if ($row !== null) {
                    $target = (object) $row;
                }
            }
        }

        if ($target !== null && $this->gate->cannot(Permission::USER_EDIT_ANY, $target)) {
            $this->flash->set('error', $this->t->t('admin.users.permission_denied'));
            return;
        }

        switch ($action) {
            case 'promote':
                $newType = (int) ($posted['type'] ?? 0);
                $r = $this->userRepo->updateType($hexId, $newType);
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('admin.users.updated'));
                }
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

    private function setI18n(): void
    {
        $this->ctx->set('admin_users_heading', $this->t->t('admin.nav.users'));
        $this->ctx->set('label_id',         $this->t->t('admin.field.id'));
        $this->ctx->set('label_username',   $this->t->t('user.field.username'));
        $this->ctx->set('label_type',       $this->t->t('admin.field.type'));
        $this->ctx->set('label_verified',   $this->t->t('admin.field.verified'));
        $this->ctx->set('label_deleted',    $this->t->t('admin.field.deleted'));
        $this->ctx->set('label_created_at', $this->t->t('admin.field.date'));
        $this->ctx->set('label_actions',    $this->t->t('admin.field.actions'));
        $this->ctx->set('btn_promote',      $this->t->t('admin.btn.promote'));
        $this->ctx->set('btn_delete',       $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_edit',         $this->t->t('admin.btn.edit'));
    }
}
