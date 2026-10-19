{
  description = "AstrX: a JavaScript-free PHP framework and the website built on it";

  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-25.05";

  outputs =
    { self, nixpkgs }:
    let
      systems = [
        "x86_64-linux"
        "aarch64-linux"
        "x86_64-darwin"
        "aarch64-darwin"
      ];
      forAllSystems = f: nixpkgs.lib.genAttrs systems (system: f nixpkgs.legacyPackages.${system});

      # PHP 8.4 with nixpkgs' default extension set, which covers everything
      # the packages' composer.json files require (ctype, dom, filter, gd,
      # iconv, mbstring, openssl, pdo, pdo_mysql, session) plus the suggested
      # sodium, posix and opcache, and pdo_sqlite for the test suite.
      phpFor = pkgs: pkgs.php84;
    in
    {
      # `nix develop`: PHP, Composer and MariaDB for running the site, the
      # checks and the tests (see docs/getting-started.md).
      devShells = forAllSystems (
        pkgs:
        let
          php = phpFor pkgs;
        in
        {
          default = pkgs.mkShell {
            packages = [
              php
              pkgs.php84Packages.composer
              pkgs.mariadb
              pkgs.git
            ];
          };
        }
      );

      # `nix flake check`: the zero-dependency integrity gates CI runs before
      # the database jobs (no network, no database).
      checks = forAllSystems (
        pkgs:
        let
          php = phpFor pkgs;
        in
        {
          gates = pkgs.runCommand "astrx-gates" { nativeBuildInputs = [ php ]; } ''
            cp -r ${self} source
            chmod -R u+w source
            cd source
            php tools/check_architecture.php
            php tools/packages.php
            php tools/check_modules.php
            php tools/check_config.php
            php tools/check_lang_parity.php
            php tools/check_diagnostics.php
            touch $out
          '';
        }
      );

      formatter = forAllSystems (pkgs: pkgs.nixfmt-rfc-style);
    };
}
