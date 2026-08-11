#!/usr/bin/env python3
"""Emit byte-identical goldens for websearch htmlparse stage-2 by driving the
real Python extract(). Prints a Rust `CASES` array (html, serialized-Extracted)
ready to paste into tests/xcheck_htmlparse_stage2.rs, whose Rust `serialize()`
mirrors the `serialize()` here exactly."""
from websearch.htmlparse import extract


def esc(s):
    return (s.replace("\\", "\\\\").replace("\t", "\\t")
             .replace("\n", "\\n").replace("\r", "\\r"))


def serialize(e):
    L = []
    L.append("T\t" + esc(e.title))
    L.append("D\t" + esc(e.description))
    L.append("X\t" + esc(e.text))
    L.append("ROBOTS\t" + esc(e.meta_robots))
    L.append("LANG\t" + esc(e.lang or ""))
    L.append("CANON_NONE" if e.canonical is None else "CANON\t" + esc(e.canonical))
    L.append("BASE_NONE" if e.base_href is None else "BASE\t" + esc(e.base_href))
    for l in e.links:
        L.append("LINK\t" + esc(l))
    for im in e.images:
        L.append("IMG\t" + "\t".join(esc(x) for x in im))
    for v in e.videos:
        dur = "" if v["duration"] is None else str(v["duration"])
        L.append("VID\t" + "\t".join(esc(x) for x in [
            v["video_url"], v["embed_url"], v["watch_url"], v["title"],
            v["thumbnail"], v["source"], dur, v["context"]]))
    for k in e.og:
        L.append("OG\t" + esc(k) + "\t" + esc(e.og[k]))
    for k in e.twitter:
        L.append("TW\t" + esc(k) + "\t" + esc(e.twitter[k]))
    L.append("NCOUNT\t" + str(len(e.noscript_parts)))
    L.append("LDCOUNT\t" + str(len(e.ldjson_blobs)))
    L.append("STCOUNT\t" + str(len(e.state_blobs)))
    return "\n".join(L)


def rs(s):
    out = ['"']
    for ch in s:
        if ch == '\\':
            out.append('\\\\')
        elif ch == '"':
            out.append('\\"')
        elif ch == '\n':
            out.append('\\n')
        elif ch == '\t':
            out.append('\\t')
        elif ch == '\r':
            out.append('\\r')
        elif ord(ch) < 0x20:
            out.append('\\u{%x}' % ord(ch))
        else:
            out.append(ch)
    out.append('"')
    return ''.join(out)


LONG = ("This is a full length article body with plenty of real readable words "
        "so the static body comfortably exceeds the two hundred character thin "
        "threshold and recovery leaves it untouched entirely here today.")

