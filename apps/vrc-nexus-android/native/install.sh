#!/bin/sh
# Kopiert die nativen Teile (Homescreen-Widget, MainActivity mit Plugin) in das von „npx cap add android“ erzeugte Projekt.
set -e
cd "$(dirname "$0")/.."
SRC=android/app/src/main
cp native/java/de/ghostnode/vrcnexus/*.java "$SRC/java/de/ghostnode/vrcnexus/"
cp -r native/res/* "$SRC/res/"
grep -q 'NexusWidget' "$SRC/AndroidManifest.xml" || sed -i 's#</application>#    <receiver android:name=".NexusWidget" android:exported="true" android:label="VRC Nexus">\n            <intent-filter><action android:name="android.appwidget.action.APPWIDGET_UPDATE" /></intent-filter>\n            <meta-data android:name="android.appwidget.provider" android:resource="@xml/nexus_widget_info" />\n        </receiver>\n    </application>#' "$SRC/AndroidManifest.xml"
echo "Native Teile installiert."
