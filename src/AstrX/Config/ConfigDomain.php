<?php
declare(strict_types=1);

namespace AstrX\Config;

use Attribute;

/**
 * Declares WHICH config section a class is configured from, and WHICH file that
 * section lives in.
 *
 * AstrX resolves a class's config section by convention: the class short name,
 * falling back to the immediate parent namespace segment when no
 * `{ShortName}.config.php` exists. 25 of the 38 declared sections have a name
 * that differs from the file they live in (e.g. section `ImapClient` is declared
 * in `Mail.config.php`, because `AstrX\Mail\ImapClient` reaches it through the
 * parent-namespace fallback). That convention is invisible at the call site, so
 * a writer had to re-derive it by hand — and got it wrong: the webmail admin
 * page read section `ImapClient` (from Mail.config.php) but persisted to
 * `Imap.config.php`, a file no code path ever loads. Every IMAP setting was
 * write-only, including the SOCKS5 host/port that route IMAP through Tor.
 *
 * This attribute makes the pairing explicit and machine-checkable, and BOTH ends
 * consume it:
 *   - {@see ConfigDomainResolver::forClass()} — the read side (ModuleLoader).
 *   - {@see ConfigWriter::write()}            — the write side (admin editors).
 *   - tools/check_config.php                  — CI, so the declaration cannot
 *     drift from the on-disk layout without failing the build.
 *
 * Repeatable: one class may own several sections (an admin editor that saves
 * both `ImapClient` and `WebmailService` declares both).
 *
 *   #[ConfigDomain('ImapClient', file: 'Mail')]   // Mail.config.php['ImapClient']
 *   #[ConfigDomain('Routing')]                    // Routing.config.php['Routing']
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ConfigDomain
{
    /**
     * @param string $section Top-level key inside the config array.
     * @param string $file    Config file base name, i.e. `{file}.config.php`.
     *                        Empty means "same as the section name".
     */
    public function __construct(
        public readonly string $section,
        public readonly string $file = '',
    ) {}

    /** The `{name}.config.php` base name this section is declared in. */
    public function fileBaseName(): string
    {
        return $this->file !== '' ? $this->file : $this->section;
    }
}