CASES = [
    # 1. images: src, data-src fallback, alt/title, preceding context
    "<body><p>Some preceding words here.</p>"
    "<img src=\"/a.png\" alt=\"Alt A\" title=\"Title A\">"
    "<img data-src=\"/b.png\"><img alt=\"no src\"></body>",

    # 2. html5 video + multiple sources + poster, direct-media <a>
    "<body><video poster=\"/p.jpg\"><source src=\"/v1.mp4\"><source src=\"/v2.webm\">"
    "</video><a href=\"/clip.mov\">dl</a><video src=\"/direct.mp4\"></video></body>",

    # 3. iframes: youtube, vimeo, unknown (no video)
    "<body><iframe src=\"https://www.youtube.com/embed/dQw4w9WgXcQ\"></iframe>"
    "<iframe src=\"https://player.vimeo.com/video/12345\"></iframe>"
    "<iframe src=\"https://example.com/thing\"></iframe></body>",

    # 4. OpenGraph video + image cards + twitter player -> recovery videos
    "<html><head>"
    "<meta property=\"og:title\" content=\"OG Title\">"
    "<meta property=\"og:description\" content=\"OG Desc\">"
    "<meta property=\"og:video\" content=\"http://x/og.mp4\">"
    "<meta property=\"og:image\" content=\"http://x/og.jpg\">"
    "<meta name=\"twitter:player\" content=\"http://x/tw.html\">"
    "<meta name=\"twitter:title\" content=\"TW Title\">"
    "</head><body></body></html>",

    # 5. JSON-LD VideoObject (ISO duration, thumbnailUrl object)
    "<html><head><script type=\"application/ld+json\">"
    "{\"@type\":\"VideoObject\",\"name\":\"Cats\",\"embedUrl\":\"http://x/e\","
    "\"contentUrl\":\"http://x/v.mp4\",\"duration\":\"PT1M30S\","
    "\"thumbnailUrl\":{\"url\":\"http://x/t.jpg\"},\"description\":\"Fun cats\"}"
    "</script></head><body></body></html>",

    # 6. JSON-LD @graph: Article (articleBody) + ImageObject + numeric duration video
    "<html><head><script type=\"application/ld+json\">"
    "{\"@graph\":[{\"@type\":\"Article\",\"headline\":\"Head\",\"articleBody\":\"Graph body text.\"},"
    "{\"@type\":\"ImageObject\",\"contentUrl\":\"http://x/i.jpg\",\"caption\":\"Cap\"},"
    "{\"@type\":\"VideoObject\",\"name\":\"V\",\"contentUrl\":\"http://x/g.mp4\",\"duration\":95}]}"
    "</script></head><body></body></html>",

    # 7. thin body -> recover from og title/description only
    "<html><head><meta property=\"og:title\" content=\"Only OG\">"
    "<meta property=\"og:description\" content=\"Just a description here\">"
    "</head><body><p>tiny</p></body></html>",

    # 8. noscript recovery into a thin body
    "<html><body><noscript>This is the noscript fallback content that should be "
    "recovered into the body for a JS-only page.</noscript></body></html>",

    # 9. inline __NUXT__ state via a generic script scan
    "<html><body><script>window.__NUXT__ = {\"data\":{\"title\":\"State Title\","
    "\"description\":\"State description text here\"}};</script></body></html>",

    # 10. application/json state blob (readable leaves recovered)
    "<html><body><script type=\"application/json\" id=\"__NEXT_DATA__\">"
    "{\"props\":{\"headline\":\"Next Headline\",\"summary\":\"Next summary text\"}}"
    "</script></body></html>",

    # 11. full body present (>200 chars): recovery must NOT touch body; video still harvested
    "<html><head><title>Real Title</title>"
    "<meta name=\"description\" content=\"Real meta description.\"></head><body>"
    "<p>" + LONG + "</p>"
    "<script type=\"application/ld+json\">{\"@type\":\"VideoObject\",\"name\":\"Clip\","
    "\"contentUrl\":\"http://x/c.mp4\"}</script></body></html>",

    # 12. duplicate attributes -> last wins; entity decoding in text
    "<body><a href=\"/first\" href=\"/second\">x</a>"
    "<p>Caf&eacute; &amp; cr&#232;me &#x2764;</p></body>",

    # 13. malformed JSON-LD is skipped (no crash, no recovery from it)
    "<html><head><script type=\"application/ld+json\">{not valid json,,}</script>"
    "</head><body><p>short</p></body></html>",

    # 14. everything at once: og + ldjson video + image + noscript + full body
    "<html lang=\"en\"><head><title>Mixed</title>"
    "<meta property=\"og:image\" content=\"http://x/o.jpg\">"
    "<script type=\"application/ld+json\">{\"@type\":\"VideoObject\",\"name\":\"Mv\","
    "\"contentUrl\":\"http://x/mv.mp4\",\"duration\":\"PT2M\"}</script></head>"
    "<body><p>" + LONG + "</p><img src=\"/m.png\" alt=\"m\">"
    "<noscript>ns text</noscript></body></html>",

    # 15. empty / trivial
    "<html><body></body></html>",

    # 16. Windows-1252 numeric character references (C1 remap)
    "<body><p>It&#146;s a &#147;test&#148; &#151; 5&#128;</p></body>",

    # 17. invalid numeric refs -> U+FFFD (0 / surrogate / over-max)
    "<body><p>a&#0;b &#xD800; c&#x110000;d</p></body>",

    # 18. </scriptx> does NOT close a <script> (raw-text end state)
    "<body><script>var a=1;</scriptx> still script</script><p>after</p></body>",

    # 19. CDATA marked section is dropped, not leaked at the first '>'
    "<body><p>x</p><![CDATA[a > b]]><p>y</p></body>",

    # 20. a stray slash in a start tag does not self-close it
    "<body><nav / class=\"x\">secret nav text</nav><p>real body</p></body>",

    # 21. strict JSON: a leading-zero number rejects the whole blob (no recovery)
    "<html><head><script type=\"application/ld+json\">"
    "{\"@type\":\"Article\",\"headline\":\"LZ\",\"n\":03}</script></head>"
    "<body><p>short</p></body></html>",
]

print("// %d cases — generated by /tmp/gen_stage2.py driving Python extract()" % len(CASES))
for html in CASES:
    print("    (")
    print("        %s," % rs(html))
    print("        %s," % rs(serialize(extract(html))))
    print("    ),")
