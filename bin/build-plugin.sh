#!/usr/bin/env bash
#
# Builds a clean copy of the plugin: exactly the files customers and
# WordPress.org should receive (no tests, CI config or dotfiles).
#
# Usage: bin/build-plugin.sh <output-dir> [zip]
#   Creates <output-dir>/povikvane-for-woocommerce/ and, with "zip",
#   <output-dir>/povikvane-for-woocommerce-<version>.zip.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="${1:?Usage: bin/build-plugin.sh <output-dir> [zip]}"
slug="povikvane-for-woocommerce"

rm -rf "${out:?}/${slug}"
mkdir -p "${out}/${slug}"

cp "${root}/${slug}.php" "${root}/readme.txt" "${root}/uninstall.php" "${out}/${slug}/"
cp -R "${root}/src" "${root}/assets" "${root}/languages" "${out}/${slug}/"
find "${out}/${slug}" -name '.DS_Store' -delete

if [ "${2:-}" = "zip" ]; then
	version="$(sed -n 's/^ \* Version: *//p' "${root}/${slug}.php" | head -1)"
	(cd "${out}" && rm -f "${slug}-${version}.zip" && zip -rq "${slug}-${version}.zip" "${slug}")
	echo "Built ${out}/${slug}-${version}.zip"
fi
