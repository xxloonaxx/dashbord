"""GHOST NODE · VRC Nexus Desktop-Client für Windows.

Liest die VRChat-Logs (Welten, Spieler, Avatare, Videos, Screenshots, Einladungen), schickt sie ans Dashboard,
führt OSC-Befehle aus dem Dashboard aus und bietet eine eigene OSC-Fernbedienung (Chatbox, Parameter, Rotator).

    GhostNodeNexus.exe                 Oberfläche
    GhostNodeNexus.exe --minimized     minimiert starten (Autostart)
    python ghostnode_nexus.py --headless [--once] [--import-all] [--server URL --token gnx_… --logdir PFAD]
"""
import argparse
import os
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from gn_core import APP, VERSION, Config, Service, set_autostart   # noqa: E402

KIND_LABEL = {"world_join": "🌐 Welt", "world_leave": "🚪 Verlassen", "player_join": "👋 Kam", "player_leave": "👋 Ging", "avatar": "🎭 Avatar",
              "video": "🎬 Video", "screenshot": "📸 Foto", "notification": "🔔 Info", "vrc_start": "▶ Start", "vrc_quit": "⏹ Ende"}


def describe(e: dict) -> str:
    k = e["kind"]
    if k == "world_join":
        return e["world_name"] or e["world_id"]
    if k in ("player_join", "player_leave"):
        return e["player_name"]
    if k == "notification":
        return "%s von %s" % (e["detail"], e["player_name"])
    return e.get("detail") or e.get("world_name") or ""


# ---------------------------------------------------------------- ohne Oberfläche

def headless(args, cfg: Config):
    svc = Service(cfg)
    if args.import_all:
        print("Importiere alle Logs …", svc.import_all(), "Ereignisse")
    if args.once:
        svc.start_listener()
        svc.hello()
        print("Server:", svc.server_msg)
        svc._vrc_check = time.time()
        svc.tick()
        svc._last_osc = 0
        svc.tick()   # zweiter Durchlauf: OSC abholen und ausführen
        while not svc.ui.empty():
            kind, data = svc.ui.get()
            if kind == "event":
                print(time.strftime("%H:%M:%S", time.localtime(data["ts"])), KIND_LABEL.get(data["kind"], data["kind"]), describe(data))
            elif kind == "osc":
                print("OSC ausgeführt:", data["kind"], data.get("payload"))
            elif kind == "error":
                print("!", data)
        print("Hochgeladen:", svc.uploaded, "· offen:", len(svc.outbox), "· OSC:", svc.osc_done, "· Status:", svc.server_msg)
        if svc.listener:
            svc.listener.stop()
        return 0 if svc.server_ok is not False else 1
    svc.start()
    print("%s %s läuft – Strg+C zum Beenden. Logs: %s" % (APP, VERSION, cfg.logs))
    try:
        while True:
            kind, data = svc.ui.get()
            if kind == "event":
                print(time.strftime("%H:%M:%S", time.localtime(data["ts"])), KIND_LABEL.get(data["kind"], data["kind"]), describe(data), flush=True)
            elif kind in ("server", "error"):
                print("·", data or svc.server_msg, flush=True)
    except KeyboardInterrupt:
        svc.stop()
    return 0


# ---------------------------------------------------------------- Oberfläche

