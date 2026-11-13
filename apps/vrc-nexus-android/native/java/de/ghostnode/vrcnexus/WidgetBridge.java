package de.ghostnode.vrcnexus;

import android.content.Context;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/** Von der App aufgerufen: speichert die Zusammenfassung (JSON) und zeichnet alle Widgets neu. */
@CapacitorPlugin(name = "NexusWidget")
public class WidgetBridge extends Plugin {
    @PluginMethod
    public void update(PluginCall call) {
        String data = call.getString("data", "{}");
        getContext().getSharedPreferences(NexusWidget.PREFS, Context.MODE_PRIVATE).edit().putString("data", data).apply();
        NexusWidget.refreshAll(getContext());
        call.resolve();
    }
}
