<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\UploadedFile;
use AstrX\I18n\Translator;
use AstrX\Media\MediaService;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserSession;
use function AstrX\Support\langDir;

/**
 * Admin — media library: a no-JavaScript manager (upload / rename / delete) for
 * general uploaded media that can be re-used across content pages. PRG + CSRF
 * like the other admin editors. Entry gated on ADMIN_ACCESS; every mutation is
 * re-gated on ADMIN_PAGES so a view-only MOD cannot alter shared media (mirrors
 * AdminContentController).
 *
 * Uploads survive the POST-redirect-GET cycle via ContentManager's __files__
 * mechanism: on POST it moves the upload to a persistent temp path and stores its
 * metadata in the PRG payload, then on the GET side reconstructs an UploadedFile
 * into the request FileBag — so upload() reads $request->files() as usual.
 */
final class AdminMediaController extends AbstractController
{
    private const string FORM = 'admin_media';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly MediaService           $service,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly UserSession            $session,
        private readonly Page                   $page,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Media');

        if ($this->gate->cannot(Permission::ADMIN_ACCESS)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $this->handlePrgPost($this->request, $this->prg, $selfUrl, fn(string $tok): string => $this->processForm($tok));
        $this->buildContext($selfUrl);
        return $this->ok();
    }

    private function processForm(string $prgToken): string
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return '';
        }

        return match (self::mStr($posted, 'action', '')) {
            'upload' => $this->upload($posted),
            'rename' => $this->rename($posted),
            'delete' => $this->delete($posted),
            default  => '',
        };
    }

    /** @param array<string,mixed> $posted */
    private function upload(array $posted): string
    {
        // Shared media is a site-wide asset: mutating it requires ADMIN_PAGES.
        // Re-gate under the weaker ADMIN_ACCESS entry gate so a MOD cannot add
        // files — mirrors the content editor's ADMIN_PAGES re-check.
        if ($this->gate->cannot(Permission::ADMIN_PAGES)) {
            $this->flash->set('error', $this->t->t('admin.forbidden'));
            return '';
        }

        // The file was reconstructed into the FileBag by ContentManager after the
        // PRG redirect (see class docblock). Field name matches the template.
        $file = $this->request->files()->get('file');
        if (!$file instanceof UploadedFile || $file->hasError()) {
            $this->flash->set('error', $this->t->t('media.admin.upload_no_file'));
            return '';
        }

        $hex = $this->session->userId();
        $r = $this->service->store($file, $hex !== '' ? $hex : null)->drainTo($this->collector);
        if (!$r->isOk()) {
            $this->flash->set('error', $this->t->t('media.admin.upload_failed'));
            return '';
        }
        $this->flash->set('success', $this->t->t('media.admin.uploaded'));
        return '';
    }

    /** @param array<string,mixed> $posted */
    private function rename(array $posted): string
    {
        if ($this->gate->cannot(Permission::ADMIN_PAGES)) {
            $this->flash->set('error', $this->t->t('admin.forbidden'));
            return '';
        }

        $id      = self::mInt($posted, 'id', 0);
        $newName = self::mStr($posted, 'name', '');
        if ($id <= 0) {
            return '';
        }

        $r = $this->service->rename($id, $newName)->drainTo($this->collector);
        if (!$r->isOk()) {
            $this->flash->set('error', $this->t->t('media.admin.rename_failed'));
        } elseif ($r->unwrap() === false) {
            $this->flash->set('error', $this->t->t('media.admin.rename_taken'));
        } else {
            $this->flash->set('success', $this->t->t('media.admin.renamed'));
        }
        return '';
    }

    /** @param array<string,mixed> $posted */
    private function delete(array $posted): string
    {
        if ($this->gate->cannot(Permission::ADMIN_PAGES)) {
            $this->flash->set('error', $this->t->t('admin.forbidden'));
            return '';
        }

        $id = self::mInt($posted, 'id', 0);
        if ($id > 0) {
            $r = $this->service->delete($id)->drainTo($this->collector);
            if ($r->isOk() && $r->unwrap() === true) {
                $this->flash->set('success', $this->t->t('media.admin.deleted'));
            } else {
                $this->flash->set('error', $this->t->t('media.admin.delete_failed'));
            }
        }
        return '';
    }

    private function buildContext(string $selfUrl): void
    {
        $r    = $this->service->list()->drainTo($this->collector);
        $rows = $r->isOk() ? $r->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $url = $this->service->fileUrl($row['name']);
            $list[] = [
                'id'         => $row['id'],
                'name'       => $row['name'],
                'orig_name'  => $row['orig_name'] !== '' ? $row['orig_name'] : $row['name'],
                'mime'       => $row['mime'],
                'size'       => $this->formatSize($row['size']),
                'dims'       => $row['width'] > 0 && $row['height'] > 0
                    ? $row['width'] . '×' . $row['height']
                    : '',
                'url'        => $url,
                // A copy-ready Markdown image snippet for pasting into a content page.
                'embed'      => '![' . $row['orig_name'] . '](' . $url . ')',
                'created_at' => $row['created_at'],
                // The base name (without extension) pre-fills the rename field.
                'base'       => pathinfo($row['name'], PATHINFO_FILENAME),
            ];
        }
        $this->ctx->set('media',     $list);
        $this->ctx->set('has_media', $list !== []);

        $this->ctx->set('form_action', $selfUrl);
        $this->ctx->set('csrf_token',  $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',      $this->prg->createId($selfUrl));
        $this->ctx->set('accept',      $this->acceptAttr());

        $this->setLabels();
    }

    /** The file input's accept="" hint from the configured upload types. */
    private function acceptAttr(): string
    {
        // Static list — MediaConfig::uploadTypes() is constrained to the servable
        // image set; presenting them as MIME hints keeps the picker sensible.
        return 'image/jpeg,image/png,image/gif,image/webp';
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024)    { return $bytes . ' B'; }
        if ($bytes < 1048576) { return round($bytes / 1024, 1) . ' KB'; }
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'        => 'media.admin.heading',
            'lbl_intro'          => 'media.admin.intro',
            'lbl_upload_heading' => 'media.admin.upload_heading',
            'lbl_upload_file'    => 'media.admin.upload_file',
            'lbl_upload_hint'    => 'media.admin.upload_hint',
            'lbl_upload_btn'     => 'media.admin.upload_btn',
            'lbl_list_heading'   => 'media.admin.list_heading',
            'lbl_none'           => 'media.admin.none',
            'lbl_col_preview'    => 'media.admin.col_preview',
            'lbl_col_name'       => 'media.admin.col_name',
            'lbl_col_size'       => 'media.admin.col_size',
            'lbl_col_dims'       => 'media.admin.col_dims',
            'lbl_col_embed'      => 'media.admin.col_embed',
            'lbl_col_actions'    => 'media.admin.col_actions',
            'lbl_embed_hint'     => 'media.admin.embed_hint',
            'lbl_rename'         => 'media.admin.rename',
            'lbl_rename_btn'     => 'media.admin.rename_btn',
            'lbl_delete'         => 'media.admin.delete',
            'lbl_view'           => 'media.admin.view',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}
