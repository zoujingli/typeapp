import { createRequire } from 'node:module';
import { vueTypeScriptPath } from './typescript-bridge.mjs';

const require = createRequire(import.meta.url);
require('vue-tsc').run(vueTypeScriptPath);
