<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Module\ModuleRegistry;

/**
 * Which controller serves a page: an explicit map from `page.file_name` to a
 * controller class.
 *
 * Replaces deriving the class name from the page row
 * ('board_mod' → AstrX\Controller\BoardModController). That derivation tied
 * every controller to one application namespace, so no feature could own its
 * HTTP layer, and it let a database value choose the class name. Now:
 *
 *   - optional modules declare their controllers in their manifest's
 *     `controllers` key (packages/modules/<key>/<Module>Module.php);
 *   - always-on features and this site's own pages are listed in CORE.
 *
 * Lookup is equivalent to the old derivation for every class that exists:
 * keys are compared lowercase with underscores removed, which is exactly the
 * equivalence the old `ucwords()`/`str_replace('_', '')` + case-insensitive
 * class lookup gave ('Board_Mod', 'boardmod' and 'board_mod' all reach
 * BoardModController). A file_name with no entry has no controller (the page
 * gets the controller-missing 404, as before).
 *
 * Disabled modules' controllers stay in the map on purpose: whether a module's
 * page may be served is ModulePageGuard's decision, made before dispatch, and
 * the entry gate still has to read #[RequiresPermission] off the class.
 */
final class ControllerMap
{
    /**
     * Controllers of always-on features and of this site.
     *
     * @var array<string, class-string>
     */
    public const array CORE = [
        // This site's own pages (application layer)
        'comment' => \AstrX\Site\Controller\CommentController::class,
        'error'   => \AstrX\Site\Controller\ErrorController::class,
        'exit'    => \AstrX\Site\Controller\ExitController::class,
        'js'      => \AstrX\Site\Controller\JsController::class,
        'main'    => \AstrX\Site\Controller\MainController::class,
        'sitemap' => \AstrX\Site\Controller\SitemapController::class,
        'user'    => \AstrX\Site\Controller\UserController::class,
        // Accounts
        'avatar'        => \AstrX\User\Controller\AvatarController::class,
        'login'         => \AstrX\User\Controller\LoginController::class,
        'logout'        => \AstrX\User\Controller\LogoutController::class,
        'profile'       => \AstrX\User\Controller\ProfileController::class,
        'recover'       => \AstrX\User\Controller\RecoverController::class,
        'register'      => \AstrX\User\Controller\RegisterController::class,
        'twofa'         => \AstrX\User\Controller\TwofaController::class,
        'twofactor'     => \AstrX\User\Controller\TwofactorController::class,
        'user_home'     => \AstrX\User\Controller\UserHomeController::class,
        'user_settings' => \AstrX\User\Controller\UserSettingsController::class,
        // Admin shell and site-wide settings
        'admin'                => \AstrX\Admin\Controller\AdminController::class,
        'admin_audit_log'      => \AstrX\Admin\Controller\AdminAuditLogController::class,
        'admin_banlist'        => \AstrX\Admin\Controller\AdminBanlistController::class,
        'admin_config_access'  => \AstrX\Admin\Controller\AdminConfigAccessController::class,
        'admin_config_captcha' => \AstrX\Admin\Controller\AdminConfigCaptchaController::class,
        'admin_config_mail'    => \AstrX\Admin\Controller\AdminConfigMailController::class,
        'admin_config_system'  => \AstrX\Admin\Controller\AdminConfigSystemController::class,
        'admin_invites'        => \AstrX\Admin\Controller\AdminInvitesController::class,
        'admin_language'       => \AstrX\Admin\Controller\AdminLanguageController::class,
        'admin_navbar'         => \AstrX\Admin\Controller\AdminNavbarController::class,
        'admin_notes'          => \AstrX\Admin\Controller\AdminNotesController::class,
        'admin_pages'          => \AstrX\Admin\Controller\AdminPagesController::class,
        'admin_panic'          => \AstrX\Admin\Controller\AdminPanicController::class,
        'admin_themes'         => \AstrX\Admin\Controller\AdminThemesController::class,
        'admin_users'          => \AstrX\Admin\Controller\AdminUsersController::class,
        // News
        'admin_news' => \AstrX\News\Controller\AdminNewsController::class,
        'feed'       => \AstrX\News\Controller\FeedController::class,
        // Comments
        'admin_comments' => \AstrX\Comment\Controller\AdminCommentsController::class,
        // Captcha
        'captcha_frame' => \AstrX\Captcha\Controller\CaptchaFrameController::class,
        'captcha_image' => \AstrX\Captcha\Controller\CaptchaImageController::class,
        // Retention
        'admin_retention' => \AstrX\Retention\Controller\AdminRetentionController::class,
    ];

    /** @var array<string, class-string> normalised key => controller class */
    private array $byKey = [];

    /** @param array<string, string> $entries page file_name => controller class */
    public function __construct(array $entries)
    {
        foreach ($entries as $fileName => $class) {
            $key = self::key($fileName);
            if (isset($this->byKey[$key]) && $this->byKey[$key] !== $class) {
                throw new \LogicException(
                    "Page '{$fileName}' is mapped to both {$this->byKey[$key]} and {$class}."
                );
            }
            /** @var class-string $class */
            $this->byKey[$key] = $class;
        }
    }

    /** The application's map: CORE plus every module manifest's `controllers`. */
    public static function forApplication(ModuleRegistry $registry): self
    {
        return new self(self::CORE + $registry->controllers());
    }

    /** Normalise a page file_name the way the old class-name derivation did. */
    public static function key(string $fileName): string
    {
        return strtolower(str_replace('_', '', $fileName));
    }

    /**
     * The controller class for a page, or null when none is mapped.
     *
     * @return class-string|null
     */
    public function classFor(string $fileName): ?string
    {
        return $this->byKey[self::key($fileName)] ?? null;
    }

    /** @return array<string, class-string> normalised key => controller class */
    public function all(): array
    {
        return $this->byKey;
    }
}
