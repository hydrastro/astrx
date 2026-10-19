<?php
declare(strict_types=1);

/**
 * Anonymous tip line — public page — en locale. Keys mirror the it counterpart
 * 1:1 (check_lang_parity.php). Loaded for the 'tipline' page.
 */
return [
    'tipline.heading'     => 'Anonymous tip line',
    'tipline.intro'       => 'Send a confidential message to the operators. It is encrypted to their key the moment it arrives and stored unreadable — no plaintext, IP, session or account is ever recorded. Only the offline private key can open it.',
    'tipline.message'     => 'Your message',
    'tipline.send'        => 'Send securely',
    'tipline.captcha'     => 'Type the characters shown',
    'tipline.bad_captcha' => 'The captcha did not match. Please try again.',
    'tipline.empty'       => 'The message was empty.',
    'tipline.failed'      => 'The tip could not be sealed. Please try again later.',
    'tipline.sent'        => 'Your message was sealed and delivered. Thank you.',
    'tipline.closed'      => 'The tip line is not currently accepting messages.',
];
