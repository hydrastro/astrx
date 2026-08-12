//! Optional HTTP Basic access control (stdlib only, default OFF).
//!
//! When the operator configures a credential, the whole server (browse, clone
//! and every endpoint) requires HTTP Basic auth. Passwords are never stored in
//! plaintext: a credential is `<user>:sha256$<salt>$<hex>` where
//! `hex = sha256(salt + password)`. Verification is constant-time on both the
//! username and the password digest, so neither a valid username nor a partial
//! password match can be discovered by timing.
//!
//! Nothing here shells out or touches the network; it is pure hashing/encoding.
//! A faithful port of the Python `gitweb.auth`, cross-checked byte-identical in
//! `tests/xcheck_auth.rs`.
//!
//! # Documented divergence
//!
//! [`hash_password`] takes the salt explicitly. Python's default
//! (`salt=None` → `secrets.token_hex(16)`) needs an entropy source, which lives
//! behind this crate's opt-in `rand` feature together with the `--hash-password`
//! CLI that is its only caller; the verifier format and digest are identical.

use crawlcore::hash::{sha256, to_hex};

use crate::pycompat::{split_whitespace_maxsplit, strip};

const SCHEME: &str = "sha256";

/// Return a `sha256$<salt>$<hex>` verifier for `password`.
///
/// This is the string an operator stores in `--auth`/`--auth-file` (never the
/// plaintext password). `salt` should be 16 random bytes rendered as hex.
#[must_use]
pub fn hash_password(password: &str, salt: &str) -> String {
    let mut buf = String::with_capacity(salt.len() + password.len());
    buf.push_str(salt);
    buf.push_str(password);
    format!("{SCHEME}${salt}${}", to_hex(&sha256(buf.as_bytes())))
}

/// Compare two byte strings without an early exit — `hmac.compare_digest`.
///
/// Like the CPython original, the *length* of the inputs is not a secret: a
/// length mismatch is folded into the accumulator rather than returned early,
/// so the comparison always touches every byte of the longer input.
fn compare_digest(a: &[u8], b: &[u8]) -> bool {
    // CPython's `_tscmp`: the loop count depends only on `len(b)`, and a length
    // mismatch compares `b` against itself with the accumulator pre-set to 1.
    let same_len = a.len() == b.len();
    let left: &[u8] = if same_len { a } else { b };
    let mut result: u8 = u8::from(!same_len);
    for i in 0..b.len() {
        result |= left[i] ^ b[i];
    }
    result == 0
}

/// Constant-time check of `password` against a stored `sha256$salt$hex`.
#[must_use]
pub fn verify_password(stored: &str, password: &str) -> bool {
    // Python: `stored.split("$", 2)` — exactly three fields, the last keeping
    // any further `$`.
    let mut it = stored.splitn(3, '$');
    let (Some(scheme), Some(salt), Some(digest)) = (it.next(), it.next(), it.next()) else {
        return false;
    };
    if scheme != SCHEME || digest.is_empty() {
        return false;
    }
    let mut buf = String::with_capacity(salt.len() + password.len());
    buf.push_str(salt);
    buf.push_str(password);
    let calc = to_hex(&sha256(buf.as_bytes()));
    compare_digest(calc.as_bytes(), digest.as_bytes())
}

/// A single username + stored password verifier.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Credential {
    /// The username the client must present.
    pub user: String,
    /// The `sha256$salt$hex` verifier.
    pub stored: String,
}

/// A malformed `--auth` spec. Reported (rather than ignored) so a typo fails
/// loudly at startup instead of silently disabling access control.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct AuthSpecError(&'static str);

impl AuthSpecError {
    /// The message, byte-identical to the Python `ValueError`'s.
    #[must_use]
    pub fn message(self) -> &'static str {
        self.0
    }
}

impl std::fmt::Display for AuthSpecError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.0)
    }
}

impl std::error::Error for AuthSpecError {}

