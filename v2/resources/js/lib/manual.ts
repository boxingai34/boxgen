/**
 * Isian manual Rancang Pertandingan.
 *
 * Bentuknya dipisah dari komponennya karena tiga tempat memakainya dan
 * ketiganya harus setuju sampai ke nama kuncinya: kartu isiannya yang
 * menyunting, halaman yang mengirimnya ke server, dan rancangan tersimpan
 * yang memasangnya kembali. Kunci yang meleset satu huruf di salah satu
 * tempat tidak menimbulkan galat apa pun — pilihannya cuma diam-diam tidak
 * terpakai, dan itu jenis kerusakan yang paling lama ketahuan.
 *
 * SEMUANYA BOLEH KOSONG. Kosong berarti "ikut ceritamu": mesin membaca
 * sendiri dari cerita seperti sebelum isian ini ada. Tidak ada nilai
 * bawaan yang diam-diam menimpa hasil bacaan — lihat Cerita::terapkanManual.
 */
export type PetinjuManual = {
    nama: string;
    karakter: string;
    seri: string;
    sex: '' | 'female' | 'male';
    fisik: string;
    /** id modul tema pakaian, katalog yang sama dengan Prompt Generator */
    pakaian_id: number | '';
    /** kalimat pakaian sendiri; menimpa kalimat bawaan temanya */
    pakaian: string;
};

export type Manual = {
    judul: string;
    /** panjang video dalam detik; 0 = ikut ceritamu */
    durasi: number | '';
    /** panjang tiap klip dalam detik; 0 = ikut ceritamu, atau mesin yang menentukan */
    detik_per_klip: number | '';
    waktu: { mulai: string; keterangan: string };
    tempat: {
        nama: string;
        isi: string;
        rincian: string;
        ring: '' | 'ya' | 'tidak';
        penonton: '' | 'none' | 'sparse' | 'packed';
    };
    pemenang: '' | '1' | '2';
    cara: string;
    petinju: [PetinjuManual, PetinjuManual];
};

function petinjuBaru(): PetinjuManual {
    return { nama: '', karakter: '', seri: '', sex: '', fisik: '', pakaian_id: '', pakaian: '' };
}

export function manualBaru(): Manual {
    return {
        judul: '',
        durasi: '',
        detik_per_klip: '',
        waktu: { mulai: '', keterangan: '' },
        tempat: { nama: '', isi: '', rincian: '', ring: '', penonton: '' },
        pemenang: '',
        cara: '',
        petinju: [petinjuBaru(), petinjuBaru()],
    };
}

/**
 * Pasang kembali isian yang tersimpan, kunci demi kunci.
 *
 * Bukan Object.assign: rancangan yang disimpan versi lama tidak punya
 * seluruh kuncinya, dan menyalin apa adanya meninggalkan objek setengah
 * jadi yang bikin v-model menulis ke properti yang tidak ada.
 */
export function manualDari(tersimpan: unknown): Manual {
    const m = manualBaru();
    const t = (tersimpan ?? {}) as Record<string, any>;

    m.judul = String(t.judul ?? '');
    m.durasi = Number(t.durasi ?? 0) > 0 ? Number(t.durasi) : '';
    m.detik_per_klip = Number(t.detik_per_klip ?? 0) > 0 ? Number(t.detik_per_klip) : '';
    m.waktu.mulai = String(t.waktu?.mulai ?? '');
    m.waktu.keterangan = String(t.waktu?.keterangan ?? '');
    m.tempat.nama = String(t.tempat?.nama ?? '');
    m.tempat.isi = String(t.tempat?.isi ?? '');
    m.tempat.rincian = String(t.tempat?.rincian ?? '');
    m.tempat.ring = (t.tempat?.ring ?? '') as Manual['tempat']['ring'];
    m.tempat.penonton = (t.tempat?.penonton ?? '') as Manual['tempat']['penonton'];
    m.pemenang = (t.pemenang ?? '') as Manual['pemenang'];
    m.cara = String(t.cara ?? '');

    for (const i of [0, 1] as const) {
        const p = (t.petinju ?? [])[i] ?? {};
        m.petinju[i] = {
            nama: String(p.nama ?? ''),
            karakter: String(p.karakter ?? ''),
            seri: String(p.seri ?? ''),
            sex: (p.sex ?? '') as PetinjuManual['sex'],
            fisik: String(p.fisik ?? ''),
            pakaian_id: Number(p.pakaian_id ?? 0) > 0 ? Number(p.pakaian_id) : '',
            pakaian: String(p.pakaian ?? ''),
        };
    }

    return m;
}

/** Berapa isian yang benar-benar diisi — dipakai lencana di kepala kartunya. */
export function jumlahTerisi(m: Manual): number {
    let n = 0;
    const hitung = (v: unknown) => {
        if (typeof v === 'number' ? v > 0 : String(v ?? '') !== '') n++;
    };

    hitung(m.judul);
    hitung(m.durasi);
    hitung(m.detik_per_klip);
    hitung(m.waktu.mulai);
    hitung(m.waktu.keterangan);
    hitung(m.tempat.nama);
    hitung(m.tempat.isi);
    hitung(m.tempat.rincian);
    hitung(m.tempat.ring);
    hitung(m.tempat.penonton);
    hitung(m.pemenang);
    hitung(m.cara);
    for (const p of m.petinju) {
        hitung(p.nama);
        hitung(p.karakter);
        hitung(p.seri);
        hitung(p.sex);
        hitung(p.fisik);
        hitung(p.pakaian_id);
        hitung(p.pakaian);
    }

    return n;
}
