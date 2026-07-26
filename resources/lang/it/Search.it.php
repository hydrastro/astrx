<?php
declare(strict_types=1);

/**
 * Ricerca globale del sito — locale it.
 *
 * Caricato da SiteSearchController tramite loadDomain(langDir(), 'Search').
 * Le chiavi rispecchiano 1:1 la controparte en (check_lang_parity.php).
 */
return [
    'search.heading'             => 'Cerca',
    'search.query_label'         => 'Termini di ricerca',
    'search.submit'              => 'Cerca',
    'search.type_label'          => 'Cerca in',
    'search.no_results'          => 'Nessun risultato trovato.',
    'search.result_count'        => '{count} risultato/i',

    // Opzioni del menu a tendina per il filtro tipo.
    'search.type_option.all'      => 'Tutto',
    'search.type_option.news'     => 'Notizie',
    'search.type_option.pages'    => 'Pagine',
    'search.type_option.comments' => 'Commenti',
    'search.type_option.board'    => 'Post delle board',

    // Etichette per tipo di risultato (il badge accanto a ogni risultato).
    'search.type.news'     => 'Notizia',
    'search.type.pages'    => 'Pagina',
    'search.type.comments' => 'Commento',
    'search.type.board'    => 'Post',
];
