package de.ghostnode.vrcnexus;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.widget.RemoteViews;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import org.json.JSONArray;
import org.json.JSONObject;

/** Homescreen-Widget: Freunde im Spiel/online, ungelesene Benachrichtigungen, die ersten Freunde mit Welt. Tippen öffnet die App. */
public class NexusWidget extends AppWidgetProvider {
    static final String PREFS = "nexus_widget";

    @Override
    public void onUpdate(Context ctx, AppWidgetManager mgr, int[] ids) {
        for (int id : ids) mgr.updateAppWidget(id, build(ctx));
    }

    static void refreshAll(Context ctx) {
        AppWidgetManager mgr = AppWidgetManager.getInstance(ctx);
        int[] ids = mgr.getAppWidgetIds(new ComponentName(ctx, NexusWidget.class));
        for (int id : ids) mgr.updateAppWidget(id, build(ctx));
    }

    static RemoteViews build(Context ctx) {
        RemoteViews v = new RemoteViews(ctx.getPackageName(), R.layout.nexus_widget);
        String raw = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString("data", "");
        String head = "VRC Nexus öffnen", line = "Noch keine Daten – App einmal öffnen.", list = "", foot = "";
        try {
            if (raw != null && raw.length() > 2) {
                JSONObject o = new JSONObject(raw);
                String me = o.optString("me", "");
                head = me.isEmpty() ? "VRC Nexus" : me + statusDot(o.optString("status", ""));
                // Zeile in der gewählten App-Sprache (kommt fertig aus der App), sonst Deutsch
                line = o.optString("line", o.optInt("in_game", 0) + " im Spiel · " + o.optInt("online", 0) + " online") + (o.optInt("notifs", 0) > 0 ? " · 🔔 " + o.optInt("notifs", 0) : "");
                JSONArray top = o.optJSONArray("top");
                StringBuilder sb = new StringBuilder();
                if (top != null) for (int i = 0; i < top.length() && i < 5; i++) sb.append(i > 0 ? "\n" : "").append("• ").append(top.optString(i));
                list = sb.toString();
                long ts = o.optLong("ts", 0);
                if (ts > 0) foot = o.optString("stand", "Stand") + " " + new SimpleDateFormat("HH:mm", Locale.GERMANY).format(new Date(ts * 1000));
            }
        } catch (Exception e) { line = "⚠ " + e.getMessage(); }
        v.setTextViewText(R.id.w_head, head);
        v.setTextViewText(R.id.w_line, line);
        v.setTextViewText(R.id.w_list, list);
        v.setTextViewText(R.id.w_foot, foot);
        Intent open = new Intent(ctx, MainActivity.class);
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent pi = PendingIntent.getActivity(ctx, 0, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        v.setOnClickPendingIntent(R.id.w_root, pi);
        return v;
    }

    private static String statusDot(String s) {
        switch (s) {
            case "join me": return "  🔵";
            case "active": return "  🟢";
            case "ask me": return "  🟠";
            case "busy": return "  🔴";
            default: return "";
        }
    }
}
