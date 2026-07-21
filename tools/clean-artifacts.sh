#!/usr/bin/env bash
set -Eeuo pipefail

rm -rf \
  7 Array default develop no cachegrind \
  astrx_clean_fixed \
  astrx_full_repo_*.zip astrx_*_changed_files.zip \
  *.patch *.diff *.rej *.orig *.bak *.tmp \
  fix-compiled-bundle.sh \
  xdebug-profiles \
  resources/template/cache/* \
  'src/{src' 'src/AstrX/{Controller,User,Auth' \
  setup/fix124 setup/setup

echo "Cleaned generated/debug/accidental artifacts."
