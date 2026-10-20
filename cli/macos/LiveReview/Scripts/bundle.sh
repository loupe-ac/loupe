#!/bin/sh
# Builds the panel and wraps it in "Live review.app" with an ad hoc signature.
# The signature is for local runs only: macOS drops the Accessibility grant of an
# ad hoc build when the code changes.
set -eu

cd "$(dirname "$0")/.."
out="${1:-.build/bundle}"

swift build -c release --arch arm64 --arch x86_64
bin="$(swift build -c release --arch arm64 --arch x86_64 --show-bin-path)/LiveReview"

app="$out/Live review.app"
rm -rf "$app"
mkdir -p "$app/Contents/MacOS"
cp "$bin" "$app/Contents/MacOS/LiveReview"
cat > "$app/Contents/Info.plist" <<'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleIdentifier</key><string>ac.loupe.live-review</string>
	<key>CFBundleName</key><string>Live review</string>
	<key>CFBundleDisplayName</key><string>Live review</string>
	<key>CFBundleExecutable</key><string>LiveReview</string>
	<key>CFBundlePackageType</key><string>APPL</string>
	<key>CFBundleShortVersionString</key><string>0.0.0</string>
	<key>CFBundleVersion</key><string>1</string>
	<key>LSMinimumSystemVersion</key><string>13.0</string>
	<key>LSUIElement</key><false/>
	<key>NSHighResolutionCapable</key><true/>
	<key>NSAccessibilityUsageDescription</key><string>Live review reads the elements of the simulator screen that you click.</string>
</dict>
</plist>
PLIST
codesign --force --sign - "$app"
echo "$app"