/// Parse `user:sha256$salt$hex` into a [`Credential`].
///
/// An empty (or all-whitespace) spec means "no access control" and yields
/// `Ok(None)`; a present-but-malformed spec is an [`AuthSpecError`].
pub fn parse_auth_spec(spec: &str) -> Result<Option<Credential>, AuthSpecError> {
    const BAD_SPEC: AuthSpecError = AuthSpecError("auth spec must be 'user:sha256$salt$hex'");
    const BAD_PW: AuthSpecError = AuthSpecError("auth password must be 'sha256$salt$hex'");
    const BAD_PARTS: AuthSpecError =
        AuthSpecError("auth password must have a non-empty salt and hash");

    let spec = strip(spec);
    if spec.is_empty() {
        return Ok(None);
    }
    let Some((user, stored)) = spec.split_once(':') else {
        return Err(BAD_SPEC);
    };
    if user.is_empty() || stored.is_empty() {
        return Err(BAD_SPEC);
    }
    let parts: Vec<&str> = stored.split('$').collect();
    if parts.len() != 3 || parts[0] != SCHEME {
        return Err(BAD_PW);
    }
    // A present-but-empty salt or hash would parse yet lock everyone out
    // (`verify_password` rejects an empty digest), silently bricking access
    // control. Fail loudly at parse time instead.
    if parts[1].is_empty() || parts[2].is_empty() {
        return Err(BAD_PARTS);
    }
    Ok(Some(Credential {
        user: user.to_string(),
        stored: stored.to_string(),
    }))
}

/// Python `base64.b64decode(s, validate=True)`: `None` where CPython raises
/// `binascii.Error`.
///
/// `validate=True` first rejects anything outside `[A-Za-z0-9+/]*={0,2}`; the
/// decoder then rejects a data-character count of `4k+1` and any `4k+2`/`4k+3`
/// group that is not brought up to a full quad by padding. Trailing `=` on an
/// already-complete quad is ignored, exactly as CPython's non-strict decoder
/// does (`b64decode("QUJD=", validate=True) == b"ABC"`).
fn b64decode_validate(s: &str) -> Option<Vec<u8>> {
    let b = s.as_bytes();
    let data_len = b.iter().take_while(|c| **c != b'=').count();
    let pads = b.len() - data_len;
    if pads > 2 || b[data_len..].iter().any(|c| *c != b'=') {
        return None;
    }
    let val = |c: u8| -> Option<u32> {
        match c {
            b'A'..=b'Z' => Some(u32::from(c - b'A')),
            b'a'..=b'z' => Some(u32::from(c - b'a') + 26),
            b'0'..=b'9' => Some(u32::from(c - b'0') + 52),
            b'+' => Some(62),
            b'/' => Some(63),
            _ => None,
        }
    };
    match data_len % 4 {
        0 => {}
        1 => return None,             // "cannot be 1 more than a multiple of 4"
        2 if pads < 2 => return None, // "Incorrect padding"
        3 if pads < 1 => return None, // "Incorrect padding"
        _ => {}
    }
    let mut out = Vec::with_capacity(data_len * 3 / 4);
    let mut acc: u32 = 0;
    let mut bits = 0u32;
    for &c in &b[..data_len] {
        acc = (acc << 6) | val(c)?;
        bits += 6;
        if bits >= 8 {
            bits -= 8;
            out.push(((acc >> bits) & 0xff) as u8);
        }
    }
    Some(out)
}

/// True if an `Authorization: Basic …` header matches `cred`.
///
/// Both the username and the password digest are compared in constant time and
/// always evaluated (no short-circuit), so a wrong username cannot be told from
/// a wrong password by timing. A non-ASCII username is denied, never an error.
#[must_use]
pub fn check_basic_auth(header: Option<&str>, cred: &Credential) -> bool {
    let Some(header) = header.filter(|h| !h.is_empty()) else {
        return false;
    };
    let parts = split_whitespace_maxsplit(header, 1);
    if parts.len() != 2 || parts[0].to_lowercase() != "basic" {
        return false;
    }
    let Some(raw) = b64decode_validate(strip(parts[1])) else {
        return false;
    };
    let decoded = String::from_utf8_lossy(&raw);
    let Some((user, password)) = decoded.split_once(':') else {
        return false;
    };
    // Compare on UTF-8 *bytes*: the username is attacker-controlled, so a
    // crafted non-ASCII username must return false (denied), never raise.
    let user_ok = compare_digest(user.as_bytes(), cred.user.as_bytes());
    let pw_ok = verify_password(&cred.stored, password);
    user_ok && pw_ok
}
