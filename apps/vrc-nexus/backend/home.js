'use strict';
/*
 * Startseite: sammelt in einer Anfrage alles, was die Widgets brauchen (jedes Teil für sich abgesichert).
 * Auch für das Android-Homescreen-Widget (kompakte Zusammenfassung).
 */
const { str, int, now } = require('./util');

const safe = (fn, d = null) => { try { return fn(); } catch (e) { return d; } };

class Home {
    constructor(app) { this.app = app; }
    data() {
        const a = this.app, g = a.game, acc = safe(() => a.vrc.account());
        const fr = acc ? safe(() => a.vrc.friendList(), []) : [];
        const me = acc ? acc.me || {} : {};
        const st = g.state;
        const pc = !a.mobile;
        const out = {
            mobile: a.mobile, ts: now(),
            me: acc ? { name: acc.display_name, status: str(me.status), desc: str(me.statusDescription), image: str(me.currentAvatarThumbnailImageUrl || me.userIcon || '') } : null,
            friends: {
                in_game: fr.filter(f => f.state === 'online').length, online: fr.filter(f => f.state === 'active').length, total: fr.length,
                list: fr.filter(f => f.state === 'online').sort((x, y) => (y.favorite - x.favorite) || x.name.localeCompare(y.name)).slice(0, 40)
                    .map(f => ({ id: f.id, name: f.name, status: f.status, world: f.loc.kind === 'world' ? f.loc.world_name || '' : f.loc.label || '', access: f.loc.access || '', fav: f.favorite, image: f.image })),
            },
            notifs: acc ? safe(() => { const n = a.x.notifList({ view: 'open' }); return { unseen: n.unseen, items: n.items.slice(0, 6).map(i => ({ type: i.type, title: i.title || i.type, message: str(i.message).slice(0, 120), ts: i.ts || i.created || 0 })) }; }, null) : null,
            feed: acc ? safe(() => a.vrc.feed({ limit: 10 }).slice(0, 10), []) : [],
            update: safe(() => a.updater.status(), null),
        };
        if (pc) Object.assign(out, {
            game: { running: st.running, world: st.worldName, world_id: st.worldId, instance: st.instance, since: st.since, players: [...st.players.values()].map(p => ({ id: p.id, name: p.name, since: p.since })).slice(0, 80), avatar: st.avatar },
            stats: safe(() => g.stats().today, null),
            macros: a.macros.list().map(m => ({ id: m.id, name: m.name })),
            music: a.auto.music, afk: !!a.auto.afkSince,
            rotator: safe(() => { const c = g.cfg(); return { on: !!c.rotator_enabled, text: g.rotatorText(g.rotatorNext ? (str(c.rotator_texts).split('\n').filter(x => x.trim())[0] || c.rotator_text) : c.rotator_text) }; }, null),
            osc: { active: g.osc.active, avatar: g.osc.avatarId },
            params: safe(() => { const l = a.params.list(''); return l.params.filter(p => p.fav && p.input).slice(0, 16); }, []),
            cam: { mode: (g.osc.cam || {}).Mode ?? null, burst: !!a.cam.burstRun, presets: a.cam.presets().map(p => p.name).slice(0, 8) },
            overlay: a.tools.host.overlayState ? safe(() => a.tools.host.overlayState(), null) : null,
            speech: a.speech ? safe(() => a.speech.status(), null) : null,
        });
        return out;
    }
    /** kurze Zusammenfassung für das Android-Homescreen-Widget */
    summary() {
        const d = this.data();
        return { ts: d.ts, me: d.me ? d.me.name : '', status: d.me ? d.me.status : '', in_game: d.friends.in_game, online: d.friends.online, notifs: d.notifs ? d.notifs.unseen : 0,
            top: d.friends.list.slice(0, 5).map(f => f.name + (f.world ? ' · ' + f.world : '')) };
    }
}

module.exports = { Home };
