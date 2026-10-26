<?php
require_once __DIR__ . '/config.php';

$cpmiId = $_GET['id'] ?? '';

$stmt = $pdo->prepare("
    SELECT 
        p.*,
        ml.nama_lembaga,
        mk.nama_kejuruan,
        sv.status AS status_verifikasi
    FROM tb_pendaftaran p
    LEFT JOIN tb_master_lembaga ml ON p.id_lembaga = ml.id_lembaga
    LEFT JOIN tb_master_kejuruan mk ON p.id_kejuruan = mk.id_kejuruan
    LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
    WHERE p.cpmi_id = ?
    LIMIT 1
");
$stmt->execute([$cpmiId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die("Data pendaftaran tidak ditemukan.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tanda Bukti Pendaftaran - <?= htmlspecialchars($data['cpmi_id']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; }
            .print-box { border: none; shadow: none; }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen py-10 px-4">
    <div class="max-w-xl mx-auto bg-white border border-slate-300 rounded-xl p-8 print-box">
        <div class="border-b-2 border-slate-900 pb-4 mb-6 text-center">
            <h1 class="text-sm font-bold tracking-tight uppercase text-slate-900">Balai Pelayanan Pelindungan Pekerja Migran Indonesia</h1>
            <p class="text-xs font-semibold text-slate-700">BP3MI JAWA TENGAH</p>
            <p class="text-[11px] text-slate-500 mt-0.5">TANDA BUKTI PENDAFTARAN PENINGKATAN KAPASITAS CPMI</p>
        </div>

        <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-6 text-center">
            <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">Nomor Registrasi Peserta (CPMI ID)</span>
            <span class="text-2xl font-bold font-mono text-blue-700 tracking-wider mt-1 block">
                <?= htmlspecialchars($data['cpmi_id']) ?>
            </span>
            <span class="text-xs text-slate-500 mt-1 block">
                <?= htmlspecialchars($data['batch_gelombang']) ?>
            </span>
        </div>

        <table class="w-full text-xs mb-6">
            <tbody>
                <tr class="border-b border-slate-100">
                    <td class="py-2 text-slate-500 w-36">NIK</td>
                    <td class="py-2 font-mono font-bold text-slate-800"><?= htmlspecialchars($data['nik_sesuai_ktp']) ?></td>
                </tr>
                <tr class="border-b border-slate-100">
                    <td class="py-2 text-slate-500">Nama Lengkap</td>
                    <td class="py-2 font-semibold text-slate-800 uppercase"><?= htmlspecialchars($data['nama_lengkap']) ?></td>
                </tr>
                <tr class="border-b border-slate-100">
                    <td class="py-2 text-slate-500">Domisili</td>
                    <td class="py-2 text-slate-800"><?= htmlspecialchars($data['domisili']) ?></td>
                </tr>
                <tr class="border-b border-slate-100">
                    <td class="py-2 text-slate-500">Email</td>
                    <td class="py-2 text-slate-800"><?= htmlspecialchars($data['email']) ?></td>
                </tr>
                <tr class="border-b border-slate-100">
                    <td class="py-2 text-slate-500">Lembaga Pelatihan</td>
                    <td class="py-2 font-semibold text-slate-800"><?= htmlspecialchars($data['nama_lembaga'] ?? $data['lembaga_pelatihan']) ?></td>
                </tr>
                <tr class="border-b border-slate-100">
                    <td class="py-2 text-slate-500">Kejuruan</td>
                    <td class="py-2 text-slate-800"><?= htmlspecialchars($data['nama_kejuruan'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td class="py-2 text-slate-500">Status Berkas</td>
                    <td class="py-2 font-bold text-amber-600"><?= htmlspecialchars($data['status_verifikasi'] ?? 'MENUNGGU VERIFIKASI') ?></td>
                </tr>
            </tbody>
        </table>

        <div class="border-t border-slate-200 pt-4 text-[11px] text-slate-500 leading-relaxed">
            <p class="font-bold text-slate-700">Ketentuan Pendaftaran:</p>
            <ol class="list-decimal pl-4 space-y-0.5 mt-1">
                <li>Simpan tanda bukti ini sebagai identitas resmi seleksi CPMI.</li>
                <li>Tahapan verifikasi berkas dilakukan oleh panitia seleksi BP3MI Jawa Tengah.</li>
                <li>Nomor CPMI ID ini akan digunakan untuk login ujian dan tahapan berikutnya.</li>
            </ol>
        </div>

        <div class="mt-8 flex gap-3 no-print">
            <button onclick="window.print()" class="flex-1 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-lg shadow transition">
                Cetak / Simpan PDF
            </button>
            <a href="index.php" class="px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-semibold rounded-lg transition text-center">
                Selesai
            </a>
        </div>
    </div>
</body>
</html>