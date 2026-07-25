<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\I18n\Translator;
use AstrX\Imageboard\BoardRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin — Imageboard board list (read-only overview).
 *
 * Shows every board with its per-board limits (cooldown, reply cap, thread cap)
 * so an admin can see the effective flood/size settings at a glance. Global
 * defaults are edited on the Imageboard config page linked from here; full
 * per-board editing (create/rename/delete, override the caps) is a separate
 * panel. Gated on ADMIN_CONFIG_IMAGEBOARD.
 */
final class AdminBoardsController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly BoardRepository        $boards,
        private readonly Gate                   $gate,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_IMAGEBOARD)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $listR   = $this->boards->listActive();
        $rows    = $listR->isOk() ? $listR->unwrap() : [];
        $default = $this->t->t('admin.boards.default');

        $boards = [];
        foreach ($rows as $b) {
            $maxReplies = self::mInt($b, 'max_replies');
            $boards[]   = [
                'slug'         => self::mStr($b, 'slug'),
                'title'        => self::mStr($b, 'title'),
                'active'       => self::mBool($b, 'active') ? '✓' : '—',
                'cooldown'     => self::mInt($b, 'cooldown_secs'),
                'max_replies'  => $maxReplies > 0 ? (string) $maxReplies : $default,
                'thread_limit' => self::mInt($b, 'thread_limit'),
            ];
        }

        $this->ctx->set('admin_forbidden', false);
        $this->ctx->set('boards',     $boards);
        $this->ctx->set('has_boards', $boards !== []);
        $this->ctx->set('config_url', $this->urlGen->toPage($this->t->t('WORDING_ADMIN_CONFIG_IMAGEBOARD')));

        $this->ctx->set('heading',         $this->t->t('admin.boards.heading'));
        $this->ctx->set('intro',           $this->t->t('admin.boards.intro'));
        $this->ctx->set('col_slug',        $this->t->t('admin.boards.col_slug'));
        $this->ctx->set('col_title',       $this->t->t('admin.boards.col_title'));
        $this->ctx->set('col_active',      $this->t->t('admin.boards.col_active'));
        $this->ctx->set('col_cooldown',    $this->t->t('admin.boards.col_cooldown'));
        $this->ctx->set('col_max_replies', $this->t->t('admin.boards.col_max_replies'));
        $this->ctx->set('col_threads',     $this->t->t('admin.boards.col_threads'));
        $this->ctx->set('lbl_none',        $this->t->t('admin.boards.none'));
        $this->ctx->set('config_link',     $this->t->t('admin.boards.config_link'));

        return $this->ok();
    }
}
