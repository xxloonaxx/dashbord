// VRC Nexus – SteamVR-Overlay-Helfer.
// Wird von der Electron-App gestartet. Protokoll:
//   stdin  (binär): [1 Byte Typ][uint32 Länge LE][Daten]
//                   Typ 1 = Bild: uint32 Breite, uint32 Höhe, Pixel BGRA (von Electron)  → SetOverlayRaw (RGBA)
//                   Typ 2 = Befehl als JSON-Text, z. B. {"cmd":"show"} {"cmd":"hide"} {"cmd":"toggle"} {"cmd":"mode","mode":"wrist","hand":"left","width":0.28,"alpha":0.95}
//   stdout (Text):  eine JSON-Zeile pro Ereignis: ready, error, mouse (move/down/up, x, y in Pixeln), scroll, visible, quit
using System;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using Valve.VR;

namespace NexusOverlay
{
    internal static class Program
    {
        static readonly object Lock = new object();
        static byte[] frame; static uint fw, fh; static bool frameNew;
        static string pendingCmd;
        static ulong dash, thumb, hud;
        static bool hudVisible;
        static string mode = "wrist", hand = "left";
        static float width = 0.30f, alpha = 0.96f, distance = 1.2f;
        static uint curW = 1024, curH = 640;
        static volatile bool running = true;

        static void Out(string json) { lock (Console.Out) { Console.Out.WriteLine(json); Console.Out.Flush(); } }
        static string Q(string s) => "\"" + (s ?? "").Replace("\\", "\\\\").Replace("\"", "\\\"").Replace("\n", " ") + "\"";

        static int Main(string[] args)
        {
            Console.OutputEncoding = new UTF8Encoding(false);
            string icon = args.Length > 0 ? args[0] : "";
            var err = EVRInitError.None;
            OpenVR.Init(ref err, EVRApplicationType.VRApplication_Overlay);
            if (err != EVRInitError.None) { Out("{\"ev\":\"error\",\"msg\":" + Q("SteamVR nicht erreichbar: " + err) + "}"); return 2; }
            var ov = OpenVR.Overlay;
            if (ov.CreateDashboardOverlay("de.ghostnode.vrcnexus.dash", "VRC Nexus", ref dash, ref thumb) != EVROverlayError.None) { Out("{\"ev\":\"error\",\"msg\":\"Dashboard-Overlay konnte nicht angelegt werden (läuft VRC Nexus doppelt?)\"}"); return 3; }
            ov.CreateOverlay("de.ghostnode.vrcnexus.hud", "VRC Nexus (Handgelenk)", ref hud);
            if (icon != "" && File.Exists(icon)) ov.SetOverlayFromFile(thumb, icon);
            foreach (var h in new[] { dash, hud })
            {
                ov.SetOverlayInputMethod(h, VROverlayInputMethod.Mouse);
                ov.SetOverlayFlag(h, VROverlayFlags.SendVRSmoothScrollEvents, true);
                ov.SetOverlayFlag(h, VROverlayFlags.EnableClickStabilization, true);
            }
            ov.SetOverlayWidthInMeters(dash, 2.4f);
            ov.SetOverlayFlag(hud, VROverlayFlags.MakeOverlaysInteractiveIfVisible, true);
            ov.SetOverlaySortOrder(hud, 10);
            ApplyLayout();
            new Thread(ReadInput) { IsBackground = true }.Start();
            Out("{\"ev\":\"ready\"}");

            var ev = new VREvent_t();
            uint evSize = (uint)Marshal.SizeOf(typeof(VREvent_t));
            while (running)
            {
                byte[] f = null; uint w = 0, hgt = 0; string cmd = null;
                lock (Lock)
                {
                    if (frameNew) { f = frame; w = fw; hgt = fh; frameNew = false; }
                    cmd = pendingCmd; pendingCmd = null;
                }
                if (cmd != null) foreach (var c in cmd.Split('\n')) if (c.Trim() != "") Command(c);
                if (f != null) Upload(f, w, hgt);
                foreach (var h in new[] { dash, hud })
                    while (ov.PollNextOverlayEvent(h, ref ev, evSize)) HandleEvent(ev);
                while (OpenVR.System.PollNextEvent(ref ev, evSize))
                    if (ev.eventType == (uint)EVREventType.VREvent_Quit) { Out("{\"ev\":\"quit\"}"); OpenVR.System.AcknowledgeQuit_Exiting(); running = false; }
                if (hudVisible && mode == "front") { /* bleibt, wo es ist */ }
                Thread.Sleep(11);
            }
            OpenVR.Shutdown();
            return 0;
        }

