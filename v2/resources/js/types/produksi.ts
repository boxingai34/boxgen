/**
 * Bentuk data menu PRODUKSI — cermin §4, §5, dan §8 RENCANA-PRODUKSI.md.
 *
 * Nama kolom sengaja dibiarkan persis seperti yang dikirim controller
 * (snake_case, bahasa Indonesia), supaya membaca kontrak dan membaca
 * halaman tidak perlu menerjemahkan dua kali.
 *
 * Dua kelonggaran yang disengaja:
 * - Angka uang boleh datang sebagai teks ("1.00"): kolom DECIMAL MySQL
 *   dibaca PDO sebagai string kalau controller lupa meng-cast. Pengatur
 *   tampilan di lib/produksi.ts menerima keduanya.
 * - Bendera ya/tidak boleh datang sebagai 0/1 dari TINYINT. Halaman
 *   selalu membacanya lewat kebenaran (truthy), bukan === true.
 */

// ---- Kamus status (§4, §5) ----

/** Tahap pack, dihitung server (tidak disimpan). */
export type Tahap = 'diblokir' | 'cek' | 'tester' | 'klip' | 'review' | 'siap' | 'selesai';

/** Status umur pack = yang terburuk dari karakternya. */
export type UmurStatus = 'dewasa' | 'olahraga' | 'tolak' | 'tak_dikenal';

/** Tingkat hasil cek: aman (hijau), saran (oranye), awas (item tertahan), blokir (seluruh pack tertahan). */
export type Tingkat = 'aman' | 'saran' | 'awas' | 'blokir';

export type StatusItem = 'belum' | 'berjalan' | 'review' | 'lolos' | 'gagal' | 'diblokir';

export type StatusTake = 'siap' | 'unggah' | 'queued' | 'in_progress' | 'completed' | 'unduh' | 'selesai' | 'failed' | 'nsfw' | 'canceled' | 'galat';

export type Keputusan = 'lolos' | 'pakai' | 'gagal';

export type Jenis = 'tester' | 'klip';

export type FormatPack = 'json' | 'md' | 'lama';

/** Nilai satu kriteria di checklist review. */
export type NilaiKriteria = 'lolos' | 'gagal';

/** Bendera dari TINYINT bisa datang sebagai 0/1. */
export type Bendera = boolean | 0 | 1;

/** Uang dalam USD; lihat catatan di atas soal teks. */
export type Uang = number | string;

// ---- Potongan yang dipakai banyak halaman ----

export interface Gambar {
    nama: string;
    url: string;
}

export interface Cek {
    kode: 'gambar' | 'panjang' | 'sebutan' | 'umur' | 'subtitle' | 'negative' | 'waktu' | 'prompt' | string;
    tingkat: Tingkat;
    judul: string;
    detail: string;
    /** Kode item (T04, 01); null = berlaku untuk seluruh pack. */
    item: string | null;
}

export interface UmurKarakter {
    nama: string;
    status: UmurStatus;
    umur: number | null;
    catatan: string | null;
    karya: string | null;
}

/** Satu baris take di Antrian, dan isi polling produksi.status. */
export interface TakeBaris {
    id: number;
    pack_id: number;
    pack_judul: string;
    item_kode: string;
    item_judul: string;
    nomor: number;
    status: StatusTake;
    /** Kalimat status dari server ("Sedang dibuat", "Diblokir filter"). */
    label: string;
    /** Keterangan tambahan ("Diunduh 07.12 · 10,0 dtk · $1,00"). */
    ket: string;
    biaya: Uang | null;
    bisa_review: Bendera;
    url_review: string | null;
    /** Jam "HH.mm". */
    waktu: string;
    /** Take API yang dibuat dengan prompt yang sudah diganti di pack (tag "prompt lama"). Take manual: selalu false. */
    prompt_lama: Bendera;
    /** Take API yang sengaja dikirim tanpa suara (tag "tanpa suara"). */
    tanpa_suara: Bendera;
}

/**
 * Keadaan pemantau latar (Pemantau::infoPemantau). `hidup` hanya kalau
 * pemantau sendiri yang menulis detaknya; `pesan` terisi kalau tidak hidup
 * padahal ada take aktif.
 */
