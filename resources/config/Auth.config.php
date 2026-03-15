<?php
declare(strict_types=1);

use AstrX\Auth\Permission;

/**
 * Permission grants per user group.
 *
 * Keys are UserGroup case names (ADMIN, MOD, USER, GUEST).
 * Values are lists of permission patterns:
 *   '*'           — all permissions
 *   'comment.*'   — all permissions whose value starts with 'comment.'
 *   'news.view'   — exact match
 *
 * Principle of least privilege: start from the bottom (GUEST) and add upward.
 */
return [
    'Gate' => [
        'grants' => [
            'GUEST' => [
                Permission::NEWS_VIEW->value,
                Permission::COMMENT_POST->value,
                Permission::COMMENT_FLAG->value,
                Permission::USER_VIEW_PUBLIC->value,
            ],

            'USER' => [
                Permission::NEWS_VIEW->value,
                Permission::COMMENT_POST->value,
                Permission::COMMENT_FLAG->value,
                Permission::COMMENT_HIDE_OWN->value,
                Permission::COMMENT_DELETE_OWN->value,
                Permission::USER_VIEW_PUBLIC->value,
                Permission::USER_EDIT_OWN->value,
                Permission::USER_DELETE_OWN->value,
            ],

            'MOD' => [
                Permission::NEWS_VIEW->value,
                Permission::COMMENT_POST->value,
                Permission::COMMENT_FLAG->value,
                Permission::COMMENT_HIDE_OWN->value,
                Permission::COMMENT_HIDE_ANY->value,
                Permission::COMMENT_DELETE_OWN->value,
                Permission::COMMENT_DELETE_ANY->value,
                Permission::USER_VIEW_PUBLIC->value,
                Permission::USER_EDIT_OWN->value,
                Permission::USER_DELETE_OWN->value,
                // Mods get admin access to comments only
                Permission::ADMIN_ACCESS->value,
                Permission::ADMIN_COMMENTS->value,
            ],

            'ADMIN' => ['*'],  // full access
        ],
    ],
];
