<?php

namespace App\Services\RequestPatreon;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien API Notion untuk database Schedule Upload.
 *
 * Yang dipakai hanya lima hal: daftar kartu (semua kecuali Archived), isi
 * teks satu kartu, ubah status, tambah blok prompt di ujung kartu, dan
 * hapus blok prompt lama. Kunci integrasi tinggal di server.
 */
final class Notion
{
    /** Penanda judul bagian prompt di kartu; isi kartu dibaca sampai sini saja. */
    public const PENANDA = 'PROMPT NOVELAI';

    public function __construct(private ?string $kunci = null)
    {
    }

    public function siap(): bool
    {
        return $this->kunciBawaan() !== '';
    }

    /**
     * Semua kartu yang tidak di-archive, terlama dulu.
     *
     * @return list<array{id:string, url:string, judul_asli:string, judul:string, patron:string, status:string, dibuat:string, diubah:string}>
     */
    public function kartu(int $batas = 1000): array
    {
        $db = (string) config('request_patreon.notion.database');
        $hasil = [];
        $kursor = null;
        do {
            $body = [
                'page_size' => 100,
                'filter'    => ['property' => 'Status', 'select' => ['does_not_equal' => Kartu::ARSIP]],
                'sorts'     => [['timestamp' => 'created_time', 'direction' => 'ascending']],
            ];
            if ($kursor !== null) {
                $body['start_cursor'] = $kursor;
            }
            $j = $this->minta('post', "databases/$db/query", $body);
            foreach ((array) ($j['results'] ?? []) as $p) {
                $hasil[] = self::halaman((array) $p);
            }
            $kursor = ($j['has_more'] ?? false) ? (string) ($j['next_cursor'] ?? '') : null;
        } while ($kursor !== null && $kursor !== '' && count($hasil) < $batas);

        return $hasil;
    }

    /**
     * Isi kartu sebagai teks polos, berhenti di bagian prompt buatan boxgen.
     *
     * @return array{teks:string, gambar:int, tautan:list<string>}
     */
    public function isi(string $id): array
    {
        $baris = [];
        $gambar = 0;
        $tautan = [];
        $kursor = null;
        $halaman = 0;
        do {
            $j = $this->minta('get', "blocks/$id/children", array_filter(['page_size' => 100, 'start_cursor' => $kursor]));
            foreach ((array) ($j['results'] ?? []) as $b) {
                $jenis = (string) ($b['type'] ?? '');
                $data = (array) ($b[$jenis] ?? []);
                $teks = self::teks((array) ($data['rich_text'] ?? []));
                if (str_starts_with($jenis, 'heading') && str_contains(mb_strtoupper($teks), self::PENANDA)) {
                    break 2;
                }
                foreach ((array) ($data['rich_text'] ?? []) as $t) {
                    $h = (string) ($t['href'] ?? '');
                    if ($h !== '') {
                        $tautan[] = $h;
                    }
                }
                if ($jenis === 'image') {
                    $gambar++;
                } elseif (in_array($jenis, ['bookmark', 'embed', 'link_preview'], true)) {
                    $tautan[] = (string) ($data['url'] ?? '');
                } elseif ($teks !== '') {
                    $baris[] = match ($jenis) {
                        'bulleted_list_item', 'to_do' => '- ' . $teks,
                        'numbered_list_item'          => '1. ' . $teks,
                        default                       => $teks,
                    };
                }
            }
            $kursor = ($j['has_more'] ?? false) ? (string) ($j['next_cursor'] ?? '') : null;
        } while ($kursor && ++$halaman < 20);

        return [
            'teks'   => trim(implode("\n", $baris)),
            'gambar' => $gambar,
            'tautan' => array_values(array_unique(array_filter($tautan))),
        ];
    }

    public function ubahStatus(string $id, string $status): void
    {
        $this->minta('patch', "pages/$id", ['properties' => ['Status' => ['select' => ['name' => $status]]]]);
    }

