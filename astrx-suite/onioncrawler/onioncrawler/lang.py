"""Cheap, dependency-free language guessing for indexed pages.

A stop-word frequency heuristic over a handful of languages. This is not a
linguist-grade classifier; it is a good-enough signal to power a language facet
and filter in search. Returns a 2-letter code or ``"un"`` (unknown).

Deterministic and stdlib-only so it can run at index time and be unit-tested.
"""

from __future__ import annotations

import re

# Small, distinctive stop-word sets. Overlap between the Romance languages is
# unavoidable; the heuristic requires a minimum score and a margin so ambiguous
# text falls back to 'un' rather than guessing confidently wrong.
_STOP = {
    "en": {"the", "and", "of", "to", "in", "is", "that", "it", "for", "was",
           "with", "as", "be", "this", "have", "are", "on", "or", "you", "not"},
    "es": {"el", "la", "de", "que", "y", "en", "los", "un", "por", "con",
           "para", "una", "su", "las", "del", "se", "no", "es", "al", "lo"},
    "fr": {"le", "la", "de", "et", "les", "des", "un", "une", "dans", "que",
           "pour", "sur", "pas", "plus", "ce", "il", "au", "est", "vous", "ne"},
    "de": {"der", "die", "und", "den", "das", "von", "mit", "ist", "im", "ein",
           "nicht", "auch", "eine", "als", "auf", "sich", "dem", "zu", "wird"},
    "it": {"di", "che", "la", "il", "un", "per", "con", "non", "una", "sono",
           "come", "ma", "se", "gli", "alla", "delle", "questo", "anche"},
    "pt": {"de", "que", "os", "as", "um", "uma", "para", "com", "nao", "por",
           "mais", "dos", "das", "ao", "seu", "sua", "ou", "quando", "muito"},
    "ru": {"и", "в", "не", "на", "что", "с", "по", "как", "это", "из", "за",
           "от", "для", "же", "бы", "он", "она", "мы", "вы", "то"},
}

_TOKEN = re.compile(r"[^\W\d_]+", re.UNICODE)

_MIN_SCORE = 3       # need at least this many stop-word hits to guess at all
_MIN_MARGIN = 2      # winner must beat runner-up by this many hits


def guess_lang(text: str, min_tokens: int = 8) -> str:
    """Guess the language of *text*; return a 2-letter code or ``"un"``."""
    if not text:
        return "un"
    tokens = [t.lower() for t in _TOKEN.findall(text)]
    if len(tokens) < min_tokens:
        return "un"
    scores = {code: 0 for code in _STOP}
    for tok in tokens:
        for code, words in _STOP.items():
            if tok in words:
                scores[code] += 1
    ranked = sorted(scores.items(), key=lambda kv: kv[1], reverse=True)
    best_code, best_score = ranked[0]
    runner_score = ranked[1][1] if len(ranked) > 1 else 0
    if best_score < _MIN_SCORE or (best_score - runner_score) < _MIN_MARGIN:
        return "un"
    return best_code


def known_languages():
    return sorted(_STOP.keys())
