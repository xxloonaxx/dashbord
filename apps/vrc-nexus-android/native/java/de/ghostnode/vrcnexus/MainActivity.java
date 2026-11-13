package de.ghostnode.vrcnexus;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

/** Startet die WebView-App und meldet das Homescreen-Widget-Plugin an. */
public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(WidgetBridge.class);
        super.onCreate(savedInstanceState);
    }
}
