// UDP gibt es im Browser nicht – OSC ist auf Android ausgeschaltet
const sock = () => ({ on() { return this; }, once() { return this; }, send(b, p, h, cb) { if (typeof cb === 'function') cb(new Error('Auf Android nicht verfügbar.')); }, bind(p, h, cb) { if (typeof cb === 'function') cb(); return this; },
    close() {}, unref() { return this; }, ref() { return this; }, address: () => ({ port: 0, address: '0.0.0.0' }), setBroadcast() {} });
module.exports = { createSocket: sock };
