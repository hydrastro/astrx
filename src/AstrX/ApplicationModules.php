<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Blocklist\BlocklistModule;
use AstrX\BotTrap\BotTrapModule;
use AstrX\Canary\CanaryModule;
use AstrX\Chat\ChatModule;
use AstrX\Content\ContentModule;
use AstrX\Downloads\DownloadsModule;
use AstrX\FederatedSearch\FederatedSearchModule;
use AstrX\GitBrowse\GitBrowseModule;
use AstrX\Imageboard\ImageboardModule;
use AstrX\Media\MediaModule;
use AstrX\Mirrors\MirrorsModule;
use AstrX\OnionSearch\OnionSearchModule;
use AstrX\Search\SearchModule;
use AstrX\SuiteAdmin\SuiteAdminModule;
use AstrX\Tipline\TiplineModule;
use AstrX\TorrentSearch\TorrentSearchModule;
use AstrX\WebSearch\WebSearchModule;
use AstrX\Webmail\WebmailModule;

/**
 * The optional modules this website installs (sibling of ApplicationPolicies
 * and ApplicationPermissions).
 *
 * This list is the only place a module becomes part of the application:
 * nothing scans directories. Being listed means "installed"; whether it is
 * switched on is resources/config/Modules.config.php's call (a module with no
 * flag there is OFF). Removing a module from this list removes its pages,
 * navigation, policies, permissions and controllers from the application, but
 * not its data (tools/module.php purge does that, deliberately).
 *
 * @see \AstrX\Module\ModuleRegistry
 */
final class ApplicationModules
{
    /** @var list<class-string<Module\Module>> */
    public const array INSTALLED = [
        BlocklistModule::class,
        BotTrapModule::class,
        CanaryModule::class,
        ChatModule::class,
        ContentModule::class,
        DownloadsModule::class,
        FederatedSearchModule::class,
        GitBrowseModule::class,
        ImageboardModule::class,
        MediaModule::class,
        MirrorsModule::class,
        OnionSearchModule::class,
        SearchModule::class,
        SuiteAdminModule::class,
        TiplineModule::class,
        TorrentSearchModule::class,
        WebSearchModule::class,
        WebmailModule::class,
    ];
}
