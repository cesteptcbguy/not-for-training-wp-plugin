#!/usr/bin/env bash
# Makes tests/fixtures/real-gps.heic and real-nogps.heic: real HEICs encoded
# by Apple's ImageIO (macOS sips), from the source JPEGs that
# tests/bin/make-fixtures.php writes. macOS only; the committed files are
# what the tests use, so this only needs running if the sources change.
#
# sips carries the source's EXIF (including GPS) into the HEIC and adds an
# XMP item of its own, so these check the parser against Apple's real box
# layout rather than only our synthetic fixtures.
set -euo pipefail
cd "$(dirname "$0")/../fixtures"
command -v sips >/dev/null || { echo "sips not found: run this on macOS." >&2; exit 1; }
sips -s format heic heic-source-gps.jpg --out real-gps.heic >/dev/null
sips -s format heic heic-source-nogps.jpg --out real-nogps.heic >/dev/null
ls -l real-gps.heic real-nogps.heic