        static void HandleEvent(VREvent_t ev)
        {
            switch ((EVREventType)ev.eventType)
            {
                case EVREventType.VREvent_MouseMove:
                case EVREventType.VREvent_MouseButtonDown:
                case EVREventType.VREvent_MouseButtonUp:
                {
                    string t = ev.eventType == (uint)EVREventType.VREvent_MouseMove ? "move" : ev.eventType == (uint)EVREventType.VREvent_MouseButtonDown ? "down" : "up";
                    // OpenVR zählt y von unten
                    int x = (int)ev.data.mouse.x, y = (int)(curH - ev.data.mouse.y);
                    Out("{\"ev\":\"mouse\",\"t\":\"" + t + "\",\"x\":" + x + ",\"y\":" + y + ",\"b\":" + ev.data.mouse.button + "}");
                    break;
                }
                case EVREventType.VREvent_ScrollSmooth:
                case EVREventType.VREvent_ScrollDiscrete:
                    Out("{\"ev\":\"scroll\",\"dx\":" + ev.data.scroll.xdelta.ToString(System.Globalization.CultureInfo.InvariantCulture) + ",\"dy\":" + ev.data.scroll.ydelta.ToString(System.Globalization.CultureInfo.InvariantCulture) + "}");
                    break;
                case EVREventType.VREvent_DashboardActivated: Out("{\"ev\":\"dashboard\",\"v\":true}"); break;
                case EVREventType.VREvent_DashboardDeactivated: Out("{\"ev\":\"dashboard\",\"v\":false}"); break;
            }
        }

        static void Upload(byte[] bgra, uint w, uint h)
        {
            for (int i = 0; i + 3 < bgra.Length; i += 4) { byte b = bgra[i]; bgra[i] = bgra[i + 2]; bgra[i + 2] = b; }
            var ov = OpenVR.Overlay;
            if (w != curW || h != curH)
            {
                curW = w; curH = h;
                var scale = new HmdVector2_t { v0 = w, v1 = h };
                ov.SetOverlayMouseScale(dash, ref scale);
                ov.SetOverlayMouseScale(hud, ref scale);
            }
            var handle = GCHandle.Alloc(bgra, GCHandleType.Pinned);
            try
            {
                ov.SetOverlayRaw(dash, handle.AddrOfPinnedObject(), w, h, 4);
                if (hudVisible) ov.SetOverlayRaw(hud, handle.AddrOfPinnedObject(), w, h, 4);
            }
            finally { handle.Free(); }
        }

        static string Str(string json, string key) { var m = Regex.Match(json, "\"" + key + "\"\\s*:\\s*\"([^\"]*)\""); return m.Success ? m.Groups[1].Value : null; }
        static float? Num(string json, string key) { var m = Regex.Match(json, "\"" + key + "\"\\s*:\\s*(-?[0-9.]+)"); return m.Success ? float.Parse(m.Groups[1].Value, System.Globalization.CultureInfo.InvariantCulture) : (float?)null; }

        static void Command(string json)
        {
            var ov = OpenVR.Overlay;
            string cmd = Str(json, "cmd");
            if (cmd == "mode")
            {
                mode = Str(json, "mode") ?? mode;
                hand = Str(json, "hand") ?? hand;
                width = Num(json, "width") ?? width;
                alpha = Num(json, "alpha") ?? alpha;
                distance = Num(json, "distance") ?? distance;
                ApplyLayout();
            }
            else if (cmd == "show" || (cmd == "toggle" && !hudVisible)) { hudVisible = mode != "off"; ApplyLayout(); if (hudVisible) ov.ShowOverlay(hud); }
            else if (cmd == "hide" || cmd == "toggle") { hudVisible = false; ov.HideOverlay(hud); }
            else if (cmd == "quit") running = false;
            Out("{\"ev\":\"visible\",\"v\":" + (hudVisible ? "true" : "false") + ",\"mode\":" + Q(mode) + "}");
        }

