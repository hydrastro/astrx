use crawlcore::storefile::WriterLease;
#[test]
#[ignore = "subprocess helper"]
fn lease_child() {
    let path = std::env::var_os("ASTRX_LEASE_TEST_PATH").unwrap();
    let result = WriterLease::acquire(path);
    if std::env::var_os("ASTRX_EXPECT_BUSY").is_some() {
        assert_eq!(result.unwrap_err().kind(), std::io::ErrorKind::WouldBlock);
    } else {
        assert!(result.is_ok());
    }
}
#[test]
fn lease_excludes_other_process_and_releases_on_drop() {
    let path = std::env::temp_dir().join(format!("astrx-lease-{}", std::process::id()));
    let child = |busy| {
        // argv[0] also works on systems where /proc/self/exe is unavailable.
        let mut cmd = std::process::Command::new(std::env::args_os().next().unwrap());
        cmd.args(["--exact", "lease_child", "--ignored"])
            .env("ASTRX_LEASE_TEST_PATH", &path);
        if busy {
            cmd.env("ASTRX_EXPECT_BUSY", "1");
        }
        assert!(cmd.status().unwrap().success());
    };
    let lease = WriterLease::acquire(&path).unwrap();
    child(true);
    drop(lease);
    child(false);
    std::fs::remove_file(
        path.with_file_name(format!("astrx-lease-{}.writer.lock", std::process::id())),
    )
    .unwrap();
}
