#!/usr/bin/env bash
# Copy the release files (everything not in .distignore) to build/not-for-training/,
# then zip them as build/not-for-training-<version>.zip for upload or testing.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' not-for-training.php)
rm -rf build
mkdir -p build/not-for-training
rsync -a --exclude-from=.distignore ./ build/not-for-training/
(cd build && zip -rqX "not-for-training-${version}.zip" not-for-training -x '*.DS_Store')
echo "Built build/not-for-training and build/not-for-training-${version}.zip"
