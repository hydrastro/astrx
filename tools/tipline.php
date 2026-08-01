<?php
declare(strict_types=1);

/**
 * AstrX tip-line offline crypto helper — `php tools/tipline.php <command>`
 *
 * Deliberately STANDALONE: it pulls in no AstrX bootstrap, no database, no
 * autoloader — nothing but ext-sodium. Copy this one file to an offline machine
 * and run it there, so the tip-line SECRET key never has to exist on the public
 * server (whose compromise is exactly what the sealed-box design defends against).
 *
 *   keygen                       Generate a fresh sealed-box keypair. Paste the
 *                                printed PUBLIC key into admin → Tip line; keep
 *                                the SECRET key offline.
 *   decrypt <secret-key-file>    Read base64 ciphertext lines on stdin (one sealed
 *                                tip per line, as shown in the admin queue) and
 *                                print each decrypted message. The secret key is
 *                                read from the given file (base64), never echoed.
 *
 * Example:
 *   php tools/tipline.php keygen > keys.txt         # then split pub/secret
 *   php tools/tipline.php decrypt secret.key < tips.b64
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "ERROR: ext-sodium is required (php-sodium).\n");
    exit(1);
}

/** @var list<string> $argv */
$argv = $argv ?? [];
$cmd  = $argv[1] ?? 'help';

function tl_out(string $s): void { fwrite(STDOUT, $s); }
function tl_err(string $s): void { fwrite(STDERR, $s); }

/** @return never */
function tl_fail(string $msg): void
{
    tl_err("ERROR: {$msg}\n");
    exit(1);
}

function tl_usage(): void
{
    tl_out(
        "AstrX tip-line offline helper\n\n" .
        "  php tools/tipline.php keygen\n" .
        "  php tools/tipline.php decrypt <secret-key-file>   (ciphertext on stdin)\n"
    );
}

switch ($cmd) {
    case 'keygen':
        $kp  = sodium_crypto_box_keypair();
        $pub = sodium_crypto_box_publickey($kp);
        $sec = sodium_crypto_box_secretkey($kp);
        tl_out("# AstrX tip-line keypair\n");
        tl_out("# PUBLIC KEY — paste into admin -> Tip line:\n");
        tl_out(base64_encode($pub) . "\n\n");
        tl_out("# SECRET KEY — keep OFFLINE, never on the server:\n");
        tl_out(base64_encode($sec) . "\n");
        sodium_memzero($sec);
        sodium_memzero($kp);
        exit(0);

    case 'decrypt':
        $file = $argv[2] ?? '';
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            tl_fail('provide a readable secret-key file: decrypt <secret-key-file>');
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            tl_fail('could not read the secret-key file.');
        }
        $sec = base64_decode(trim($raw), true);
        if ($sec === false || strlen($sec) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            tl_fail('the secret-key file is not a valid base64 sealed-box secret key.');
        }
        $pub = sodium_crypto_box_publickey_from_secretkey($sec);
        $kp  = sodium_crypto_box_keypair_from_secretkey_and_publickey($sec, $pub);

        $n = 0; $ok = 0;
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $n++;
            $cipher = base64_decode($line, true);
            if ($cipher === false) {
                tl_out("----- tip {$n}: SKIPPED (not base64) -----\n");
                continue;
            }
            $plain = sodium_crypto_box_seal_open($cipher, $kp);
            if ($plain === false) {
                tl_out("----- tip {$n}: FAILED to open (wrong key or corrupt) -----\n");
                continue;
            }
            $ok++;
            tl_out("----- tip {$n} -----\n{$plain}\n\n");
        }
        sodium_memzero($sec);
        sodium_memzero($kp);
        tl_out("# {$ok}/{$n} decrypted\n");
        exit(0);

    case 'help':
    default:
        tl_usage();
        exit($cmd === 'help' ? 0 : 2);
}
