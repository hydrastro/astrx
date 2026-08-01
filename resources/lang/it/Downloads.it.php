<?php
declare(strict_types=1);

/**
 * Download firmati / manifest di rilascio — pagina pubblica — locale it.
 * Le chiavi rispecchiano 1:1 la controparte en (check_lang_parity.php).
 */
return [
    'downloads.heading'      => 'Download',
    'downloads.intro'        => 'Il manifest qui sotto elenca ogni file rilasciato con il suo hash SHA-256. Verifica ciò che hai scaricato con questa lista e controlla la firma prima di fidarti.',
    'downloads.none'         => 'Nessun manifest di rilascio firmato è attualmente pubblicato.',
    'downloads.sig_valid'    => 'Firma VALIDA — questo manifest è stato firmato con la chiave dell operatore qui sotto.',
    'downloads.sig_invalid'  => 'Firma NON VALIDA — non fidarti di questo manifest. Non corrisponde alla chiave pubblicata.',
    'downloads.sig_unsigned' => 'Questo manifest non è firmato. Considera gli hash solo come informativi.',
    'downloads.pubkey_label' => 'Chiave di firma dell operatore (ED25519, base64)',
    'downloads.verify_hint'  => 'Verifica offline: calcola lo sha256sum dei file e confronta, poi verifica la firma staccata con la chiave pubblicata usando uno strumento di cui ti fidi.',
];
