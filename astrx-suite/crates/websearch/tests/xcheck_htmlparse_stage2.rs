//! Cross-check: the Rust stage-2 `htmlparse::extract` reproduces the Python
//! `websearch.htmlparse.extract` byte-identically on a corpus exercising the
//! image/video verticals (img/video/source/iframe/direct-media/OpenGraph/
//! Twitter/JSON-LD) and the JSON-LD / inline-state / noscript `_recover`
//! backfill. Each golden is a serialized `Extracted`; the Rust `serialize`
//! below mirrors the Python `serialize` in `gen_stage2.py`. Regenerate the
//! goldens with that script.

use websearch::htmlparse::{extract, Extracted};

fn esc(s: &str) -> String {
    s.replace('\\', "\\\\")
        .replace('\t', "\\t")
        .replace('\n', "\\n")
        .replace('\r', "\\r")
}

fn serialize(e: &Extracted) -> String {
    let mut l: Vec<String> = Vec::new();
    l.push(format!("T\t{}", esc(&e.title)));
    l.push(format!("D\t{}", esc(&e.description)));
    l.push(format!("X\t{}", esc(&e.text)));
    l.push(format!("ROBOTS\t{}", esc(&e.meta_robots)));
    l.push(format!("LANG\t{}", esc(e.lang.as_deref().unwrap_or(""))));
    match &e.canonical {
        None => l.push("CANON_NONE".to_string()),
        Some(c) => l.push(format!("CANON\t{}", esc(c))),
    }
    match &e.base_href {
        None => l.push("BASE_NONE".to_string()),
        Some(b) => l.push(format!("BASE\t{}", esc(b))),
    }
    for link in &e.links {
        l.push(format!("LINK\t{}", esc(link)));
    }
    for im in &e.images {
        l.push(format!(
            "IMG\t{}\t{}\t{}\t{}",
            esc(&im.src),
            esc(&im.alt),
            esc(&im.title),
            esc(&im.context)
        ));
    }
    for v in &e.videos {
        let dur = v.duration.map(|d| d.to_string()).unwrap_or_default();
        l.push(format!(
            "VID\t{}\t{}\t{}\t{}\t{}\t{}\t{}\t{}",
            esc(&v.video_url),
            esc(&v.embed_url),
            esc(&v.watch_url),
            esc(&v.title),
            esc(&v.thumbnail),
            esc(&v.source),
            esc(&dur),
            esc(&v.context)
        ));
    }
    for (k, val) in &e.og {
        l.push(format!("OG\t{}\t{}", esc(k), esc(val)));
    }
    for (k, val) in &e.twitter {
        l.push(format!("TW\t{}\t{}", esc(k), esc(val)));
    }
    l.push(format!("NCOUNT\t{}", e.noscript_parts.len()));
    l.push(format!("LDCOUNT\t{}", e.ldjson_blobs.len()));
    l.push(format!("STCOUNT\t{}", e.state_blobs.len()));
    l.join("\n")
}

