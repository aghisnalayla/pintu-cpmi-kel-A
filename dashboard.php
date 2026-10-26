<?php
session_start();

if (!isset($_SESSION['cpmi_id'])) {
    header("Location: index.html");
    exit;
}

$cpmi_id =$_SESSION['cpmi_id'];
require_once __DIR__ . '/api/koneksi.php';

$stmtVerif =$pdo->prepare("
    SELECT sv.status 
    FROM tb_status_verifikasi sv 
    WHERE sv.cpmi_id = :cpmi_id 
    LIMIT 1
");
$stmtVerif->execute(['cpmi_id' =>$cpmi_id]);
$statusVerif =$stmtVerif->fetchColumn();

if (strtoupper(trim((string)$statusVerif)) !== 'MEMENUHI PERSYARATAN') {
    session_unset();
    session_destroy();
    header("Location: index.html");
    exit;
}

$stmt =$pdo->prepare("SELECT * FROM tb_pendaftaran WHERE cpmi_id = :cpmi_id LIMIT 1");
$stmt->execute(['cpmi_id' =>$cpmi_id]);
$peserta =$stmt->fetch(PDO::FETCH_ASSOC);

if (!$peserta) {
    session_unset();
    session_destroy();
    header("Location: index.html");
    exit;
}

$stmtPU =$pdo->prepare("
    SELECT jenis_ujian, status_pengerjaan 
    FROM tb_peserta_ujian 
    WHERE cpmi_id = :cpmi_id
");
$stmtPU->execute(['cpmi_id' =>$cpmi_id]);
$listUjian =$stmtPU->fetchAll(PDO::FETCH_KEY_PAIR); // Format: ['TERTULIS' => 'BELUM', 'INTERVIEW' => 'SELESAI']

$aksesTertulis = isset($listUjian['TERTULIS']) ? 1 : (int)($peserta['akses_tertulis'] ?? 0);
$statusTertulis = isset($listUjian['TERTULIS']) 
    ? strtolower($listUjian['TERTULIS']) 
    : strtolower(trim($peserta['status_tertulis'] ?? 'belum'));

$aksesInterview = isset($listUjian['INTERVIEW']) ? 1 : (int)($peserta['akses_interview'] ?? 0);
$statusInterview = isset($listUjian['INTERVIEW']) 
    ? strtolower($listUjian['INTERVIEW']) 
    : strtolower(trim($peserta['status_interview'] ?? 'belum'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi & Dashboard Ujian | PINTU BP3MI Jateng</title>
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .liquid-bg {
            background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 50%, #fdf2f8 100%);
            min-height: 100vh;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.7);
        }
    </style>
</head>
<body class="liquid-bg text-slate-800 min-h-screen flex flex-col justify-between selection:bg-indigo-500 selection:text-white">

    <header class="glass-card border-b border-white/60 sticky top-0 z-50 shadow-xs">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3.5 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" 
                     alt="Logo BP2MI" class="h-10 sm:h-11 w-auto object-contain">
                <div>
                    <span class="block font-extrabold text-slate-900 text-sm">PINTU-UJIAN</span>
                    <span class="block text-xs text-slate-500 font-medium">BP3MI Jawa Tengah</span>
                </div>
            </div>

            <div class="flex items-center space-x-4">
                <div class="text-right hidden sm:block">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Waktu Server</span>
                    <span id="jamServer" class="font-mono font-bold text-xs text-indigo-700">--:--:-- WIB</span>
                </div>
                <a href="api/logout.php" onclick="return confirm('Apakah Anda yakin ingin keluar dari sesi ini?')" 
                   class="inline-flex items-center text-xs font-bold text-rose-600 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-3.5 py-2 rounded-xl transition">
                    Keluar
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-8 w-full flex-grow space-y-8">
        
        <div class="bg-gradient-to-r from-indigo-600 via-indigo-700 to-violet-700 rounded-3xl p-6 sm:p-8 text-white shadow-lg relative overflow-hidden">
            <div class="relative z-10">
                <span class="inline-block bg-white/20 text-white text-[11px] font-extrabold px-3 py-1 rounded-full uppercase tracking-wider mb-2">Portal Ujian Mandiri</span>
                <h1 class="text-2xl sm:text-3xl font-extrabold">Selamat Datang, <?php echo htmlspecialchars($peserta['nama_lengkap']); ?>!</h1>
                <p class="text-indigo-100 text-xs sm:text-sm mt-1 max-w-2xl leading-relaxed">
                    Pastikan informasi data diri di bawah ini telah sesuai sebelum Anda memulai pengerjaan modul ujian yang tersedia.
                </p>
            </div>
        </div>

        <div class="glass-card rounded-3xl p-6 sm:p-8 shadow-sm border border-white">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">Konfirmasi Data Diri Peserta</h2>
                    <p class="text-xs text-slate-500">Data ini tersinkronisasi langsung dengan berkas verifikasi pendaftaran.</p>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="bg-emerald-50 text-emerald-700 font-bold text-xs px-3 py-1.5 rounded-xl border border-emerald-200">
                        ✓ Terverifikasi
                    </span>
                    <span class="bg-indigo-50 text-indigo-700 font-mono font-bold text-xs px-3 py-1.5 rounded-xl border border-indigo-100">
                        <?php echo htmlspecialchars($peserta['cpmi_id']); ?>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 font-bold uppercase tracking-wider block mb-1 text-[10px]">ID CPMI</span>
                    <span class="font-mono font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($peserta['cpmi_id']); ?></span>
                </div>

                <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 font-bold uppercase tracking-wider block mb-1 text-[10px]">NIK KTP</span>
                    <span class="font-mono font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($peserta['nik_sesuai_ktp']); ?></span>
                </div>

                <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100 sm:col-span-2">
                    <span class="text-slate-400 font-bold uppercase tracking-wider block mb-1 text-[10px]">Nama Lengkap</span>
                    <span class="font-bold text-slate-900 text-sm block truncate"><?php echo htmlspecialchars($peserta['nama_lengkap']); ?></span>
                </div>

                <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 font-bold uppercase tracking-wider block mb-1 text-[10px]">Tanggal Lahir</span>
                    <span class="font-semibold text-slate-900">
                        <?php echo !empty($peserta['tanggal_lahir']) ? date('d F Y', strtotime($peserta['tanggal_lahir'])) : '-'; ?>
                    </span>
                </div>

                <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 font-bold uppercase tracking-wider block mb-1 text-[10px]">Jenis Kelamin</span>
                    <span class="font-semibold text-slate-900"><?php echo htmlspecialchars($peserta['jenis_kelamin'] ?: '-'); ?></span>
                </div>

                <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 font-bold uppercase tracking-wider block mb-1 text-[10px]">Batch Pelatihan</span>
                    <span class="font-bold text-indigo-700"><?php echo htmlspecialchars($peserta['batch_pelatihan'] ?: '-'); ?></span>
                </div>

                <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 font-bold uppercase tracking-wider block mb-1 text-[10px]">Lembaga Pelatihan</span>
                    <span class="font-semibold text-slate-900 block truncate"><?php echo htmlspecialchars($peserta['lembaga_pelatihan'] ?: '-'); ?></span>
                </div>

                <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100 sm:col-span-2 lg:col-span-4">
                    <span class="text-slate-400 font-bold uppercase tracking-wider block mb-1 text-[10px]">Alamat Domisili</span>
                    <span class="font-semibold text-slate-700 leading-relaxed block"><?php echo htmlspecialchars($peserta['alamat_lengkap'] ?? ($peserta['alamat_domisili'] ?? '-')); ?></span>
                </div>
            </div>
        </div>

        <div>
            <div class="mb-4">
                <h2 class="text-lg font-extrabold text-slate-900">Modul Ujian Anda</h2>
                <p class="text-xs text-slate-500">Pilih modul ujian di bawah ini untuk memulai atau melanjutkan pengerjaan.</p>
            </div>

            <?php if ($aksesTertulis == 0 &&$aksesInterview == 0): ?>
                <div class="bg-white/80 p-8 rounded-3xl border border-rose-200 text-center shadow-xs">
                    <div class="w-12 h-12 bg-rose-50 text-rose-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                    <h3 class="font-bold text-rose-800 text-base mb-1">Akses Ujian Belum Diaktifkan</h3>
                    <p class="text-rose-600 text-xs max-w-md mx-auto">Akun Anda belum memiliki izin untuk membuka modul ujian. Harap laporkan ke panitia atau pengawas ujian.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <?php if ($aksesTertulis == 1): ?>
                    <div class="glass-card p-6 sm:p-7 rounded-3xl shadow-xs border-2 flex flex-col justify-between <?php echo $statusTertulis === 'selesai' ? 'border-emerald-200 bg-emerald-50/30' : 'border-indigo-100 hover:border-indigo-300'; ?> transition">
                        <div>
                            <div class="flex justify-between items-start mb-4">
                                <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center font-bold">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                </div>
                                <?php if ($statusTertulis === 'selesai'): ?>
                                    <span class="bg-emerald-100 text-emerald-700 text-[10px] font-extrabold px-3 py-1 rounded-full uppercase">Selesai</span>
                                <?php elseif ($statusTertulis === 'sedang'): ?>
                                    <span class="bg-amber-100 text-amber-700 text-[10px] font-extrabold px-3 py-1 rounded-full uppercase">Sedang Dikerjakan</span>
                                <?php else: ?>
                                    <span class="bg-indigo-100 text-indigo-700 text-[10px] font-extrabold px-3 py-1 rounded-full uppercase">Tersedia</span>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-extrabold text-slate-900 mb-1">Ujian Tertulis</h3>
                            <p class="text-xs text-slate-500 leading-relaxed mb-6">Ujian tertulis pilihan ganda dengan bobot kunci jawaban benar dan salah.</p>
                        </div>

                        <div>
                            <?php if ($statusTertulis === 'selesai'): ?>
                                <button disabled class="w-full bg-slate-100 text-slate-400 font-bold py-3 rounded-xl text-xs cursor-not-allowed">
                                    Ujian Telah Selesai (Terkunci)
                                </button>
                            <?php else: ?>
                                <a href="ujian.php?jenis=tertulis" class="block text-center w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl text-xs transition shadow-md shadow-indigo-500/20">
                                    <?php echo $statusTertulis === 'sedang' ? 'Lanjutkan Ujian Tertulis' : 'Mulai Kerjakan Ujian Tertulis'; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($aksesInterview == 1): ?>
                    <div class="glass-card p-6 sm:p-7 rounded-3xl shadow-xs border-2 flex flex-col justify-between <?php echo $statusInterview === 'selesai' ? 'border-emerald-200 bg-emerald-50/30' : 'border-amber-100 hover:border-amber-300'; ?> transition">
                        <div>
                            <div class="flex justify-between items-start mb-4">
                                <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center font-bold">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z"></path></svg>
                                </div>
                                <?php if ($statusInterview === 'selesai'): ?>
                                    <span class="bg-emerald-100 text-emerald-700 text-[10px] font-extrabold px-3 py-1 rounded-full uppercase">Selesai</span>
                                <?php elseif ($statusInterview === 'sedang'): ?>
                                    <span class="bg-amber-100 text-amber-700 text-[10px] font-extrabold px-3 py-1 rounded-full uppercase">Sedang Dikerjakan</span>
                                <?php else: ?>
                                    <span class="bg-indigo-100 text-indigo-700 text-[10px] font-extrabold px-3 py-1 rounded-full uppercase">Tersedia</span>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-extrabold text-slate-900 mb-1">Ujian Interview Tertulis</h3>
                            <p class="text-xs text-slate-500 leading-relaxed mb-6">Ujian wawancara tertulis bertingkat dengan bobot poin penilaian tiap opsi pilihan.</p>
                        </div>

                        <div>
                            <?php if ($statusInterview === 'selesai'): ?>
                                <button disabled class="w-full bg-slate-100 text-slate-400 font-bold py-3 rounded-xl text-xs cursor-not-allowed">
                                    Ujian Telah Selesai (Terkunci)
                                </button>
                            <?php else: ?>
                                <a href="ujian.php?jenis=interview" class="block text-center w-full bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 rounded-xl text-xs transition shadow-md shadow-amber-500/20">
                                    <?php echo $statusInterview === 'sedang' ? 'Lanjutkan Ujian Interview' : 'Mulai Kerjakan Ujian Interview'; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            <?php endif; ?>
        </div>

    </main>

    <footer class="text-center py-6 text-xs text-slate-400 border-t border-white/60 glass-card">
        &copy; <?php echo date('Y'); ?> Prima Ammaray, Tim Teknologi Informasi | BP3MI Jawa Tengah. All rights reserved.
    </footer>

    <script>
        function updateServerClock() {
            const now = new Date();
            const wib = new Date(now.toLocaleString("en-US", { timeZone: "Asia/Jakarta" }));
            const h = String(wib.getHours()).padStart(2, '0');
            const m = String(wib.getMinutes()).padStart(2, '0');
            const s = String(wib.getSeconds()).padStart(2, '0');
            const el = document.getElementById('jamServer');
            if (el) el.textContent = `${h}:${m}:${s} WIB`;
        }
        setInterval(updateServerClock, 1000);
        updateServerClock();
    </script>
</body>
</html>