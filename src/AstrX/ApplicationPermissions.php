<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Admin\AdminPermission;
use AstrX\Api\ApiPermission;
use AstrX\Auth\PermissionRegistry;
use AstrX\Comment\CommentPermission;
use AstrX\Module\ModuleRegistry;
use AstrX\News\NewsPermission;
use AstrX\Retention\RetentionPermission;
use AstrX\User\UserPermission;

/**
 * Application-level permission wiring (sibling of ApplicationPolicies).
 *
 * The security layer does not know which features exist. The always-on
 * features' permission enums are listed here; optional modules declare theirs
 * in their manifest's `permissions` key.
 */
final class ApplicationPermissions
{
    /** @var list<class-string<Auth\Permission>> */
    public const array CORE = [
        AdminPermission::class,
        ApiPermission::class,
        CommentPermission::class,
        NewsPermission::class,
        RetentionPermission::class,
        UserPermission::class,
    ];

    /**
     * Every permission: CORE plus EVERY module manifest's enums, enabled or
     * not (see PermissionRegistry for why disabled modules must be included).
     */
    public static function registry(ModuleRegistry $modules): PermissionRegistry
    {
        return new PermissionRegistry([...self::CORE, ...$modules->permissionEnums()]);
    }
}
