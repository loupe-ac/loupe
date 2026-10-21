#!/bin/sh
# Builds the panel and wraps it in "Live review.app".
# LIVE_REVIEW_SIGN_IDENTITY names the signing identity. The default "-" is an ad hoc
# signature for local runs: macOS drops the Accessibility grant of an ad hoc build
# when the code changes. LIVE_REVIEW_VERSION sets the bundle version.
set -eu

cd "$(dirname "$0")/.."
out="${1:-.build/bundle}"
identity="${LIVE_REVIEW_SIGN_IDENTITY:--}"
version="${LIVE_REVIEW_VERSION:-0.0.0}"

swift build -c release --arch arm64 --arch x86_64
bin="$(swift build -c release --arch arm64 --arch x86_64 --show-bin-path)/LiveReview"

app="$out/Live review.app"
rm -rf "$app"
mkdir -p "$app/Contents/MacOS"
cp "$bin" "$app/Contents/MacOS/LiveReview"
cat > "$app/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleIdentifier</key><string>ac.loupe.live-review</string>
	<key>CFBundleName</key><string>Live review</string>
	<key>CFBundleDisplayName</key><string>Live review</string>
	<key>CFBundleExecutable</key><string>LiveReview</string>
	<key>CFBundlePackageType</key><string>APPL</string>
	<key>CFBundleShortVersionString</key><string>$version</string>
	<key>CFBundleVersion</key><string>$version</string>
	<key>LSMinimumSystemVersion</key><string>13.0</string>
	<key>LSUIElement</key><false/>
	<key>NSHighResolutionCapable</key><true/>
	<key>NSAccessibilityUsageDescription</key><string>Live review reads the elements of the simulator screen that you click.</string>
</dict>
</plist>
PLIST
if [ "$identity" = "-" ]; then
	codesign --force --sign - "$app"
else
	codesign --force --options runtime --timestamp --sign "$identity" "$app"
fi
echo "$app"
