<?php
ob_start();
session_start();

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    die('Berkas config.php tidak ditemukan.');
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$namaAdmin  =$_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin =$_SESSION['admin_level'] ?? 'user';

if (strtolower($levelAdmin) === 'lembaga') {
    die("Akses Ditolak.");
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Jakarta');

$pesan = '';
$tipePesan = '';$data_peserta = null;
$list_kandidat = []; 

$keyword_cari = trim($_GET['cari'] ?? '');
$select_id    = trim($_GET['select_id'] ?? '');

if (!empty($select_id)) {
    try {
        $stmtCari =$pdo->prepare("
            SELECT p.*, sv.status AS status_verifikasi 
            FROM tb_pendaftaran p 
            LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id 
            WHERE p.cpmi_id = ? 
            LIMIT 1
        ");
        $stmtCari->execute([$select_id]);
        $data_peserta =$stmtCari->fetch(PDO::FETCH_ASSOC);

        if ($data_peserta) {
            $keyword_cari =$data_peserta['cpmi_id']; 
            if (file_exists(__DIR__ . '/../api/helper_log.php')) {
                require_once __DIR__ . '/../api/helper_log.php';
                if (function_exists('catatLog')) catatLog($pdo, "Admin mengakses cetak bukti untuk: {$data_peserta['cpmi_id']}", 'CETAK_BUKTI');
            }
        } else {
            $pesan = "Data tidak ditemukan.";
            $tipePesan = "error";
        }
    } catch (Throwable $e) {$pesan = "Kesalahan basis data: " . $e->getMessage();$tipePesan = "error";
    }

} elseif (!empty($keyword_cari)) {
    try {
        $kw_like = "\%{$keyword_cari}%";
        $stmtCari =$pdo->prepare("
            SELECT p.*, sv.status AS status_verifikasi 
            FROM tb_pendaftaran p 
            LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id 
            WHERE p.cpmi_id = ? OR p.nik_sesuai_ktp = ? OR p.nama_lengkap LIKE ?
            ORDER BY p.cpmi_id DESC
        ");
        $stmtCari->execute([$keyword_cari, $keyword_cari,$kw_like]);
        $hasil =$stmtCari->fetchAll(PDO::FETCH_ASSOC);

        if (count($hasil) === 1) {$data_peserta = $hasil[0];         } elseif (count($hasil) > 1) {
            $list_kandidat =$hasil;
            $pesan = "Ditemukan " . count($hasil) . " peserta dengan nama/data serupa. Silakan pilih target dari tabel di bawah.";
            $tipePesan = "info";
        } else {
            $pesan = "Pendaftar dengan ID/NIK/Nama '{$keyword_cari}' tidak ditemukan.";
            $tipePesan = "error";
        }
    } catch (Throwable $e) {$pesan = "Kesalahan basis data: " . $e->getMessage();$tipePesan = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Tanda Bukti Pendaftaran — PINTU Admin</title>
    
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Nunito Sans', 'Plus Jakarta Sans', sans-serif; 
            background-color: #f4f6f9;
        }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(14, 116, 187, 0.2); border-radius: 10px; }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover { background: rgba(14, 116, 187, 0.4); }
        
        @media print {
            body { background: #ffffff !important; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print, #mainSidebar, #sidebarOverlay { display: none !important; }
            main { margin: 0 !important; padding: 0 !important; background: transparent !important; overflow: visible !important; height: auto !important; }
            .print-area { box-shadow: none !important; border: none !important; max-width: 100% !important; margin: 0 auto !important; padding: 0 !important; }
            @page { margin: 1cm; }
        }
    </style>
</head>
<body class="bg-[#f4f6f9] text-slate-800 min-h-screen md:h-screen flex flex-col md:flex-row overflow-x-hidden md:overflow-hidden selection:bg-[#0e74bb] selection:text-white">

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-h-0 md:h-screen overflow-y-auto relative scroll-smooth bg-[#f4f6f9]">
        
        <div class="p-4 sm:p-7 max-w-7xl mx-auto w-full space-y-6">
            
            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs relative overflow-hidden flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 no-print">
                <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
                
                <div>
                    <div class="flex items-center gap-2 text-[11px] font-bold text-slate-400 mb-1.5">
                        <i class="fas fa-home text-[#0e74bb]"></i>
                        <span>/</span>
                        <span>Modul Pendaftaran</span>
                        <span>/</span>
                        <span class="text-slate-600">Cetak Bukti Pendaftaran</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="w-2.5 h-6 bg-[#0e74bb] rounded-full"></div>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight uppercase">CETAK TANDA BUKTI</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium pl-5">Modul cetak ulang tanda bukti registrasi pendaftaran CPMI.</p>
                </div>
                
                <div class="inline-flex items-center gap-3 bg-slate-50 px-3.5 py-2 rounded-lg border border-slate-200 shadow-2xs shrink-0">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                    <div class="text-right">
                        <div id="liveClock" class="text-xs sm:text-sm font-bold text-[#0e74bb] font-mono leading-none">--:--:-- WIB</div>
                        <div id="liveDate" class="text-[9px] font-bold text-slate-500 uppercase tracking-wider mt-0.5 leading-none">Memuat Tanggal...</div>
                    </div>
                </div>
            </div>

            <?php if (!empty($pesan)): ?>
                <?php 
                    $warnaNotif = 'bg-rose-50 text-rose-700 border-rose-200';
                    if ($tipePesan === 'info') $warnaNotif = 'bg-sky-50 text-sky-700 border-sky-200';
                ?>
                <div class="p-4 rounded-xl text-xs font-bold border <?= $warnaNotif ?> shadow-xs no-print flex items-center gap-2.5">
                    <i class="fas fa-circle-info text-sm"></i>
                    <span><?= htmlspecialchars($pesan) ?></span>
                </div>
            <?php endif; ?>

            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs no-print">
                <div class="flex items-center gap-2 mb-3.5 pb-2.5 border-b border-slate-100">
                    <div class="w-7 h-7 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                        <i class="fas fa-magnifying-glass"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Cari Berkas Pendaftar</span>
                </div>
                <form method="GET" action="cetak_bukti.php" class="flex flex-col sm:flex-row gap-3.5 items-end">
                    <div class="flex-1 w-full">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Pencarian Peserta (ID / NIK / Nama Lengkap)</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <i class="fas fa-id-card text-xs"></i>
                            </div>
                            <input type="text" name="cari" value="<?= htmlspecialchars($keyword_cari) ?>" placeholder="Contoh: CPMI-0015, atau Ketik Nama Peserta" required class="w-full h-11 pl-9 pr-4 rounded-lg border border-slate-300 text-xs font-mono tracking-wider focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] bg-white transition">
                        </div>
                    </div>
                    <button type="submit" class="w-full sm:w-auto h-11 px-6 bg-[#0e74bb] hover:bg-[#0b5e99] text-white text-xs font-bold rounded-lg transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-2 shrink-0">
                        <i class="fas fa-search text-xs"></i>
                        <span>Cari Data</span>
                    </button>
                </form>
            </div>

            <?php if (!empty($list_kandidat)): ?>
                <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col mb-6 no-print">
                    <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-50/70 flex items-center justify-between">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center gap-2">
                            <i class="fas fa-users text-[#0e74bb]"></i>
                            <span>Pilih Peserta Spesifik (<?= count($list_kandidat) ?> Data Ditemukan)</span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-700">
                            <thead>
                                <tr class="bg-slate-50 text-slate-500 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                                    <th class="py-3 px-4 w-44">CPMI-ID / NIK</th>
                                    <th class="py-3 px-4">Nama Lengkap</th>
                                    <th class="py-3 px-4">Lembaga & Batch Target</th>
                                    <th class="py-3 px-4 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white font-medium">
                                <?php foreach ($list_kandidat as $k): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="py-3 px-4">
                                        <span class="block font-mono font-bold text-[#0e74bb]"><?= htmlspecialchars($k['cpmi_id']) ?></span>
                                        <span class="text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($k['nik_sesuai_ktp']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 font-bold text-slate-900"><?= htmlspecialchars($k['nama_lengkap']) ?></td>
                                    <td class="py-3 px-4">
                                        <span class="block text-slate-800 font-semibold"><?= htmlspecialchars($k['lembaga_pelatihan']) ?></span>
                                        <span class="text-[10px] text-slate-500 font-bold uppercase"><?= htmlspecialchars($k['batch_pelatihan']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <a href="cetak_bukti.php?select_id=<?= htmlspecialchars($k['cpmi_id']) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg transition text-[11px] uppercase tracking-wider shadow-xs">
                                            <i class="fas fa-file-invoice text-xs"></i>
                                            <span>Buka Lembar Cetak</span>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($data_peserta): ?>
                
                <div class="mb-3 text-right no-print">
                    <button onclick="window.print()" class="px-6 py-2.5 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg text-xs transition shadow-sm uppercase tracking-wider inline-flex items-center gap-2">
                        <i class="fas fa-print"></i>
                        <span>Cetak / Simpan PDF Sekarang</span>
                    </button>
                </div>

                <div class="print-area max-w-2xl mx-auto w-full bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden relative mb-12">
                    <div class="h-2 w-full bg-[#0e74bb]"></div>
                    <div class="p-8 sm:p-12">
                        
                        <div class="flex items-center justify-center space-x-4 border-b-2 border-slate-800 pb-6 mb-8 text-center">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" alt="Logo BP2MI" class="h-14 sm:h-16 w-auto object-contain">
                            <div>
                                <h1 class="font-extrabold text-slate-900 text-xs sm:text-sm tracking-widest uppercase">Tanda Bukti Registrasi Peserta</h1>
                                <p class="font-black text-[#0e74bb] text-base sm:text-lg uppercase tracking-tight leading-none mt-1">Peningkatan Kapasitas CPMI</p>
                                <p class="text-xs font-bold text-slate-500 mt-1 uppercase">BP3MI Jawa Tengah</p>
                            </div>
                        </div>

                        <div class="bg-emerald-50 border border-emerald-200 p-4 rounded-lg text-center mb-7">
                            <span class="block text-emerald-700 font-extrabold text-xs mb-1 uppercase tracking-widest">Pendaftaran Berhasil!</span>
                            <p class="text-xs text-slate-600 font-medium">Data dan berkas Anda telah masuk ke dalam sistem. Anda hanya dapat melakukan pendaftaran 1x. Silakan simpan tanda bukti ini dan tunggu informasi verifikasi dari tim BP3MI Jawa Tengah.</p>
                        </div>

                        <div class="border border-slate-200 rounded-xl p-5 sm:p-6 relative">
                            <span class="absolute -top-3 left-5 bg-white px-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Identitas Pendaftar</span>
                            
                            <table class="w-full text-xs text-slate-800 font-medium">
                                <tbody>
                                    <tr><td class="py-2 w-36 text-slate-500 font-bold uppercase tracking-wider">CPMI - ID</td><td class="py-2 w-4">:</td><td class="py-2 font-mono font-black text-[#0e74bb] text-sm"><?= htmlspecialchars($data_peserta['cpmi_id']) ?></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">NIK KTP</td><td class="py-2">:</td><td class="py-2 font-mono font-bold text-slate-900"><?= htmlspecialchars($data_peserta['nik_sesuai_ktp']) ?></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Nama Lengkap</td><td class="py-2">:</td><td class="py-2 font-bold text-slate-900 text-sm"><?= htmlspecialchars($data_peserta['nama_lengkap']) ?></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Email Aktif</td><td class="py-2">:</td><td class="py-2 text-slate-800"><?= htmlspecialchars($data_peserta['email']) ?></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Domisili</td><td class="py-2">:</td><td class="py-2 text-slate-800"><?= htmlspecialchars($data_peserta['domisili']) ?></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Pendidikan</td><td class="py-2">:</td><td class="py-2 text-slate-800"><?= htmlspecialchars($data_peserta['pendidikan']) ?></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">No. WhatsApp</td><td class="py-2">:</td><td class="py-2 font-mono font-bold text-slate-800"><?= htmlspecialchars($data_peserta['no_hp_wa']) ?></td></tr>
                                    <tr><td colspan="3" class="border-t border-dashed border-slate-200 my-2"></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Lembaga Pilihan</td><td class="py-2">:</td><td class="py-2 font-bold text-slate-900"><?= htmlspecialchars($data_peserta['lembaga_pelatihan']) ?></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Kejuruan (Program)</td><td class="py-2">:</td><td class="py-2 font-bold text-slate-900"><?= htmlspecialchars($data_peserta['ref_pelatihan_dimin']) ?></td></tr>
                                    <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Batch Gelombang</td><td class="py-2">:</td><td class="py-2 font-bold text-slate-800"><?= htmlspecialchars($data_peserta['batch_pelatihan']) ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-8 flex justify-between items-end text-[10px] text-slate-500 font-medium">
                            <div>
                                <p>Cetak Ulang Pada: <span class="font-mono font-bold text-slate-700"><?= date('d M Y - H:i:s') ?> WIB</span></p>
                                <p>Status: <span class="font-bold text-amber-600">PENDAFTARAN BERHASIL</span></p>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-slate-800">BP3MI Jawa Tengah</p>
                                <p class="text-[9px] text-slate-400">PINTU-DAFTAR | PUSAT INFORMASI PENDAFTARAN PENINGKATAN KAPASITAS CPMI</p>
                            </div>
                        </div>

                    </div>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <script>
        function updateClock() {
            const now = new Date();
            const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            
            let hh = now.getHours().toString().padStart(2, '0');
            let mm = now.getMinutes().toString().padStart(2, '0');
            let ss = now.getSeconds().toString().padStart(2, '0');
            document.getElementById('liveClock').textContent = `${hh}:${mm}:${ss} WIB`;
            document.getElementById('liveDate').textContent = `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
        }
        setInterval(updateClock, 1000); 
        updateClock();
    </script>
</body>
</html>