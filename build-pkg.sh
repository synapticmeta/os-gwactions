#!/bin/sh
# Build the os-gwactions package from src/.
#
# Run this ON the OPNsense box (or any FreeBSD host with pkg + python3).
# No ports tree, git or gmake required — uses `pkg create` directly.
#
#   Usage: ./build-pkg.sh [version]   (default version 1.0)
#
# src/ mirrors /usr/local, so every file installs under /usr/local/<relpath>.
set -e

HERE=$(cd "$(dirname "$0")" && pwd)
SRC="$HERE/src"
VER="${1:-1.0}"

WORK=$(mktemp -d)
ROOT="$WORK/root"
mkdir -p "$ROOT/usr/local" "$WORK/out"

# stage the tree under /usr/local
cp -R "$SRC/." "$ROOT/usr/local/"

# executables must ship as 0755 (configd/syshook exec them directly); a 0644
# script installs as non-executable and silently breaks the plugin.
chmod 0755 "$ROOT/usr/local/opnsense/scripts/OPNsense/GwActions/engine.py" \
           "$ROOT/usr/local/opnsense/scripts/OPNsense/GwActions/setup.php" \
           "$ROOT/usr/local/etc/rc.syshook.d/monitor/30-gwactions"

# packing list = every staged file, as absolute install paths
( cd "$ROOT" && find usr -type f ) | sed 's#^#/#' > "$WORK/plist"

# manifest (JSON; pkg accepts it) with embedded lifecycle scripts
python3 - "$WORK" "$VER" <<'PY'
import json, sys
work, ver = sys.argv[1], sys.argv[2]
post_install = (
    "#!/bin/sh\n"
    "rm -f /var/lib/php/tmp/opnsense_menu_cache.xml "
    "/var/lib/php/tmp/opnsense_acl_cache.json 2>/dev/null\n"
    "[ -f /usr/local/etc/gwactions/config.json ] || "
    "/usr/local/opnsense/scripts/OPNsense/GwActions/setup.php >/dev/null 2>&1\n"
    "exit 0\n"
)
post_deinstall = (
    "#!/bin/sh\n"
    "rm -f /var/lib/php/tmp/opnsense_menu_cache.xml "
    "/var/lib/php/tmp/opnsense_acl_cache.json 2>/dev/null\n"
    "exit 0\n"
)
manifest = {
    "name": "os-gwactions", "version": ver,
    "origin": "opnsense/os-gwactions",
    "comment": "Gateway Actions - run actions on gateway state changes",
    "maintainer": "github.com@box.mavier.net",
    "www": "https://github.com/synapticmeta/os-gwactions",
    "prefix": "/usr/local", "categories": ["net"],
    "licenselogic": "single", "licenses": ["BSD2CLAUSE"],
    "desc": ("Gateway Actions runs configurable actions (e.g. restart all "
             "WireGuard tunnels) when a gateway changes state. Built for "
             "multi-WAN failover setups. Provides a Services GUI with status "
             "dashboard, rule editor and event history."),
    "scripts": {"post-install": post_install, "post-deinstall": post_deinstall},
}
open(work + "/+MANIFEST", "w").write(json.dumps(manifest, indent=2))
PY

pkg create -M "$WORK/+MANIFEST" -p "$WORK/plist" -r "$ROOT" -o "$WORK/out"

mkdir -p "$HERE/dist"
cp "$WORK/out/"os-gwactions-*.pkg "$HERE/dist/"
echo "built: dist/os-gwactions-${VER}.pkg"
rm -rf "$WORK"
