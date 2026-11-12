/*
 * Optionales Skript eines Widgets. Wird einmal pro Seite geladen.
 * Widgets.register('<ordnername>', function (el, w) { … }) wird bei jedem (Neu-)Zeichnen aufgerufen:
 *   el        – das <section class="wg">-Element dieser Instanz
 *   w.uid     – ID der Instanz
 *   w.action(op, daten) – Aktion an den Server schicken (ruft 'action' in widget.php auf, danach neu zeichnen)
 *   w.reload()          – Widget neu vom Server holen
 * Gib optional eine Funktion zurück – sie wird beim nächsten Neuzeichnen aufgerufen (z. B. Timer stoppen).
 */
Widgets.register('_vorlage', function (el, w) {
    const t = setInterval(() => { /* z. B. etwas animieren */ }, 1000);
    return () => clearInterval(t);
});
