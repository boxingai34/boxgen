<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Perawatan — tombol untuk tugas yang tadinya hanya bisa lewat command line.
 *
 * Semuanya berjalan POTONG DEMI POTONG, bukan sekali jalan. Alasannya di
 * admin/tugas.php: mencari judul untuk 15.528 karakter butuh sekitar 11
 * jam, dan tidak ada permintaan HTTP yang bertahan selama itu.
 */

$r = Sumber::ringkasan();

$sync = [];
foreach (Database::all("SELECT kind, processed, finished_at, status FROM sync_log
                        WHERE id IN (SELECT MAX(id) FROM sync_log GROUP BY kind)") as $s) {
    $sync[$s['kind']] = $s;
}

$aiSiap = AiClient::isConfigured();

adminHeader('Perawatan', 'perawatan.php');
?>

<p class="hint">
    Tiap tombol mengerjakan satu potong kecil lalu melapor sisanya. Centang
    <strong>Ulangi sampai habis</strong> kalau ingin dibiarkan jalan sendiri —
    boleh dihentikan kapan saja, tidak ada yang rusak karena tiap potong
    langsung tersimpan.
</p>

<!-- ============ DETEKSI SUMBER ============ -->
<h2>Deteksi sumber</h2>

<div class="card">
    <h3>Kelompokkan judul <span class="pill" id="sisa-judul"><?= number_format($r['judul_belum'], 0, ',', '.') ?> belum</span></h3>
    <p class="hint">
        Menentukan sebuah judul itu anime, game, VTuber, kartun, atau komik.
        Danbooru <strong>tidak</strong> menandainya sama sekali — bagi mereka
        semuanya cuma "copyright" — jadi tidak ada data yang bisa ditanya.
        Di sini AI dipakai, dan ia hanya boleh memilih dari tujuh label yang
        sudah ditentukan. Kalau ragu, ia sengaja tidak menebak.
    </p>
    <?php if (!$aiSiap): ?>
        <div class="alert warn">AI_API_KEY belum diisi, jadi tugas ini belum bisa jalan.</div>
    <?php endif; ?>
    <div class="tugas-baris">
        <label>Per potong <input type="number" class="batas" value="50" min="10" max="200" step="10"></label>
        <label class="check"><input type="checkbox" class="ulang"> Ulangi sampai habis</label>
        <button class="btn primary btn-tugas" data-tugas="judul" <?= $aiSiap ? '' : 'disabled' ?>>Jalankan</button>
        <button class="btn btn-stop" hidden>Hentikan</button>
    </div>
    <pre class="tugas-log" hidden></pre>
</div>

<div class="card">
    <h3>Cari judul asal karakter <span class="pill" id="sisa-karakter"><?= number_format($r['karakter_belum'], 0, ',', '.') ?> belum</span></h3>
    <p class="hint">
        Sumbernya Danbooru sendiri: tag copyright mana yang paling sering
        muncul bersama karakter itu. Ambangnya 30% supaya kolaborasi dan
        fanart tidak salah dianggap asalnya.
    </p>
    <p class="hint">
        <strong>Lambat</strong> — sekitar 2,5 detik per karakter, karena ada
        jeda 1,1 detik tiap permintaan demi sopan santun API. Sisanya
        sekarang berarti sekitar
        <?= number_format($r['karakter_belum'] * 2.5 / 3600, 1, ',', '.') ?> jam
        kalau dijalankan terus-menerus. Yang terpopuler dikerjakan duluan,
        jadi potongan pertama sudah mencakup nama yang paling sering dicari.
    </p>
    <div class="tugas-baris">
        <label>Per potong <input type="number" class="batas" value="25" min="5" max="40" step="5"></label>
        <label class="check"><input type="checkbox" class="ulang"> Ulangi sampai habis</label>
        <button class="btn primary btn-tugas" data-tugas="karakter">Jalankan</button>
        <button class="btn btn-stop" hidden>Hentikan</button>
    </div>
    <pre class="tugas-log" hidden></pre>
</div>

<!-- ============ KAMUS DANBOORU ============ -->
<h2>Kamus tag Danbooru</h2>

<p class="hint">
    Kamus tag <strong>tidak</strong> memperbarui dirinya sendiri. Ia hanya
    berubah kalau tombol di bawah ditekan, atau kalau kamu mendaftarkan
    URL-nya di cron-job.org.
    <strong>Urutannya wajib: tag &rarr; alias &rarr; implikasi</strong>,
    karena alias dan implikasi hanya tersimpan kalau tag tujuannya sudah ada.
</p>

<?php foreach ([
    'tags'         => ['Tag', 'Kamus utama. Berhenti sendiri saat jumlah gambarnya sudah di bawah ' . TAG_MIN_POST_COUNT . '.'],
    'aliases'      => ['Alias', 'Nama lain untuk tag yang sama, misalnya "kick" yang menunjuk ke "kicking".'],
    'implications' => ['Implikasi', 'Tag yang otomatis menyeret tag lain — dipakai untuk membuang tag mubazir.'],
] as $kind => [$judul, $ket]):
    $s = $sync[$kind] ?? null;
?>
<div class="card">
    <h3><?= e($judul) ?>
        <?php if ($s): ?>
            <span class="pill"><?= number_format((int)$s['processed'], 0, ',', '.') ?> baris</span>
            <span class="pill">terakhir <?= e(date('j M Y', strtotime((string)$s['finished_at']))) ?></span>
        <?php else: ?>
            <span class="pill">belum pernah</span>
        <?php endif; ?>
    </h3>
    <p class="hint"><?= e($ket) ?></p>
    <div class="tugas-baris">
        <label>Halaman <input type="number" class="batas" value="5" min="1" max="20"></label>
        <label class="check"><input type="checkbox" class="ulang"> Ulangi sampai habis</label>
        <button class="btn primary btn-tugas" data-tugas="<?= e($kind) ?>">Jalankan</button>
        <button class="btn btn-stop" hidden>Hentikan</button>
    </div>
    <pre class="tugas-log" hidden></pre>
</div>
<?php endforeach; ?>

<!-- ============ SEEDER ============ -->
<h2>Data menu</h2>

<div class="card">
    <h3>Jalankan seeder</h3>
    <p class="hint">
        Memasukkan ulang isi <code>database/data/*.php</code> ke database.
        Perlu setiap kali berkas datanya berubah — tanpa ini, perubahan
        daftar pose atau pakaian tidak akan kelihatan di halaman depan.
        Aman diulang.
    </p>
    <div class="tugas-baris">
        <button class="btn primary btn-tugas" data-tugas="seed">Jalankan</button>
    </div>
    <pre class="tugas-log" hidden></pre>
</div>

<script>
// Tanpa token ini, _bootstrap.php menolak setiap POST dengan
// "Token keamanan tidak cocok" — body JSON tidak pernah masuk ke $_POST.
const CSRF = <?= json_encode(csrfToken()) ?>;

document.querySelectorAll('.btn-tugas').forEach((tombol) => {
    const kartu  = tombol.closest('.card');
    const log    = kartu.querySelector('.tugas-log');
    const stop   = kartu.querySelector('.btn-stop');
    const ulang  = kartu.querySelector('.ulang');
    const batas  = kartu.querySelector('.batas');
    let berhenti = false;

    const tulis = (t) => {
        log.hidden = false;
        log.textContent += t + '\n';
        log.scrollTop = log.scrollHeight;
    };

    if (stop) stop.onclick = () => { berhenti = true; tulis('Dihentikan setelah potongan ini selesai.'); };

    tombol.onclick = async () => {
        const tugas = tombol.dataset.tugas;
        berhenti = false;
        tombol.disabled = true;
        if (stop) stop.hidden = false;
        log.hidden = false;
        log.textContent = '';

        let putaran = 0;

        do {
            putaran++;
            tulis(`— potongan ${putaran} —`);

            let d;
            try {
                const res = await fetch('tugas.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: JSON.stringify({ tugas, batas: batas ? +batas.value : 25 })
                });
                d = await res.json();
            } catch (e) {
                tulis('GAGAL: server membalas bukan JSON. ' + e.message);
                break;
            }

            if (d.keluaran) tulis(d.keluaran);

            if (d.diproses !== undefined) {
                tulis(`diproses ${d.diproses}, berhasil ${d.berhasil}, dilewati ${d.dilewati}`);
                (d.contoh || []).forEach((c) => tulis('   ' + c));
            }

            if (d.sisa) {
                const sj = document.querySelector('#sisa-judul');
                const sk = document.querySelector('#sisa-karakter');
                if (sj) sj.textContent = d.sisa.judul.toLocaleString('id-ID') + ' belum';
                if (sk) sk.textContent = d.sisa.karakter.toLocaleString('id-ID') + ' belum';
            }

            if (!d.ok) { tulis('BERHENTI: ' + (d.error || 'ada yang gagal')); break; }

            // Sudah habis? Tidak ada gunanya memutar lagi.
            // Dua tugas punya penanda berbeda: deteksi sumber melapor
            // lewat 'diproses', sinkronisasi lewat 'selesai' — ia memang
            // tidak punya sisa yang bisa dihitung di muka.
            if (d.diproses === 0) { tulis('Sudah tidak ada sisa.'); break; }
            if (d.selesai)        { tulis('Sinkronisasi sudah sampai batas bawah.'); break; }

        } while (ulang && ulang.checked && !berhenti);

        tulis('Selesai.');
        tombol.disabled = false;
        if (stop) stop.hidden = true;
    };
});
</script>

<?php adminFooter(); ?>
