import { usePage } from '@inertiajs/vue3';

/**
 * Kalimat milik komponen halaman depan, dalam bahasa yang sedang dipilih.
 *
 * Dibaca dari prop halaman, bukan dari berkas terjemahan di sisi Vue:
 * bahasanya sudah ditentukan server waktu merender, jadi mengirim ketujuh
 * bahasa ke peramban cuma memperbesar HTML-nya tanpa ada yang membacanya.
 *
 * Kunci yang tidak dikenal dikembalikan apa adanya. Itu terlihat jelek di
 * layar — dan memang begitu maksudnya: kunci salah ketik harus kelihatan,
 * bukan berubah jadi ruang kosong yang tidak ada yang sadari.
 */
export function teksLanding() {
    const halaman = usePage();

    return (kunci: string, ganti?: Record<string, string | number>): string => {
        const peta = (halaman.props.bahasa as { ui?: Record<string, string> } | undefined)?.ui ?? {};
        let teks = peta[kunci] ?? kunci;

        for (const [nama, nilai] of Object.entries(ganti ?? {})) {
            teks = teks.replace(':' + nama, String(nilai));
        }

        return teks;
    };
}
