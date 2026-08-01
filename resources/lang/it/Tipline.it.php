<?php
declare(strict_types=1);

/**
 * Linea di segnalazione anonima — pagina pubblica — locale it.
 * Le chiavi rispecchiano 1:1 la controparte en (check_lang_parity.php).
 */
return [
    'tipline.heading'     => 'Linea di segnalazione anonima',
    'tipline.intro'       => 'Invia un messaggio riservato agli operatori. Viene cifrato con la loro chiave nel momento in cui arriva e conservato illeggibile — nessun testo in chiaro, IP, sessione o account viene mai registrato. Solo la chiave privata offline può aprirlo.',
    'tipline.message'     => 'Il tuo messaggio',
    'tipline.send'        => 'Invia in modo sicuro',
    'tipline.captcha'     => 'Digita i caratteri mostrati',
    'tipline.bad_captcha' => 'Il captcha non corrisponde. Riprova.',
    'tipline.empty'       => 'Il messaggio era vuoto.',
    'tipline.failed'      => 'Impossibile sigillare la segnalazione. Riprova più tardi.',
    'tipline.sent'        => 'Il tuo messaggio è stato sigillato e consegnato. Grazie.',
    'tipline.closed'      => 'La linea di segnalazione al momento non accetta messaggi.',
];
