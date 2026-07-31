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
                // Imageboard
                'board.view',
                'board.post',
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
                // Imageboard
                'board.view',
                'board.post',
                'board.delete.own',
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
                // R9: 'admin.config.mail' removed from MOD. The mail page configures
                // the SMTP relay host/credentials — i.e. WHERE password-reset tokens
                // are delivered — so a MOD holding it could repoint the relay to a
                // host they control, trigger an ADMIN password reset, and harvest the
                // token (full ADMIN takeover). Mail relay config is ADMIN-only now.
                'api.key.create',
                'api.key.revoke',
                // Chat
                'chat.view',
                'chat.post',
                'chat.delete.own',
                'chat.delete.any',
                'chat.moderate',
                'admin.config.chat',
                // Imageboard
                'board.view',
                'board.post',
                'board.delete.own',
                'board.moderate',
                'admin.config.imageboard',
            ],
        ],
    ],
];
