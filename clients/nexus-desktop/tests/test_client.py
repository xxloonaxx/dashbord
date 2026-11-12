"""Tests für Log-Parser und OSC (laufen auch unter Linux):  python -m unittest discover -s tests"""
import os
import socket
import sys
import tempfile
import time
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
from gn_logparse import LogParser, LogWatcher, parse_ts   # noqa: E402
from gn_osc import OscListener, OscSender, chatbox_args, decode_packet, encode_message   # noqa: E402

SAMPLE = """2026.11.12 20:00:01 Log        -  [Behaviour] Initialized PlayerAPI "Luna" is local
2026.11.12 20:00:02 Log        -  User Authenticated: Luna Fox (usr_11111111-2222-3333-4444-555555555555)
2026.11.12 20:00:10 Log        -  [Behaviour] Joining wrld_aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee:12345~friends(usr_11111111-2222-3333-4444-555555555555)~region(eu)
2026.11.12 20:00:11 Log        -  [Behaviour] Joining or Creating Room: The Great Pug
2026.11.12 20:00:15 Log        -  [Behaviour] OnPlayerJoined Luna Fox (usr_11111111-2222-3333-4444-555555555555)
2026.11.12 20:00:16 Log        -  [Behaviour] OnPlayerJoined Wolf Byte (usr_99999999-2222-3333-4444-555555555555)
2026.11.12 20:00:17 Log        -  [Behaviour] OnPlayerJoined OldName
2026.11.12 20:01:00 Log        -  [Behaviour] Switching Luna Fox to avatar Novabeast Deluxe
2026.11.12 20:01:01 Log        -  [Behaviour] Switching Wolf Byte to avatar Something
2026.11.12 20:02:00 Log        -  [Video Playback] Attempting to resolve URL 'https://www.youtube.com/watch?v=abc'
2026.11.12 20:03:00 Log        -  [VRC Camera] Took screenshot to: C:\\Users\\x\\Pictures\\VRChat\\VRChat_2026-11-12_20-03-00.png
2026.11.12 20:04:00 Log        -  [API] Received Notification: <Notification from username:Wolf Byte, sender user id:usr_99999999-2222-3333-4444-555555555555 to of type: friendRequest, id: not_1, created at: 11/12/2026 19:04:00 UTC, details: {{}}, type:friendRequest, m seen:False, message: ""> received at 11/12/2026 19:04:00 UTC
2026.11.12 20:05:00 Log        -  [Behaviour] OnPlayerLeftRoom
2026.11.12 20:05:01 Log        -  [Behaviour] OnPlayerLeft Wolf Byte (usr_99999999-2222-3333-4444-555555555555)
2026.11.12 20:05:02 Log        -  [Behaviour] OnPlayerLeft OldName
2026.11.12 20:06:00 Log        -  [Behaviour] OnLeftRoom
2026.11.12 20:07:00 Log        -  VRCApplication: OnApplicationQuit at 1234.5
"""