    /**
     * Tambah blok di ujung kartu (dipotong per 100, batas API).
     *
     * @param list<array<string,mixed>> $blok
     * @return list<string> id blok tingkat atas yang dibuat
     */
    public function tambah(string $id, array $blok): array
    {
        $ids = [];
        foreach (array_chunk($blok, 100) as $potong) {
            $j = $this->minta('patch', "blocks/$id/children", ['children' => $potong]);
            foreach ((array) ($j['results'] ?? []) as $b) {
                $ids[] = (string) ($b['id'] ?? '');
            }
        }

        return array_values(array_filter($ids));
    }

    /** Hapus satu blok; blok yang sudah tidak ada (404) dianggap beres. */
    public function hapus(string $blokId): void
    {
        try {
            $this->minta('delete', "blocks/$blokId");
        } catch (RequestGalat $e) {
            if (! str_contains($e->getMessage(), '(404)')) {
                throw $e;
            }
        }
    }

    /**
     * @param array<string,mixed> $p objek page dari API
     * @return array{id:string, url:string, judul_asli:string, judul:string, patron:string, status:string, dibuat:string, diubah:string}
     */
    public static function halaman(array $p): array
    {
        $judulProp = [];
        foreach ((array) ($p['properties'] ?? []) as $prop) {
            if (($prop['type'] ?? '') === 'title') {
                $judulProp = (array) ($prop['title'] ?? []);
                break;
            }
        }
        $teks = self::teks($judulProp);
        $tebal = implode('', array_map(
            static fn ($t) => ! empty($t['annotations']['bold']) ? (string) ($t['plain_text'] ?? '') : '',
            $judulProp
        ));
        $pecah = Kartu::pecahJudul($teks, $tebal);

        return [
            'id'         => Kartu::id((string) ($p['id'] ?? '')),
            'url'        => (string) ($p['url'] ?? ''),
            'judul_asli' => $teks,
            'judul'      => $pecah['judul'],
            'patron'     => $pecah['patron'],
            'status'     => (string) ($p['properties']['Status']['select']['name'] ?? ''),
            'dibuat'     => (string) ($p['created_time'] ?? ''),
            'diubah'     => (string) ($p['last_edited_time'] ?? ''),
        ];
    }

    /** @param list<array<string,mixed>> $rich */
    private static function teks(array $rich): string
    {
        return trim(implode('', array_map(static fn ($t) => (string) ($t['plain_text'] ?? ''), $rich)));
    }

    /** @return array<string,mixed> */
    private function minta(string $cara, string $jalur, array $body = []): array
    {
        if (! $this->siap()) {
            throw new RequestGalat('Notion belum tersambung: isi NOTION_API_KEY di v2/.env, lalu bagikan database Schedule Upload ke integrasinya.');
        }
        $url = rtrim((string) config('request_patreon.notion.base'), '/') . '/' . $jalur;

        try {
            // GET: $body = query. Jangan menaruh query di $url — Guzzle menimpanya dengan opsi query.
            /** @var Response $r */
            $r = match ($cara) {
                'delete' => $this->klien()->delete($url),
                default  => $this->klien()->{$cara}($url, $body),
            };
        } catch (ConnectionException $e) {
            throw new RequestGalat('Notion tidak bisa dihubungi: ' . $e->getMessage());
        } catch (RequestException $e) {
            $r = $e->response;
        }

        if ($r->failed()) {
            $pesan = (string) ($r->json('message') ?? $r->body());
            $saran = match ($r->status()) {
                401     => ' Kunci NOTION_API_KEY ditolak; salin ulang Internal Integration Secret.',
                403, 404 => ' Pastikan database Schedule Upload sudah dibagikan ke integrasi (••• › Connections).',
                default => '',
            };
            throw new RequestGalat(sprintf('Notion menolak (%d): %s.%s', $r->status(), mb_substr($pesan, 0, 300), $saran));
        }

        return (array) $r->json();
    }

    private function klien(): PendingRequest
    {
        return Http::withToken($this->kunciBawaan())
            ->withHeaders(['Notion-Version' => (string) config('request_patreon.notion.versi')])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('request_patreon.notion.timeout', 30))
            // 429 = batas 3 permintaan/detik; tunggu sebentar lalu ulang.
            ->retry(3, 1200, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 502, 503], true)), throw: false);
    }

    private function kunciBawaan(): string
    {
        return trim((string) ($this->kunci ?? config('request_patreon.notion.kunci', '')));
    }
}
