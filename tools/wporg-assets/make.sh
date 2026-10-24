#!/bin/sh
# Render the WordPress.org icon and banner into .wordpress-org/.
#
# Needs Google Chrome (for the HTML and the Manrope web font) and Python's
# Pillow (to scale the banner down). Run from the plugin folder:
#
#   sh tools/wporg-assets/make.sh
#
# The screenshots are not made here: they are taken from a test site with
# real plugin screens. See tools/wporg-assets/README.md.
set -e

here=$(cd "$(dirname "$0")" && pwd)
out="$here/../../.wordpress-org"
chrome="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
mkdir -p "$out"

shot() { # file width height output
	"$chrome" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=1 \
		--default-background-color=00000000 --virtual-time-budget=5000 \
		--window-size="$2,$3" --screenshot="$4" "file://$here/$1" >/dev/null 2>&1
}

# Headless Chrome will not make a window smaller than about 500 pixels, so the
# icon is drawn at 1024 and scaled down, like the small banner.
tmp=$(mktemp -d)
shot icon.html 1024 1024 "$tmp/icon.png"
shot banner.html 1544 500 "$tmp/banner.png"

python3 - "$tmp" "$out" <<'PY'
import sys
from PIL import Image
tmp, out = sys.argv[1], sys.argv[2]
icon = Image.open(tmp + '/icon.png').convert('RGB')
banner = Image.open(tmp + '/banner.png').convert('RGB')
for size in (128, 256):
    icon.resize((size, size), Image.LANCZOS).save('%s/icon-%dx%d.png' % (out, size, size), optimize=True)
banner.save(out + '/banner-1544x500.png', optimize=True)
banner.resize((772, 250), Image.LANCZOS).save(out + '/banner-772x250.png', optimize=True)
PY
rm -rf "$tmp"

ls -l "$out"
