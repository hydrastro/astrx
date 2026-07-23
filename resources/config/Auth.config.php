<?php
declare(strict_types=1);

return [
    'Gate' => [
        'grants' => [
            'ADMIN' => [
                '*',
            ],
            'GUEST' => [
                'news.view',
                'comment.post',
                'comment.flag',
                'user.view.public',
                // Chat
                'chat.view',
                'chat.post',
                'chat.pm',
            ],
            'USER' => [
                'news.view',
                'news.create',
                'news.edit.any',
                'comment.post',
                'comment.hide.own',
                'comment.delete.own',
                'comment.flag',
                'user.view.public',
                'user.edit.own',
                'user.delete.own',
                'webmail.access',
                'webmail.send',
                // API key management (fix103) — default: users can manage their own keys.
                // Remove these two lines to lock the API to admin-provisioned keys only.
                'api.key.create',
                'api.key.revoke',
                // Chat
                'chat.view',
                'chat.post',
                'chat.delete.own',
                'chat.pm',
            ],
            'MOD' => [
                'news.view',
                'news.create',
                'comment.post',
                'comment.hide.own',
                'comment.hide.any',
                'comment.delete.own',
                'comment.delete.any',
                'comment.flag',
                'user.view.public',
                'user.edit.own',
                'user.delete.own',
                'admin.access',
                'admin.comments',
                'admin.config.captcha',
                'admin.config.users',
                'admin.config.mail',
                'api.key.create',
                'api.key.revoke',
                // Chat
                'chat.view',
                'chat.post',
                'chat.delete.own',
                'chat.delete.any',
                'chat.moderate',
                'admin.config.chat',
            ],
        ],
    ],
];
