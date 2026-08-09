"""Optional HTTP Basic access control (stdlib only, default OFF).

When the operator configures a credential, the whole server (browse, clone and
every endpoint) requires HTTP Basic auth.  Passwords are never stored in
plaintext: a credential is ``<user>:sha256$<salt>$<hex>`` where
``hex = sha256(salt + password)``.  Verification is constant-time
(:func:`hmac.compare_digest`) on both the username and the password digest, so
neither a valid username nor a partial password match can be discovered by
timing.

Nothing here shells out or touches the network; it is pure hashing/encoding.
"""

from __future__ import annotations

import base64
import binascii
import hashlib
import hmac
import secrets
from dataclasses import dataclass
from typing import Optional

_SCHEME = "sha256"


def hash_password(password: str, salt: Optional[str] = None) -> str:
    """Return a ``sha256$<salt>$<hex>`` verifier for ``password``.

    A random 16-byte salt is generated when one is not supplied.  This is the
    string an operator stores in ``--auth``/``--auth-file`` (never the plaintext
    password).
    """
    if salt is None:
        salt = secrets.token_hex(16)
    digest = hashlib.sha256((salt + password).encode("utf-8")).hexdigest()
    return f"{_SCHEME}${salt}${digest}"


def verify_password(stored: str, password: str) -> bool:
    """Constant-time check of ``password`` against a stored ``sha256$salt$hex``."""
    try:
        scheme, salt, digest = stored.split("$", 2)
    except ValueError:
        return False
    if scheme != _SCHEME or not digest:
        return False
    calc = hashlib.sha256((salt + password).encode("utf-8")).hexdigest()
    return hmac.compare_digest(calc, digest)


@dataclass
class Credential:
    """A single ``username`` + stored password verifier."""

    user: str
    stored: str  # sha256$salt$hex


def parse_auth_spec(spec: str) -> Optional[Credential]:
    """Parse ``user:sha256$salt$hex`` into a :class:`Credential` (or ``None``).

    Raises :class:`ValueError` when the spec is present but malformed, so a
    typo'd ``--auth`` fails loudly at startup rather than silently disabling
    access control.
    """
    spec = (spec or "").strip()
    if not spec:
        return None
    if ":" not in spec:
        raise ValueError("auth spec must be 'user:sha256$salt$hex'")
    user, stored = spec.split(":", 1)
    if not user or not stored:
        raise ValueError("auth spec must be 'user:sha256$salt$hex'")
    parts = stored.split("$")
    if len(parts) != 3 or parts[0] != _SCHEME:
        raise ValueError("auth password must be 'sha256$salt$hex'")
    _scheme, salt, digest = parts
    # A present-but-empty salt or hash would parse yet lock everyone out
    # (``verify_password`` rejects an empty digest), silently bricking access
    # control.  Fail loudly at parse time instead.
    if not salt or not digest:
        raise ValueError("auth password must have a non-empty salt and hash")
    return Credential(user=user, stored=stored)


def check_basic_auth(header: Optional[str], cred: Credential) -> bool:
    """True if an ``Authorization: Basic …`` header matches ``cred``.

    Both the username and the password digest are compared in constant time and
    always evaluated (no short-circuit), so a wrong username cannot be told from
    a wrong password by timing.
    """
    if not header:
        return False
    parts = header.split(None, 1)
    if len(parts) != 2 or parts[0].lower() != "basic":
        return False
    try:
        decoded = base64.b64decode(parts[1].strip(), validate=True).decode(
            "utf-8", "replace"
        )
    except (binascii.Error, ValueError):
        return False
    if ":" not in decoded:
        return False
    user, password = decoded.split(":", 1)
    # Compare on UTF-8 *bytes*: ``hmac.compare_digest`` raises ``TypeError`` for a
    # ``str`` containing non-ASCII, and the username is attacker-controlled, so a
    # crafted non-ASCII username must return False (denied), never raise (500).
    user_ok = hmac.compare_digest(user.encode("utf-8"), cred.user.encode("utf-8"))
    pw_ok = verify_password(cred.stored, password)
    return user_ok and pw_ok
