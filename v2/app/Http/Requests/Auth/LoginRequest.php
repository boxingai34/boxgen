<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Masuk dengan username ATAU email, seperti versi lama.
 *
 * Aturannya disalin dari engine/Auth.php supaya akun yang sama berlaku di
 * dua aplikasi: akun yang belum disetujui admin dan akun yang ditolak
 * mendapat pesan yang menyebutkan sebabnya, bukan "username atau kata
 * sandi salah" yang membuat orang mencoba lagi berkali-kali.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required'    => 'Username atau email harus diisi.',
            'password.required' => 'Kata sandinya harus diisi.',
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $masuk = trim((string) $this->input('login'));

        $user = User::query()
            ->where('username', $masuk)
            ->orWhere('email', Str::lower($masuk))
            ->first();

        // Hash palsu tetap diperiksa waktu akunnya tidak ada, supaya lama
        // jawabannya sama saja dan tidak membocorkan username mana yang
        // terdaftar lewat selisih waktu.
        $hash = $user?->password_hash
            ?: '$2y$10$usernameinipalsuusernameinipalsuusernameinipalsuusernameini';

        if ($user === null || ! Hash::check((string) $this->input('password'), $hash)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'Username atau kata sandinya salah.',
            ]);
        }

        if ($user->status === 'pending') {
            throw ValidationException::withMessages([
                'login' => 'Akunmu sudah terdaftar tapi belum disetujui admin. Tunggu sebentar, lalu coba lagi.',
            ]);
        }

        if ($user->status === 'rejected') {
            throw ValidationException::withMessages([
                'login' => 'Pendaftaranmu ditolak admin.',
            ]);
        }

        Auth::login($user);

        DB::table('users')->where('id', $user->id)->update(['last_login' => now()]);

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $detik = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => 'Terlalu banyak percobaan. Coba lagi ' . $detik . ' detik lagi.',
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('login')) . '|' . $this->ip());
    }
}
