"""VRChat-Logdateien lesen: Welten, Spieler, Avatare, Videos, Screenshots, Benachrichtigungen.

Logs liegen unter Windows in  %LOCALAPPDATA%Low\\VRChat\\VRChat\\output_log_YYYY-MM-DD_HH-MM-SS.txt
(VRChat legt bei jedem Start eine neue Datei an). Zeilenformat:
    2026.11.12 20:31:12 Log        -  [Behaviour] OnPlayerJoined Name (usr_…)
"""
import glob
import os
import re
import time

__all__ = ["default_log_dir", "LogParser", "LogWatcher", "SessionState", "parse_ts"]

LINE = re.compile(r"^(\d{4})\.(\d{2})\.(\d{2}) (\d{2}):(\d{2}):(\d{2}) +(\w+) +- +(.*)$")
USR = r"(usr_[0-9a-fA-F\-]{8,40}|[0-9A-Za-z]{10})"
PATTERNS = [
    ("joining", re.compile(r"^\[Behaviour\] Joining ((?:wrld|wld)_[0-9a-fA-F\-]{8,40}):(.*)$")),
    ("room", re.compile(r"^\[Behaviour\] (?:Joining or Creating Room|Entering Room): (.+)$")),
    ("left_room", re.compile(r"^\[Behaviour\] OnLeftRoom\s*$")),
    ("player_join", re.compile(r"^\[Behaviour\] OnPlayerJoined (.+?)(?: \(" + USR + r"\))?\s*$")),
    ("player_leave", re.compile(r"^\[Behaviour\] OnPlayerLeft (.+?)(?: \(" + USR + r"\))?\s*$")),
    ("avatar", re.compile(r"^\[Behaviour\] Switching (.+?) to avatar (.+?)\s*$")),
    ("video", re.compile(r"^\[Video Playback\] Attempting to resolve URL '(.+)'\s*$")),
    ("screenshot", re.compile(r"^\[VRC Camera\] Took screenshot to: (.+?)\s*$")),
    ("auth", re.compile(r"User Authenticated: (.+?) \(" + USR + r"\)")),
    ("notification", re.compile(r"Received Notification: <Notification from username:(.*?), sender user id:" + USR + r".*?of type:\s*(\w+)")),
    ("quit", re.compile(r"VRCApplication: (?:OnApplicationQuit|HandleApplicationQuit)")),
]
NOTIFY = {"invite": "Einladung", "requestInvite": "Einladungsanfrage", "friendRequest": "Freundschaftsanfrage",
          "inviteResponse": "Antwort auf Einladung", "requestInviteResponse": "Antwort auf Anfrage", "votetokick": "Abstimmung zum Kicken"}


def default_log_dir() -> str:
    """Standard-Ordner der VRChat-Logs unter Windows (LocalLow)."""
    base = os.environ.get("USERPROFILE") or os.path.expanduser("~")
    return os.path.join(base, "AppData", "LocalLow", "VRChat", "VRChat")


def parse_ts(y, mo, d, h, mi, s) -> int:
    """Zeit aus dem Log (Ortszeit des PCs) → Unix-Zeit."""
    return int(time.mktime((int(y), int(mo), int(d), int(h), int(mi), int(s), 0, 0, -1)))


class SessionState:
    """Was gerade in VRChat los ist (aus dem Log mitgeschrieben)."""

    def __init__(self):
        self.reset_world()
        self.display_name = ""
        self.user_id = ""
        self.avatar = ""
        self.running = False
        self.last_line = 0

    def reset_world(self):
        self.world_id = ""
        self.instance = ""
        self.world_name = ""
        self.since = 0
        self.players = {}   # Schlüssel (ID oder Name) → {"id", "name", "since"}

    def snapshot(self) -> dict:
        return {"running": self.running, "world_id": self.world_id, "instance": self.instance, "world_name": self.world_name,
                "since": self.since, "players": sorted(self.players.values(), key=lambda p: p["name"].lower()),
                "avatar": self.avatar, "display_name": self.display_name}