class LogParserTest(unittest.TestCase):
    def test_sequence(self):
        p = LogParser()
        events = []
        for line in SAMPLE.splitlines():
            events.extend(p.feed(line + "\r\n"))
        kinds = [e["kind"] for e in events]
        self.assertEqual(kinds, ["world_join", "player_join", "player_join", "player_join", "avatar", "video", "screenshot", "notification",
                                 "player_leave", "player_leave", "world_leave", "vrc_quit"])
        w = events[0]
        self.assertEqual(w["world_id"], "wrld_aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee")
        self.assertTrue(w["instance"].startswith("12345~friends"))
        self.assertEqual(w["world_name"], "The Great Pug")
        self.assertEqual(w["ts"], parse_ts(2026, 11, 12, 20, 0, 11))
        self.assertEqual(events[2]["player_id"], "usr_99999999-2222-3333-4444-555555555555")
        self.assertEqual(events[3]["player_name"], "OldName")
        self.assertEqual(events[3]["player_id"], "")
        self.assertEqual(events[4]["detail"], "Novabeast Deluxe")       # nur der eigene Avatar
        self.assertEqual(events[5]["detail"], "https://www.youtube.com/watch?v=abc")
        self.assertTrue(events[6]["detail"].endswith(".png"))
        self.assertEqual(events[7]["detail"], "Freundschaftsanfrage")
        self.assertEqual(events[7]["player_name"], "Wolf Byte")
        self.assertEqual(events[10]["world_name"], "The Great Pug")      # Verlassen nennt die Welt
        self.assertEqual(p.state.world_name, "")
        self.assertFalse(p.state.running)

    def test_state_players(self):
        p = LogParser()
        for line in SAMPLE.splitlines()[:9]:
            p.feed(line)
        st = p.state.snapshot()
        self.assertEqual(st["display_name"], "Luna Fox")
        self.assertEqual(st["avatar"], "Novabeast Deluxe")
        self.assertEqual(sorted(x["name"] for x in st["players"]), ["Luna Fox", "OldName", "Wolf Byte"])

    def test_garbage(self):
        p = LogParser()
        self.assertEqual(p.feed("kaputte Zeile"), [])
        self.assertEqual(p.feed(""), [])
        self.assertEqual(p.feed("2026.11.12 20:00:01 Error      -  irgendwas"), [])

    def test_watcher_tail(self):
        with tempfile.TemporaryDirectory() as d:
            f1 = os.path.join(d, "output_log_2026-11-12_19-59-00.txt")
            lines = SAMPLE.splitlines(True)
            with open(f1, "w", encoding="utf-8", newline="\r\n") as fh:
                fh.write("".join(lines[:4]))
                fh.write(lines[4][:30])            # halbe Zeile
            w = LogWatcher(d)
            ev = w.poll()
            self.assertEqual([e["kind"] for e in ev], ["vrc_start", "world_join"])
            self.assertEqual(ev[0]["ts"], parse_ts(2026, 11, 12, 19, 59, 0))
            with open(f1, "a", encoding="utf-8", newline="\r\n") as fh:
                fh.write(lines[4][30:] + "".join(lines[5:7]))
            self.assertEqual([e["kind"] for e in w.poll()], ["player_join", "player_join", "player_join"])
            self.assertEqual(w.poll(), [])
            # Neustart von VRChat → neue Datei
            time.sleep(0.05)
            f2 = os.path.join(d, "output_log_2026-11-12_21-00-00.txt")
            with open(f2, "w", encoding="utf-8") as fh:
                fh.write("2026.11.12 21:00:05 Log        -  [Behaviour] Joining or Creating Room: Zweite Welt\n")
            os.utime(f2, (time.time() + 5, time.time() + 5))
            self.assertEqual([e["kind"] for e in w.poll()], ["vrc_start", "world_join"])
            self.assertEqual(w.parser.state.world_name, "Zweite Welt")
            self.assertEqual(len(w.import_all()), 5)

    def test_umlauts(self):
        p = LogParser()
        p.feed("2026.11.12 20:00:11 Log        -  [Behaviour] Joining or Creating Room: Fuchsbau 🦊 Ü")
        ev = p.feed("2026.11.12 20:00:15 Log        -  [Behaviour] OnPlayerJoined Jürgen ✨")
        self.assertEqual(ev[0]["player_name"], "Jürgen ✨")
        self.assertEqual(ev[0]["world_name"], "Fuchsbau 🦊 Ü")


class OscTest(unittest.TestCase):
    def test_encode_decode(self):
        pkt = encode_message("/chatbox/input", *chatbox_args("Hallo 🦊", False))
        self.assertEqual(len(pkt) % 4, 0)
        self.assertEqual(decode_packet(pkt), [("/chatbox/input", ["Hallo 🦊", True, False])])
        pkt = encode_message("/avatar/parameters/Ears", 0.5)
        self.assertEqual(pkt, b"/avatar/parameters/Ears\x00,f\x00\x00" + b"\x3f\x00\x00\x00")
        self.assertEqual(decode_packet(encode_message("/input/Jump", 1)), [("/input/Jump", [1])])
        self.assertEqual(encode_message("/a"), b"/a\x00\x00,\x00\x00\x00")

    def test_bundle(self):
        m1, m2 = encode_message("/avatar/parameters/A", True), encode_message("/avatar/parameters/B", 3)
        b = b"#bundle\x00" + b"\x00" * 8 + len(m1).to_bytes(4, "big") + m1 + len(m2).to_bytes(4, "big") + m2
        self.assertEqual(decode_packet(b), [("/avatar/parameters/A", [True]), ("/avatar/parameters/B", [3])])

    def test_chatbox_limit(self):
        self.assertEqual(len(chatbox_args("x" * 300)[0]), 144)

    def test_send_and_listen(self):
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
        s.close()
        lst = OscListener(port)
        lst.start()
        time.sleep(0.2)
        snd = OscSender("127.0.0.1", port)
        snd.send("/avatar/change", "avtr_123")
        snd.parameter("Ears", True)
        snd.run_command("param", {"name": "Tail", "type": "float", "value": 0.25})
        time.sleep(0.4)
        lst.stop()
        snd.close()
        self.assertEqual(lst.avatar_id, "avtr_123")
        self.assertEqual(lst.params, {"Ears": True, "Tail": 0.25})
        self.assertTrue(lst.active)


if __name__ == "__main__":
    unittest.main()