export interface InfoPemantau {
    hidup: Bendera;
    pesan: string | null;
    /** Jam detak terakhir "HH.mm", atau null. */
    detak: string | null;
}

export interface JawabStatus {
    /** Take aktif + 20 terakhir; dengan ?pack_id= juga SEMUA take pack itu. */
    takes: TakeBaris[];
    slot_terpakai: number;
    pemantau: InfoPemantau;
    /** Nama lama, sama dengan pemantau.hidup. */
    pemantau_hidup: Bendera;
    /** Perintah Command Prompt untuk menyalakan pemantau sendiri. */
    perintah_pemantau: string;
}

// ---- Produksi/Papan ----

export interface PackRingkas {
    id: number;
    folder: string;
    judul: string;
    revisi: string;
    model: string;
    resolusi: string;
    rasio: string;
    jumlah_klip: number;
    /** Detik. */
    durasi_total: number;
    tahap: Tahap;
    /** "Tester 3 dari 6 lolos" */
    ringkas: string;
    /** Kalimat langkah berikutnya. */
    berikutnya: string;
    umur_status: UmurStatus;
    umur_alasan: string;
    gambar_a: string | null;
    gambar_b: string | null;
    biaya: Uang;
    /** Jumlah cek saran/awas. */
    saran: number;
    /** Jumlah item yang prompt-nya diperbarui sesudah take API terakhirnya dan versi barunya belum dicoba. */
    prompt_baru: number;
}

export interface FolderBaru {
    folder: string;
    format: FormatPack;
}

export interface KoneksiPapan {
    siap: Bendera;
    awalan: string;
    model: string;
    nama_model: string;
    resolusi: string;
    rasio: string;
    slot_terpakai: number;
}

export interface Aktivitas {
    waktu: string;
    teks: string;
}

export interface PropsPapan {
    packs: PackRingkas[];
    folderBaru: FolderBaru[];
    koneksi: KoneksiPapan;
    biayaBulanIni: Uang;
    aktivitas: Aktivitas[];
}

// ---- Produksi/Pack ----

export interface PackDetail {
    id: number;
    folder: string;
    judul: string;
    revisi: string;
    model: string;
    nama_model: string;
    resolusi: string;
    rasio: string;
    jumlah_klip: number;
    durasi_total: number;
    tahap: Tahap;
    umur_status: UmurStatus;
    umur: UmurKarakter[];
    umur_dikonfirmasi: Bendera;
    klip_dibuka: Bendera;
    klip_terkunci: Bendera;
    /** Kode tester yang belum lolos. */
    kurang: string[];
    cek: Cek[];
    kontinuitas: string[];
    dokumen: string[];
    biaya_terpakai: Uang;
    perkiraan_sisa: Uang;
    peringatan: string[];
    /** "id:status" semua take pack, terurut — dibandingkan dengan sidikTake() hasil polling. */
    sidik_take: string;
}

export interface TakeRingkas {
    id: number;
    nomor: number;
    status: StatusTake;
}

export interface TakeSingkat {
    id: number;
    nomor: number;
}

/** ITEM_HALAMAN: satu tester atau klip di halaman pack. */
export interface ItemHalaman {
    id: number;
    kode: string;
    judul: string;
    durasi: number;
    gambar: Gambar[];
    status: StatusItem;
    /** Take bernomor terbesar. */
    take_terakhir: TakeRingkas | null;
    /** Take selesai pertama yang belum diputuskan (tujuan tombol Review). */
    take_review: TakeSingkat | null;
    /** Take yang lolos/dipakai (tujuan tombol Lihat). */
    take_pilihan: TakeSingkat | null;
    jumlah_take: number;
    /** Jumlah biaya SEMUA take item ini, bukan harga satu take. */
    biaya: Uang;
    cek: Cek[];
    bisa_kirim: Bendera;
    alasan_tidak: string | null;
    /**
     * Nomor take API terbaru kalau prompt item sudah diperbarui sesudahnya dan
     * versi barunya belum dicoba (chip "Prompt baru · belum dicoba"); null = tanpa tanda.
     */
    prompt_baru_sesudah: number | null;
}

export interface PropsPack {
    pack: PackDetail;
    tester: ItemHalaman[];
    klip: ItemHalaman[];
    /** Byte terbesar yang bisa diunggah lewat "Tambah take dari berkas"; null = PHP tidak membatasi. */
    batas_unggah: number | null;
}

