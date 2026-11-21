#!/bin/bash
#
# Build script for com_mcpserver Joomla component
# Creates a distributable .zip package for installation via Joomla Extension Manager
#

set -euo pipefail

COMPONENT="com_mcpserver"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Extract version from manifest
VERSION=$(grep -oP '<version>\K[^<]+' "$SCRIPT_DIR/mcpserver.xml")
# The release is a Joomla package: the component plus the system plugin that
# serves the OAuth URLs. mcpserver.xml stays the version source of truth; the
# package and plugin manifests are synced to it below.
PACKAGE="pkg_mcpserver-${VERSION}.zip"
BUILD_DIR="$SCRIPT_DIR/build"
COMPONENT_DIR="$BUILD_DIR/com_mcpserver"
PLUGIN_DIR="$BUILD_DIR/plg_system_mcpserver"
PKG_DIR="$BUILD_DIR/pkg_mcpserver"

echo "Building ${COMPONENT} v${VERSION}..."

# Sync the README header to the manifest version (mcpserver.xml is the source of truth)
README_PATH="$SCRIPT_DIR/README.md"
if [[ -f "$README_PATH" ]]; then
    CURRENT_README_VERSION=$(grep -oP '\*\*Version:\*\*\s+\K\S+' "$README_PATH" || true)
    if [[ -n "$CURRENT_README_VERSION" && "$CURRENT_README_VERSION" != "$VERSION" ]]; then
        sed -i -E "s/(\*\*Version:\*\*[[:space:]]+)\S+/\1${VERSION}/" "$README_PATH"
        echo "Updated README version: ${CURRENT_README_VERSION} → ${VERSION}"
    fi
fi

# Sync the package and plugin manifests to the same version, in place, so the
# committed files never drift from mcpserver.xml (PackageManifestTest checks it)
for EXTRA_MANIFEST in "$SCRIPT_DIR/pkg_mcpserver.xml" "$SCRIPT_DIR/plugins/system/mcpserver/mcpserver.xml"; do
    sed -i -E "s#<version>[^<]+</version>#<version>${VERSION}</version>#" "$EXTRA_MANIFEST"
done

# Sync the Joomla updater feed: prepend an <update> entry for this version
# (newest first) if one isn't already present, including infourl, tags, and
# maintainerurl. mcpserver.xml stays the source of truth; Joomla offers the
# highest version whose targetplatform/php match.
UPDATE_PATH="$SCRIPT_DIR/update.xml"
if [[ -f "$UPDATE_PATH" ]]; then
    python3 "$SCRIPT_DIR/scripts/sync_update_entry.py" "$UPDATE_PATH" "$VERSION"
    echo "update.xml entry ensured for v${VERSION}"
fi

# Sync changelog.xml from the matching ## <version> section in CHANGELOG.md.
CHANGELOG_XML_PATH="$SCRIPT_DIR/changelog.xml"
CHANGELOG_MD_PATH="$SCRIPT_DIR/CHANGELOG.md"
if [[ -f "$CHANGELOG_XML_PATH" && -f "$CHANGELOG_MD_PATH" ]]; then
    python3 "$SCRIPT_DIR/scripts/sync_changelog_entry.py" "$CHANGELOG_XML_PATH" "$VERSION" "$CHANGELOG_MD_PATH"
    echo "changelog.xml entry ensured for v${VERSION}"
fi

# Clean previous build
rm -rf "$BUILD_DIR"
mkdir -p "$COMPONENT_DIR" "$PKG_DIR/packages"

# Copy manifest and install script
cp "$SCRIPT_DIR/mcpserver.xml" "$COMPONENT_DIR/"
cp "$SCRIPT_DIR/script.php" "$COMPONENT_DIR/"

# Copy release documentation required by distribution channels
for RELEASE_FILE in LICENSE README.md CHANGELOG.md SECURITY.md update.xml changelog.xml; do
    if [[ -f "$SCRIPT_DIR/$RELEASE_FILE" ]]; then
        cp "$SCRIPT_DIR/$RELEASE_FILE" "$COMPONENT_DIR/"
        cp "$SCRIPT_DIR/$RELEASE_FILE" "$PKG_DIR/"
    fi
done

# Install production dependencies
composer install --no-dev --optimize-autoloader --working-dir="$SCRIPT_DIR/admin" --quiet

# Copy admin files
rsync -a --exclude='.DS_Store' --exclude='composer.lock' "$SCRIPT_DIR/admin/" "$COMPONENT_DIR/admin/"

# Copy site files
rsync -a --exclude='.DS_Store' "$SCRIPT_DIR/site/" "$COMPONENT_DIR/site/"

# The system plugin (its manifest version was synced above)
rsync -a --exclude='.DS_Store' "$SCRIPT_DIR/plugins/system/mcpserver/" "$PLUGIN_DIR/"

# Ship the MCPB template, icon and bridge so the admin can generate personalised
# bundles without depending on the site-installed bridge file
mkdir -p "$COMPONENT_DIR/admin/mcpb/server"
sed "s/__VERSION__/${VERSION}/" "$SCRIPT_DIR/mcpb/manifest.json" > "$COMPONENT_DIR/admin/mcpb/manifest.json"
cp "$SCRIPT_DIR/assets/logo.png" "$COMPONENT_DIR/admin/mcpb/icon.png"
cp "$SCRIPT_DIR/site/mcp-http-bridge.js" "$COMPONENT_DIR/admin/mcpb/server/mcp-http-bridge.js"

