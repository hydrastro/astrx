//! Cheap, dependency-free language guessing for indexed pages.
//!
//! A stop-word frequency heuristic over a handful of languages — not a
//! linguist-grade classifier, but a good-enough signal to power a language facet
//! and filter in search. Returns a 2-letter code or `"un"` (unknown).
//! Deterministic and stdlib-only so it can run at index time.
//!
//! Ported from the Python `lang.py`; cross-checked in `tests/xcheck_lang.rs`.
//! The Unicode tokenizer follows the Python `[^\W\d_]+` (letters only, digits
//! and `_` excluded) via [`char::is_alphabetic`]; on exotic codepoints the two
//! can differ, but for the language sets here (Latin + Cyrillic) they agree, and
//! this is a fuzzy heuristic by design.

/// `(code, stop-words)` in a fixed order. Scoring ties resolve to the earliest
/// entry here, which matches the Python dict's insertion order under a stable,
/// reverse sort. Overlap between the Romance languages is unavoidable, so
/// [`guess_lang`] requires both a minimum score and a margin over the runner-up.
const STOP: &[(&str, &[&str])] = &[
    (
        "en",
        &[
            "the", "and", "of", "to", "in", "is", "that", "it", "for", "was", "with", "as", "be",
            "this", "have", "are", "on", "or", "you", "not",
        ],
    ),
    (
        "es",
        &[
            "el", "la", "de", "que", "y", "en", "los", "un", "por", "con", "para", "una", "su",
            "las", "del", "se", "no", "es", "al", "lo",
        ],
    ),
    (
        "fr",
        &[
            "le", "la", "de", "et", "les", "des", "un", "une", "dans", "que", "pour", "sur", "pas",
            "plus", "ce", "il", "au", "est", "vous", "ne",
        ],
    ),
    (
        "de",
        &[
            "der", "die", "und", "den", "das", "von", "mit", "ist", "im", "ein", "nicht", "auch",
            "eine", "als", "auf", "sich", "dem", "zu", "wird",
        ],
    ),
    (
        "it",
        &[
            "di", "che", "la", "il", "un", "per", "con", "non", "una", "sono", "come", "ma", "se",
            "gli", "alla", "delle", "questo", "anche",
        ],
    ),
    (
        "pt",
        &[
            "de", "que", "os", "as", "um", "uma", "para", "com", "nao", "por", "mais", "dos",
            "das", "ao", "seu", "sua", "ou", "quando", "muito",
        ],
    ),
    (
        "ru",
        &[
            "и", "в", "не", "на", "что", "с", "по", "как", "это", "из", "за", "от", "для", "же",
            "бы", "он", "она", "мы", "вы", "то",
        ],
    ),
];

/// Need at least this many stop-word hits to guess at all.
const MIN_SCORE: i32 = 3;
/// The winner must beat the runner-up by at least this many hits.
const MIN_MARGIN: i32 = 2;

/// Tokenize into lowercased letter-runs (`[^\W\d_]+`, Unicode).
fn tokenize_lower(text: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut cur = String::new();
    for c in text.chars() {
        if c.is_alphabetic() {
            cur.extend(c.to_lowercase());
        } else if !cur.is_empty() {
            out.push(std::mem::take(&mut cur));
        }
    }
    if !cur.is_empty() {
        out.push(cur);
    }
    out
}

/// Guess the language of *text*; return a 2-letter code or `"un"` (unknown).
///
/// Requires at least *min_tokens* letter tokens (the Python default is 8) before
/// it will guess; below that, or when the best language does not clear the score
/// and margin thresholds, it returns `"un"`.
#[must_use]
pub fn guess_lang(text: &str, min_tokens: usize) -> &'static str {
    if text.is_empty() {
        return "un";
    }
    let tokens = tokenize_lower(text);
    if tokens.len() < min_tokens {
        return "un";
    }
    let mut scores: Vec<(&'static str, i32)> = STOP.iter().map(|&(c, _)| (c, 0)).collect();
    for tok in &tokens {
        for (idx, &(_, words)) in STOP.iter().enumerate() {
            if words.contains(&tok.as_str()) {
                scores[idx].1 += 1;
            }
        }
    }
    // Stable sort by score descending: ties keep the STOP order, matching the
    // Python `sorted(..., reverse=True)` over the insertion-ordered dict.
    // `sort_by_key` is a stable sort, so `Reverse` preserves that tie-break.
    scores.sort_by_key(|&(_, s)| std::cmp::Reverse(s));
    let (best_code, best_score) = scores[0];
    let runner_score = scores.get(1).map_or(0, |&(_, s)| s);
    if best_score < MIN_SCORE || (best_score - runner_score) < MIN_MARGIN {
        return "un";
    }
    best_code
}

/// The set of language codes this guesser knows, sorted.
#[must_use]
pub fn known_languages() -> Vec<&'static str> {
    let mut v: Vec<&'static str> = STOP.iter().map(|&(c, _)| c).collect();
    v.sort_unstable();
    v
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn short_or_empty_is_unknown() {
        assert_eq!(guess_lang("", 8), "un");
        assert_eq!(guess_lang("short text", 8), "un"); // < 8 tokens
    }

    #[test]
    fn known_languages_sorted() {
        assert_eq!(
            known_languages(),
            vec!["de", "en", "es", "fr", "it", "pt", "ru"]
        );
    }

    #[test]
    fn ambiguous_falls_back_to_unknown() {
        // No stop words at all → never clears MIN_SCORE.
        assert_eq!(guess_lang("aaa bbb ccc ddd eee fff ggg hhh", 8), "un");
    }
}
