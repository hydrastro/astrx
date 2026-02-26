<?php
declare(strict_types=1);

return [
    'foo.invalid_dereference' => 'Invalid dereference: value={value}',
    'foo.welcome' => 'Welcome, {name}!',
    'foo.generic_emails_message' => function(array $vars): string {
        $n = (int)($vars['EMAILS_NUMBER'] ?? 0);
        return match ($n) {
            0 => 'Non hai ricevuto alcuna email.',
            1 => 'Hai ricevuto una email.',
            default => 'Hai ricevuto {EMAILS_NUMBER} email.',
        };
    },
];