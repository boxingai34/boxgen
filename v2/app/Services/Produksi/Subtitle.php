<?php

namespace App\Services\Produksi;

/**
 * Subtitle — baca dan tulis SRT, lalu geser waktunya mengikuti durasi take
 * yang benar-benar jadi.
 *
 * SRT di pack ditulis untuk film rencana: klip 01 = 0–30 dtk, klip 02 =
 * 30–60 dtk, dan seterusnya. Take asli jarang pas — 29,6 dtk di sini,
 * 30,4 dtk di sana — dan selisihnya menumpuk ke klip-klip sesudahnya. geser()
 * menaruh tiap baris di klip tempat ia mulai menurut rencana, lalu
 * memindahkannya ke awal klip itu di film yang sebenarnya, dengan jarak
 * dari awal klip yang sama. Baris tidak pernah keluar dari klipnya.
 *
 * Pembaca SRT-nya sabar: BOM UTF-8/UTF-16, CRLF/LF/CR, milidetik dengan koma
 * atau titik, teks beberapa baris, baris kosong berlebih, nomor urut yang
 * hilang atau acak. Penulisnya ketat: CRLF, nomor urut dari 1, tanpa BOM —
 * bentuk yang dibaca CapCut tanpa keluhan.
 */
class Subtitle
{
    /** Pergeseran lebih dari ini dihitung "bergeser" di ringkasan, milidetik. */
    public const AMBANG_BERGESER = 500;

    private const POLA_WAKTU = '/^\s*(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:[,.](\d{1,3}))?\s*-->\s*(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:[,.](\d{1,3}))?/';

    /**
     * @return list<array{mulai: int, selesai: int, teks: string}> milidetik, urutan sesuai berkas
     */
    public static function baca(string $isi): array
    {
        $baris = preg_split('/\r\n|\r|\n/', self::keUtf8($isi));

        $cue = [];
        $kini = null;
        $teks = [];
        foreach ($baris as $b) {
            if (preg_match(self::POLA_WAKTU, $b, $m)) {
                if ($kini !== null) {
                    $cue[] = self::tutup($kini, $teks);
                }
                $kini = [
                    'mulai'   => self::ms($m[1], $m[2], $m[3], $m[4] ?? ''),
                    'selesai' => self::ms($m[5] ?? '', $m[6] ?? '0', $m[7] ?? '0', $m[8] ?? ''),
                ];
                $teks = [];
                continue;
            }
            if ($kini !== null) {
                $teks[] = $b;
            }
        }
        if ($kini !== null) {
            $cue[] = self::tutup($kini, $teks, false);
        }

        return array_values(array_filter($cue, fn ($c) => $c['teks'] !== ''));
    }

    /** Tulis SRT: CRLF, nomor urut 1…n, baris kosong di antara blok. Baris bertekst kosong dilewati. */
    public static function tulis(array $cue): string
    {
        $keluar = '';
        $n = 0;
        foreach ($cue as $c) {
            $baris = array_values(array_filter(
                array_map('rtrim', preg_split('/\r\n|\r|\n/', (string) ($c['teks'] ?? ''))),
                fn ($b) => trim($b) !== '' // baris kosong di tengah teks akan memutus blok SRT
            ));
            if (!$baris) {
                continue;
            }
            $n++;
            $keluar .= $n . "\r\n"
                . self::waktu((int) $c['mulai']) . ' --> ' . self::waktu((int) $c['selesai']) . "\r\n"
                . implode("\r\n", $baris) . "\r\n\r\n";
        }

        return $keluar;
    }

