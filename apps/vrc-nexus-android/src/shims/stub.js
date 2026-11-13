// Node-Module, die es auf Android nicht gibt (net, http, child_process, zlib …)
const na = () => { throw new Error('Auf Android nicht verfügbar.'); };
module.exports = new Proxy({}, { get: (t, k) => (k === '__esModule' ? false : k === 'default' ? module.exports : na) });
