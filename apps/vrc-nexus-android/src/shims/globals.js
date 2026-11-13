// Globale Node-Namen für das gebündelte Backend (per esbuild „inject“)
import { Buffer } from 'buffer';
export { Buffer };
export const process = { env: {}, platform: 'android', pid: 1, argv: [], execPath: '', cwd: () => '/', nextTick: (fn, ...a) => Promise.resolve().then(() => fn(...a)) };
