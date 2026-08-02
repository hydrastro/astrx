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
            ],
            'MOD' => [
                'news.view',
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
                // R12 (tightened MOD grant set): the four admin.config.* grants a
                // MOD used to hold were removed at the ROOT here. Every round found
                // one more system-level lever a MOD could reach through them
                // (R9 mail relay, R10 imageboard EXIF/IP + auth policy, R11 chat
                // image_embed + captcha DoS + storage dirs). The R9–R11 controller
                // gates still defend each lever individually (defense in depth);
                // removing the grants closes the whole class. A MOD keeps every
                // MODERATION power (comment.*, chat.moderate, board.moderate,
                // admin.comments) — only CONFIG of those subsystems is now
                // ADMIN-only. Removed: admin.config.captcha, admin.config.users,
                // admin.config.chat, admin.config.imageboard (mail was removed in R9).
                'api.key.create',
                'api.key.revoke',
                // Chat
                'chat.view',
                'chat.post',
                'chat.delete.own',
                'chat.delete.any',
                'chat.moderate',
                // Imageboard
                'board.view',
                'board.post',
                'board.moderate',
            ],
        ],
    ],
];