# JED Checker: strip vendor leftovers and patch JAMSS/framework false positives
python3 "$SCRIPT_DIR/scripts/prepare_vendor_for_jed.py" \
    "$COMPONENT_DIR/admin/vendor" \
    "$COMPONENT_DIR/admin/src"

# JED Checker requires GPL-compatible licence notices in bundled vendor PHP files
python3 "$SCRIPT_DIR/scripts/add_vendor_license_headers.py" "$COMPONENT_DIR/admin/vendor"

# The JED-prep scripts rewrite PHP source; fail the build if any rewrite broke syntax
find "$BUILD_DIR" -name '*.php' -print0 | xargs -0 -n1 -P4 php -l > /dev/null
echo "Packaged PHP syntax check passed"

# The rewrites can also change behaviour without breaking syntax (1.8.0-1.10.1 shipped
# credentials in standard base64 that the server then refused), so exercise the
# packaged base64url encoders directly
php -r '
define("_JEXEC", 1);
require $argv[1] . "/McpCredential.php";
require $argv[1] . "/CredentialCipher.php";
use Joomla\Component\Mcpserver\Administrator\Service\{McpCredential, CredentialCipher};
$cipher = new CredentialCipher("build-check", base64_encode(random_bytes(32)));
for ($i = 0; $i < 200; $i++) {
    $token = McpCredential::issue()["token"];
    if (!preg_match("/^mcp_[A-Za-z0-9_-]{16}\\.[A-Za-z0-9_-]{43}$/D", $token)) {
        fwrite(STDERR, "Packaged McpCredential issued a non-base64url token: $token\n");
        exit(1);
    }
    $plaintext = random_bytes(48);
    $encrypted = $cipher->encrypt($plaintext);
    if (preg_match("/[^A-Za-z0-9_-]/", $encrypted["ciphertext"] . $encrypted["nonce"] . $encrypted["tag"])
        || $cipher->decrypt($encrypted) !== $plaintext) {
        fwrite(STDERR, "Packaged CredentialCipher failed its base64url round trip\n");
        exit(1);
    }
}
' "$COMPONENT_DIR/admin/src/Service"
echo "Packaged credential encoding check passed"

# Zip the component and the plugin into the package, then the package itself
(cd "$COMPONENT_DIR" && zip -rq "$PKG_DIR/packages/com_mcpserver.zip" . -x '*.git*')
(cd "$PLUGIN_DIR" && zip -rq "$PKG_DIR/packages/plg_system_mcpserver.zip" . -x '*.git*')
cp "$SCRIPT_DIR/pkg_mcpserver.xml" "$PKG_DIR/"
cp "$SCRIPT_DIR/script.pkg.php" "$PKG_DIR/"

rm -f "$SCRIPT_DIR/$PACKAGE"
(cd "$PKG_DIR" && zip -rq "$SCRIPT_DIR/$PACKAGE" . -x '*.git*')

# Clean up
rm -rf "$BUILD_DIR"

# Record the package SHA-256 in the update feed so Joomla can verify integrity.
# The hash must match the exact artifact published to the GitHub release, so the
# release workflow recomputes and commits this on master against the CI build.
if [[ -f "$UPDATE_PATH" ]]; then
    PACKAGE_SHA256=$(sha256sum "$SCRIPT_DIR/$PACKAGE" | cut -d' ' -f1)
    python3 "$SCRIPT_DIR/scripts/sync_update_entry.py" "$UPDATE_PATH" "$VERSION" "$PACKAGE_SHA256"
    echo "update.xml checksum set for v${VERSION}: ${PACKAGE_SHA256}"
fi

SIZE=$(du -h "$PACKAGE" | cut -f1)
echo "Package created: ${PACKAGE} (${SIZE})"

# Build the generic Claude Desktop extension bundle (.mcpb). It is intentionally
# unversioned so GitHub's /releases/latest/download/com_mcpserver.mcpb URL stays
# stable; the version lives in the manifest inside the bundle.
MCPB_PACKAGE="${COMPONENT}.mcpb"
MCPB_BUILD_DIR="$SCRIPT_DIR/build-mcpb"
rm -rf "$MCPB_BUILD_DIR"
mkdir -p "$MCPB_BUILD_DIR/server"

sed "s/__VERSION__/${VERSION}/" "$SCRIPT_DIR/mcpb/manifest.json" > "$MCPB_BUILD_DIR/manifest.json"
python3 -m json.tool "$MCPB_BUILD_DIR/manifest.json" > /dev/null
cp "$SCRIPT_DIR/assets/logo.png" "$MCPB_BUILD_DIR/icon.png"
cp "$SCRIPT_DIR/site/mcp-http-bridge.js" "$MCPB_BUILD_DIR/server/mcp-http-bridge.js"

rm -f "$SCRIPT_DIR/$MCPB_PACKAGE"
cd "$MCPB_BUILD_DIR"
zip -rq "$SCRIPT_DIR/$MCPB_PACKAGE" .
cd "$SCRIPT_DIR"
rm -rf "$MCPB_BUILD_DIR"

MCPB_SIZE=$(du -h "$MCPB_PACKAGE" | cut -f1)
echo "MCPB bundle created: ${MCPB_PACKAGE} (${MCPB_SIZE})"
