/**
 * Tombol "Buat gambarnya" — dipakai di beberapa halaman sekaligus.
 *
 * Dipisah ke berkas sendiri karena tiga halaman memerlukannya dan
 * masing-masing punya alur yang berbeda: Rancang Pertandingan punya
 * kartu tokoh dan kartu latar, Dari Gambar/Video punya satu keluaran
 * NovelAI, Dari Komik punya satu per halaman dan per panel. Yang sama
 * cuma tombolnya, jadi cuma tombolnya yang dibagi.
 *
 * Gambarnya datang sebagai data-URI dan berhenti di browser — server
 * tidak menyimpannya sama sekali. Lihat engine/GambarAi.php.
 */
(function () {
    'use strict';

    function buat(tag, cls, teks) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (teks !== undefined && teks !== null) { n.textContent = teks; }
        return n;
    }

    /**
     * Isi jawaban ditampilkan apa adanya kalau bukan JSON.
     *
     * Halaman yang memuat berkas ini tidak selalu punya penjelas sendiri,
     * jadi disediakan di sini — "gagal" tanpa isi pesannya membuat orang
     * menebak-nebak, padahal sebabnya biasanya tertulis jelas di badan
     * jawaban yang terbuang.
     */
    async function kirim(url, muatan) {
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(muatan)
        });

        var mentah = await res.text();
        var data;
        try {
            data = JSON.parse(mentah);
        } catch (e) {
            var bersih = mentah.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error('Server membalas bukan JSON (HTTP ' + res.status + '). Isinya: '
                + (bersih.length > 400 ? bersih.slice(0, 400) + ' …' : bersih));
        }
        if (!data.ok) { throw new Error(data.error || 'Terjadi kesalahan.'); }
        return data;
    }

    /**
     * @param {object} o
     * @param {string} o.url      alamat endpoint, lengkap dengan ?action=
     * @param {function} o.muatan dipanggil saat diklik, mengembalikan badan kiriman
     * @param {string} [o.alt]    teks alternatif gambarnya
     * @param {string} [o.label]  tulisan di tombol
     */
    /**
     * Pilihan bentuk gambar.
     *
     * Rasio, bukan piksel: tiap penyedia punya daftar ukurannya sendiri
     * yang boleh dipakai, dan server yang memetakan rasio ini ke ukuran
     * terdekat yang mereka terima. Mengirim piksel dari sini berarti
     * halaman harus ikut tahu daftar itu dan ikut basi waktu berubah.
     */
    var BENTUK = [
        { nilai: '3:4',  label: 'Potret'  },
        { nilai: '16:9', label: 'Lanskap' },
        { nilai: '1:1',  label: 'Persegi' },
    ];

    window.tombolGambar = function (o) {
        var bung  = buat('div', 'buat-latar');
        var label = o.label || 'Buat gambarnya';
        var btn   = buat('button', 'btn kecil', label);
        btn.type = 'button';

        // Bawaannya mengikuti isinya: tokoh itu berdiri, latar itu ruangan.
        var pilih = document.createElement('select');
        pilih.className = 'bentuk-gambar';
        BENTUK.forEach(function (b) {
            var opt = document.createElement('option');
            opt.value = b.nilai;
            opt.textContent = b.label;
            if (b.nilai === (o.bentuk || '3:4')) { opt.selected = true; }
            pilih.appendChild(opt);
        });

        var pesan  = buat('p', 'hint');
        var tampil = buat('div', 'hasil-latar');

        btn.addEventListener('click', async function () {
            btn.disabled = true;
            btn.textContent = 'Menggambar…';
            pesan.textContent = 'Biasanya 5-30 detik.';
            pesan.classList.remove('galat');

            try {
                var muatan = o.muatan();
                muatan.rasio = pilih.value;
                var data = await kirim(o.url, muatan);
                tampil.innerHTML = '';
                var img = buat('img');
                img.src = data.gambar;
                img.alt = o.alt || 'Hasil';
                tampil.appendChild(img);
                pesan.textContent = Math.round(data.byte / 1024) + ' KB · ' + data.model
                    + ' · tidak disimpan di server, klik kanan untuk menyimpannya sendiri.';
            } catch (err) {
                pesan.textContent = err.message;
                pesan.classList.add('galat');
            } finally {
                btn.disabled = false;
                btn.textContent = label;
            }
        });

        var baris = buat('div', 'baris-gambar');
        baris.appendChild(btn);
        baris.appendChild(pilih);

        bung.appendChild(baris);
        bung.appendChild(pesan);
        bung.appendChild(tampil);
        return bung;
    };
})();
