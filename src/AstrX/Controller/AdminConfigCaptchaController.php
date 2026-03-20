<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Captcha\CaptchaRenderer;
use AstrX\Captcha\CaptchaType;
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
 * Admin — Captcha configuration editor.
 *
 * Two sections:
 *   1. CaptchaService  — expiration (token TTL)
 *   2. CaptchaRenderer — all visual/difficulty settings
 *
 * Live preview: after loading the page, the template renders a live captcha
 * image using the CURRENT config so you can see the effect of changes.
 * The preview is regenerated on every page load (GET).
 *
 * A "preview only" POST action (section='preview') lets the template submit
 * temporary settings and re-render without saving — giving a real-time
 * preview before committing. The response redirects back with ?preview=1
 * and the preview image is stored in session flash.
 *
 * Writes Captcha.config.php atomically.
 */
final class AdminConfigCaptchaController extends AbstractController
{
    private const FORM = 'admin_config_captcha';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly Config                $config,
        private readonly ConfigWriter          $writer,
        private readonly CaptchaRenderer       $renderer,
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
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_CAPTCHA)) {
            http_response_code(403);
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $section = $this->processForm($prgToken);
            $qs = $section === 'preview' ? '?preview=1' : '';
            Response::redirect($selfUrl . $qs)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext($selfUrl);
        return $this->ok();
    }

    // =========================================================================

    /** Returns the section name so handle() knows whether to append ?preview=1 */
    private function processForm(string $prgToken): string
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return '';
        }

        $section = (string) ($posted['section'] ?? '');

        if ($section === 'preview') {
            // Render a preview image with the posted settings WITHOUT saving.
            $previewB64 = $this->renderWithSettings($posted);
            $_SESSION['_captcha_preview'] = $previewB64;
            return 'preview';
        }

        $result = match ($section) {
            'service'  => $this->saveService($posted),
            'renderer' => $this->saveRenderer($posted),
            default    => null,
        };

        if ($result !== null) {
            $result->drainTo($this->collector);
            if ($result->isOk()) {
                $this->flash->set('success', $this->t->t('admin.config.saved'));
            }
        }
        return $section;
    }

    // ── Savers ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $p */
    private function saveService(array $p): Result
    {
        return $this->writer->write('Captcha', array_merge(
            $this->loadFullCaptchaConfig(),
            ['CaptchaService' => [
                'captcha_expiration' => max(60, (int) ($p['captcha_expiration'] ?? 600)),
            ]]
        ));
    }

    /** @param array<string, mixed> $p */
    private function saveRenderer(array $p): Result
    {
        return $this->writer->write('Captcha', array_merge(
            $this->loadFullCaptchaConfig(),
            ['CaptchaRenderer' => $this->rendererArrayFrom($p)]
        ));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Render a preview captcha using values from a posted form array.
     * Applies the settings to a fresh CaptchaRenderer clone, renders, returns base64.
     * @param array<string, mixed> $p
     */
    private function renderWithSettings(array $p): string
    {
        $cfg      = $this->rendererArrayFrom($p);
        $renderer = clone $this->renderer;

        // Apply each config key to the renderer using the Config mechanism.
        // We use a temporary in-memory Config is not available here, so we
        // directly call the setters via reflection — same as Config::applyModuleConfig.
        $rc = new \ReflectionObject($renderer);
        foreach ($rc->getMethods() as $method) {
            $attrs = $method->getAttributes(\AstrX\Config\InjectConfig::class);
            if ($attrs === []) { continue; }
            $key = $attrs[0]->newInstance()->key;
            if (array_key_exists($key, $cfg)) {
                $method->invoke($renderer, $cfg[$key]);
            }
        }

        // Use a simple test string as the preview text.
        $text = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0,
                       max(1, (int) ($cfg['captcha_length'] ?? 5)));
        return $renderer->render($text);
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function rendererArrayFrom(array $p): array
    {
        return [
            'image_width'                 => max(1, (int) ($p['image_width']  ?? 1)),
            'image_height'                => max(1, (int) ($p['image_height'] ?? 1)),
            'background_color'            => $this->sanitiseHex((string) ($p['background_color'] ?? '000000')),
            'text_color'                  => $this->sanitiseHex((string) ($p['text_color']       ?? 'ffffff')),
            'lines_color'                 => $this->sanitiseHex((string) ($p['lines_color']      ?? 'ffffff')),
            'dots_color'                  => $this->sanitiseHex((string) ($p['dots_color']       ?? 'ffffff')),
            'text_color_random'           => !empty($p['text_color_random']),
            'lines_color_random'          => !empty($p['lines_color_random']),
            'dots_color_random'           => !empty($p['dots_color_random']),
            'lines_start_from_border'     => !empty($p['lines_start_from_border']),
            'lines_number'                => max(0, (int) ($p['lines_number'] ?? 10)),
            'dots_number'                 => max(0, (int) ($p['dots_number']  ?? 100)),
            'char_list'                   => trim((string) ($p['char_list']  ?? '')),
            'captcha_length'              => max(1, (int) ($p['captcha_length'] ?? 5)),
            'captcha_type'                => (int) ($p['captcha_type'] ?? CaptchaType::MEDIUM->value),
            'font_size'                   => max(8, (int) ($p['font_size'] ?? 20)),
            'font_file'                   => trim((string) ($p['font_file'] ?? '')),
            'font_min_distance'           => (int) ($p['font_min_distance'] ?? 0),
            'font_max_distance'           => (int) ($p['font_max_distance'] ?? 10),
            'font_min_angle'              => (int) ($p['font_min_angle'] ?? -45),
            'font_max_angle'              => (int) ($p['font_max_angle'] ?? 45),
            'font_x_border'               => max(0, (int) ($p['font_x_border'] ?? 5)),
            'font_y_border'               => max(0, (int) ($p['font_y_border'] ?? 5)),
            'trace_line_color'            => $this->sanitiseHex((string) ($p['trace_line_color'] ?? 'ff0000')),
            'non_captcha_char_number'     => max(0, (int) ($p['non_captcha_char_number'] ?? 5)),
            'use_border_linear_randomness'=> !empty($p['use_border_linear_randomness']),
            'max_rounds_number'           => max(100, (int) ($p['max_rounds_number'] ?? 5000)),
        ];
    }

    private function sanitiseHex(string $v): string
    {
        $v = ltrim(trim($v), '#');
        return preg_match('/^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : '000000';
    }

    /** @return array<string, array<string, mixed>> */
    private function loadFullCaptchaConfig(): array
    {
        $path = (defined('CONFIG_DIR') ? CONFIG_DIR : '') . 'Captcha.config.php';
        if (!is_file($path)) { return []; }
        $loaded = @include $path;
        return is_array($loaded) ? $loaded : [];
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(string $selfUrl): void
    {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        // Live preview: render with current config.
        $previewText = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0,
                              max(1, (int) $this->config->getConfig('CaptchaRenderer', 'captcha_length', 5)));
        $previewB64  = $this->renderer->render($previewText);

        // Replace with preview image from a recent preview-only POST.
        if (isset($_SESSION['_captcha_preview']) && is_string($_SESSION['_captcha_preview'])) {
            $previewB64 = $_SESSION['_captcha_preview'];
            unset($_SESSION['_captcha_preview']);
        }

        // Difficulty options
        $currentType = (int) $this->config->getConfig('CaptchaRenderer', 'captcha_type', CaptchaType::MEDIUM->value);
        $typeOptions = [];
        foreach (CaptchaType::cases() as $type) {
            $typeOptions[] = [
                'value'    => $type->value,
                'label'    => $type->name,
                'selected' => $type->value === $currentType,
            ];
        }

        $this->ctx->set('csrf_token',    $csrfToken);
        $this->ctx->set('prg_id',        $prgId);
        $this->ctx->set('preview_image', $previewB64);
        $this->ctx->set('preview_text',  $previewText);
        $this->ctx->set('type_options',  $typeOptions);

        // Service
        $this->ctx->set('cfg_captcha_expiration', (int) $this->config->getConfig('CaptchaService', 'captcha_expiration', 600));

        // Renderer
        foreach ([
                     'image_width', 'image_height',
                     'background_color', 'text_color', 'lines_color', 'dots_color',
                     'text_color_random', 'lines_color_random', 'dots_color_random',
                     'lines_start_from_border', 'lines_number', 'dots_number',
                     'char_list', 'captcha_length', 'captcha_type',
                     'font_size', 'font_file',
                     'font_min_distance', 'font_max_distance',
                     'font_min_angle', 'font_max_angle',
                     'font_x_border', 'font_y_border',
                     'trace_line_color', 'non_captcha_char_number',
                     'use_border_linear_randomness', 'max_rounds_number',
                 ] as $key) {
            $this->ctx->set('cfg_' . $key, $this->config->getConfig('CaptchaRenderer', $key, null));
        }

        $this->setI18n();
    }

    private function setI18n(): void
    {
        $this->ctx->set('heading',                          $this->t->t('admin.config.captcha.heading'));
        $this->ctx->set('section_service',                  $this->t->t('admin.config.captcha.service'));
        $this->ctx->set('section_renderer',                 $this->t->t('admin.config.captcha.renderer'));
        $this->ctx->set('section_preview',                  $this->t->t('admin.config.captcha.preview'));
        $this->ctx->set('label_captcha_expiration',         $this->t->t('admin.config.field.captcha_expiration'));
        $this->ctx->set('label_image_width',                $this->t->t('admin.config.field.image_width'));
        $this->ctx->set('label_image_height',               $this->t->t('admin.config.field.image_height'));
        $this->ctx->set('label_background_color',           $this->t->t('admin.config.field.background_color'));
        $this->ctx->set('label_text_color',                 $this->t->t('admin.config.field.text_color'));
        $this->ctx->set('label_lines_color',                $this->t->t('admin.config.field.lines_color'));
        $this->ctx->set('label_dots_color',                 $this->t->t('admin.config.field.dots_color'));
        $this->ctx->set('label_text_color_random',          $this->t->t('admin.config.field.text_color_random'));
        $this->ctx->set('label_lines_color_random',         $this->t->t('admin.config.field.lines_color_random'));
        $this->ctx->set('label_dots_color_random',          $this->t->t('admin.config.field.dots_color_random'));
        $this->ctx->set('label_lines_start_from_border',    $this->t->t('admin.config.field.lines_start_from_border'));
        $this->ctx->set('label_lines_number',               $this->t->t('admin.config.field.lines_number'));
        $this->ctx->set('label_dots_number',                $this->t->t('admin.config.field.dots_number'));
        $this->ctx->set('label_char_list',                  $this->t->t('admin.config.field.char_list'));
        $this->ctx->set('label_captcha_length',             $this->t->t('admin.config.field.captcha_length'));
        $this->ctx->set('label_captcha_type',               $this->t->t('admin.config.field.captcha_type'));
        $this->ctx->set('label_font_size',                  $this->t->t('admin.config.field.font_size'));
        $this->ctx->set('label_font_file',                  $this->t->t('admin.config.field.font_file'));
        $this->ctx->set('label_font_min_distance',          $this->t->t('admin.config.field.font_min_distance'));
        $this->ctx->set('label_font_max_distance',          $this->t->t('admin.config.field.font_max_distance'));
        $this->ctx->set('label_font_min_angle',             $this->t->t('admin.config.field.font_min_angle'));
        $this->ctx->set('label_font_max_angle',             $this->t->t('admin.config.field.font_max_angle'));
        $this->ctx->set('label_font_x_border',              $this->t->t('admin.config.field.font_x_border'));
        $this->ctx->set('label_font_y_border',              $this->t->t('admin.config.field.font_y_border'));
        $this->ctx->set('label_trace_line_color',           $this->t->t('admin.config.field.trace_line_color'));
        $this->ctx->set('label_non_captcha_char_number',    $this->t->t('admin.config.field.non_captcha_char_number'));
        $this->ctx->set('label_use_border_linear_randomness', $this->t->t('admin.config.field.use_border_linear_randomness'));
        $this->ctx->set('label_max_rounds_number',          $this->t->t('admin.config.field.max_rounds_number'));
        $this->ctx->set('btn_save',                         $this->t->t('admin.btn.save'));
        $this->ctx->set('btn_preview',                      $this->t->t('admin.config.captcha.btn_preview'));
    }
}