<?php
declare(strict_types=1);

/**
 * MONITOR AGENT — pencatat satu kejadian dari hook Claude Code.
 *
 * Dipanggil oleh hooks yang ditulis di frontmatter agent/skill (riset-tinju,
 * alur-tanding, scout-karakter). Claude Code mengirim JSON kejadian lewat
 * stdin; nama pelakunya datang dari argumen pertama:
 *
 *   C:/xampp2/php/php.exe C:/xampp2/htdocs/boxgen/tools/agent-monitor/lapor.php riset-tinju
 *
 * Hasilnya satu baris JSON di v2/storage/app/agent-monitor/YYYY-MM-DD.jsonl,
 * dibaca halaman /generator/monitor. Sengaja tanpa Laravel supaya cepat —
 * hook ini berjalan di setiap pemakaian alat oleh agent.
 *
 * Skrip ini tidak boleh pernah menggagalkan kerja agent: semua galat
 * ditelan dan keluarnya selalu 0.
 */

const MAKS_RINGKAS = 160;

// php.ini XAMPP memakai Europe/Berlin; pergantian berkas harian harus ikut jam pemilik studio.
date_default_timezone_set('Asia/Jakarta');

try {
    $sumber = preg_replace('/[^a-z0-9._-]/i', '', (string) ($argv[1] ?? '')) ?: 'tanpa-nama';
    $masuk = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($masuk)) {
        exit(0);
    }

    $baris = susunBaris($sumber, $masuk);

    $dir = dirname(__DIR__, 2) . '/v2/storage/app/agent-monitor';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents(
        $dir . '/' . date('Y-m-d') . '.jsonl',
        json_encode($baris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
} catch (Throwable) {
    // diam — pencatat tidak boleh mengganggu agent
}
exit(0);

/**
 * Ringkas kejadian jadi kolom-kolom pendek. Isi tool_input/tool_response
 * yang besar (isi berkas, halaman web) tidak pernah disimpan.
 *
 * @param array<string, mixed> $m
 * @return array<string, mixed>
 */
function susunBaris(string $sumber, array $m): array
{
    $ev = (string) ($m['hook_event_name'] ?? '');
    $alat = (string) ($m['tool_name'] ?? '');
    $input = is_array($m['tool_input'] ?? null) ? $m['tool_input'] : [];

    $baris = [
        't'      => date('c'),
        'sumber' => $sumber,
        'ev'     => $ev,
        'sesi'   => substr((string) ($m['session_id'] ?? ''), 0, 12),
        'agen'   => (string) ($m['agent_type'] ?? ''),
        'agenId' => substr((string) ($m['agent_id'] ?? ''), 0, 24),
        'alat'   => $alat,
        'id'     => substr((string) ($m['tool_use_id'] ?? ''), -24),
        'ringkas' => potong(ringkasAlat($alat, $input)),
    ];

    if ($ev === 'PostToolUse') {
        if (isset($m['duration_ms']) && is_numeric($m['duration_ms'])) {
            $baris['ms'] = (int) $m['duration_ms'];
        }
        $respon = $m['tool_response'] ?? null;
        if (is_array($respon) && (!empty($respon['is_error']) || !empty($respon['error']))) {
            $baris['gagal'] = true;
        }
    }
    if ($ev === 'Stop' || $ev === 'SubagentStop') {
        $baris['ringkas'] = potong((string) ($m['last_assistant_message'] ?? ''));
    }

    return $baris;
}

/** @param array<string, mixed> $in */
function ringkasAlat(string $alat, array $in): string
{
    $s = static fn (string $k): string => is_scalar($in[$k] ?? null) ? (string) $in[$k] : '';

    return match ($alat) {
        'WebSearch' => $s('query'),
        'WebFetch'  => $s('url'),
        'Read', 'Write', 'Edit', 'NotebookEdit' => namaBerkas($s('file_path') ?: $s('notebook_path')),
        'Glob'      => $s('pattern'),
        'Grep'      => $s('pattern') . ($s('path') !== '' ? '  ·  ' . namaBerkas($s('path')) : ''),
        'Bash', 'PowerShell' => $s('description') ?: $s('command'),
        'Agent', 'Task' => trim(($s('subagent_type') ?: 'agent') . ': ' . $s('description')),
        'Skill'     => $s('skill') ?: $s('command') ?: $s('name'),
        default     => '',
    };
}

/** Nama berkas + satu folder induknya: "_FIGHT BIBLE/04 - Referensi Match Wanita.md". */
function namaBerkas(string $jalur): string
{
    if ($jalur === '') {
        return '';
    }
    $bagian = preg_split('#[\\\\/]+#', rtrim($jalur, '\\/')) ?: [];
    return implode('/', array_slice($bagian, -2));
}

function potong(string $t): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    return mb_strlen($t) > MAKS_RINGKAS ? mb_substr($t, 0, MAKS_RINGKAS - 1) . '…' : $t;
}
