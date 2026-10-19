<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.chat/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred in the chat. Please try again.',

    'astrx.chat/gate_denied' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You are not allowed to do that.',

    'astrx.chat/empty' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Your message cannot be empty.',

    'astrx.chat/too_long' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Your message is too long.',

    'astrx.chat/flood' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You are posting too fast. Please wait a moment.',

    'astrx.chat/muted' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You are muted and cannot post right now.',

    'astrx.chat/room_forbidden' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You do not have access to that room.',

    'astrx.chat/room_not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That room does not exist.',

    'astrx.chat/nick_invalid' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Please choose a valid nickname (2–32 letters, digits, spaces).',

    'astrx.chat/entry_password' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Wrong entry password.',

    'astrx.chat/not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Message not found.',

    'astrx.chat/kicked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You have been kicked from the chat.',

    'astrx.chat/banned' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You are banned from the chat.',

    'astrx.chat/nick_taken' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That nickname is already taken. Please choose another.',

    'astrx.chat/censored' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Your message was blocked by the word filter.',

    'astrx.chat/filter_blocked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Your message was blocked by a chat filter.',

    'astrx.chat/filter_kicked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You were removed from the chat by a filter.',

    'astrx.chat/nick_blocked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That nickname is not allowed. Please choose another.',

    'astrx.chat/upload_disabled' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Image attachments are not enabled.',

    'astrx.chat/upload_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The file upload failed. Please try again.',

    'astrx.chat/upload_too_big' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That image is too large.',

    'astrx.chat/upload_type' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That file type is not allowed.',

    'astrx.chat/upload_invalid' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That file is not a valid image.',

    'astrx.chat/upload_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The image could not be saved.',

    'astrx.chat/pm_target' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That user is not currently in the chat.',

    'astrx.chat/not_in_chat' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You need to enter the chat first.',

    'astrx.chat/full' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The chat is full. Please try again later.',

    // Safety net for ChatService::opErr()'s default arm. Unreachable in normal
    // operation (every call site passes a known op), but kept so an unexpected
    // op renders a clean message instead of a bare diagnostic id.
    'astrx.chat/unknown' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Something went wrong in the chat. Please try again.',
];