    /**
     * Geser waktu subtitle dari film rencana ke film yang sebenarnya.
     *
     * Baris masuk klip k kalau mulainya berada di [awal rencana k, awal
     * rencana k+1). Mulai dan selesainya digeser sama besar: awal aktual k −
     * awal rencana k. Selesai dipotong di akhir aktual klip k. Kalau take-nya
     * lebih pendek sampai baris itu jatuh di luar klip, baris ditarik masuk
     * ke ekor klip dengan panjang aslinya (sebisanya), supaya mulai < selesai.
     *
     * @param list<array{mulai:int, selesai:int, teks:string}> $cue
     * @param list<int> $rencanaMs durasi rencana tiap klip, urut
     * @param list<int> $aktualMs  durasi take terpilih tiap klip, urut; yang kosong = sama dengan rencana
     * @return array{cue: list<array>, bergeser: int} bergeser = baris yang pindah lebih dari 0,5 dtk
     */
    public static function geser(array $cue, array $rencanaMs, array $aktualMs): array
    {
        $rencana = array_values(array_map(fn ($x) => max(0, (int) round((float) $x)), $rencanaMs));
        $aktual = array_values($aktualMs);
        $n = count($rencana);
        if ($n === 0) {
            return ['cue' => array_values($cue), 'bergeser' => 0];
        }

        $awalR = $awalA = $durA = [];
        $r = $a = 0;
        for ($k = 0; $k < $n; $k++) {
            $awalR[$k] = $r;
            $awalA[$k] = $a;
            $durA[$k] = isset($aktual[$k]) && is_numeric($aktual[$k]) && $aktual[$k] > 0 ? (int) round((float) $aktual[$k]) : $rencana[$k];
            $r += $rencana[$k];
            $a += $durA[$k];
        }

        $hasil = [];
        $bergeser = 0;
        foreach (array_values($cue) as $c) {
            $mulai = (int) $c['mulai'];
            $selesai = (int) $c['selesai'];

            // Klip terakhir yang awal rencananya tidak lewat dari mulai baris;
            // klip berdurasi 0 dilewati, baris sesudah film rencana masuk klip terakhir.
            $k = 0;
            for ($j = $n - 1; $j >= 0; $j--) {
                if ($rencana[$j] > 0 && $mulai >= $awalR[$j]) {
                    $k = $j;
                    break;
                }
            }

            $panjang = max(1, $selesai - $mulai);
            $akhirKlip = $awalA[$k] + $durA[$k];
            $baruMulai = $mulai + $awalA[$k] - $awalR[$k];
            $baruSelesai = min($baruMulai + $panjang, $akhirKlip);
            if ($baruSelesai <= $baruMulai) {
                $baruSelesai = max($akhirKlip, $awalA[$k] + 1);
                $baruMulai = max($awalA[$k], $baruSelesai - $panjang);
            }
            $baruMulai = max(0, $baruMulai);
            if ($baruSelesai <= $baruMulai) {
                $baruSelesai = $baruMulai + 1;
            }

            if (max(abs($baruMulai - $mulai), abs($baruSelesai - $selesai)) > self::AMBANG_BERGESER) {
                $bergeser++;
            }
            $hasil[] = ['mulai' => $baruMulai, 'selesai' => $baruSelesai] + $c;
        }

        return ['cue' => $hasil, 'bergeser' => $bergeser];
    }

    // ------------------------------------------------------------------
    // Pembantu
    // ------------------------------------------------------------------

    /**
     * Tutup satu blok. Baris angka tepat sebelum baris waktu berikutnya
     * adalah nomor urut blok itu, bukan teks blok ini.
     */
    private static function tutup(array $kini, array $teks, bool $adaBerikut = true): array
    {
        if ($adaBerikut && $teks && preg_match('/^\s*\d+\s*$/', (string) end($teks))) {
            array_pop($teks);
        }
        $teks = array_filter(array_map('rtrim', $teks), fn ($b) => trim($b) !== '');

        return $kini + ['teks' => implode("\n", $teks)];
    }

    /** Jam/menit/detik/pecahan → milidetik. Pecahan "5" berarti ,500 bukan ,005. */
    private static function ms(string $jam, string $menit, string $detik, string $pecahan): int
    {
        $pecahan = $pecahan === '' ? 0 : (int) str_pad(substr($pecahan, 0, 3), 3, '0');

        return (((int) $jam * 60 + (int) $menit) * 60 + (int) $detik) * 1000 + $pecahan;
    }

    private static function waktu(int $ms): string
    {
        $ms = max(0, $ms);

        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3_600_000), intdiv($ms, 60_000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
    }

    /** BOM dibuang; UTF-16 dan teks ANSI lama (Windows-1252) diubah ke UTF-8. */
    private static function keUtf8(string $isi): string
    {
        if (str_starts_with($isi, "\xEF\xBB\xBF")) {
            $isi = substr($isi, 3);
        } elseif (str_starts_with($isi, "\xFF\xFE")) {
            $isi = mb_convert_encoding(substr($isi, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($isi, "\xFE\xFF")) {
            $isi = mb_convert_encoding(substr($isi, 2), 'UTF-8', 'UTF-16BE');
        }
        if (!mb_check_encoding($isi, 'UTF-8')) {
            $isi = mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
        }

        return $isi;
    }
}
