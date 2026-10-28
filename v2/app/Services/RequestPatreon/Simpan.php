<?php

namespace App\Services\RequestPatreon;

/**
 * Simpan prompt satu kartu: cek umur (kode) → aturan rumah → NSFW (dewasa
 * saja, DeepSeek) → berkas lokal → bagian prompt di kartu Notion → status
 * "Prompt Siap".
 *
 * Masukan = JSON yang ditulis Claude lewat skill request-patreon:
 *
 *   kartu       id/URL halaman Notion
 *   keputusan   "buat" | "ditolak" | "perlu_info"
 *   alasan, balasan   untuk ditolak/perlu_info (balasan = draf Inggris untuk patron)
 *   karakter    [{nama, sumber}] — SEMUA karakter yang digambar
 *   gaya, catatan, nsfw_ide
 *   gambar      [{judul, posisi, base, uc_base, karakter:[{nama, prompt, uc}]}] — versi SFW
 *
 * Putusan umur dari kode menang atas keputusan di masukan: kartu "buat"
 * dengan karakter di bawah 16 tetap tersimpan sebagai ditolak.
 */
final class Simpan
{
    public const KEPUTUSAN = ['buat', 'ditolak', 'perlu_info'];

    public function __construct(private Gudang $gudang, private Umur $umur, private Nsfw $nsfw, private Notion $notion)
    {
    }

    /**
     * @param array<string,mixed> $m masukan
     * @return array<string,mixed> data yang tersimpan (+ 'peringatan')
     *
     * @throws RequestGalat masukan tidak sah, prompt melanggar aturan, atau Venice/Notion gagal
     */
    public function jalan(array $m, bool $keNotion = true): array
    {
        $id = Kartu::id((string) ($m['kartu'] ?? ''));
        if ($id === '') {
            throw new RequestGalat('Kolom "kartu" harus id atau URL halaman Notion.');
        }
        $keputusan = (string) ($m['keputusan'] ?? 'buat');
        if (! in_array($keputusan, self::KEPUTUSAN, true)) {
            throw new RequestGalat('Kolom "keputusan" harus salah satu: ' . implode(', ', self::KEPUTUSAN) . '.');
        }

        $kartu = $this->gudang->daftar()['kartu'][$id] ?? [];
        $lama = $this->gudang->prompt($id);
        $data = [
            'kartu'     => $id,
            'judul'     => (string) ($kartu['judul'] ?? $m['judul'] ?? ''),
            'patron'    => (string) ($kartu['patron'] ?? $m['patron'] ?? ''),
            'url'       => (string) ($kartu['url'] ?? $m['url'] ?? ''),
            'keputusan' => $keputusan,
            'putusan'   => null,
            'alasan'    => trim((string) ($m['alasan'] ?? '')),
            'balasan'   => trim((string) ($m['balasan'] ?? '')),
            'umur'      => [],
            'gaya'      => trim((string) ($m['gaya'] ?? '')),
            'catatan'   => trim((string) ($m['catatan'] ?? '')),
            'nsfw_ide'  => trim((string) ($m['nsfw_ide'] ?? '')),
            'gambar'    => [],
            'disimpan'  => now()->toIso8601String(),
            'biaya_usd' => 0.0,
            'notion'    => 'belum',
            'notion_blok' => (array) ($lama['notion_blok'] ?? []),
        ];
        $peringatan = [];

        if ($keputusan === 'buat') {
            $gambar = $this->bacaGambar((array) ($m['gambar'] ?? []));
            $orang = array_values(array_filter((array) ($m['karakter'] ?? []), 'is_array'));
            if ($orang === []) {
                throw new RequestGalat('Kolom "karakter" kosong: tulis semua karakter yang digambar ({nama, sumber}) supaya umurnya bisa dicek.');
            }

            foreach ($orang as $o) {
                $nama = trim((string) ($o['nama'] ?? ''));
                if ($nama === '') {
                    throw new RequestGalat('Ada karakter tanpa nama di kolom "karakter".');
                }
                $data['umur'][] = $this->umur->periksa($nama, trim((string) ($o['sumber'] ?? '')), (bool) ($o['cek_ulang'] ?? false));
            }
            $p = Umur::putusan($data['umur']);
            $data['putusan'] = $p['putusan'];

            if ($p['putusan'] === Umur::DITOLAK) {
                $data['keputusan'] = 'ditolak';
                $data['alasan'] = $p['alasan'];
                $data['balasan'] = $data['balasan'] !== '' ? $data['balasan'] : self::balasanTolak($data['patron']);
                $peringatan[] = $p['alasan'];
            } else {
                if ($p['putusan'] === Umur::SFW) {
                    $data['alasan'] = $p['alasan'];
                    foreach ($gambar as $i => $g) {
                        $langgar = Aturan::langgarOlahraga($g);
                        if ($langgar !== []) {
                            throw new RequestGalat(sprintf(
                                'Gambar %d memuat tag seksual (%s), padahal kartu ini SFW olahraga saja (%s). Tulis ulang gambarnya tanpa tag itu.',
                                $i + 1, implode(', ', $langgar), $p['alasan']
                            ));
                        }
                    }
                }
                foreach ($gambar as $i => $g) {
                    if (Aturan::adaPenandaAnak($g)) {
                        throw new RequestGalat('Gambar ' . ($i + 1) . ' memuat penanda anak; tidak disimpan.');
                    }
                    $sfw = Aturan::rapikan($g);
                    $nsfw = null;
                    if ($p['putusan'] === Umur::DEWASA && ($m['nsfw'] ?? true) !== false) {
                        $nsfw = $this->nsfw->ubah($sfw, $data['nsfw_ide'], (string) ($kartu['isi'] ?? ''));
                    }
                    $data['gambar'][] = [
                        'judul'  => (string) ($g['judul'] ?? ''),
                        'posisi' => (string) ($g['posisi'] ?? ''),
                        'sfw'    => ['base' => $sfw['base'], 'uc_base' => $sfw['uc_base'], 'karakter' => $sfw['karakter']],
                        'nsfw'   => $nsfw,
                    ];
                }
                $data['biaya_usd'] = round($this->nsfw->totalUsd(), 4);
            }
        }

        // Simpan dulu: kalau Notion gagal, hasil DeepSeek yang sudah dibayar tidak hilang.
        $this->gudang->simpanPrompt($id, $data);

        if ($keNotion && $this->notion->siap()) {
            $data = $this->keNotion($id);
        } elseif ($keNotion) {
            $peringatan[] = 'Notion belum tersambung (NOTION_API_KEY kosong): prompt hanya tersimpan lokal, bagian kartu dan status belum diubah.';
        }

        return $data + ['peringatan' => $peringatan];
    }

