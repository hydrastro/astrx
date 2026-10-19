# astrx/library

Building blocks of [AstrX](https://github.com/hydrastro/astrx), on top of
`astrx/framework`:

- **Database** — the PDO connection factory and its diagnostics.
- **Auth** — the authorization gate, permissions and policies.
- **Api** — API key authentication.
- **Captcha** — image captchas rendered without JavaScript.
- **Image** — upload validation and re-encoding.
- **Identicon** — generated avatars.
- **Mail** — SMTP and IMAP clients and mailbox management.

`resources/` holds the captcha partial, the captcha catalog, these components'
diagnostic messages (en, it) and their default configuration.

```sh
composer require astrx/library
```

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/library` directory.
