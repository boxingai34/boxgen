/**
 * Kirim permintaan JSON ke Laravel, lengkap dengan token CSRF.
 *
 * Inertia memakai axios untuk kunjungan halaman, tapi tombol di halaman
 * Rancang bukan kunjungan halaman: dia menunggu jawaban yang bisa dua
 * menit lamanya, lalu menggambar hasilnya tanpa memuat ulang apa pun.
 * Untuk itu fetch biasa jauh lebih ringan — asal token XSRF dari kuki
 * ikut dikirim, karena tanpanya Laravel menolak dengan 419.
 */

function tokenXsrf(): string {
    const kuki = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return kuki ? decodeURIComponent(kuki.slice('XSRF-TOKEN='.length)) : '';
}

export class GalatKirim extends Error {
    constructor(
        pesan: string,
        public status: number,
    ) {
        super(pesan);
        this.name = 'GalatKirim';
    }
}

export async function kirim<T = any>(alamat: string, isi?: unknown, metode: 'GET' | 'POST' | 'DELETE' = 'POST'): Promise<T> {
    const jawab = await fetch(alamat, {
        method: metode,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': tokenXsrf(),
        },
        body: isi === undefined ? undefined : JSON.stringify(isi),
    });

    let data: any = null;
    try {
        data = await jawab.json();
    } catch {
        throw new GalatKirim('Server membalas bukan JSON. Coba muat ulang halaman.', jawab.status);
    }

    if (!jawab.ok || data?.ok === false) {
        // Pesan validasi Laravel datang sebagai { errors: { field: [pesan] } }.
        const pesanValidasi = data?.errors ? (Object.values(data.errors)[0] as string[])?.[0] : null;

        throw new GalatKirim(data?.error || pesanValidasi || data?.message || `Gagal (HTTP ${jawab.status}).`, jawab.status);
    }

    return data as T;
}

/**
 * Kirim yang tahan diputus proxy.
 *
 * Hosting memakai nginx yang menutup sambungan sekitar 60 detik, sedangkan
 * membaca cerita memanggil AI dua kali dan bisa dua menit. PHP di server
 * tetap menyelesaikannya, dan jawaban tiap panggilan AI tersimpan di
 * ai_cache — jadi bertanya lagi dengan kiriman yang sama biasanya jauh
 * lebih cepat, dan tidak ada token yang terbuang dua kali.
 *
 * Yang diulang cuma kegagalan SAMBUNGAN (putus, 502, 503, 504). Galat
 * yang berupa jawaban server — jatah habis, cerita terlalu pendek, sesi
 * kedaluwarsa — langsung dilempar, karena mengulangnya tidak akan
 * mengubah apa pun.
 */
export async function kirimUlang<T = any>(
    alamat: string,
    isi: unknown,
    opsi: { ulang?: number; lapor?: (teks: string) => void } = {},
): Promise<T> {
    const maks = opsi.ulang ?? 4;
    const jeda = [15000, 25000, 40000, 60000];

    for (let ke = 0; ke <= maks; ke++) {
        try {
            return await kirim<T>(alamat, isi);
        } catch (e) {
            const sambunganPutus =
                !(e instanceof GalatKirim) || [0, 502, 503, 504].includes((e as GalatKirim).status);

            if (!sambunganPutus || ke === maks) throw e;

            const detik = Math.round(jeda[Math.min(ke, jeda.length - 1)] / 1000);
            opsi.lapor?.(`Sambungan terputus, tapi pekerjaannya tetap jalan di server — mengambil hasilnya ${detik} detik lagi…`);
            await new Promise((r) => setTimeout(r, jeda[Math.min(ke, jeda.length - 1)]));
        }
    }

    throw new GalatKirim('Gagal menghubungi server.', 0);
}

/** Unggah satu berkas (multipart), dengan token CSRF yang sama. */
export async function unggahBerkas<T = any>(alamat: string, nama: string, berkas: File, lapor?: (persen: number) => void): Promise<T> {
    return new Promise((selesai, gagal) => {
        const xhr = new XMLHttpRequest();
        const data = new FormData();
        data.append(nama, berkas);

        xhr.open('POST', alamat);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-XSRF-TOKEN', tokenXsrf());

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable && lapor) lapor(Math.round((e.loaded / e.total) * 100));
        };

        xhr.onload = () => {
            let j: any = null;
            try {
                j = JSON.parse(xhr.responseText);
            } catch {
                gagal(new GalatKirim('Server membalas bukan JSON.', xhr.status));
                return;
            }
            if (xhr.status >= 200 && xhr.status < 300 && j?.ok !== false) {
                selesai(j as T);
            } else {
                const pesanValidasi = j?.errors ? (Object.values(j.errors)[0] as string[])?.[0] : null;
                gagal(new GalatKirim(j?.error || pesanValidasi || j?.message || `Gagal (HTTP ${xhr.status}).`, xhr.status));
            }
        };
        xhr.onerror = () => gagal(new GalatKirim('Sambungan terputus saat mengunggah.', 0));
        xhr.send(data);
    });
}

/** Salin ke papan klip, dengan cadangan untuk browser yang menolak. */
export async function salin(teks: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(teks);
        return true;
    } catch {
        try {
            const ta = document.createElement('textarea');
            ta.value = teks;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch {
            return false;
        }
    }
}

/**
 * Minta sesuatu yang bukan JSON — gambar, misalnya.
 *
 * Gambar tidak dikirim sebagai data-URI di dalam JSON: base64 menggembungkan
 * satu megabyte jadi hampir satu setengah, dan badan sebesar itu sempat
 * diputus di tengah jalan. Yang datang biner apa adanya, keterangannya
 * (model, ukuran, sisa jatah) menumpang di header. Galat tetap JSON, jadi
 * pesannya tetap terbaca seperti biasa.
 */
export async function kirimGambar(alamat: string, isi: unknown): Promise<{ url: string; model: string; byte: number; kuota: string }> {
    const jawab = await fetch(alamat, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'image/*, application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': tokenXsrf(),
        },
        body: JSON.stringify(isi),
    });

    const tipe = jawab.headers.get('content-type') || '';

    if (!jawab.ok || !tipe.startsWith('image/')) {
        let pesan = `Gagal (HTTP ${jawab.status}).`;
        try {
            const data = await jawab.json();
            pesan = data?.error || data?.message || pesan;
        } catch {
            /* bukan JSON juga — pakai pesan bawaan di atas */
        }
        throw new GalatKirim(pesan, jawab.status);
    }

    const gumpal = await jawab.blob();

    return {
        url: URL.createObjectURL(gumpal),
        model: jawab.headers.get('X-Gambar-Model') || '',
        byte: Number(jawab.headers.get('X-Gambar-Byte') || gumpal.size),
        kuota: jawab.headers.get('X-Gambar-Kuota') || '',
    };
}
