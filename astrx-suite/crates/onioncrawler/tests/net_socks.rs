//! Loopback round-trip of the async SOCKS5 client against a mock SOCKS5 server
//! (net feature). Exercises the real handshake end-to-end — greeting, method
//! selection, optional username/password sub-negotiation, DOMAINNAME CONNECT,
//! reply parsing, and that the returned stream is a transparent tunnel — without
//! needing a live Tor proxy.
#![cfg(feature = "net")]

use onioncrawler::socks::socks5_connect;
use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

/// Read the client greeting and reply selecting `method`.
async fn mock_greeting(sock: &mut TcpStream, method: u8) {
    let mut hdr = [0u8; 2];
    sock.read_exact(&mut hdr).await.unwrap();
    assert_eq!(hdr[0], 0x05);
    let mut methods = vec![0u8; hdr[1] as usize];
    sock.read_exact(&mut methods).await.unwrap();
    sock.write_all(&[0x05, method]).await.unwrap();
}

async fn mock_read_connect(sock: &mut TcpStream, want_host: &[u8], want_port: u16) {
    let mut req = [0u8; 4];
    sock.read_exact(&mut req).await.unwrap();
    assert_eq!([req[0], req[1], req[3]], [0x05, 0x01, 0x03]); // VER, CONNECT, DOMAINNAME
    let mut len = [0u8; 1];
    sock.read_exact(&mut len).await.unwrap();
    let mut host = vec![0u8; len[0] as usize];
    sock.read_exact(&mut host).await.unwrap();
    let mut port = [0u8; 2];
    sock.read_exact(&mut port).await.unwrap();
    assert_eq!(host, want_host);
    assert_eq!(u16::from_be_bytes(port), want_port);
    // reply: VER, REP=succeeded, RSV, ATYP=IPv4, BND.ADDR=0.0.0.0, BND.PORT=0
    sock.write_all(&[0x05, 0x00, 0x00, 0x01, 0, 0, 0, 0, 0, 0])
        .await
        .unwrap();
}

#[tokio::test]
async fn socks5_connect_noauth_tunnel() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = tokio::spawn(async move {
        let (mut sock, _) = listener.accept().await.unwrap();
        mock_greeting(&mut sock, 0x00).await;
        mock_read_connect(&mut sock, b"test.onion", 80).await;
        // prove the tunnel: echo one byte
        let mut b = [0u8; 1];
        sock.read_exact(&mut b).await.unwrap();
        sock.write_all(&b).await.unwrap();
    });

    let mut stream = socks5_connect(
        "127.0.0.1",
        port,
        "test.onion",
        80,
        None,
        Duration::from_secs(5),
    )
    .await
    .unwrap();
    stream.write_all(b"Z").await.unwrap();
    let mut resp = [0u8; 1];
    stream.read_exact(&mut resp).await.unwrap();
    assert_eq!(resp[0], b'Z');
    server.await.unwrap();
}

#[tokio::test]
async fn socks5_connect_userpass() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = tokio::spawn(async move {
        let (mut sock, _) = listener.accept().await.unwrap();
        // select username/password
        mock_greeting(&mut sock, 0x02).await;
        // RFC 1929 sub-negotiation: VER, ULEN, UNAME, PLEN, PASSWD
        let mut vu = [0u8; 2];
        sock.read_exact(&mut vu).await.unwrap();
        assert_eq!(vu[0], 0x01);
        let mut uname = vec![0u8; vu[1] as usize];
        sock.read_exact(&mut uname).await.unwrap();
        let mut plen = [0u8; 1];
        sock.read_exact(&mut plen).await.unwrap();
        let mut passwd = vec![0u8; plen[0] as usize];
        sock.read_exact(&mut passwd).await.unwrap();
        assert_eq!(uname, b"circuit-iso");
        assert_eq!(passwd, b"x");
        sock.write_all(&[0x01, 0x00]).await.unwrap(); // auth success
        mock_read_connect(&mut sock, b"iso.onion", 443).await;
    });

    let stream = socks5_connect(
        "127.0.0.1",
        port,
        "iso.onion",
        443,
        Some(("circuit-iso", "x")),
        Duration::from_secs(5),
    )
    .await
    .unwrap();
    drop(stream);
    server.await.unwrap();
}
