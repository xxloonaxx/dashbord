'use strict';
/* Hilfsfunktionen (Gegenstücke zu den PHP-Funktionen in inc/modules/vrchat.php) */

const VRC_STATUS = { 'join me': ['Komm vorbei', '#42caff'], active: ['Online', '#51e57e'], 'ask me': ['Frag mich', '#e88134'], busy: ['Beschäftigt', '#ff4f4f'], offline: ['Offline', '#808a9b'] };
const VRC_PLATFORM = { standalonewindows: 'PC', android: 'Quest / Android', ios: 'iOS', web: 'Website / App' };

class VrcAuthError extends Error {}
class UserError extends Error {}

const now = () => Math.floor(Date.now() / 1000);
const str = v => (v === undefined || v === null ? '' : String(v));
const int = v => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : 0; };
/** Wie mb_substr: zählt Zeichen (auch Emojis) statt UTF-16-Einheiten */
const cut = (s, n) => { s = str(s); if (s.length <= n) return s; return Array.from(s).slice(0, n).join(''); };
const arr = v => (Array.isArray(v) ? v : v && typeof v === 'object' ? Object.values(v) : []);
const obj = v => (v && typeof v === 'object' && !Array.isArray(v) ? v : {});
const https = u => (typeof u === 'string' && /^https:\/\//.test(u) ? u : '');
/** strtotime für ISO-Daten von VRChat → Unix-Sekunden (0 bei Fehler) */
const ts = s => { const t = Date.parse(str(s)); return Number.isFinite(t) ? Math.floor(t / 1000) : 0; };
/** date('N') (1 = Montag … 7 = Sonntag) und date('G') (Stunde) in Ortszeit */
const isoDay = t => { const d = new Date(t * 1000).getDay(); return d === 0 ? 7 : d; };
const hourOf = t => new Date(t * 1000).getHours();
const startOfToday = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return Math.floor(d.getTime() / 1000); };

function userImage(u) {
    for (const k of ['userIcon', 'profilePicOverrideThumbnail', 'profilePicOverride', 'currentAvatarThumbnailImageUrl', 'thumbnailUrl', 'currentAvatarImageUrl', 'imageUrl']) {
        if (u && u[k] && /^https:\/\//.test(u[k])) return u[k];
    }
    return '';
}

const ME_KEYS = ['id', 'displayName', 'status', 'statusDescription', 'bio', 'tags', 'last_platform', 'pronouns', 'date_joined', 'currentAvatarThumbnailImageUrl', 'userIcon',
    'profilePicOverride', 'friends', 'onlineFriends', 'activeFriends', 'offlineFriends', 'presence', 'statusHistory', 'currentAvatar', 'location', 'bioLinks', 'last_login'];
const meSlim = u => Object.fromEntries(ME_KEYS.filter(k => u && k in u).map(k => [k, u[k]]));

function trust(tags) {
    tags = arr(tags);
    if (tags.some(t => String(t).startsWith('admin_'))) return ['VRChat-Team', '#ff2626'];
    if (tags.includes('system_troll')) return ['Nuisance', '#782f2f'];
    for (const [t, n, c] of [['system_trust_veteran', 'Trusted User', '#8143e6'], ['system_trust_trusted', 'Known User', '#ff7b42'], ['system_trust_known', 'User', '#2bcf5c'], ['system_trust_basic', 'New User', '#1778ff']]) {
        if (tags.includes(t)) return [n, c];
    }
    return ['Visitor', '#cccccc'];
}

/** Ort zerlegen: wrld_x:12345~friends(usr_y)~region(eu) */
function location(loc) {
    loc = str(loc).trim();
    if (loc === '' || loc === 'offline') return { kind: 'offline', label: 'Offline' };
    if (loc === 'private') return { kind: 'private', label: 'Private Welt' };
    if (loc.startsWith('traveling')) return { kind: 'traveling', label: 'Unterwegs …', world: loc.slice(10).split(':')[0] || '' };
    const i = loc.indexOf(':');
    const world = i < 0 ? loc : loc.slice(0, i);
    const inst = i < 0 ? '' : loc.slice(i + 1);
    let access = 'Public';
    if (inst.includes('~hidden(')) access = 'Friends+';
    else if (inst.includes('~friends(')) access = 'Friends';
    else if (inst.includes('~private(')) access = inst.includes('~canRequestInvite') ? 'Invite+' : 'Invite';
    let m = inst.match(/~groupAccessType\((\w+)\)/);
    if (m) access = { public: 'Gruppe öffentlich', plus: 'Group+', members: 'Gruppe' }[m[1]] || 'Gruppe';
    else if (inst.includes('~group(')) access = 'Gruppe';
    m = inst.match(/~region\((\w+)\)/);
    const region = m ? m[1].toUpperCase() : 'US';
    const name = inst.split('~')[0];
    const g = inst.match(/~group\((grp_[\w-]+)\)/);
    return { kind: 'world', world, instance: inst, name, access, region, group: g ? g[1] : '', location: loc, label: access + ' · #' + name + ' · ' + region };
}

/** Text von VRChat sicher als HTML: maskieren, Links klickbar, Zeilenumbrüche */
function formatText(s) {
    s = str(s);
    const esc = x => x.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let out = esc(s);
    out = out.replace(/\bhttps?:\/\/[^\s<>"']+/g, u => {
        const clean = u.replace(/[).,!?]+$/, '');
        return '<a href="' + clean + '" target="_blank" rel="noopener noreferrer">' + clean + '</a>' + u.slice(clean.length);
    });
    return out.replace(/\r?\n/g, '<br>');
}

/** VRChat-Dateiadresse → Bildadresse der App (über nexus.php?img=…), sonst nur https */
function imgUrl(url) {
    const m = str(url).match(/\/file\/(file_[0-9a-f-]{36})\/(\d+)/i);
    if (m) return 'nexus.php?img=' + m[1] + '&v=' + m[2];
    return https(url);
}

/** Prüft VRChat-Kennungen (wie $valid in nexus.php) */
function valid(v, prefix) {
    v = str(v);
    const ok = new RegExp('^' + prefix + '_[0-9a-f-]{8,40}$', 'i').test(v) || (prefix === 'usr' && /^[0-9A-Za-z]{10}$/.test(v));
    if (!ok) throw new UserError('Ungültige Kennung.');
    return v;
}

const sleep = ms => new Promise(r => setTimeout(r, ms));

module.exports = { VRC_STATUS, VRC_PLATFORM, VrcAuthError, UserError, now, str, int, cut, arr, obj, https, ts, isoDay, hourOf, startOfToday, userImage, meSlim, trust, location, formatText, imgUrl, valid, sleep };
