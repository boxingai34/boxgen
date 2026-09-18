<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Pemakai — memakai tabel `users` yang sudah ada, bukan tabel baru.
 *
 * Kolomnya beda dari bawaan Laravel karena tabel ini lebih tua: kata
 * sandinya di `password_hash`, namanya di `full_name`, dan ada `status`
 * (pending / active / rejected) yang menentukan boleh masuk atau tidak.
 * Semua akun lama tetap bisa masuk dengan kata sandi yang sama — hash-nya
 * sama-sama bcrypt.
 */
class User extends Authenticatable
{
    protected $table = 'users';

    /** Tabelnya cuma punya created_at, tidak ada updated_at. */
    public $timestamps = false;

    protected $fillable = ['username', 'full_name', 'email', 'password_hash', 'role', 'status'];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'verified_at' => 'datetime',
        'last_login'  => 'datetime',
        'created_at'  => 'datetime',
    ];

    /** Laravel mencari kolom `password`; di sini namanya password_hash. */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /**
     * "Ingat saya" dimatikan: tabelnya tidak punya kolom remember_token,
     * dan menambah kolom ke tabel yang dipakai aplikasi lama bukan harga
     * yang pantas untuk kenyamanan sekecil itu.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // sengaja kosong
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /** Nama yang enak dibaca: nama lengkap kalau ada, kalau tidak username. */
    public function getNamaAttribute(): string
    {
        return (string) ($this->full_name ?: $this->username);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
