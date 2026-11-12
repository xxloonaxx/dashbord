"""OSC (Open Sound Control) für VRChat – nur Python-Standardbibliothek.

VRChat empfängt OSC auf UDP 9000 (Chatbox, Avatar-Parameter, Eingaben) und sendet auf UDP 9001
(Änderungen der Avatar-Parameter, Avatar-Wechsel). Siehe https://docs.vrchat.com/docs/osc-overview
"""
import socket
import struct
import threading
import time

__all__ = ["encode_message", "decode_packet", "OscSender", "OscListener", "chatbox_args"]


def _pad(b: bytes) -> bytes:
    """Auf ein Vielfaches von 4 Byte mit Nullbytes auffüllen (mindestens ein Nullbyte bei Zeichenketten)."""
    return b + b"\x00" * (4 - len(b) % 4)


def _osc_string(s: str) -> bytes:
    return _pad(s.encode("utf-8"))


def encode_message(address: str, *args) -> bytes:
    """OSC-Nachricht bauen. Typen: bool → T/F, int → i, float → f, str → s, bytes → b."""
    if not address.startswith("/"):
        raise ValueError("OSC-Adresse muss mit / beginnen")
    tags = ","
    data = b""
    for a in args:
        if a is True:
            tags += "T"
        elif a is False:
            tags += "F"
        elif a is None:
            tags += "N"
        elif isinstance(a, int):
            if not -2 ** 31 <= a < 2 ** 31:
                raise ValueError("Ganzzahl außerhalb von int32")
            tags += "i"
            data += struct.pack(">i", a)
        elif isinstance(a, float):
            tags += "f"
            data += struct.pack(">f", a)
        elif isinstance(a, str):
            tags += "s"
            data += _osc_string(a)
        elif isinstance(a, (bytes, bytearray)):
            tags += "b"
            data += struct.pack(">i", len(a)) + bytes(a) + b"\x00" * ((4 - len(a) % 4) % 4)
        else:
            raise TypeError("Nicht unterstützter OSC-Typ: %r" % type(a))
    return _osc_string(address) + _osc_string(tags) + data


def _read_string(data: bytes, pos: int):
    end = data.index(b"\x00", pos)
    s = data[pos:end].decode("utf-8", "replace")
    pos = end + 1
    pos += (4 - pos % 4) % 4
    return s, pos


def _decode_message(data: bytes):
    address, pos = _read_string(data, 0)
    if pos >= len(data):
        return address, []
    tags, pos = _read_string(data, pos)
    args = []
    for t in tags[1:]:
        if t == "i":
            args.append(struct.unpack(">i", data[pos:pos + 4])[0]); pos += 4
        elif t == "f":
            args.append(struct.unpack(">f", data[pos:pos + 4])[0]); pos += 4
        elif t == "d":
            args.append(struct.unpack(">d", data[pos:pos + 8])[0]); pos += 8
        elif t == "h":
            args.append(struct.unpack(">q", data[pos:pos + 8])[0]); pos += 8
        elif t == "s":
            s, pos = _read_string(data, pos); args.append(s)
        elif t == "b":
            n = struct.unpack(">i", data[pos:pos + 4])[0]; pos += 4
            args.append(data[pos:pos + n]); pos += n + (4 - n % 4) % 4
        elif t == "T":
            args.append(True)
        elif t == "F":
            args.append(False)
        elif t == "N":
            args.append(None)
        else:
            break   # unbekannter Typ – Rest ignorieren
    return address, args


def decode_packet(data: bytes):
    """Paket (Nachricht oder #bundle) → Liste von (adresse, [argumente])."""
    if data.startswith(b"#bundle\x00"):
        out = []
        pos = 16   # "#bundle\0" + 8 Byte Zeitstempel
        while pos + 4 <= len(data):
            n = struct.unpack(">i", data[pos:pos + 4])[0]
            pos += 4
            out.extend(decode_packet(data[pos:pos + n]))
            pos += n
        return out
    return [_decode_message(data)]