/// (html, serialized Python `Extracted`) — goldens from the real Python module.
const CASES: &[(&str, &str)] = &[
    (
        "<body><p>Some preceding words here.</p><img src=\"/a.png\" alt=\"Alt A\" title=\"Title A\"><img data-src=\"/b.png\"><img alt=\"no src\"></body>",
        "T	
D	
X	Some preceding words here.
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
IMG	/a.png	Alt A	Title A	Some preceding words here.
IMG	/b.png			Some preceding words here.
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<body><video poster=\"/p.jpg\"><source src=\"/v1.mp4\"><source src=\"/v2.webm\"></video><a href=\"/clip.mov\">dl</a><video src=\"/direct.mp4\"></video></body>",
        "T	
D	
X	dl
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
LINK	/clip.mov
VID	/v1.mp4				/p.jpg	html5		
VID	/v2.webm				/p.jpg	html5		
VID	/clip.mov					direct		
VID	/direct.mp4					html5		dl
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<body><iframe src=\"https://www.youtube.com/embed/dQw4w9WgXcQ\"></iframe><iframe src=\"https://player.vimeo.com/video/12345\"></iframe><iframe src=\"https://example.com/thing\"></iframe></body>",
        "T	
D	
X	
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
VID		https://www.youtube.com/embed/dQw4w9WgXcQ	https://www.youtube.com/watch?v=dQw4w9WgXcQ			youtube		
VID		https://player.vimeo.com/video/12345	https://vimeo.com/12345			vimeo		
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<html><head><meta property=\"og:title\" content=\"OG Title\"><meta property=\"og:description\" content=\"OG Desc\"><meta property=\"og:video\" content=\"http://x/og.mp4\"><meta property=\"og:image\" content=\"http://x/og.jpg\"><meta name=\"twitter:player\" content=\"http://x/tw.html\"><meta name=\"twitter:title\" content=\"TW Title\"></head><body></body></html>",
        "T	OG Title
D	OG Desc
X	OG Title OG Desc
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
VID	http://x/og.mp4			OG Title	http://x/og.jpg	opengraph		OG Title
VID		http://x/tw.html		TW Title	http://x/og.jpg	twitter		TW Title
OG	og:title	OG Title
OG	og:description	OG Desc
OG	og:video	http://x/og.mp4
OG	og:image	http://x/og.jpg
TW	twitter:player	http://x/tw.html
TW	twitter:title	TW Title
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<html><head><script type=\"application/ld+json\">{\"@type\":\"VideoObject\",\"name\":\"Cats\",\"embedUrl\":\"http://x/e\",\"contentUrl\":\"http://x/v.mp4\",\"duration\":\"PT1M30S\",\"thumbnailUrl\":{\"url\":\"http://x/t.jpg\"},\"description\":\"Fun cats\"}</script></head><body></body></html>",
        "T	Cats
D	Fun cats
X	Cats Fun cats
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
VID	http://x/v.mp4	http://x/e		Cats	http://x/t.jpg	ld-json	90	Cats
NCOUNT	0
LDCOUNT	1
STCOUNT	0",
    ),
    (
        "<html><head><script type=\"application/ld+json\">{\"@graph\":[{\"@type\":\"Article\",\"headline\":\"Head\",\"articleBody\":\"Graph body text.\"},{\"@type\":\"ImageObject\",\"contentUrl\":\"http://x/i.jpg\",\"caption\":\"Cap\"},{\"@type\":\"VideoObject\",\"name\":\"V\",\"contentUrl\":\"http://x/g.mp4\",\"duration\":95}]}</script></head><body></body></html>",
        "T	V
D	
X	V Graph body text.
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
IMG	http://x/i.jpg	Cap		
VID	http://x/g.mp4			V		ld-json	95	V
NCOUNT	0
LDCOUNT	1
STCOUNT	0",
    ),
    (
        "<html><head><meta property=\"og:title\" content=\"Only OG\"><meta property=\"og:description\" content=\"Just a description here\"></head><body><p>tiny</p></body></html>",
        "T	Only OG
D	Just a description here
X	tiny Only OG Just a description here
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
OG	og:title	Only OG
OG	og:description	Just a description here
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<html><body><noscript>This is the noscript fallback content that should be recovered into the body for a JS-only page.</noscript></body></html>",
        "T	
D	
X	This is the noscript fallback content that should be recovered into the body for a JS-only page.
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	1
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<html><body><script>window.__NUXT__ = {\"data\":{\"title\":\"State Title\",\"description\":\"State description text here\"}};</script></body></html>",
        "T	
D	
X	State Title State description text here
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	0
STCOUNT	1",
    ),
    (
        "<html><body><script type=\"application/json\" id=\"__NEXT_DATA__\">{\"props\":{\"headline\":\"Next Headline\",\"summary\":\"Next summary text\"}}</script></body></html>",
        "T	
D	
X	Next Headline Next summary text
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	0
STCOUNT	1",
    ),
    (
        "<html><head><title>Real Title</title><meta name=\"description\" content=\"Real meta description.\"></head><body><p>This is a full length article body with plenty of real readable words so the static body comfortably exceeds the two hundred character thin threshold and recovery leaves it untouched entirely here today.</p><script type=\"application/ld+json\">{\"@type\":\"VideoObject\",\"name\":\"Clip\",\"contentUrl\":\"http://x/c.mp4\"}</script></body></html>",
        "T	Real Title
D	Real meta description.
X	This is a full length article body with plenty of real readable words so the static body comfortably exceeds the two hundred character thin threshold and recovery leaves it untouched entirely here today.
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
VID	http://x/c.mp4			Clip		ld-json		Clip
NCOUNT	0
LDCOUNT	1
STCOUNT	0",
    ),
    (
        "<body><a href=\"/first\" href=\"/second\">x</a><p>Caf&eacute; &amp; cr&#232;me &#x2764;</p></body>",
        "T	
D	
X	x Café & crème ❤
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
LINK	/second
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<html><head><script type=\"application/ld+json\">{not valid json,,}</script></head><body><p>short</p></body></html>",
        "T	
D	
X	short
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	1
STCOUNT	0",
    ),
    (
        "<html lang=\"en\"><head><title>Mixed</title><meta property=\"og:image\" content=\"http://x/o.jpg\"><script type=\"application/ld+json\">{\"@type\":\"VideoObject\",\"name\":\"Mv\",\"contentUrl\":\"http://x/mv.mp4\",\"duration\":\"PT2M\"}</script></head><body><p>This is a full length article body with plenty of real readable words so the static body comfortably exceeds the two hundred character thin threshold and recovery leaves it untouched entirely here today.</p><img src=\"/m.png\" alt=\"m\"><noscript>ns text</noscript></body></html>",
        "T	Mixed
D	
X	This is a full length article body with plenty of real readable words so the static body comfortably exceeds the two hundred character thin threshold and recovery leaves it untouched entirely here today.
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
IMG	/m.png	m		s is a full length article body with plenty of real readable words so the static body comfortably exceeds the two hundred character thin threshold and recovery leaves it untouched entirely here today.
VID	http://x/mv.mp4			Mv		ld-json	120	Mv
OG	og:image	http://x/o.jpg
NCOUNT	1
LDCOUNT	1
STCOUNT	0",
    ),
    (
        "<html><body></body></html>",
        "T	
D	
X	
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<body><p>It&#146;s a &#147;test&#148; &#151; 5&#128;</p></body>",
        "T	
D	
X	It’s a “test” — 5€
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<body><p>a&#0;b &#xD800; c&#x110000;d</p></body>",
        "T	
D	
X	a�b � c�d
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<body><script>var a=1;</scriptx> still script</script><p>after</p></body>",
        "T	
D	
X	after
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<body><p>x</p><![CDATA[a > b]]><p>y</p></body>",
        "T	
D	
X	x y
ROBOTS	
LANG	es
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<body><nav / class=\"x\">secret nav text</nav><p>real body</p></body>",
        "T	
D	
X	real body
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	0
STCOUNT	0",
    ),
    (
        "<html><head><script type=\"application/ld+json\">{\"@type\":\"Article\",\"headline\":\"LZ\",\"n\":03}</script></head><body><p>short</p></body></html>",
        "T	
D	
X	short
ROBOTS	
LANG	en
CANON_NONE
BASE_NONE
NCOUNT	0
LDCOUNT	1
STCOUNT	0",
    ),
];

#[test]
fn stage2_matches_python() {
    for (i, (html, want)) in CASES.iter().enumerate() {
        let got = serialize(&extract(html));
        assert_eq!(&got, *want, "stage-2 case #{i}");
    }
}
