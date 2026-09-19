/* =====================================================================
   Merender intro.html jadi berkas video.
   ---------------------------------------------------------------------
   Caranya: Chrome dijalankan tanpa jendela, tiap bingkai diminta lewat
   drawFrame(t) lalu difoto satu per satu, baru ffmpeg merakitnya. Karena
   animasinya digerakkan oleh angka detik (bukan jam dinding), hasilnya
   tidak pernah terpengaruh mesin yang lambat - tiap bingkai selalu pas.

   Jalankan:  node render.mjs
   Pilihan :  --mov       ikut membuat ProRes 4444 (transparan, berkasnya besar)
              --no-audio  tanpa dentuman
              --keep      bingkai PNG-nya tidak dihapus
   ===================================================================== */

import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync, existsSync, mkdtempSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';

const here = dirname(fileURLToPath(import.meta.url));
const OUT = join(here, 'out');
const FRAMES = join(OUT, 'frames');
const args = process.argv.slice(2);
const WANT_MOV = args.includes('--mov');
const WANT_AUDIO = !args.includes('--no-audio');
const KEEP = args.includes('--keep');

const CHROME = [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
].find(p => existsSync(p));
if (!CHROME) { console.error('Chrome/Edge tidak ketemu.'); process.exit(1); }

const FFMPEG = process.env.FFMPEG || 'ffmpeg';

/* ---------------------------------------------------------------- CDP */
class Cdp {
  constructor(ws) { this.ws = ws; this.id = 0; this.waiting = new Map(); this.onEvent = () => {}; }
  static async connect(url) {
    const ws = new WebSocket(url);
    await new Promise((ok, no) => { ws.onopen = ok; ws.onerror = () => no(new Error('gagal menyambung ke Chrome')); });
    const c = new Cdp(ws);
    ws.onmessage = (ev) => {
      const m = JSON.parse(ev.data);
      if (m.id && c.waiting.has(m.id)) {
        const { ok, no } = c.waiting.get(m.id); c.waiting.delete(m.id);
        m.error ? no(new Error(m.method + ': ' + m.error.message)) : ok(m.result);
      } else if (m.method) c.onEvent(m);
    };
    return c;
  }
  send(method, params = {}, sessionId) {
    const id = ++this.id;
    return new Promise((ok, no) => {
      this.waiting.set(id, { ok, no });
      this.ws.send(JSON.stringify(sessionId ? { id, method, params, sessionId } : { id, method, params }));
    });
  }
  close() { try { this.ws.close(); } catch { /* sudah tertutup */ } }
}

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function findEndpoint(port, deadlineMs = 20000) {
  const until = Date.now() + deadlineMs;
  while (Date.now() < until) {
    try {
      const r = await fetch('http://127.0.0.1:' + port + '/json/version');
      if (r.ok) return (await r.json()).webSocketDebuggerUrl;
    } catch { /* Chrome belum siap */ }
    await sleep(150);
  }
  throw new Error('Chrome tidak membuka port debug ' + port);
}

/* ------------------------------------------------------------- render */
async function shootFrames() {
  rmSync(FRAMES, { recursive: true, force: true });
  mkdirSync(FRAMES, { recursive: true });

  const port = 9200 + (process.pid % 500);
  const profile = mkdtempSync(join(tmpdir(), 'intro-chrome-'));
  const chrome = spawn(CHROME, [
    '--headless=new', '--disable-gpu', '--hide-scrollbars', '--mute-audio',
    '--no-first-run', '--no-default-browser-check', '--disable-extensions',
    '--disable-background-timer-throttling', '--disable-lcd-text',
    '--force-device-scale-factor=1', '--window-size=1920,1080',
    '--user-data-dir=' + profile, '--remote-debugging-port=' + port,
    'about:blank',
  ], { stdio: 'ignore' });

  let cdp;
  try {
    cdp = await Cdp.connect(await findEndpoint(port));

    const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
    const S = sessionId;

    await cdp.send('Page.enable', {}, S);
    await cdp.send('Runtime.enable', {}, S);
    await cdp.send('Emulation.setDeviceMetricsOverride',
      { width: 1920, height: 1080, deviceScaleFactor: 1, mobile: false }, S);
    // latar bawaan dibuat tembus pandang supaya foto bingkainya punya alpha
    await cdp.send('Emulation.setDefaultBackgroundColorOverride',
      { color: { r: 0, g: 0, b: 0, a: 0 } }, S);

    const url = 'file:///' + join(here, 'intro.html').replace(/\\/g, '/') + '?render=1';
    const loaded = new Promise(ok => {
      cdp.onEvent = (m) => { if (m.method === 'Page.loadEventFired') ok(); };
    });
    await cdp.send('Page.navigate', { url }, S);
    await loaded;

    const evalIn = async (expr) => {
      const r = await cdp.send('Runtime.evaluate', { expression: expr, returnByValue: true }, S);
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + ' — ' + expr);
      return r.result.value;
    };

    const until = Date.now() + 15000;
    while (!(await evalIn('!!window.introReady'))) {
      if (Date.now() > until) throw new Error('intro.html tidak pernah siap (aset gagal dimuat?)');
      await sleep(100);
    }

    const cfg = JSON.parse(await evalIn('JSON.stringify(window.INTRO_CFG)'));
    const total = Math.round(cfg.DUR * cfg.FPS);
    process.stdout.write('Merender ' + total + ' bingkai @ ' + cfg.FPS + ' fps ');

    for (let i = 0; i < total; i++) {
      await evalIn('drawFrame(' + (i / cfg.FPS) + ')');
      const shot = await cdp.send('Page.captureScreenshot',
        { format: 'png', fromSurface: true, captureBeyondViewport: false }, S);
      writeFileSync(join(FRAMES, 'f' + String(i).padStart(4, '0') + '.png'),
        Buffer.from(shot.data, 'base64'));
      if (i % 10 === 0) process.stdout.write('.');
    }
    console.log(' selesai');
    return cfg;
  } finally {
    cdp?.close();
    chrome.kill();
    await sleep(400);
    rmSync(profile, { recursive: true, force: true });
  }
}

