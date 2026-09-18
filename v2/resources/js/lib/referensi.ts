/**
 * Menyiapkan referensi di browser sebelum dikirim.
 *
 * Yang naik ke server bukan berkas aslinya: gambar dikecilkan dulu, video
 * dipotong jadi beberapa frame plus satu lembar kontak. Satu frame 1080p
 * berukuran 8 MB sebagai kanvas — mengirim dua belas buah apa adanya sama
 * saja membuang kuota dan waktu untuk data yang tidak dibaca model.
 *
 * Semua ini berjalan di tab pengunjung; server tidak pernah menerima
 * berkas mentahnya.
 */

/** Sisi terpanjang gambar tunggal. */
const SISI_GAMBAR = 1280;

/** Sisi terpanjang tiap frame video. */
const SISI_FRAME = 1024;

/** Tiap petak lembar kontak 4×2. */
const PETAK_SHEET = 512;

const MUTU_GAMBAR = 0.85;
const MUTU_FRAME = 0.82;

export type Bingkai = {
    data: string;
    mime: string;
    t: number | null;
    w: number;
    h: number;
    url: string;
};

export type Referensi = {
    kind: 'image' | 'video';
    images: Bingkai[];
    sheet: { data: string; mime: string } | null;
    duration: number | null;
    w: number;
    h: number;
    nama: string;
    byte: number;
};

export function jenisBerkas(file: File): 'image' | 'video' | null {
    const tipe = (file.type || '').toLowerCase();
    const nama = (file.name || '').toLowerCase();

    if (tipe.startsWith('image/') || /\.(jpe?g|png|webp)$/.test(nama)) return 'image';
    if (tipe.startsWith('video/') || /\.(mp4|webm|mov|m4v)$/.test(nama)) return 'video';

    return null;
}

/** Kecilkan ke kanvas dengan sisi terpanjang ≤ maksSisi — tidak pernah diperbesar. */
function kecilkan(sumber: CanvasImageSource, sw: number, sh: number, maksSisi: number): HTMLCanvasElement {
    const skala = Math.min(1, maksSisi / Math.max(sw, sh));
    const w = Math.max(1, Math.round(sw * skala));
    const h = Math.max(1, Math.round(sh * skala));

    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;

    const ctx = c.getContext('2d')!;
    // JPEG tidak punya transparansi: PNG berlatar bening jadi hitam kalau
    // tidak dialasi dulu.
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(sumber, 0, 0, w, h);

    return c;
}

function keBase64(kanvas: HTMLCanvasElement, mutu: number): { url: string; data: string } {
    const url = kanvas.toDataURL('image/jpeg', mutu);

    return { url, data: url.slice(url.indexOf(',') + 1) };
}

async function muatBitmap(file: File): Promise<ImageBitmap | HTMLImageElement> {
    // createImageBitmap sekalian membaca orientasi EXIF — foto dari ponsel
    // sering tersimpan miring dan baru "diputar" oleh penampilnya.
    if (window.createImageBitmap) {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch {
            /* jatuh ke <img> biasa */
        }
    }

    return new Promise((selesai, gagal) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => { URL.revokeObjectURL(url); selesai(img); };
        img.onerror = () => { URL.revokeObjectURL(url); gagal(new Error('Gambar ini tidak bisa dibaca browser.')); };
        img.src = url;
    });
}

export async function prosesGambar(file: File): Promise<Referensi> {
    const bmp = await muatBitmap(file);
    const sw = (bmp as HTMLImageElement).naturalWidth || (bmp as ImageBitmap).width;
    const sh = (bmp as HTMLImageElement).naturalHeight || (bmp as ImageBitmap).height;

    if (!sw || !sh) throw new Error('Ukuran gambar tidak terbaca.');

    const c = kecilkan(bmp as CanvasImageSource, sw, sh, SISI_GAMBAR);
    if ((bmp as ImageBitmap).close) (bmp as ImageBitmap).close();

    const b = keBase64(c, MUTU_GAMBAR);

    return {
        kind: 'image',
        images: [{ data: b.data, mime: 'image/jpeg', t: null, w: c.width, h: c.height, url: b.url }],
        sheet: null,
        duration: null,
        w: sw,
        h: sh,
        nama: file.name,
        byte: file.size,
    };
}

/** Janji yang selesai pada event `ok`, atau gagal pada `error`. */
function tunggu(elemen: HTMLElement, ok: string, pesanGagal: string): Promise<void> {
    return new Promise((selesai, gagal) => {
        const bersih = () => {
            elemen.removeEventListener(ok, sudah);
            elemen.removeEventListener('error', salah);
        };
        const sudah = () => { bersih(); selesai(); };
        const salah = () => { bersih(); gagal(new Error(pesanGagal)); };

        elemen.addEventListener(ok, sudah);
        elemen.addEventListener('error', salah);
    });
}

