<?php
declare(strict_types=1);

/**
 * Admin — it: the Tipline module's keys (merged into the site's Admin catalog).
 */
return [
    // Linea di segnalazione cifrata
    'admin.tipline.heading'     => 'Linea di segnalazione anonima',
    'admin.tipline.intro'       => 'Imposta la chiave pubblica sealed-box con cui vengono cifrate le segnalazioni in arrivo. Le segnalazioni sono conservate solo come testo cifrato; la lettura avviene offline così la chiave segreta non tocca mai questo server.',
    'admin.tipline.pubkey'      => 'Chiave pubblica sealed-box (base64)',
    'admin.tipline.hint_keygen' => 'Genera una coppia di chiavi offline: php tools/tipline.php keygen. Incolla qui la chiave pubblica; tieni la chiave segreta fuori da questa macchina.',
    'admin.tipline.hint_decrypt'=> 'Per leggere le segnalazioni, esporta il testo cifrato ed esegui: php tools/tipline.php decrypt <file-chiave-segreta>. Non incollare mai la chiave segreta in un modulo web.',
    'admin.tipline.save'        => 'Salva chiave pubblica',
    'admin.tipline.saved'       => 'Chiave pubblica della linea salvata.',
    'admin.tipline.bad_pubkey'  => 'La chiave pubblica deve essere in base64 di una chiave sealed-box di 32 byte.',
    'admin.tipline.queue'       => 'Segnalazioni sigillate',
    'admin.tipline.time'        => 'Ricevuta',
    'admin.tipline.sealed'      => 'Testo cifrato (sigillato)',
    'admin.tipline.none'        => 'Nessuna segnalazione ricevuta finora.',
    'admin.tipline.delete'      => 'Elimina',
    'admin.tipline.deleted'     => 'Segnalazione eliminata.',
    'admin.tipline.purge'       => 'Distruggi tutte le segnalazioni',
    'admin.tipline.purged'      => 'Tutte le segnalazioni distrutte.',

    // Voce della conservazione dati
    'admin.retention.label.tipline' => 'Segnalazioni sigillate',
];
