"""Einstellungen, Server-Verbindung und der Hintergrund-Dienst des Clients (ohne Oberfläche)."""
import json
import os
import platform
import queue
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

from gn_logparse import LogParser, LogWatcher, SessionState, default_log_dir
from gn_osc import OscListener, OscSender

VERSION = "1.0.1"
APP = "GhostNodeNexus"


# ---------- Einstellungen ----------

def config_dir() -> str:
    if os.name == "nt":
        base = os.environ.get("APPDATA") or os.path.expanduser("~")
        d = os.path.join(base, APP)
    else:
        d = os.path.join(os.environ.get("XDG_CONFIG_HOME") or os.path.expanduser("~/.config"), "ghostnode-nexus")
    os.makedirs(d, exist_ok=True)
    return d


DEFAULTS = {
    "server": "",            # z. B. https://meine-seite.de/  (Ordner mit nexus.php / client.php)
    "token": "",             # gnx_… aus Nexus → 🖥️ Client → Geräte
    "log_dir": "",           # leer = Standardordner von VRChat
    "upload": True,          # Spiel-Log ans Dashboard senden
    "remote_osc": True,      # OSC-Befehle vom Dashboard ausführen
    "osc_host": "127.0.0.1",
    "osc_send_port": 9000,
    "osc_listen_port": 9001,
    "osc_listen": True,
    "rotator_enabled": False,
    "rotator_text": "🕒 {zeit} · 🌐 {welt} · 👥 {spieler}",
    "rotator_seconds": 30,
    "autostart": False,
}


class Config(dict):
    def __init__(self, path: str = None):
        super().__init__(DEFAULTS)
        self.path = path or os.path.join(config_dir(), "config.json")
        try:
            with open(self.path, "r", encoding="utf-8") as fh:
                data = json.load(fh)
            self.update({k: v for k, v in data.items() if k in DEFAULTS})
        except (OSError, ValueError):
            pass

    def save(self):
        tmp = self.path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(dict(self), fh, indent=2, ensure_ascii=False)
        os.replace(tmp, self.path)

    @property
    def logs(self) -> str:
        return self.get("log_dir") or default_log_dir()


def set_autostart(enabled: bool) -> str:
    """Mit Windows starten (Registry HKCU\\…\\Run). Gibt eine Meldung zurück."""
    if os.name != "nt":
        return "Autostart gibt es nur unter Windows."
    import winreg   # nur unter Windows vorhanden
    key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Software\Microsoft\Windows\CurrentVersion\Run", 0, winreg.KEY_SET_VALUE)
    try:
        if enabled:
            exe = sys.executable if getattr(sys, "frozen", False) else '"%s" "%s"' % (sys.executable.replace("python.exe", "pythonw.exe"), os.path.abspath(sys.argv[0]))
            winreg.SetValueEx(key, APP, 0, winreg.REG_SZ, exe if exe.startswith('"') else '"%s" --minimized' % exe)
        else:
            try:
                winreg.DeleteValue(key, APP)
            except FileNotFoundError:
                pass
    finally:
        winreg.CloseKey(key)
    return "Autostart " + ("aktiviert." if enabled else "deaktiviert.")


def vrchat_running() -> bool:
    """Läuft VRChat.exe? (nur Windows; sonst None = unbekannt)"""
    if os.name != "nt":
        return None
    try:
        out = subprocess.run(["tasklist", "/FI", "IMAGENAME eq VRChat.exe", "/NH"], capture_output=True, text=True, timeout=5,
                             creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0)).stdout
        return "VRChat.exe" in out
    except Exception:
        return None


# ---------- Server ----------

class ApiError(Exception):
    def __init__(self, msg, status=0, auth=False):
        super().__init__(msg)
        self.status = status
        self.auth = auth


