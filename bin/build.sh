#!/usr/bin/env bash
# Copy the release files (everything not in .distignore) to build/not-for-training/.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build
mkdir -p build/not-for-training
rsync -a --exclude-from=.distignore ./ build/not-for-training/
echo "Built build/not-for-training"
