#!/usr/bin/env bash
#
# Refreshes the translation files.
#
#   1. Extracts every translatable string into languages/povikvane-for-woocommerce.pot
#      (this English template is the master: all source strings are English).
#   2. Merges new and changed strings into each languages/*.po file.
#   3. Compiles every .po into the .mo file WordPress loads.
#
# Needs the GNU gettext tools (xgettext, msgmerge, msgfmt), for example
# "brew install gettext". Usage: bin/i18n.sh

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

slug="povikvane-for-woocommerce"
version="$(sed -n 's/^ \* Version: *//p' "${slug}.php" | head -1)"
pot="languages/${slug}.pot"

files="$(find src -name '*.php' | sort) ${slug}.php uninstall.php"

# shellcheck disable=SC2086
xgettext \
	--language=PHP \
	--from-code=UTF-8 \
	--add-comments=translators: \
	--keyword=__ --keyword=_e --keyword=_n:1,2 --keyword=_x:1,2c --keyword=_nx:1,2,4c \
	--keyword=esc_html__ --keyword=esc_attr__ --keyword=esc_html_e --keyword=esc_attr_e \
	--package-name="Povikvane SMS for WooCommerce" \
	--package-version="${version}" \
	--msgid-bugs-address="support@povikvane.com" \
	--no-wrap \
	--sort-by-file \
	-o "${pot}" ${files}

for po in languages/*.po; do
	[ -e "${po}" ] || continue
	msgmerge --update --backup=none --no-wrap --quiet "${po}" "${pot}"
	msgfmt --check --output-file="${po%.po}.mo" "${po}"
	echo "Compiled ${po%.po}.mo"
done

echo "Wrote ${pot}"
