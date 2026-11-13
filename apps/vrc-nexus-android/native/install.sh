#!/bin/sh
# Kopiert die nativen Teile (Homescreen-Widget, MainActivity mit Plugin) in das von „npx cap add android“ erzeugte Projekt.
set -e
cd "$(dirname "$0")/.."
SRC=android/app/src/main
cp native/java/de/ghostnode/vrcnexus/*.java "$SRC/java/de/ghostnode/vrcnexus/"
cp -r native/res/* "$SRC/res/"
grep -q 'NexusWidget' "$SRC/AndroidManifest.xml" || sed -i 's#</application>#    <receiver android:name=".NexusWidget" android:exported="true" android:label="VRC Nexus">\n            <intent-filter><action android:name="android.appwidget.action.APPWIDGET_UPDATE" /></intent-filter>\n            <meta-data android:name="android.appwidget.provider" android:resource="@xml/nexus_widget_info" />\n        </receiver>\n    </application>#' "$SRC/AndroidManifest.xml"
# Updates selbst installieren dürfen (Android fragt den Nutzer trotzdem)
grep -q 'REQUEST_INSTALL_PACKAGES' "$SRC/AndroidManifest.xml" || sed -i 's#<application#<uses-permission android:name="android.permission.REQUEST_INSTALL_PACKAGES" />\n    <application#' "$SRC/AndroidManifest.xml"
# FileProvider von Capacitor muss den Cache-Ordner freigeben (dort landet die heruntergeladene APK)
FP="$SRC/res/xml/file_paths.xml"
if [ -f "$FP" ] && ! grep -q 'cache-path' "$FP"; then sed -i 's#</paths>#    <cache-path name="nexus_cache" path="." />\n</paths>#' "$FP"; fi
if [ ! -f "$FP" ]; then printf '<?xml version="1.0" encoding="utf-8"?>\n<paths xmlns:android="http://schemas.android.com/apk/res/android">\n    <cache-path name="nexus_cache" path="." />\n</paths>\n' > "$FP"; fi
grep -n 'fileprovider\|REQUEST_INSTALL' "$SRC/AndroidManifest.xml" || true
cat "$FP"
echo "Native Teile installiert."