export async function prosesVideo(file: File, n: number, lapor: (pesan: string) => void): Promise<Referensi> {
    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.preload = 'auto';

    const url = URL.createObjectURL(file);

    try {
        video.src = url;
        await tunggu(video, 'loadedmetadata', 'Browser tidak bisa memutar video ini — coba ubah dulu ke MP4 (H.264).');

        let durasi = video.duration;
        if (!isFinite(durasi) || durasi <= 0) {
            // Beberapa WebM hasil rekam layar tidak menulis durasinya di kepala
            // berkas. Melompat ke "tak hingga" memaksa browser menghitungnya.
            video.currentTime = 1e6;
            await tunggu(video, 'seeked', 'Durasi video tidak terbaca.');
            durasi = video.duration;
        }
        if (!isFinite(durasi) || durasi <= 0) throw new Error('Durasi video tidak terbaca.');

        const vw = video.videoWidth;
        const vh = video.videoHeight;
        if (!vw || !vh) throw new Error('Ukuran video tidak terbaca.');

        const ambil = async (t: number): Promise<HTMLCanvasElement> => {
            const tujuan = Math.min(Math.max(0, t), Math.max(0, durasi - 0.05));
            if (Math.abs(video.currentTime - tujuan) > 0.001) {
                video.currentTime = tujuan;
                await tunggu(video, 'seeked', `Gagal melompat ke detik ${t.toFixed(1)}.`);
            }

            return kecilkan(video, vw, vh, SISI_FRAME);
        };

        const waktuKe = (i: number, total: number) => Math.round(((durasi * (i + 0.5)) / total) * 100) / 100;

        // Frame merata — titik tengah tiap potongan, supaya tidak kebagian
        // frame hitam di detik 0 atau fade-out di ujung.
        const frames: Bingkai[] = [];
        const kanvas: Array<{ t: number; kecil: HTMLCanvasElement }> = [];

        for (let i = 0; i < n; i++) {
            const t = waktuKe(i, n);
            lapor(`Mengambil frame ${i + 1}/${n}…`);
            const kecil = await ambil(t);
            const b = keBase64(kecil, MUTU_FRAME);
            frames.push({ data: b.data, mime: 'image/jpeg', t, w: kecil.width, h: kecil.height, url: b.url });
            kanvas.push({ t, kecil });
        }

        // Lembar kontak 4×2: delapan petak, tiap petak diberi nomor dan
        // detiknya supaya model vision bisa merujuk urutannya.
        lapor('Menyusun lembar kontak…');
        const sheet = document.createElement('canvas');
        sheet.width = PETAK_SHEET * 4;
        sheet.height = PETAK_SHEET * 2;

        const ctx = sheet.getContext('2d')!;
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, sheet.width, sheet.height);

        for (let i = 0; i < 8; i++) {
            const t = waktuKe(i, 8);
            const punya = kanvas.find((k) => Math.abs(k.t - t) < 0.011);
            const sumber = punya ? punya.kecil : await ambil(t);

            const skala = Math.min(PETAK_SHEET / sumber.width, PETAK_SHEET / sumber.height);
            const w = Math.round(sumber.width * skala);
            const h = Math.round(sumber.height * skala);
            const px = (i % 4) * PETAK_SHEET;
            const py = Math.floor(i / 4) * PETAK_SHEET;

            ctx.drawImage(sumber, px + Math.round((PETAK_SHEET - w) / 2), py + Math.round((PETAK_SHEET - h) / 2), w, h);

            ctx.fillStyle = 'rgba(0,0,0,.65)';
            ctx.fillRect(px + 8, py + 8, 124, 30);
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 20px sans-serif';
            ctx.textBaseline = 'middle';
            ctx.fillText(`#${i + 1}  ${t.toFixed(1)}s`, px + 14, py + 23);
        }

        const sb = keBase64(sheet, MUTU_FRAME);

        return {
            kind: 'video',
            images: frames,
            sheet: { data: sb.data, mime: 'image/jpeg' },
            duration: Math.round(durasi * 10) / 10,
            w: vw,
            h: vh,
            nama: file.name,
            byte: file.size,
        };
    } finally {
        video.removeAttribute('src');
        video.load();
        URL.revokeObjectURL(url);
    }
}

/** Apa pun yang dijatuhkan, ditempel, atau dipilih — jadi satu referensi. */
export async function proses(file: File, frame: number, lapor: (pesan: string) => void): Promise<Referensi> {
    const jenis = jenisBerkas(file);

    if (jenis === 'image') {
        lapor('Mengecilkan gambar…');

        return prosesGambar(file);
    }
    if (jenis === 'video') {
        return prosesVideo(file, frame, lapor);
    }

    throw new Error('Berkas ini bukan gambar atau video yang dikenali (JPG, PNG, WebP, MP4, WebM, MOV).');
}