        static HmdMatrix34_t M(float m0, float m1, float m2, float m3, float m4, float m5, float m6, float m7, float m8, float m9, float m10, float m11) =>
            new HmdMatrix34_t { m0 = m0, m1 = m1, m2 = m2, m3 = m3, m4 = m4, m5 = m5, m6 = m6, m7 = m7, m8 = m8, m9 = m9, m10 = m10, m11 = m11 };

        static void ApplyLayout()
        {
            var ov = OpenVR.Overlay;
            ov.SetOverlayWidthInMeters(hud, Math.Max(0.1f, Math.Min(3f, width)));
            ov.SetOverlayAlpha(hud, Math.Max(0.1f, Math.Min(1f, alpha)));
            ov.SetOverlayAlpha(dash, 1f);
            if (mode == "wrist")
            {
                uint dev = OpenVR.System.GetTrackedDeviceIndexForControllerRole(hand == "right" ? ETrackedControllerRole.RightHand : ETrackedControllerRole.LeftHand);
                if (dev == OpenVR.k_unTrackedDeviceIndexInvalid) dev = 0;
                // leicht über dem Handgelenk, zum Gesicht geneigt (Drehung um X)
                double a = -60 * Math.PI / 180; float c = (float)Math.Cos(a), s = (float)Math.Sin(a);
                float side = hand == "right" ? -0.02f : 0.02f;
                var m = M(1, 0, 0, side, 0, c, -s, 0.03f, 0, s, c, 0.12f);
                ov.SetOverlayTransformTrackedDeviceRelative(hud, dev, ref m);
            }
            else if (mode == "front")
            {
                var poses = new TrackedDevicePose_t[1];
                OpenVR.System.GetDeviceToAbsoluteTrackingPose(ETrackingUniverseOrigin.TrackingUniverseStanding, 0, poses);
                var p = poses[0].mDeviceToAbsoluteTracking;
                float fx = -p.m2, fz = -p.m10, len = (float)Math.Sqrt(fx * fx + fz * fz);
                if (len < 0.001f) { fx = 0; fz = -1; len = 1; }
                fx /= len; fz /= len;
                // Overlay-Vorderseite (+Z) zeigt zum Kopf
                float sn = -fx, cs = -fz;
                var m = M(cs, 0, sn, p.m3 + fx * distance, 0, 1, 0, p.m7 - 0.1f, -sn, 0, cs, p.m11 + fz * distance);
                ov.SetOverlayTransformAbsolute(hud, ETrackingUniverseOrigin.TrackingUniverseStanding, ref m);
            }
            if (mode == "off" || !hudVisible) ov.HideOverlay(hud);
        }

        static void ReadInput()
        {
            var s = Console.OpenStandardInput();
            var head = new byte[5];
            try
            {
                while (running)
                {
                    if (!ReadAll(s, head, 5)) break;
                    int len = BitConverter.ToInt32(head, 1);
                    if (len < 0 || len > 64 * 1024 * 1024) break;
                    var data = new byte[len];
                    if (!ReadAll(s, data, len)) break;
                    if (head[0] == 1 && len >= 8)
                    {
                        uint w = BitConverter.ToUInt32(data, 0), h = BitConverter.ToUInt32(data, 4);
                        if (w * h * 4 != len - 8) continue;
                        var px = new byte[len - 8];
                        Buffer.BlockCopy(data, 8, px, 0, px.Length);
                        lock (Lock) { frame = px; fw = w; fh = h; frameNew = true; }
                    }
                    else if (head[0] == 2)
                    {
                        string txt = Encoding.UTF8.GetString(data);
                        lock (Lock) { pendingCmd = pendingCmd == null ? txt : pendingCmd + "\n" + txt; }
                    }
                }
            }
            catch (Exception) { }
            running = false;    // App beendet → Overlay schließen
        }

        static bool ReadAll(Stream s, byte[] buf, int n)
        {
            int got = 0;
            while (got < n) { int r = s.Read(buf, got, n - got); if (r <= 0) return false; got += r; }
            return true;
        }
    }
}
