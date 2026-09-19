import { computed, ref, type Ref } from 'vue';

/**
 * Cari, saring, dan urutkan isi satu katalog.
 *
 * Katalog di alat ini tumbuh terus — gaya visual sudah enam puluh enam,
 * dan daftar pakaian akan jauh lebih panjang lagi. Di atas kira-kira tiga
 * puluh kartu, menggulir sambil melihat satu per satu berhenti jadi cara
 * yang masuk akal untuk menemukan sesuatu yang sudah kamu tahu namanya.
 *
 * Dipisah dari komponennya karena dua katalog memakainya dengan bentuk
 * data yang berbeda (gaya punya id angka, modul bisa id teks), dan menyalin
 * logika yang sama dua kali berarti dua tempat yang bisa berbeda perilaku.
 */
export type IsiKatalog = {
    id: number | string;
    nama: string;
    kategori?: string;
    ket?: string;
    contoh?: string | null;
};

/**
 * Kata dinormalkan sebelum dibandingkan.
 *
 * Tag ditulis dengan garis bawah (chest_sarashi), namanya dengan spasi
 * ("Sarashi dada"), dan yang mengetik memakai salah satunya tanpa berpikir.
 * Keduanya diratakan supaya "chest sarashi" dan "chest_sarashi" sama-sama
 * ketemu.
 */
function rata(t: unknown): string {
    return String(t ?? '')
        .toLowerCase()
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

export type Urutan = 'bawaan' | 'nama' | 'bergambar';

export function pakaiKatalog<T extends IsiKatalog>(sumber: Ref<T[]> | (() => T[])) {
    const semua = computed<T[]>(() => (typeof sumber === 'function' ? sumber() : sumber.value));

    const cari = ref('');
    const kategoriAktif = ref('');
    const urut = ref<Urutan>('bawaan');

    /** Kategori yang benar-benar ada isinya, urut sesuai urutan datangnya. */
    const kategori = computed(() => {
        const keluar: string[] = [];
        for (const m of semua.value) {
            const k = m.kategori || '';
            if (k !== '' && ! keluar.includes(k)) keluar.push(k);
        }

        return keluar;
    });

    const hasil = computed<T[]>(() => {
        const kata = rata(cari.value);
        let isi = semua.value;

        if (kategoriAktif.value !== '') {
            isi = isi.filter((m) => (m.kategori || '') === kategoriAktif.value);
        }

        if (kata !== '') {
            // Tiap kata dicari terpisah dan semuanya harus ketemu, jadi
            // "bikini tali" menemukan "string bikini" walau urutannya beda.
            const potong = kata.split(' ');
            isi = isi.filter((m) => {
                const bahan = rata([m.nama, m.kategori, m.ket, m.id].join(' '));

                return potong.every((p) => bahan.includes(p));
            });
        }

        if (urut.value === 'nama') {
            isi = [...isi].sort((a, b) => a.nama.localeCompare(b.nama, 'id'));
        } else if (urut.value === 'bergambar') {
            // Yang sudah punya contoh dulu; di dalamnya urutan aslinya
            // dipertahankan, bukan diacak jadi abjad.
            isi = [...isi].sort((a, b) => Number(Boolean(b.contoh)) - Number(Boolean(a.contoh)));
        }

        return isi;
    });

    /**
     * Dikelompokkan per kategori — kecuali waktu sedang dicari atau
     * diurutkan ulang. Kalau hasilnya tinggal empat kartu dari tiga
     * kategori, judul kategori lebih banyak daripada isinya.
     */
    const berkelompok = computed(() => {
        if (cari.value.trim() !== '' || urut.value !== 'bawaan' || kategoriAktif.value !== '') {
            return [{ nama: '', isi: hasil.value }];
        }

        const peta = new Map<string, T[]>();
        for (const m of hasil.value) {
            const k = m.kategori || '';
            if (! peta.has(k)) peta.set(k, []);
            peta.get(k)!.push(m);
        }

        return [...peta.entries()].map(([nama, isi]) => ({ nama, isi }));
    });

    function bersihkan() {
        cari.value = '';
        kategoriAktif.value = '';
        urut.value = 'bawaan';
    }

    return { cari, kategoriAktif, urut, kategori, hasil, berkelompok, bersihkan };
}