class Api:
    def __init__(self, server: str, token: str):
        server = (server or "").strip()
        if server and not server.startswith(("http://", "https://")):
            server = "https://" + server
        if server.endswith(".php"):
            server = server.rsplit("/", 1)[0]
        self.base = server.rstrip("/") + "/client.php"
        self.token = (token or "").strip().strip("'\"").strip()

    @property
    def configured(self) -> bool:
        return self.base.startswith("http") and self.token.startswith("gnx_")

    def call(self, action: str, body: dict = None, timeout: float = 15):
        url = self.base + "?a=" + action
        data = json.dumps(body).encode("utf-8") if body is not None else None
        req = urllib.request.Request(url, data=data, method="POST" if data is not None else "GET", headers={
            "Authorization": "Bearer " + self.token, "X-Nexus-Token": self.token, "Accept": "application/json", "Content-Type": "application/json",
            "User-Agent": "GhostNodeNexus/" + VERSION, "X-Client-Version": VERSION, "X-Client-Platform": platform.system() + " " + platform.release()})
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return json.loads(r.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            try:
                d = json.loads(e.read().decode("utf-8"))
            except Exception:
                d = {}
            raise ApiError(d.get("error") or "HTTP %d" % e.code, e.code, bool(d.get("auth")))
        except urllib.error.URLError as e:
            raise ApiError("Server nicht erreichbar: %s" % e.reason)
        except ValueError:
            raise ApiError("Unerwartete Antwort vom Server (ist die Adresse richtig?)")


# ---------- Hintergrund-Dienst ----------

class Service:
    """Liest Logs, lädt hoch, holt OSC-Befehle ab, Chatbox-Rotator. Meldungen an die Oberfläche über self.ui (Queue)."""

    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.ui = queue.Queue()
        self.state = SessionState()
        self.watcher = LogWatcher(cfg.logs, LogParser(self.state))
        self.outbox = []            # noch nicht hochgeladene Ereignisse
        self.outbox_file = os.path.join(os.path.dirname(cfg.path), "outbox.json")
        self.sender = OscSender(cfg["osc_host"], cfg["osc_send_port"])
        self.listener = None
        self.api = Api(cfg["server"], cfg["token"])
        self.server_ok = None
        self.server_msg = "nicht eingerichtet"
        self.user = ""
        self.uploaded = 0
        self.osc_done = 0
        self._stop = threading.Event()
        self._last_state = 0
        self._last_osc = 0
        self._last_rot = 0
        self._backoff = 0
        self._vrc_check = 0
        self._vrc_proc = None
        self._load_outbox()

    # -- Hilfen --
    def emit(self, kind, data=None):
        self.ui.put((kind, data))

    def _load_outbox(self):
        try:
            with open(self.outbox_file, "r", encoding="utf-8") as fh:
                self.outbox = json.load(fh)[-20000:]
        except (OSError, ValueError):
            self.outbox = []

    def _save_outbox(self):
        try:
            with open(self.outbox_file, "w", encoding="utf-8") as fh:
                json.dump(self.outbox[-20000:], fh)
        except OSError:
            pass

    def reconfigure(self):
        self.api = Api(self.cfg["server"], self.cfg["token"])
        self.sender = OscSender(self.cfg["osc_host"], self.cfg["osc_send_port"])
        if self.watcher.log_dir != self.cfg.logs:
            self.watcher = LogWatcher(self.cfg.logs, LogParser(self.state))
        self.start_listener()
        self._backoff = 0
        self.hello()

    def start_listener(self):
        if self.listener:
            self.listener.stop()
            self.listener = None
        if self.cfg["osc_listen"]:
            self.listener = OscListener(self.cfg["osc_listen_port"])
            self.listener.start()

    def hello(self):
        if not self.api.configured:
            self.server_ok, self.server_msg = None, "Server-Adresse und Token unter Einstellungen eintragen"
            self.emit("server")
            return False
        try:
            d = self.api.call("hello")
            self.server_ok, self.user = True, d.get("user", {}).get("name", "")
            self.server_msg = "verbunden als %s (%s)" % (self.user, d.get("device", ""))
        except ApiError as e:
            self.server_ok, self.server_msg = False, str(e)
        self.emit("server")
        return bool(self.server_ok)

    def queue_events(self, events):
        if not events:
            return
        for e in events:
            self.emit("event", e)
        if self.cfg["upload"]:
            self.outbox.extend(events)

    def import_all(self):
        events = self.watcher.import_all()
        self.queue_events(events)
        return len(events)

    def rotator_text(self) -> str:
        st = self.state
        dur = int(time.time() - st.since) if st.since else 0
        vals = {"zeit": time.strftime("%H:%M"), "datum": time.strftime("%d.%m.%Y"), "welt": st.world_name or "–", "spieler": str(len(st.players)),
                "avatar": st.avatar or "–", "dauer": "%d:%02d" % (dur // 3600, dur // 60 % 60), "name": st.display_name or ""}
        text = self.cfg["rotator_text"]
        for k, v in vals.items():
            text = text.replace("{" + k + "}", v)
        return text[:144]

    def osc_snapshot(self) -> dict:
        snap = self.state.snapshot()
        if self.listener:
            snap["osc"] = self.listener.active
            snap["params"] = dict(list(self.listener.params.items())[:64])
            snap["avatar_id"] = self.listener.avatar_id
        else:
            snap["osc"], snap["params"], snap["avatar_id"] = False, {}, ""
        return snap

    # -- ein Durchlauf --
    def tick(self):
        now = time.time()
        try:
            self.queue_events(self.watcher.poll())
        except OSError as e:
            self.emit("error", "Log lesen: %s" % e)
        # VRChat-Prozess (Windows) alle 15 s prüfen
        if now - self._vrc_check > 15:
            self._vrc_check = now
            r = vrchat_running()
            if r is not None:
                if self._vrc_proc and not r and self.state.running:
                    self.state.running = False
                    self.state.reset_world()
                self._vrc_proc = r
                if r:
                    self.state.running = True
        # Chatbox-Rotator
        if self.cfg["rotator_enabled"] and now - self._last_rot >= max(5, int(self.cfg["rotator_seconds"])):
            self._last_rot = now
            try:
                self.sender.chatbox(self.rotator_text(), sound=False)
            except OSError as e:
                self.emit("error", "OSC: %s" % e)
        if not self.api.configured or now < self._backoff:
            return
        try:
            if self.outbox and self.cfg["upload"]:
                batch = self.outbox[:500]
                r = self.api.call("events", {"events": batch})
                self.outbox = self.outbox[len(batch):]
                self.uploaded += int(r.get("stored", 0))
                self._save_outbox()
            if now - self._last_state >= 20:
                self._last_state = now
                self.api.call("state", {"state": self.osc_snapshot()})
            if self.cfg["remote_osc"] and now - self._last_osc >= 3:
                self._last_osc = now
                cmds = self.api.call("osc").get("commands", [])
                results = []
                for c in cmds:
                    try:
                        self.sender.run_command(c["kind"], c.get("payload") or {})
                        results.append({"id": c["id"], "ok": True})
                        self.osc_done += 1
                        self.emit("osc", c)
                    except Exception as e:
                        results.append({"id": c["id"], "ok": False, "error": str(e)[:200]})
                if results:
                    self.api.call("osc_ack", {"results": results})
            if self.server_ok is not True:
                self.server_ok = True
                self.server_msg = "verbunden" + (" als " + self.user if self.user else "")
                self.emit("server")
            self._backoff = 0
        except ApiError as e:
            self.server_ok, self.server_msg = False, str(e)
            self.emit("server")
            self._save_outbox()
            self._backoff = now + (300 if e.auth else 30)

    def run(self):
        self.start_listener()
        self.hello()
        while not self._stop.is_set():
            try:
                self.tick()
            except Exception as e:   # nie abstürzen
                self.emit("error", "Fehler: %s" % e)
            self.emit("tick")
            self._stop.wait(1.0)
        self._save_outbox()
        if self.listener:
            self.listener.stop()

    def start(self):
        t = threading.Thread(target=self.run, daemon=True, name="service")
        t.start()
        return t

    def stop(self):
        self._stop.set()