// ---- Produksi/Kirim ----

export interface PackKirim {
    id: number;
    judul: string;
    model: string;
    nama_model: string;
    resolusi: string;
    rasio: string;
    /** "id:status" semua take pack saat halaman dibuka — polling memuat ulang `item` begitu sidiknya berbeda. */
    sidik_take: string;
}

export interface ItemKirim {
    id: number;
    jenis: Jenis;
    kode: string;
    judul: string;
    durasi: number;
    gambar: Gambar[];
    /** "Belum pernah dijalankan", "Ulang dengan seed baru — take 1 diblokir filter" (atau isi `peringatan`) */
    ket: string;
    /**
     * Take terakhir item ini mungkin sudah dibuat dan ditagih: tidak pernah
     * tercentang awal, dan kirim butuh centang "sudah cek dashboard" dulu.
     */
    peringatan: string | null;
    pilih_awal: Bendera;
    /** Nomor take berikutnya (take pertama yang akan dibuat). */
    take_berikut: number;
    tarif_per_take: Uang;
    bisa_kirim: Bendera;
    alasan_tidak: string | null;
    /** Seperti ItemHalaman: nomor take API terbaru yang masih memakai prompt lama, atau null. */
    prompt_baru_sesudah: number | null;
    /** Prompt memuat dialog Jepang (「…」): "Suara: Mati" tidak boleh dipilih. */
    ada_dialog: Bendera;
}

export interface PropsKirim {
    pack: PackKirim;
    item: ItemKirim[];
}

/** Satu baris pilihan kirim: 1–3 take untuk satu item. */
export interface PilihanKirim {
    item_id: number;
    takes: number;
    /** false = kirim tanpa suara (generate_audio false); harga sama. Tidak diisi = menyala. */
    audio?: boolean;
}

export interface JawabPerkiraan {
    total: Uang;
    rincian: Array<Record<string, unknown>>;
}

export interface JawabKirim {
    ok: boolean;
    take_ids: number[];
    total: Uang;
}

/** POST produksi.item.manual (multipart `berkas`) dan produksi.item.manual.folder ({nama}). */
export interface JawabManual {
    ok: boolean;
    take_id: number;
    url: string;
}

/** Satu video di folder pack untuk "Ambil dari folder pack". */
export interface BerkasPack {
    /** Relatif ke folder pack, pemisah '/': "test klip higgsfield/api T04 seed1238296069.mp4". */
    nama: string;
    /** Byte. */
    ukuran: number;
    /** "HH.mm" kalau hari ini, selain itu "dd/mm HH.mm". */
    diubah: string;
    /** Take pack ini yang ukurannya sama persis ("T04 take 3") — mungkin sudah pernah ditambahkan. */
    take_sama: string | null;
}

/** GET produksi.item.berkas */
export interface JawabBerkasPack {
    ok: boolean;
    /** Nama folder pack di 1 - ANTRIAN. */
    folder: string;
    /** Terbaru dulu, paling banyak 200. */
    berkas: BerkasPack[];
}

// ---- Produksi/Antrian ----

export interface PropsAntrian {
    takes: TakeBaris[];
}

// ---- Produksi/Review ----

export interface Mirip {
    take_id: number;
    nomor: number;
    /** 0..1 */
    skor: number;
}

export interface Analisis {
    durasi: number | null;
    lebar: number | null;
    tinggi: number | null;
    fps: number | null;
    audio: Bendera | null;
    puncak_db: number | null;
    mirip: Mirip[];
}

export interface CekTake {
    tingkat: Tingkat;
    teks: string;
    detail: string | null;
}

/** Catatan per waktu; t dalam detik. */
export interface CatatanWaktu {
    t: number;
    isi: string;
}

/**
 * Penilaian per kriteria, berkunci indeks kriteria.
 * PHP mengubah array berindeks 0..n jadi JSON array, dan array kosong jadi
 * [] — jadi bentuk daftar juga diterima (lihat rapikanNilai di lib/produksi.ts).
 */
export type NilaiTake = Record<string, NilaiKriteria> | Array<NilaiKriteria | null>;