/* -------------------------------------------------------------- ffmpeg */
function ff(label, argv) {
  const r = spawnSync(FFMPEG, ['-hide_banner', '-loglevel', 'error', '-y', ...argv],
    { stdio: ['ignore', 'inherit', 'inherit'] });
  if (r.status !== 0) throw new Error('ffmpeg gagal saat ' + label);
  console.log('  ' + label);
}

/* Dentuman sederhana: desingan naik, lalu tumbukan (sub + badan + retak),
   ekor udara, dan satu desis waktu gambarnya luruh. Ganti saja out/sfx.wav
   dengan SFX sendiri kalau mau. */
function buildAudio(cfg, dst) {
  const hit = cfg.tImpact, exit = cfg.tExit, dur = cfg.DUR;
  const ms = (s) => Math.round(s * 1000);
  const fc = [
    `aevalsrc='0.5*sin(2*PI*(80*t+700*t*t))*pow(t/${hit},2)':s=48000:d=${hit}[riser]`,
    `anoisesrc=d=${hit}:c=pink:s=48000,highpass=f=500,volume='0.75*pow(t/${hit},3)':eval=frame[wsh]`,
    `aevalsrc='sin(2*PI*(95*t-32*t*t))*exp(-7*t)':s=48000:d=1.6,volume=0.95,adelay=${ms(hit)}|${ms(hit)}[sub]`,
    `anoisesrc=d=0.9:c=white:s=48000,lowpass=f=2600,volume='0.85*exp(-24*t)':eval=frame,adelay=${ms(hit)}|${ms(hit)}[body]`,
    `anoisesrc=d=0.5:c=white:s=48000,highpass=f=3000,volume='0.6*exp(-55*t)':eval=frame,adelay=${ms(hit)}|${ms(hit)}[crack]`,
    `anoisesrc=d=1.8:c=pink:s=48000,bandpass=f=900:w=900,volume='0.16*exp(-2.2*t)':eval=frame,adelay=${ms(hit + 0.02)}|${ms(hit + 0.02)}[air]`,
    `anoisesrc=d=0.6:c=white:s=48000,highpass=f=1200,volume='0.3*exp(-6*t)':eval=frame,adelay=${ms(exit)}|${ms(exit)}[swish]`,
    // amix berhenti di masukan terpanjang, jadi ujungnya dipanjangkan
    // dengan apad dulu - kalau tidak, videonya ikut terpotong oleh -shortest
    `[riser][wsh][sub][body][crack][air][swish]amix=inputs=7:normalize=0,` +
    `alimiter=limit=0.94,apad=whole_dur=${dur},` +
    `afade=t=out:st=${(dur - 0.12).toFixed(2)}:d=0.12,` +
    `atrim=0:${dur},asetpts=N/SR/TB[a]`,
  ].join(';');
  ff('sfx.wav', ['-filter_complex', fc, '-map', '[a]', '-ac', '2', '-ar', '48000', dst]);
}

/* ---------------------------------------------------------------- main */
const cfg = await shootFrames();
mkdirSync(OUT, { recursive: true });
const pat = join(FRAMES, 'f%04d.png');
const fps = String(cfg.FPS);
console.log('Merakit video:');

let audio = null;
if (WANT_AUDIO) { audio = join(OUT, 'sfx.wav'); buildAudio(cfg, audio); }

// 1) versi tembus pandang - untuk ditumpuk di atas video utama
ff('intro_alpha.webm', [
  '-framerate', fps, '-i', pat,
  '-c:v', 'libvpx-vp9', '-pix_fmt', 'yuva420p', '-b:v', '0', '-crf', '20',
  '-row-mt', '1', '-auto-alt-ref', '0', '-an', join(OUT, 'intro_alpha.webm'),
]);

// 2) versi biasa di atas hitam - bisa langsung disambung di depan video
ff('intro_1080p.mp4', [
  '-f', 'lavfi', '-i', 'color=c=black:s=1920x1080:r=' + fps + ':d=' + cfg.DUR,
  '-framerate', fps, '-i', pat,
  ...(audio ? ['-i', audio] : []),
  '-filter_complex', '[0:v][1:v]overlay=shortest=1,format=yuv420p[v]',
  '-map', '[v]', ...(audio ? ['-map', '2:a', '-c:a', 'aac', '-b:a', '192k'] : ['-an']),
  '-c:v', 'libx264', '-preset', 'slow', '-crf', '16', '-r', fps, '-t', String(cfg.DUR),
  '-movflags', '+faststart', join(OUT, 'intro_1080p.mp4'),
]);

// 3) opsional: ProRes 4444, transparan, untuk editor yang tidak suka WebM
if (WANT_MOV) {
  ff('intro_alpha.mov', [
    '-framerate', fps, '-i', pat,
    '-c:v', 'prores_ks', '-profile:v', '4444', '-pix_fmt', 'yuva444p10le',
    '-alpha_bits', '8', '-an', join(OUT, 'intro_alpha.mov'),
  ]);
}

if (!KEEP) rmSync(FRAMES, { recursive: true, force: true });
console.log('\nSelesai. Berkasnya ada di ' + OUT);