class LogParser:
    """Wandelt Logzeilen in Ereignisse für das Dashboard um und führt den SessionState nach."""

    def __init__(self, state: SessionState = None):
        self.state = state or SessionState()
        self._pending = None   # (world_id, instance) aus „Joining wrld_…“

    def feed(self, line: str):
        """Eine Zeile verarbeiten → Liste von Ereignissen (dicts)."""
        m = LINE.match(line.rstrip("\r\n"))
        if not m:
            return []
        ts = parse_ts(*m.group(1, 2, 3, 4, 5, 6))
        msg = m.group(8).strip()
        st = self.state
        st.last_line = ts
        for kind, rx in PATTERNS:
            p = rx.search(msg) if kind in ("auth", "notification", "quit") else rx.match(msg)
            if not p:
                continue
            if kind == "joining":
                self._pending = (p.group(1), p.group(2).strip())
                return []
            if kind == "room":
                wid, inst = self._pending or ("", "")
                self._pending = None
                st.reset_world()
                st.running = True
                st.world_id, st.instance, st.world_name, st.since = wid, inst, p.group(1).strip(), ts
                return [self._ev(ts, "world_join")]
            if kind == "left_room":
                if not st.world_name and not st.world_id:
                    return []
                ev = self._ev(ts, "world_leave")
                st.reset_world()
                return [ev]
            if kind in ("player_join", "player_leave"):
                name, uid = p.group(1).strip(), (p.group(2) or "")
                key = uid or "n:" + name
                if kind == "player_join":
                    st.players[key] = {"id": uid, "name": name, "since": ts}
                else:
                    st.players.pop(key, None)
                    if not uid:   # ältere Logs ohne ID: per Name entfernen
                        for k in [k for k, v in st.players.items() if v["name"] == name]:
                            st.players.pop(k, None)
                return [self._ev(ts, kind, player_id=uid, player_name=name)]
            if kind == "avatar":
                who, avatar = p.group(1).strip(), p.group(2).strip()
                if st.display_name and who == st.display_name:
                    st.avatar = avatar
                    return [self._ev(ts, "avatar", player_name=who, detail=avatar)]
                return []   # Avatare anderer Spieler nicht hochladen (zu viele Ereignisse)
            if kind == "video":
                return [self._ev(ts, "video", detail=p.group(1)[:500])]
            if kind == "screenshot":
                return [self._ev(ts, "screenshot", detail=p.group(1)[:500])]
            if kind == "auth":
                st.display_name, st.user_id = p.group(1).strip(), p.group(2)
                st.running = True
                return []
            if kind == "notification":
                typ = p.group(3)
                return [self._ev(ts, "notification", player_id=p.group(2), player_name=p.group(1).strip(), detail=NOTIFY.get(typ, typ))]
            if kind == "quit":
                ev = self._ev(ts, "vrc_quit")
                st.reset_world()
                st.running = False
                return [ev]
        return []

    def _ev(self, ts, kind, **kw):
        st = self.state
        ev = {"ts": ts, "kind": kind, "world_id": st.world_id, "instance": st.instance, "world_name": st.world_name,
              "player_id": "", "player_name": "", "detail": ""}
        ev.update(kw)
        return ev


def log_files(log_dir: str):
    """Alle VRChat-Logdateien, älteste zuerst."""
    return sorted(glob.glob(os.path.join(log_dir, "output_log_*.txt")), key=lambda f: (os.path.getmtime(f), f))


class LogWatcher:
    """Liest die jeweils neueste Logdatei mit (wie „tail -f“) und wechselt bei einem Neustart von VRChat zur neuen Datei."""

    def __init__(self, log_dir: str, parser: LogParser = None):
        self.log_dir = log_dir
        self.parser = parser or LogParser()
        self.file = None
        self.pos = 0
        self._buf = b""

    def import_all(self):
        """Alle vorhandenen Logdateien komplett einlesen (z. B. für den ersten Import)."""
        events = []
        for f in log_files(self.log_dir):
            p = LogParser()
            with open(f, "r", encoding="utf-8", errors="replace") as fh:
                for line in fh:
                    events.extend(p.feed(line))
        return events

    def poll(self):
        """Neue Zeilen lesen → Liste neuer Ereignisse."""
        files = log_files(self.log_dir)
        if not files:
            return []
        newest = files[-1]
        events = []
        if newest != self.file:
            if self.file is not None:
                events.extend(self._read())   # Rest der alten Datei
            self.file, self.pos, self._buf = newest, 0, b""
            self.parser.state.__init__()
            events.append({"ts": self._file_start(newest), "kind": "vrc_start", "world_id": "", "instance": "",
                           "world_name": "", "player_id": "", "player_name": "", "detail": os.path.basename(newest)})
        events.extend(self._read())
        return events

    @staticmethod
    def _file_start(path: str) -> int:
        m = re.search(r"output_log_(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})", os.path.basename(path))
        return parse_ts(*m.groups()) if m else int(os.path.getctime(path))

    def _read(self):
        try:
            size = os.path.getsize(self.file)
        except OSError:
            return []
        if size < self.pos:   # Datei wurde neu angelegt
            self.pos, self._buf = 0, b""
        if size == self.pos:
            return []
        with open(self.file, "rb") as fh:
            fh.seek(self.pos)
            chunk = fh.read()
            self.pos = fh.tell()
        lines = (self._buf + chunk).split(b"\n")
        self._buf = lines.pop()   # unvollständige letzte Zeile aufheben
        events = []
        for line in lines:
            events.extend(self.parser.feed(line.decode("utf-8", "replace")))
        return events