    /**
     * Tulis prompt yang SUDAH tersimpan ke kartu Notion (tanpa memanggil
     * DeepSeek lagi): bagian lama diganti, status ON QUEUE → Prompt Siap.
     *
     * @return array<string,mixed> data prompt sesudah ditulis
     *
     * @throws RequestGalat belum ada prompt, atau Notion menolak
     */
    public function keNotion(string $id): array
    {
        $data = $this->gudang->prompt($id);
        if ($data === null) {
            throw new RequestGalat("Belum ada prompt tersimpan untuk kartu $id.");
        }
        $kartu = $this->gudang->daftar()['kartu'][$id] ?? [];

        foreach ((array) ($data['notion_blok'] ?? []) as $b) {
            $this->notion->hapus((string) $b);
        }
        $data['notion_blok'] = $this->notion->tambah($id, Blok::susun($data));
        $data['notion'] = 'tertulis';

        // Status hanya maju dari ON QUEUE; kartu yang sudah lebih jauh tidak dimundurkan.
        $sekarang = (string) ($kartu['status'] ?? Kartu::ANTRI);
        if (($data['keputusan'] ?? '') === 'buat' && (Kartu::posisi($sekarang) ?? 0) === 0) {
            $this->notion->ubahStatus($id, Kartu::PROMPT_SIAP);
            $this->gudang->ubahKartu($id, ['status' => Kartu::PROMPT_SIAP]);
            $data['status_notion'] = Kartu::PROMPT_SIAP;
        }
        $this->gudang->simpanPrompt($id, $data);

        return $data;
    }

    /**
     * @param list<mixed> $gambar
     * @return list<array{judul:string, posisi:string, base:string, uc_base:string, karakter:list<array{nama:string, prompt:string, uc:string}>}>
     */
    private function bacaGambar(array $gambar): array
    {
        if ($gambar === []) {
            throw new RequestGalat('Kolom "gambar" kosong: kartu "buat" butuh minimal satu gambar.');
        }
        $hasil = [];
        foreach (array_values($gambar) as $i => $g) {
            $no = $i + 1;
            if (! is_array($g) || trim((string) ($g['base'] ?? '')) === '' || ! array_key_exists('uc_base', $g)) {
                throw new RequestGalat("Gambar $no butuh \"base\" dan \"uc_base\".");
            }
            $orang = array_values(array_filter((array) ($g['karakter'] ?? []), 'is_array'));
            if ($orang === [] || count($orang) > 6) {
                throw new RequestGalat("Gambar $no butuh 1–6 prompt karakter (NovelAI V5).");
            }
            $hasil[] = [
                'judul'    => trim((string) ($g['judul'] ?? '')),
                'posisi'   => trim((string) ($g['posisi'] ?? '')),
                'base'     => trim((string) $g['base']),
                'uc_base'  => trim((string) $g['uc_base']),
                'karakter' => array_map(static function (array $k) use ($no) {
                    if (trim((string) ($k['prompt'] ?? '')) === '' || ! array_key_exists('uc', $k)) {
                        throw new RequestGalat("Gambar $no: setiap karakter butuh \"prompt\" dan \"uc\" (UC wajib per karakter).");
                    }

                    return ['nama' => trim((string) ($k['nama'] ?? '')), 'prompt' => trim((string) $k['prompt']), 'uc' => trim((string) $k['uc'])];
                }, $orang),
            ];
        }

        return $hasil;
    }

    public static function balasanTolak(string $patron): string
    {
        $sapa = trim($patron) !== '' ? "Hi $patron" : 'Hi';

        return "$sapa, thank you so much for the request! Unfortunately I can't make this one: the characters involved aren't canonically adults, "
            . "and I keep my boxing art to adult characters only. Feel free to send another idea anytime — I'd be happy to do it!";
    }
}
