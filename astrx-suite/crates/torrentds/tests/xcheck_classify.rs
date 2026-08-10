//! Cross-check: the dependency-free Rust classifier produces the exact same
//! `tag_string` as the Python reference (`legacy-python/torrentds/classify.py`)
//! over a corpus of real-world release names — including the reference's quirks
//! (e.g. `DDP5.1` misses the acodec because `.` splits it to `ddp5`; `HDR10+`
//! folds to `hdr10` because `+` is a separator). The expected column was emitted
//! by driving the Python `classify`/`tag_string` directly.

use torrentds::classify::tag_string;

const CORPUS: &[(&str, &str)] = &[
    ("The.Film.2019.1080p.BluRay.x265-GROUP", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x265 group:group"),
    ("The Film (2019) [1080p] BluRay x265", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x265"),
    ("Some.Movie.2021.2160p.UHD.BluRay.REMUX.HDR10.DV.TrueHD.7.1.Atmos-FraMeSToR", "kind:movie year:2021 resolution:2160p source:remux acodec:truehd hdr:dolby-vision group:framestor"),
    ("Another.Movie.2018.720p.WEB-DL.DD5.1.H264-RARBG", "kind:movie year:2018 resolution:720p source:web-dl vcodec:x264 acodec:ac3 group:rarbg"),
    ("Cool.Movie.2020.1080p.WEBRip.x264-YTS", "kind:movie year:2020 resolution:1080p source:webrip vcodec:x264 group:yts"),
    ("Vieux.Film.1975.MULTi.1080p.BluRay.x264-ROMANCE", "kind:movie year:1975 resolution:1080p source:bluray vcodec:x264 group:romance lang:multi"),
    ("Der.Film.2017.German.DTS.DL.1080p.BluRay.x264-HQX", "kind:movie year:2017 resolution:1080p source:bluray vcodec:x264 acodec:dts group:hqx lang:de"),
    ("Pelicula.2016.Spanish.1080p.BluRay.DTS-HD.MA.5.1.x265", "kind:movie year:2016 resolution:1080p source:bluray vcodec:x265 acodec:dts-hd lang:es"),
    ("Film.Italiano.2015.iTA.HDTV.XviD", "kind:movie year:2015 source:hdtv vcodec:xvid lang:it"),
    ("Movie.2019.1080p.BluRay.DDP5.1.x264", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x264"),
    ("Movie.2019.1080p.BluRay.DDP.5.1.x264", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x264 acodec:eac3"),
    ("Movie.2019.2160p.HDR10+.x265", "kind:movie year:2019 resolution:2160p vcodec:x265 hdr:hdr10"),
    ("Movie.2019.2160p.HDR10Plus.x265", "kind:movie year:2019 resolution:2160p vcodec:x265 hdr:hdr10+"),
    ("Movie.2019.Directors.Cut.1080p.BluRay.x264", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x264 edition:directors-cut"),
    ("Movie.2019.Extended.1080p.BluRay.x264", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x264 edition:extended"),
    ("Movie.2019.REMASTERED.1080p.BluRay.x264", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x264 edition:remastered"),
    ("Movie.2019.PROPER.1080p.WEB.x264", "kind:movie year:2019 resolution:1080p source:web vcodec:x264 edition:proper"),
    ("Movie.2019.REPACK.1080p.WEB-DL.x264-GRP", "kind:movie year:2019 resolution:1080p source:web-dl vcodec:x264 edition:repack group:grp"),
    ("Movie.2019.IMAX.2160p.BluRay.x265", "kind:movie year:2019 resolution:2160p source:bluray vcodec:x265 edition:imax"),
    ("Movie.2018.UNRATED.1080p.BluRay.x264-AMIABLE", "kind:movie year:2018 resolution:1080p source:bluray vcodec:x264 edition:unrated group:amiable"),
    ("Old.Movie.1999.DVDRip.XviD-DiAMOND", "kind:movie year:1999 source:dvd vcodec:xvid group:diamond"),
    ("Movie.2012.BDRip.x264-SPARKS", "kind:movie year:2012 source:bluray vcodec:x264 group:sparks"),
    ("Movie.2014.HDCAM.x264", "kind:movie year:2014 source:cam vcodec:x264"),
    ("Movie.2014.TS.x264", "kind:movie year:2014 source:telesync vcodec:x264"),
    ("Movie.2014.CAM.XviD", "kind:movie year:2014 source:cam vcodec:xvid"),
    ("Movie.2020.AV1.Opus.WEB-DL", "kind:movie year:2020 source:web-dl vcodec:av1 acodec:opus group:dl"),
    ("Movie.2011.720p.HDTV.x264-2HD", "kind:movie year:2011 resolution:720p source:hdtv vcodec:x264 group:2hd"),
    ("Movie.2013.PDTV.XviD", "kind:movie year:2013 source:pdtv vcodec:xvid"),
    ("Concert.2018.1080i.HDTV.DD5.1.MPEG2", "kind:movie year:2018 resolution:1080p source:hdtv vcodec:mpeg2 acodec:ac3"),
    ("Show.Name.S02E07.1080p.WEB-DL.DDP.5.1.H264-GRP", "kind:tv season:2 episode:7 resolution:1080p source:web-dl vcodec:x264 acodec:eac3 group:grp"),
    ("Show.Name.S01E01.720p.HDTV.x264-KILLERS", "kind:tv season:1 episode:1 resolution:720p source:hdtv vcodec:x264 group:killers"),
    ("Show Name S1E2 1080p", "kind:tv season:1 episode:2 resolution:1080p"),
    ("Some Show Season 3 1080p WEB", "kind:tv season:3 resolution:1080p source:web"),
    ("Some.Show.Series.2.PDTV.XviD", "kind:tv season:2 source:pdtv vcodec:xvid"),
    ("Show.S05.1080p.BluRay.x265", "kind:tv season:5 resolution:1080p source:bluray vcodec:x265"),
    ("Show.Name.S10E100.2160p.WEB.x265", "kind:tv season:10 episode:100 resolution:2160p source:web vcodec:x265"),
    ("Anime.Series.S02.1080p.BluRay.FLAC.x265-GROUP", "kind:tv season:2 resolution:1080p source:bluray vcodec:x265 acodec:flac group:group"),
    ("Some.Game.v1.2.FitGirl.Repack", "kind:game edition:repack"),
    ("Great.Game.2021-CODEX", "kind:game year:2021 group:codex"),
    ("Another.Game.GOTY.Edition-PLAZA", "kind:game group:plaza"),
    ("Software.Suite.2020.x64", "kind:movie year:2020"),
    ("1920x1080.Test.Pattern", ""),
    ("Just.A.Plain.Name", ""),
    ("Movie-ab", "group:ab"),
    ("Movie.2020.1080p.BluRay-x264", "kind:movie year:2020 resolution:1080p source:bluray vcodec:x264"),
    ("Movie.2020.1080p.BluRay-1080p", "kind:movie year:2020 resolution:1080p source:bluray"),
    ("Movie.2020.1080p.BluRay-VeryLongReleaseGroupNameExceeds", "kind:movie year:2020 resolution:1080p source:bluray"),
    ("S02E05.Loose.Episode.720p.HDTV.x264", "kind:tv season:2 episode:5 resolution:720p source:hdtv vcodec:x264"),
    ("Documentary.2020.DUAL.1080p.WEB.H265", "kind:movie year:2020 resolution:1080p source:web vcodec:x265 lang:dual"),
    ("Movie.2019.VOSTFR.1080p.BluRay.x264", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x264 lang:fr"),
    ("Movie.2019.Blu.Ray.1080p.True.HD.x264", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x264 acodec:truehd"),
    ("Movie.2019.DTS.HD.MA.1080p.x264", "kind:movie year:2019 resolution:1080p vcodec:x264 acodec:dts-hd"),
    ("Random.Release.2007.WEBRip.AAC2.0.H.264-GROUP", "kind:movie year:2007 source:webrip vcodec:x264 group:group"),
    ("Track.List.2019.MP3.320kbps", "kind:movie year:2019 acodec:mp3"),
    ("Nothing.Here.At.All", ""),
    ("Movie.2160p.WEB.x265", "kind:movie resolution:2160p source:web vcodec:x265"),
    ("Show.S03E04.PROPER.1080p.AMZN.WEB-DL.DDP5.1.x264-NTb", "kind:tv season:3 episode:4 resolution:1080p source:web-dl vcodec:x264 edition:proper group:ntb"),
    // Punctuation-adjacent tokens (`,` `&` `!` `:` `/` `;` `~` `'`): `\b` must
    // treat any non-word char as a boundary, not just space.
    ("Movie,2019,1080p,BluRay", "kind:movie year:2019 resolution:1080p source:bluray"),
    ("Show.WEB&DL.1080p.x264", "kind:movie resolution:1080p source:web vcodec:x264"),
    ("Film.2019.x265!HDR", "kind:movie year:2019 vcodec:x265 hdr:hdr"),
    ("Fast&Furious.2019.1080p.BluRay.x264", "kind:movie year:2019 resolution:1080p source:bluray vcodec:x264"),
    ("Marvel's.Movie.2019.1080p.x264-GRP", "kind:movie year:2019 resolution:1080p vcodec:x264 group:grp"),
    ("Movie:Subtitle.2019.1080p.WEB-DL.x264", "kind:movie year:2019 resolution:1080p source:web-dl vcodec:x264"),
    ("Show/Name.S01E02.720p.HDTV.x264", "kind:tv season:1 episode:2 resolution:720p source:hdtv vcodec:x264"),
    ("x264,x265.2020.1080p", "kind:movie year:2020 resolution:1080p vcodec:x265"),
    ("Album,2019,FLAC", "kind:movie year:2019 acodec:flac"),
    ("Movie;2018;720p;x264", "kind:movie year:2018 resolution:720p vcodec:x264"),
    ("Concert~2019~1080p~x265", "kind:movie year:2019 resolution:1080p vcodec:x265"),
    ("Show.Season~3.1080p", "kind:movie resolution:1080p"),
    ("Doc.2019.web,dl.x264", "kind:movie year:2019 source:web vcodec:x264"),
    ("Movie'2020'1080p'BluRay", "kind:movie year:2020 resolution:1080p source:bluray"),
    ("Anime.S02!1080p.x265", "kind:tv season:2 resolution:1080p vcodec:x265"),
];

#[test]
fn classify_matches_python_reference() {
    let mut fails = Vec::new();
    for (name, expected) in CORPUS {
        let got = tag_string(name, &[]);
        if got != *expected {
            fails.push(format!(
                "\n  name: {name}\n   got: {got}\n  want: {expected}"
            ));
        }
    }
    assert!(
        fails.is_empty(),
        "{} classify divergence(s):{}",
        fails.len(),
        fails.join("")
    );
}
