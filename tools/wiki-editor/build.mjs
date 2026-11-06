// Builds public/assets/vendor/milkdown/wiki-editor.{js,css} from src/editor.js.
// Usage (from this folder): npm ci --ignore-scripts && npm run build
// The output is committed: Sillage never needs Node to be installed or deployed.
import * as esbuild from 'esbuild';
import { mkdirSync, statSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, '../../public/assets/vendor/milkdown');
rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

// Features disabled in src/editor.js (Latex, CodeMirror) are still imported statically by Crepe.
// Replace their heavy dependencies with inert stubs so they are not shipped.
const stubs = {
  katex: 'export default { render() {}, renderToString() { return ""; } };',
  'remark-math': 'export default function remarkMath() {}',
  '@codemirror/language-data': 'export const languages = [];',
};
const stubPlugin = {
  name: 'sillage-stubs',
  setup(build) {
    build.onResolve({ filter: new RegExp('^(' + Object.keys(stubs).map((s) => s.replace(/[/.]/g, '\\$&')).join('|') + ')$') }, (args) => ({ path: args.path, namespace: 'stub' }));
    build.onLoad({ filter: /.*/, namespace: 'stub' }, (args) => ({ contents: stubs[args.path], loader: 'js' }));
  },
};

await esbuild.build({
  entryPoints: { 'wiki-editor': path.join(here, 'src/editor.js') },
  bundle: true,
  minify: true,
  format: 'iife',
  target: 'es2020',
  legalComments: 'external',
  outdir: out,
  plugins: [stubPlugin],
  loader: { '.svg': 'dataurl', '.png': 'dataurl', '.woff': 'empty', '.woff2': 'empty', '.ttf': 'empty' },
  define: { 'process.env.NODE_ENV': '"production"', __VUE_OPTIONS_API__: 'false', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
  logLevel: 'warning',
});

const lock = JSON.parse(readFileSync(path.join(here, 'package-lock.json'), 'utf8'));
const files = ['wiki-editor.js', 'wiki-editor.css'].map((name) => {
  const file = path.join(out, name);
  return { name, bytes: statSync(file).size, sha256: createHash('sha256').update(readFileSync(file)).digest('hex') };
});
writeFileSync(path.join(out, 'BUILD.json'), JSON.stringify({
  source: 'tools/wiki-editor',
  crepe: lock.packages['node_modules/@milkdown/crepe'].version,
  esbuild: lock.packages['node_modules/esbuild'].version,
  files,
}, null, 2) + '\n');
for (const f of files) console.log(`${f.name}: ${(f.bytes / 1024).toFixed(0)} Ko`);
