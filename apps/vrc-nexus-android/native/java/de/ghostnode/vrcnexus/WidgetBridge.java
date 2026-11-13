package de.ghostnode.vrcnexus;

import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;
import androidx.core.content.FileProvider;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.io.File;

/** Brücke zur App: Homescreen-Widget aktualisieren und heruntergeladene Updates (APK) installieren. */
@CapacitorPlugin(name = "NexusWidget")
public class WidgetBridge extends Plugin {
    @PluginMethod
    public void update(PluginCall call) {
        String data = call.getString("data", "{}");
        getContext().getSharedPreferences(NexusWidget.PREFS, Context.MODE_PRIVATE).edit().putString("data", data).apply();
        NexusWidget.refreshAll(getContext());
        call.resolve();
    }

    /** Öffnet den Android-Installationsdialog für eine heruntergeladene APK (im Cache-Ordner der App). */
    @PluginMethod
    public void installApk(PluginCall call) {
        Context ctx = getContext();
        String p = call.getString("path", "");
        if (p.startsWith("file://")) p = Uri.parse(p).getPath();
        File f = new File(p);
        if (!f.exists()) { call.reject("APK nicht gefunden: " + p); return; }
        // Android 8+: „Unbekannte Apps installieren“ muss für VRC Nexus erlaubt sein
        if (Build.VERSION.SDK_INT >= 26 && !ctx.getPackageManager().canRequestPackageInstalls()) {
            Intent s = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + ctx.getPackageName()));
            s.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            ctx.startActivity(s);
            call.reject("Bitte „Unbekannte Apps installieren“ für VRC Nexus erlauben und dann erneut auf Update tippen.", "PERMISSION");
            return;
        }
        try {
            Uri uri = FileProvider.getUriForFile(ctx, ctx.getPackageName() + ".fileprovider", f);
            Intent i = new Intent(Intent.ACTION_VIEW);
            i.setDataAndType(uri, "application/vnd.android.package-archive");
            i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            ctx.startActivity(i);
            call.resolve();
        } catch (Exception e) {
            call.reject("Installation konnte nicht geöffnet werden: " + e.getMessage());
        }
    }
}