def gui(args, cfg: Config):
    import tkinter as tk
    from tkinter import filedialog, messagebox, ttk

    svc = Service(cfg)
    root = tk.Tk()
    root.title("GHOST NODE · VRC Nexus Client " + VERSION)
    root.geometry("880x600")
    root.minsize(720, 480)
    BG, PANEL, FG, MUTED, CYAN, GOLD = "#05080d", "#0d1520", "#e2e8f0", "#7c8aa0", "#38d5e6", "#f3c262"
    root.configure(bg=BG)
    style = ttk.Style(root)
    try:
        style.theme_use("clam")
    except tk.TclError:
        pass
    style.configure(".", background=BG, foreground=FG, fieldbackground=PANEL, bordercolor="#1e3a46", font=("Segoe UI", 10))
    style.configure("TNotebook", background=BG, borderwidth=0)
    style.configure("TNotebook.Tab", background=PANEL, foreground=MUTED, padding=(14, 6))
    style.map("TNotebook.Tab", background=[("selected", "#12202c")], foreground=[("selected", CYAN)])
    style.configure("TButton", background="#12202c", foreground=CYAN, padding=(10, 4))
    style.map("TButton", background=[("active", "#183040")])
    style.configure("Gold.TButton", foreground=GOLD)
    style.configure("Treeview", background=PANEL, fieldbackground=PANEL, foreground=FG, rowheight=24)
    style.configure("Treeview.Heading", background="#12202c", foreground=CYAN)
    style.configure("TLabelframe", background=BG, foreground=CYAN)
    style.configure("TLabelframe.Label", background=BG, foreground=CYAN)
    style.configure("Muted.TLabel", foreground=MUTED)
    style.configure("Big.TLabel", font=("Segoe UI", 15, "bold"), foreground=GOLD)
    style.configure("TCheckbutton", background=BG, foreground=FG)
    style.configure("TEntry", foreground=FG, insertcolor=FG)

    nb = ttk.Notebook(root)
    nb.pack(fill="both", expand=True, padx=8, pady=8)

    # --- Status ---
    t_status = ttk.Frame(nb, padding=12)
    nb.add(t_status, text="📡 Status")
    lbl_server = ttk.Label(t_status, text="Server: …")
    lbl_server.pack(anchor="w")
    lbl_vrc = ttk.Label(t_status, text="VRChat: …")
    lbl_vrc.pack(anchor="w", pady=(4, 0))
    lbl_osc = ttk.Label(t_status, text="OSC: …", style="Muted.TLabel")
    lbl_osc.pack(anchor="w", pady=(4, 10))
    lbl_world = ttk.Label(t_status, text="", style="Big.TLabel")
    lbl_world.pack(anchor="w")
    lbl_world2 = ttk.Label(t_status, text="", style="Muted.TLabel")
    lbl_world2.pack(anchor="w", pady=(0, 8))
    players = ttk.Treeview(t_status, columns=("name", "dauer"), show="headings", height=12)
    players.heading("name", text="Spieler in der Instanz")
    players.heading("dauer", text="da seit")
    players.column("dauer", width=110, anchor="e")
    players.pack(fill="both", expand=True)
    lbl_counts = ttk.Label(t_status, text="", style="Muted.TLabel")
    lbl_counts.pack(anchor="w", pady=(6, 0))

    # --- Log ---
    t_log = ttk.Frame(nb, padding=12)
    nb.add(t_log, text="📜 Spiel-Log")
    bar = ttk.Frame(t_log)
    bar.pack(fill="x", pady=(0, 6))
    ttk.Label(bar, text="Filter:").pack(side="left")
    flt = tk.StringVar()
    ttk.Entry(bar, textvariable=flt, width=30).pack(side="left", padx=6)
    log = ttk.Treeview(t_log, columns=("zeit", "art", "text"), show="headings")
    for c, t, w in (("zeit", "Zeit", 90), ("art", "Art", 110), ("text", "Was", 600)):
        log.heading(c, text=t)
        log.column(c, width=w, anchor="w", stretch=(c == "text"))
    log.pack(fill="both", expand=True)
    rows = []

    def add_row(e):
        rows.append(e)
        if len(rows) > 3000:
            del rows[:500]
        f = flt.get().lower().strip()
        txt = describe(e)
        if f and f not in txt.lower() and f not in e["kind"]:
            return
        log.insert("", 0, values=(time.strftime("%H:%M:%S", time.localtime(e["ts"])), KIND_LABEL.get(e["kind"], e["kind"]), txt))
        kids = log.get_children()
        if len(kids) > 1500:
            log.delete(*kids[1500:])

    def refilter(*_):
        log.delete(*log.get_children())
        f = flt.get().lower().strip()
        for e in list(rows)[-1500:]:
            txt = describe(e)
            if f and f not in txt.lower() and f not in e["kind"]:
                continue
            log.insert("", 0, values=(time.strftime("%H:%M:%S", time.localtime(e["ts"])), KIND_LABEL.get(e["kind"], e["kind"]), txt))
    flt.trace_add("write", refilter)

    def do_import():
        if messagebox.askyesno(APP, "Alle vorhandenen VRChat-Logs einlesen und hochladen?\n(Doppelte Einträge erkennt der Server.)"):
            n = svc.import_all()
            messagebox.showinfo(APP, "%d Ereignisse eingelesen – sie werden im Hintergrund hochgeladen." % n)
    ttk.Button(bar, text="Alte Logs importieren", command=do_import).pack(side="right")

    # --- OSC ---
    t_osc = ttk.Frame(nb, padding=12)
    nb.add(t_osc, text="🎛️ OSC")
    fc = ttk.Labelframe(t_osc, text=" Chatbox ", padding=10)
    fc.pack(fill="x")
    chat = tk.StringVar()
    e_chat = ttk.Entry(fc, textvariable=chat)
    e_chat.pack(side="left", fill="x", expand=True)
    snd = tk.BooleanVar(value=True)
    ttk.Checkbutton(fc, text="Ton", variable=snd).pack(side="left", padx=6)

    def send_chat(*_):
        txt = chat.get().strip()
        if txt:
            try:
                svc.sender.chatbox(txt, snd.get())
                chat.set("")
            except OSError as ex:
                messagebox.showerror(APP, str(ex))
    e_chat.bind("<Return>", send_chat)
    ttk.Button(fc, text="Senden", style="Gold.TButton", command=send_chat).pack(side="left")
    ttk.Button(fc, text="⌨ tippt", command=lambda: svc.sender.typing(True)).pack(side="left", padx=(6, 0))
    ttk.Button(fc, text="⌨ aus", command=lambda: svc.sender.typing(False)).pack(side="left", padx=(4, 0))

    fr = ttk.Labelframe(t_osc, text=" Chatbox-Rotator (automatischer Status) ", padding=10)
    fr.pack(fill="x", pady=8)
    rot_on = tk.BooleanVar(value=cfg["rotator_enabled"])
    rot_txt = tk.StringVar(value=cfg["rotator_text"])
    rot_sec = tk.IntVar(value=cfg["rotator_seconds"])
    ttk.Checkbutton(fr, text="aktiv", variable=rot_on).grid(row=0, column=0, sticky="w")
    ttk.Entry(fr, textvariable=rot_txt, width=60).grid(row=0, column=1, sticky="we", padx=6)
    ttk.Label(fr, text="alle").grid(row=0, column=2)
    ttk.Spinbox(fr, from_=5, to=600, textvariable=rot_sec, width=5).grid(row=0, column=3, padx=4)
    ttk.Label(fr, text="s").grid(row=0, column=4)
    fr.columnconfigure(1, weight=1)
    ttk.Label(fr, text="Platzhalter: {zeit} {datum} {welt} {spieler} {avatar} {dauer} {name}", style="Muted.TLabel").grid(row=1, column=1, sticky="w", padx=6)

    def save_rot(*_):
        cfg["rotator_enabled"], cfg["rotator_text"] = bool(rot_on.get()), rot_txt.get()[:200]
        try:
            cfg["rotator_seconds"] = max(5, int(rot_sec.get()))
        except (tk.TclError, ValueError):
            pass
        cfg.save()
    for v in (rot_on, rot_txt, rot_sec):
        v.trace_add("write", save_rot)

    fp = ttk.Labelframe(t_osc, text=" Avatar-Parameter (Doppelklick: An/Aus umschalten) ", padding=10)
    fp.pack(fill="both", expand=True)
    pbar = ttk.Frame(fp)
    pbar.pack(fill="x", pady=(0, 6))
    p_name, p_type, p_val = tk.StringVar(), tk.StringVar(value="bool"), tk.StringVar(value="1")
    ttk.Entry(pbar, textvariable=p_name, width=28).pack(side="left")
    ttk.Combobox(pbar, textvariable=p_type, values=("bool", "int", "float"), width=6, state="readonly").pack(side="left", padx=4)
    ttk.Entry(pbar, textvariable=p_val, width=8).pack(side="left")

    def send_param():
        try:
            t, v = p_type.get(), p_val.get().replace(",", ".")
            val = v.lower() in ("1", "true", "an", "on", "ja") if t == "bool" else int(v) if t == "int" else float(v)
            svc.sender.parameter(p_name.get().strip(), val)
        except (ValueError, OSError) as ex:
            messagebox.showerror(APP, str(ex))
    ttk.Button(pbar, text="Setzen", style="Gold.TButton", command=send_param).pack(side="left", padx=6)
    params = ttk.Treeview(fp, columns=("name", "wert"), show="headings", height=8)
    params.heading("name", text="Parameter (von VRChat empfangen)")
    params.heading("wert", text="Wert")
    params.column("wert", width=120, anchor="e")
    params.pack(fill="both", expand=True)

    def toggle_param(_):
        sel = params.selection()
        if not sel:
            return
        name, val = params.item(sel[0], "values")
        p_name.set(name)
        cur = svc.listener.params.get(name) if svc.listener else None
        if isinstance(cur, bool):
            svc.sender.parameter(name, not cur)
        else:
            p_type.set("int" if isinstance(cur, int) else "float")
            p_val.set(str(cur))
    params.bind("<Double-1>", toggle_param)

    # --- Einstellungen ---
    t_set = ttk.Frame(nb, padding=16)
    nb.add(t_set, text="⚙ Einstellungen")
    vars_ = {}
    fields = [("server", "Server-Adresse (Dashboard)", "z. B. https://meine-seite.de/"), ("token", "Geräte-Token", "gnx_… aus Nexus → 🖥️ Client → Geräte"),
              ("log_dir", "VRChat-Log-Ordner", "leer = Standard"), ("osc_host", "OSC-Ziel (VRChat)", "127.0.0.1"),
              ("osc_send_port", "OSC senden an Port", "9000"), ("osc_listen_port", "OSC empfangen auf Port", "9001")]
    for i, (k, label, hint) in enumerate(fields):
        ttk.Label(t_set, text=label).grid(row=i, column=0, sticky="w", pady=4)
        vars_[k] = tk.StringVar(value=str(cfg[k]))
        ent = ttk.Entry(t_set, textvariable=vars_[k], width=56, show="•" if k == "token" else "")
        ent.grid(row=i, column=1, sticky="we", padx=8)
        ttk.Label(t_set, text=hint, style="Muted.TLabel").grid(row=i, column=2, sticky="w")
    ttk.Button(t_set, text="…", width=3, command=lambda: vars_["log_dir"].set(filedialog.askdirectory(initialdir=cfg.logs) or vars_["log_dir"].get())).grid(row=2, column=3)
    checks = {}
    for j, (k, label) in enumerate((("upload", "Spiel-Log ans Dashboard senden"), ("remote_osc", "OSC-Befehle aus dem Dashboard ausführen"),
                                    ("osc_listen", "Avatar-Parameter von VRChat mitlesen (Port 9001)"), ("autostart", "Mit Windows starten"))):
        checks[k] = tk.BooleanVar(value=bool(cfg[k]))
        ttk.Checkbutton(t_set, text=label, variable=checks[k]).grid(row=len(fields) + j, column=1, sticky="w", pady=2)
    t_set.columnconfigure(1, weight=1)
    msg = ttk.Label(t_set, text="", style="Muted.TLabel")

    def save_settings():
        for k, v in vars_.items():
            val = v.get().strip()
            if k.endswith("_port"):
                try:
                    val = int(val)
                except ValueError:
                    messagebox.showerror(APP, "Port muss eine Zahl sein.")
                    return
            cfg[k] = val
        for k, v in checks.items():
            cfg[k] = bool(v.get())
        cfg.save()
        note = ""
        try:
            note = set_autostart(cfg["autostart"]) if os.name == "nt" else ""
        except OSError as ex:
            note = "Autostart: %s" % ex
        svc.reconfigure()
        msg.configure(text="Gespeichert. " + svc.server_msg + (" · " + note if note else ""))
    ttk.Button(t_set, text="Speichern & verbinden", style="Gold.TButton", command=save_settings).grid(row=len(fields) + 5, column=1, sticky="w", pady=12)
    msg.grid(row=len(fields) + 6, column=1, sticky="w")
    ttk.Label(t_set, text="Tipp: In VRChat im Action-Menü → Optionen → OSC → „Aktiviert“. Dein VRChat-Passwort braucht dieser Client nicht.",
              style="Muted.TLabel", wraplength=640).grid(row=len(fields) + 7, column=0, columnspan=3, sticky="w", pady=(16, 0))

    # --- Aktualisieren ---
    def fmt_dur(sec):
        sec = int(max(0, sec))
        return "%d:%02d h" % (sec // 3600, sec // 60 % 60) if sec >= 3600 else "%d min" % (sec // 60)

    def refresh():
        while not svc.ui.empty():
            kind, data = svc.ui.get_nowait()
            if kind == "event":
                add_row(data)
            elif kind == "error":
                lbl_osc.configure(text=str(data))
        st = svc.state
        dot = {True: "🟢", False: "🔴", None: "⚪"}[svc.server_ok]
        lbl_server.configure(text="%s Server: %s" % (dot, svc.server_msg))
        lbl_vrc.configure(text="🥽 VRChat: %s%s" % ("läuft" if st.running else "nicht gestartet", (" · angemeldet als " + st.display_name) if st.display_name else ""))
        if svc.listener:
            lbl_osc.configure(text="🎛️ OSC: " + (svc.listener.error or ("empfängt Daten von VRChat" if svc.listener.active else "wartet auf VRChat (OSC im Spiel aktivieren)")))
        if st.world_name:
            inst = st.instance
            kind = "privat" if "~private" in inst else "Freunde" if "~friends" in inst else "Freunde+" if "~hidden" in inst else "Gruppe" if "~group" in inst else "öffentlich"
            lbl_world.configure(text="🌐 " + st.world_name)
            lbl_world2.configure(text="%s · seit %s · %d Spieler%s" % (kind, fmt_dur(time.time() - st.since), len(st.players), (" · 🎭 " + st.avatar) if st.avatar else ""))
        else:
            lbl_world.configure(text="")
            lbl_world2.configure(text="In keiner Welt." if st.running else "")
        players.delete(*players.get_children())
        for p in sorted(st.players.values(), key=lambda p: p["name"].lower()):
            players.insert("", "end", values=(p["name"], fmt_dur(time.time() - p["since"])))
        lbl_counts.configure(text="Hochgeladen: %d · in der Warteschlange: %d · OSC-Befehle ausgeführt: %d" % (svc.uploaded, len(svc.outbox), svc.osc_done))
        if svc.listener:
            have = {params.item(i, "values")[0]: i for i in params.get_children()}
            for name, val in sorted(svc.listener.params.items())[:200]:
                v = ("An" if val else "Aus") if isinstance(val, bool) else str(val)
                if name in have:
                    params.item(have[name], values=(name, v))
                else:
                    params.insert("", "end", values=(name, v))
        root.after(1000, refresh)

    def on_close():
        svc.stop()
        root.after(300, root.destroy)
    root.protocol("WM_DELETE_WINDOW", on_close)
    svc.start()
    if not cfg["server"] or not cfg["token"]:
        nb.select(t_set)
    if args.minimized:
        root.iconify()
    root.after(500, refresh)
    root.mainloop()
    return 0


def main(argv=None):
    ap = argparse.ArgumentParser(description="GHOST NODE · VRC Nexus Desktop-Client")
    ap.add_argument("--headless", action="store_true", help="ohne Oberfläche (Konsole)")
    ap.add_argument("--once", action="store_true", help="nur einmal lesen/hochladen/OSC abholen und beenden")
    ap.add_argument("--import-all", action="store_true", help="alle vorhandenen Logs einlesen")
    ap.add_argument("--minimized", action="store_true", help="minimiert starten")
    ap.add_argument("--server")
    ap.add_argument("--token")
    ap.add_argument("--logdir")
    ap.add_argument("--config", help="eigene Konfigurationsdatei")
    ap.add_argument("--osc-port", type=int, help="OSC-Zielport (Standard 9000)")
    ap.add_argument("--osc-listen-port", type=int, help="OSC-Empfangsport (Standard 9001)")
    args = ap.parse_args(argv)
    cfg = Config(args.config)
    for k, v in (("server", args.server), ("token", args.token), ("log_dir", args.logdir), ("osc_send_port", args.osc_port), ("osc_listen_port", args.osc_listen_port)):
        if v:
            cfg[k] = v
    if args.headless:
        return headless(args, cfg)
    return gui(args, cfg)


if __name__ == "__main__":
    sys.exit(main())
