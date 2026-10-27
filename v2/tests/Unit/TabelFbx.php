<?php

namespace Tests\Unit;

use App\Services\AgentFbx;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tabel fbx_* di SQLite memori, bentuknya mengikuti migrasi 016 + 017. */
trait TabelFbx
{
    private function buatTabelFbx(): void
    {
        Schema::create('fbx_kandidat', function (Blueprint $t) {
            $t->increments('id');
            foreach (['minggu', 'kategori', 'karakter', 'sumber', 'pasangan', 'tingkat_umur', 'umur_canon', 'tinggi_cm',
                'status', 'bukti_url', 'danbooru_tag', 'pasangan_tag'] as $k) {
                $t->string($k)->default('');
            }
            $t->text('alasan')->nullable();
            $t->text('ide_kostum')->nullable();
            $t->timestamps();
            $t->unique(['minggu', 'kategori', 'karakter', 'pasangan']);
        });
        Schema::create('fbx_roster', function (Blueprint $t) {
            $t->increments('id');
            foreach (AgentFbx::KOLOM_ROSTER as $k) {
                $t->string($k)->default('');
            }
        });
        Schema::create('fbx_tren', function (Blueprint $t) {
            foreach (['minggu', 'karakter_tag', 'judul_tag', 'perubahan'] as $k) {
                $t->string($k)->default('');
            }
            foreach (['peringkat', 'post_minggu', 'skor_minggu', 'solo_cewek', 'total_post'] as $k) {
                $t->integer($k)->default(0);
            }
            $t->decimal('rasio_baru', 8, 4)->default(0);
            $t->primary(['minggu', 'karakter_tag']);
        });
        Schema::create('fbx_riset', function (Blueprint $t) {
            $t->string('minggu')->primary();
            $t->text('ringkasan')->nullable();
            $t->text('isi')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('fbx_gambar', function (Blueprint $t) {
            $t->increments('id');
            $t->string('danbooru_tag');
            $t->integer('post_id');
            $t->integer('urutan')->default(0);
            $t->string('kecil');
            $t->string('besar');
            $t->integer('lebar')->default(0);
            $t->integer('tinggi')->default(0);
            $t->integer('skor')->default(0);
            $t->string('rating')->default('g');
            $t->unique(['danbooru_tag', 'post_id']);
        });
        Schema::create('fbx_tag_cek', function (Blueprint $t) {
            $t->string('danbooru_tag')->primary();
            $t->integer('jumlah')->default(0);
            $t->integer('diminta')->default(0);
            $t->integer('versi')->default(1);
            $t->string('jenis')->default('');
            $t->boolean('dewasa')->default(false);
            $t->timestamp('dicek_at')->nullable();
        });
    }
}
