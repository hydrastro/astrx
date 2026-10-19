<?php
declare(strict_types=1);

/**
 * Email subject lines + any prose that lives outside templates.
 * Templates themselves are at resources/template/email/<kind>_{html,txt}.html
 * and use Mustache variables ({{username}}, {{site_name}}, {{link}}).
 *
 * Subject lines support {{var}} interpolation via EmailService::interpolate.
 */
return [
    'email.verify_account.subject'   => 'Verify your {{site_name}} account',
    'email.password_reset.subject'   => 'Reset your {{site_name}} password',
];