export interface ReviewTake {
    id: number;
    nomor: number;
    status: StatusTake;
    sumber: 'api' | 'manual' | string;
    seed: number | string | null;
    model: string;
    resolusi: string;
    durasi: number;
    video_url: string | null;
    poster_url: string | null;
    bingkai: Array<{ url: string; t: number }>;
    analisis: Analisis | null;
    cek: CekTake[];
    nilai: NilaiTake | null;
    catatan: CatatanWaktu[] | null;
    keputusan: Keputusan | null;
    galat: string | null;
    /** Untuk dicari di dashboard Higgsfield; null kalau belum pernah diterima. */
    request_id: string | null;
    /** Biaya tercatat; null = belum pasti (mungkin ditagih). */
    biaya: Uang | null;
    /** Harga take ini (tarif × durasi); tampil di konfirmasi kirim ulang. */
    estimasi: Uang;
    /**
     * Take galat yang mungkin sudah dibayar: tombol pemulihan (POST produksi.take.periksa).
     * Dengan request_id: "Periksa lagi", satu klik. Tanpa request_id: body dikirim ulang,
     * yang bisa membuat dan menagih take — wajib konfirmasi harga ({konfirmasi: true}).
     */
    bisa_periksa: Bendera;
    /** Take ini dibuat dengan prompt yang sudah diganti di pack (take manual: selalu false). */
    prompt_lama: Bendera;
    /** Take API yang sengaja dikirim tanpa suara; cek "Ada suara" tidak menganggapnya cacat. */
    tanpa_suara: Bendera;
}

export interface ReviewItem {
    id: number;
    jenis: Jenis;
    kode: string;
    judul: string;
    durasi: number;
    kriteria: string[];
    gambar: Gambar[];
    /**
     * Seperti ItemHalaman: nomor take API terbaru yang masih memakai prompt lama, atau null
     * (versi prompt sekarang sudah dipakai take yang lebih baru — "Ulangi dengan prompt baru" tidak ditawarkan).
     */
    prompt_baru_sesudah: number | null;
}

export interface TakeLain {
    id: number;
    nomor: number;
    status: StatusTake;
    keputusan: Keputusan | null;
    prompt_lama: Bendera;
}

export interface ReviewPack {
    id: number;
    judul: string;
    revisi: string;
    kontinuitas: string[];
}

export interface PropsReview {
    take: ReviewTake;
    item: ReviewItem;
    takes: TakeLain[];
    pack: ReviewPack;
    /** Byte terbesar yang bisa diunggah lewat "Tambah take dari berkas"; null = PHP tidak membatasi. */
    batas_unggah: number | null;
}

export interface JawabPutuskan {
    ok: boolean;
    item_status: StatusItem;
}

// ---- Produksi/Hasil ----

export interface HasilPack {
    id: number;
    judul: string;
    folder: string;
}

export interface UrutanHasil {
    kode: string;
    judul: string;
    take_id: number | null;
    take_nomor: number | null;
    durasi_rencana: number;
    durasi_aktual: number | null;
}

export interface SubtitleHasil {
    /** Kode bahasa: id, en, ja. */
    bahasa: string[];
    baris: number;
    /** Jumlah baris yang bergeser lebih dari 0,5 dtk. */
    bergeser: number;
    catatan: string;
}

export interface BiayaHasil {
    tester: Uang;
    tester_take: number;
    klip: Uang;
    klip_take: number;
    total: Uang;
    detik_dibuat: number;
    detik_film: number;
}

export interface PropsHasil {
    pack: HasilPack;
    urutan: UrutanHasil[];
    total_rencana: number;
    total_aktual: number;
    subtitle: SubtitleHasil;
    catatan_edit: string[];
    biaya: BiayaHasil;
    siap: Bendera;
    sudah_ekspor: Bendera;
    folder_final: string;
    diserahkan: Bendera;
}

// ---- Produksi/Pengaturan ----

export interface ModelTersedia {
    kode: string;
    nama: string;
    /** USD per detik: satu angka (resolusi aktif) atau per resolusi seperti di config. */
    tarif: number | Record<string, number>;
}

export interface PropsPengaturan {
    koneksi: { siap: Bendera; awalan: string };
    folder: string;
    antrian_ada: Bendera;
    model: string;
    model_tersedia: ModelTersedia[];
    resolusi: string;
    rasio: string;
}

export interface JawabUji {
    ok: boolean;
    pesan: string;
}
