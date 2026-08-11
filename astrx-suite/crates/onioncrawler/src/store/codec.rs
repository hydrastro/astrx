//! A tiny, self-describing binary codec for the store snapshot blob.
//!
//! The store's persistence unit is a single versioned blob (no database). Unlike
//! `torrentds`'s bencode snapshot, this store's rows are riddled with `REAL`
//! timestamps, so the codec carries native `f64` (as IEEE-754 bits) alongside
//! `i64`, length-prefixed UTF-8 strings and explicit optionals — every field
//! round-trips exactly. All reads are bounds-checked and fallible, so a
//! truncated or corrupt blob yields `None` from `Store::restore` rather than a
//! panic.

/// Append-only little-endian writer.
pub struct Writer {
    buf: Vec<u8>,
}

impl Writer {
    pub fn new() -> Self {
        Writer { buf: Vec::new() }
    }

    pub fn into_bytes(self) -> Vec<u8> {
        self.buf
    }

    pub fn u8(&mut self, x: u8) {
        self.buf.push(x);
    }

    pub fn i64(&mut self, x: i64) {
        self.buf.extend_from_slice(&x.to_le_bytes());
    }

    /// Write a length or count as an unsigned 64-bit value.
    pub fn len(&mut self, x: usize) {
        self.buf.extend_from_slice(&(x as u64).to_le_bytes());
    }

    pub fn f64(&mut self, x: f64) {
        self.buf.extend_from_slice(&x.to_bits().to_le_bytes());
    }

    pub fn bool(&mut self, x: bool) {
        self.buf.push(u8::from(x));
    }

    pub fn str(&mut self, s: &str) {
        self.len(s.len());
        self.buf.extend_from_slice(s.as_bytes());
    }

    pub fn opt_str(&mut self, s: &Option<String>) {
        match s {
            Some(v) => {
                self.u8(1);
                self.str(v);
            }
            None => self.u8(0),
        }
    }

    pub fn opt_f64(&mut self, x: Option<f64>) {
        match x {
            Some(v) => {
                self.u8(1);
                self.f64(v);
            }
            None => self.u8(0),
        }
    }

    pub fn opt_i64(&mut self, x: Option<i64>) {
        match x {
            Some(v) => {
                self.u8(1);
                self.i64(v);
            }
            None => self.u8(0),
        }
    }
}

/// Bounds-checked little-endian reader. Every accessor returns `None` on an
/// out-of-range read so a corrupt blob can never panic.
pub struct Reader<'a> {
    buf: &'a [u8],
    pos: usize,
}

impl<'a> Reader<'a> {
    pub fn new(buf: &'a [u8]) -> Self {
        Reader { buf, pos: 0 }
    }

    fn take(&mut self, n: usize) -> Option<&'a [u8]> {
        let end = self.pos.checked_add(n)?;
        let slice = self.buf.get(self.pos..end)?;
        self.pos = end;
        Some(slice)
    }

    pub fn u8(&mut self) -> Option<u8> {
        Some(self.take(1)?[0])
    }

    pub fn i64(&mut self) -> Option<i64> {
        let b = self.take(8)?;
        let mut a = [0u8; 8];
        a.copy_from_slice(b);
        Some(i64::from_le_bytes(a))
    }

    pub fn len(&mut self) -> Option<usize> {
        let b = self.take(8)?;
        let mut a = [0u8; 8];
        a.copy_from_slice(b);
        usize::try_from(u64::from_le_bytes(a)).ok()
    }

    pub fn f64(&mut self) -> Option<f64> {
        let b = self.take(8)?;
        let mut a = [0u8; 8];
        a.copy_from_slice(b);
        Some(f64::from_bits(u64::from_le_bytes(a)))
    }

    pub fn bool(&mut self) -> Option<bool> {
        Some(self.u8()? != 0)
    }

    pub fn str(&mut self) -> Option<String> {
        let n = self.len()?;
        let b = self.take(n)?;
        String::from_utf8(b.to_vec()).ok()
    }

    pub fn opt_str(&mut self) -> Option<Option<String>> {
        match self.u8()? {
            0 => Some(None),
            1 => Some(Some(self.str()?)),
            _ => None,
        }
    }

    pub fn opt_f64(&mut self) -> Option<Option<f64>> {
        match self.u8()? {
            0 => Some(None),
            1 => Some(Some(self.f64()?)),
            _ => None,
        }
    }

    pub fn opt_i64(&mut self) -> Option<Option<i64>> {
        match self.u8()? {
            0 => Some(None),
            1 => Some(Some(self.i64()?)),
            _ => None,
        }
    }
}