def chatbox_args(text: str, sound: bool = True):
    """Argumente für /chatbox/input: Text (max. 144 Zeichen), sofort senden, Benachrichtigungston."""
    text = text.replace("\r", "")[:144]
    return [text, True, bool(sound)]


class OscSender:
    """Schickt OSC an VRChat (Standard 127.0.0.1:9000). Chatbox-Nachrichten werden gedrosselt (VRChat-Limit)."""

    CHATBOX_GAP = 1.6

    def __init__(self, host: str = "127.0.0.1", port: int = 9000):
        self.addr = (host, int(port))
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self._last_chat = 0.0
        self._lock = threading.Lock()

    def send(self, address: str, *args) -> None:
        self.sock.sendto(encode_message(address, *args), self.addr)

    def chatbox(self, text: str, sound: bool = True) -> None:
        with self._lock:
            wait = self._last_chat + self.CHATBOX_GAP - time.time()
            if wait > 0:
                time.sleep(wait)
            self.send("/chatbox/input", *chatbox_args(text, sound))
            self._last_chat = time.time()

    def typing(self, on: bool) -> None:
        self.send("/chatbox/typing", bool(on))

    def parameter(self, name: str, value) -> None:
        self.send("/avatar/parameters/" + name.strip("/"), value)

    def input(self, name: str, value: int = 1, pulse: bool = True) -> None:
        self.send("/input/" + name, int(value))
        if pulse and value:
            time.sleep(0.12)
            self.send("/input/" + name, 0)

    def run_command(self, kind: str, payload: dict) -> None:
        """Befehl aus der Dashboard-Warteschlange ausführen."""
        if kind == "chatbox":
            self.chatbox(str(payload.get("text", "")), bool(payload.get("sound", True)))
        elif kind == "typing":
            self.typing(bool(payload.get("on")))
        elif kind == "param":
            t = payload.get("type", "bool")
            v = payload.get("value")
            v = bool(v) if t == "bool" else int(v) if t == "int" else float(v)
            self.parameter(str(payload["name"]), v)
        elif kind == "input":
            self.input(str(payload["name"]), int(payload.get("value", 1)), bool(payload.get("pulse", True)))
        else:
            raise ValueError("Unbekannte OSC-Art: %s" % kind)

    def close(self):
        self.sock.close()


class OscListener(threading.Thread):
    """Empfängt OSC von VRChat (Standard Port 9001) und merkt sich Avatar-Parameter und Avatar-ID."""

    def __init__(self, port: int = 9001, host: str = "127.0.0.1", on_message=None):
        super().__init__(daemon=True, name="osc-listener")
        self.port = int(port)
        self.host = host
        self.on_message = on_message
        self.params = {}
        self.avatar_id = ""
        self.last_packet = 0.0
        self.error = ""
        self._stop = threading.Event()
        self.sock = None

    def run(self):
        try:
            self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            self.sock.bind((self.host, self.port))
            self.sock.settimeout(0.5)
        except OSError as e:
            self.error = "Port %d ist belegt (läuft schon ein anderes OSC-Programm?): %s" % (self.port, e)
            return
        while not self._stop.is_set():
            try:
                data, _ = self.sock.recvfrom(65535)
            except socket.timeout:
                continue
            except OSError:
                break
            try:
                msgs = decode_packet(data)
            except Exception:
                continue
            self.last_packet = time.time()
            for address, args in msgs:
                if address == "/avatar/change" and args:
                    self.avatar_id = str(args[0])
                    self.params.clear()
                elif address.startswith("/avatar/parameters/") and args:
                    name = address[len("/avatar/parameters/"):]
                    v = args[0]
                    self.params[name] = round(v, 3) if isinstance(v, float) else v
                if self.on_message:
                    try:
                        self.on_message(address, args)
                    except Exception:
                        pass
        if self.sock:
            self.sock.close()

    @property
    def active(self) -> bool:
        return time.time() - self.last_packet < 120

    def stop(self):
        self._stop.set()
