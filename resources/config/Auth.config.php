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
            ],
        ],
    ],
];
