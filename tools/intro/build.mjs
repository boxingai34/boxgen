/* Menempelkan assets/*.png ke dalam intro.core.html sebagai data URI,
   hasilnya intro.html yang berdiri sendiri: bisa dibuka dengan klik dua
   kali, tanpa server, tanpa internet.

   Jalankan: node build.mjs                                            */

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const b64 = (f) => 'data:image/png;base64,' + readFileSync(join(here, f)).toString('base64');

const core = readFileSync(join(here, 'intro.core.html'), 'utf8');
const tail =
  '<script>\n' +
  '/* Aset logo, ditempel sebagai data URI supaya kanvas tidak "ternoda"\n' +
  '   oleh aturan CORS waktu berkas ini dibuka lewat file://           */\n' +
  'boot({\n' +
  '  glove: "' + b64('assets/glove.png') + '",\n' +
  '  word:  "' + b64('assets/word.png') + '"\n' +
  '});\n' +
  '</' + 'script>\n</body>\n</html>\n';

const out = join(here, 'intro.html');
writeFileSync(out, core + tail, 'utf8');
console.log('intro.html ditulis (' + (Buffer.byteLength(core + tail) / 1024).toFixed(0) + ' KB)');
