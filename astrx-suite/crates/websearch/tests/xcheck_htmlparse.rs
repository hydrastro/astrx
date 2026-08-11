//! Cross-check: the Rust `websearch::htmlparse::extract` reproduces the Python
//! `websearch.htmlparse.extract` **core** fields (title, description, text,
//! links, canonical, base_href, lang, meta_robots) on realistic (≥200-char-body)
//! pages, where the Python `_recover` structured-data backfill is a no-op.
//! Goldens emitted by `tests/regen_goldens.py` (the `gen_htmlparse` section).
//!
//! The image/video verticals and the JSON-LD / SPA `_recover` path are stage 2
//! (not yet ported); these fixtures deliberately avoid `<img>`/`<video>`/og/
//! twitter/`<noscript>`/`ld+json`, i.e. anything `_recover` would re-add.

use websearch::htmlparse::extract;

struct Want {
    title: &'static str,
    description: &'static str,
    text: &'static str,
    links: &'static [&'static str],
    canonical: Option<&'static str>,
    base_href: Option<&'static str>,
    lang: &'static str,
    meta_robots: &'static str,
}

fn check(html: &str, w: &Want) {
    let e = extract(html);
    assert_eq!(e.title, w.title, "title");
    assert_eq!(e.description, w.description, "description");
    assert_eq!(e.text, w.text, "text");
    assert_eq!(e.links, w.links, "links");
    assert_eq!(e.canonical.as_deref(), w.canonical, "canonical");
    assert_eq!(e.base_href.as_deref(), w.base_href, "base_href");
    assert_eq!(e.lang.as_deref(), Some(w.lang), "lang");
    assert_eq!(e.meta_robots, w.meta_robots, "meta_robots");
}

#[test]
fn f1_full_english_article() {
    let html = r#"<html lang="en-US"><head><title>News &amp; Notes &#8212; Today</title>
<meta name="description" content="  A concise   summary. ">
<link rel="canonical" href="http://ex/a"><base href="http://ex/">
<meta name="robots" content="INDEX, NoFollow"></head>
<body><nav><a href="/home">Home</a> menu items that are boilerplate</nav>
<h1>Main Heading</h1>
<p>The quick brown fox jumps over the lazy dog and then the fox runs away to the woods for a while.</p>
<script>var x = a < b ? 1 : 2;</script>
<p>Read <a href="/more">more here</a> and also <a href="/x" rel="nofollow">this</a> for details today.</p>
<footer>copyright boilerplate footer text here</footer></body></html>"#;
    check(
        html,
        &Want {
            title: "News & Notes — Today",
            description: "A concise summary.",
            text: "Main Heading The quick brown fox jumps over the lazy dog and then the fox runs away to the woods for a while. Read more here and also this for details today.",
            links: &["/home", "/more", "/x"],
            canonical: Some("http://ex/a"),
            base_href: Some("http://ex/"),
            lang: "en",
            meta_robots: "index, nofollow",
        },
    );
}

#[test]
fn f2_french_no_lang_attr() {
    let html = r#"<html><head><title>Le Journal</title></head><body>
<p>Le chat de la maison et les chiens des voisins sont dans le jardin pour une promenade et une partie de jeu ensemble aujourd hui dans le parc.</p>
<p>Une histoire de la vie et des reves que les gens partagent dans les rues de la ville et dans les champs verts.</p>
</body></html>"#;
    check(
        html,
        &Want {
            title: "Le Journal",
            description: "",
            text: "Le chat de la maison et les chiens des voisins sont dans le jardin pour une promenade et une partie de jeu ensemble aujourd hui dans le parc. Une histoire de la vie et des reves que les gens partagent dans les rues de la ville et dans les champs verts.",
            links: &[],
            canonical: None,
            base_href: None,
            lang: "fr",
            meta_robots: "",
        },
    );
}

#[test]
fn f3_boilerplate_and_skip() {
    let html = r#"<head><title>Doc</title><meta name="description" content="desc"></head>
<body><header><a href="/h">hh</a>header words here as boilerplate</header>
<aside>aside words that are boilerplate and excluded from the body text entirely</aside>
<svg><text>svgtext</text></svg><template><p>tpl</p></template>
<math><mi>x</mi></math>
<p>This is the real visible article body carrying well over two hundred characters of genuine readable prose, so the thin-body recover step never even has a chance to fire and the body remains exactly as it was parsed right here today, word for word.</p>
<form><input><a href="/f">formlink</a></form></body>"#;
    check(
        html,
        &Want {
            title: "Doc",
            description: "desc",
            text: "This is the real visible article body carrying well over two hundred characters of genuine readable prose, so the thin-body recover step never even has a chance to fire and the body remains exactly as it was parsed right here today, word for word.",
            links: &["/h", "/f"],
            canonical: None,
            base_href: None,
            lang: "en",
            meta_robots: "",
        },
    );
}

#[test]
fn f4_entities_and_http_equiv_lang() {
    let html = r#"<html><head><meta http-equiv="content-language" content="de">
<title>Caf&#233; &amp; Stra&szlig;e</title></head><body>
<div>Die Katze und der Hund sind auf dem Dach von dem Haus mit dem roten Ziegel und dem alten Baum im Garten der Familie.</div>
<ul><li>eins</li><li>zwei</li><li>drei</li></ul>
<p>Ein langer Text der genug Worte hat damit die Sprache erkannt wird und der Koerper nicht als duenn gilt heute.</p></body></html>"#;
    check(
        html,
        &Want {
            title: "Café & Straße",
            description: "",
            text: "Die Katze und der Hund sind auf dem Dach von dem Haus mit dem roten Ziegel und dem alten Baum im Garten der Familie. eins zwei drei Ein langer Text der genug Worte hat damit die Sprache erkannt wird und der Koerper nicht als duenn gilt heute.",
            links: &[],
            canonical: None,
            base_href: None,
            lang: "de",
            meta_robots: "",
        },
    );
}

#[test]
fn f5_robots_none_and_stray_lt_and_dup_desc() {
    let html = r#"<html lang="es"><head><title>Hola</title>
<meta name="robots" content="none">
<meta name="description" content="primera"><meta name="description" content="segunda"></head>
<body><p>El gato y la casa de los ninos con las flores en el jardin de la ciudad para una fiesta con la familia y los amigos.</p>
<p>5 < 10 is true and the text continues with enough words to be a proper body of readable content here now.</p></body></html>"#;
    let e = extract(html);
    assert_eq!(e.title, "Hola");
    assert_eq!(e.description, "primera"); // first description wins
    assert_eq!(
        e.text,
        "El gato y la casa de los ninos con las flores en el jardin de la ciudad para una fiesta con la familia y los amigos. 5 < 10 is true and the text continues with enough words to be a proper body of readable content here now."
    );
    assert!(e.links.is_empty());
    assert_eq!(e.lang.as_deref(), Some("es"));
    assert_eq!(e.meta_robots, "none");
    // websearch semantics: "none" is neither noindex nor nofollow (substring test)
    assert!(!e.noindex());
    assert!(!e.nofollow());
}
