<?php
ob_start();
session_start();

require_once __DIR__ . '/../config.php';

if (file_exists(__DIR__ . '/../api/helper_log.php')) {
    require_once __DIR__ . '/../api/helper_log.php';
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$namaAdmin =$_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin =$_SESSION['admin_level'] ?? 'user';
$isSuperAdmin = in_array(strtolower($levelAdmin), ['superadmin', 'admin']);

if (!$isSuperAdmin) {
    header('Location: index.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

$pesan = '';
$tipePesan = '';$data_penduduk = null;
$riwayat_pendaftaran = [];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tb_master_pemutihan (
            id_pemutihan INT AUTO_INCREMENT PRIMARY KEY,
            nik VARCHAR(16) NOT NULL,
            alasan_pemutihan TEXT NOT NULL,
            tgl_pemutihan DATETIME DEFAULT CURRENT_TIMESTAMP,
            status_aktif ENUM('Y', 'N') DEFAULT 'Y'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {}

if (isset($_POST['action']) AND$_POST['action'] === 'cari_nik') {
    $nik_cari = trim(htmlspecialchars($_POST['nik'] ?? ''));
    
    if (strlen($nik_cari) === 16 AND ctype_digit($nik_cari)) {
        try {
            $stmtCari =$pdo->prepare("
                SELECT p.*, v.status AS status_verifikasi 
                FROM tb_pendaftaran p 
                LEFT JOIN tb_status_verifikasi v ON p.cpmi_id = v.cpmi_id 
                WHERE p.nik_sesuai_ktp = ? 
                ORDER BY p.cpmi_id DESC
            ");
            $stmtCari->execute([$nik_cari]);
            $riwayat_pendaftaran =$stmtCari->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($riwayat_pendaftaran)) {
                $data_penduduk =$riwayat_pendaftaran[0];
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Mencari data NIK untuk pemutihan: {$nik_cari}", 'PEMUTIHAN');
                }
            } else {
                $pesan = "Data NIK tidak ditemukan dalam riwayat pendaftaran sistem.";
                $tipePesan = "error";
            }
        } catch (Throwable $e) {$pesan = "Kesalahan basis data: " . $e->getMessage();$tipePesan = "error";
        }
    } else {
        $pesan = "Format NIK tidak valid. Pastikan berjumlah 16 digit angka.";
        $tipePesan = "error";
    }
}

if (isset($_POST['action']) AND$_POST['action'] === 'proses_pemutihan') {
    $nik_proses = trim(htmlspecialchars($_POST['nik_proses'] ?? ''));
    $catatan    = trim(htmlspecialchars($_POST['catatan_pemutihan'] ?? ''));

    if (!empty($nik_proses) AND !empty($catatan)) {
        try {
            $pdo->beginTransaction();

            $stmtNonaktif =$pdo->prepare("UPDATE tb_master_pemutihan SET status_aktif = 'N' WHERE nik = ?");
            $stmtNonaktif->execute([$nik_proses]);

            $stmtClearStatus =$pdo->prepare("
                UPDATE tb_status_verifikasi v
                JOIN tb_pendaftaran p ON v.cpmi_id = p.cpmi_id
                SET v.status = 'TIDAK MEMENUHI PERSYARATAN', 
                    v.catatan = CONCAT('PEMUTIHAN SISTEM: ', ?)
                WHERE p.nik_sesuai_ktp = ? AND v.status != 'TIDAK MEMENUHI PERSYARATAN'
            ");
            $stmtClearStatus->execute([$catatan,$nik_proses]);

            $stmtLog =$pdo->prepare("INSERT INTO tb_master_pemutihan (nik, alasan_pemutihan, status_aktif) VALUES (?, ?, 'Y')");
            $stmtLog->execute([$nik_proses,$catatan]);

            $pdo->commit();

            if (function_exists('catatLog')) {
                catatLog($pdo, "Melakukan pemutihan NIK: {$nik_proses} dengan alasan: {$catatan}", 'PEMUTIHAN');
            }

            $pesan = "Pemutihan NIK {$nik_proses} berhasil. Seluruh riwayat lama telah digugurkan dan tiket pendaftaran ulang diterbitkan.";
            $tipePesan = "success";
            $data_penduduk = null;
            $riwayat_pendaftaran = [];
            
        } catch (Throwable $e) {
            $pdo->rollBack();$pesan = "Gagal memproses pemutihan: " . $e->getMessage();$tipePesan = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Reset NIK — BP3MI Jawa Tengah</title>
    
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@300;400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-[#f4f6f9] text-slate-800 min-h-screen md:h-screen flex flex-col md:flex-row overflow-x-hidden md:overflow-hidden selection:bg-[#0e74bb] selection:text-white">

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-h-0 md:h-screen overflow-y-auto relative bg-[#f4f6f9]">
        
        <div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto w-full space-y-6">
            
            <div class="bg-white rounded-xl border border-slate-200 shadow-xs p-5 md:p-6 relative overflow-hidden flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
                <div>
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-[#0e74bb] border border-blue-100 shadow-2xs text-sm">
                            <i class="fas fa-id-card"></i>
                        </span>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight uppercase">Master Pemutihan NIK</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Modul eksekutif untuk mereset anomali, mencabut blokir NIK, dan membatalkan status Lolos Administrasi pendaftar ganda.</p>
                </div>
                <div class="text-right bg-slate-50 px-4 py-2.5 rounded-xl border border-slate-200 shadow-2xs">
                    <div id="liveClock" class="text-sm font-bold text-slate-900 font-mono">--:--:-- WIB</div>
                    <div id="liveDate" class="text-[9px] font-bold text-[#0e74bb] uppercase tracking-widest mt-0.5">Memuat Tanggal...</div>
                </div>
            </div>

            <?php if (!empty($pesan)): ?>
                <div class="p-4 rounded-xl text-xs font-bold border <?= $tipePesan === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?> shadow-2xs">
                    <?= htmlspecialchars($pesan) ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 pb-12">
                
                <div class="lg:col-span-4 h-full">
                    <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col h-full">
                        <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70">
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800 flex items-center gap-2">
                                <i class="fas fa-search text-[#0e74bb] text-xs"></i>
                                Identifikasi NIK
                            </h3>
                        </div>
                        <div class="p-5 sm:p-6">
                            <form method="POST" action="master_pemutihan.php" class="space-y-4">
                                <input type="hidden" name="action" value="cari_nik">
                                <div>
                                    <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-2">Nomor Induk Kependudukan</label>
                                    <input type="text" name="nik" placeholder="Ketik 16 Digit NIK..." required maxlength="16" pattern="\d{16}" class="w-full h-[46px] px-3.5 rounded-lg border border-slate-200 text-sm font-mono tracking-widest bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] shadow-2xs placeholder:tracking-normal placeholder:font-sans">
                                </div>
                                <button type="submit" class="w-full h-[46px] bg-[#0e74bb] hover:bg-[#0b5e99] text-white text-xs font-bold rounded-lg transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-1.5">
                                    <i class="fas fa-check-double text-xs"></i>
                                    Verifikasi Riwayat NIK
                                </button>
                                <p class="text-[10px] text-slate-400 font-medium text-center pt-2 leading-relaxed">Cari NIK peserta yang dilaporkan terkunci oleh sistem atau salah input data pada gelombang sebelumnya.</p>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-8 h-full">
                    <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col h-full">
                        <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70">
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800 flex items-center gap-2">
                                <i class="fas fa-shield-alt text-emerald-600 text-xs"></i>
                                Panel Reset NIK
                            </h3>
                        </div>
                        
                        <div class="p-5 sm:p-6">
                            <?php if ($data_penduduk): ?>
                                <div class="bg-amber-50 border border-amber-200 p-4 rounded-xl flex items-start gap-3.5 mb-6">
                                    <div class="w-9 h-9 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                                        <i class="fas fa-exclamation-triangle text-sm"></i>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-bold text-amber-800">Riwayat Terdeteksi Pada Sistem</h4>
                                        <p class="text-xs text-amber-700 mt-1 font-medium">Sistem menemukan rekam jejak pendaftaran atas NIK tersebut. Berikut adalah profil data terakhirnya:</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                                    <div class="bg-slate-50/70 p-4 rounded-xl border border-slate-200 shadow-2xs">
                                        <span class="block text-[9px] font-extrabold text-slate-500 uppercase tracking-widest mb-1">NIK Tercatat</span>
                                        <span class="block text-sm font-mono font-black text-slate-900"><?= htmlspecialchars($data_penduduk['nik_sesuai_ktp']) ?></span>
                                    </div>
                                    <div class="bg-slate-50/70 p-4 rounded-xl border border-slate-200 shadow-2xs">
                                        <span class="block text-[9px] font-extrabold text-slate-500 uppercase tracking-widest mb-1">Nama Pendaftar Terakhir</span>
                                        <span class="block text-sm font-bold text-slate-900"><?= htmlspecialchars($data_penduduk['nama_lengkap']) ?></span>
                                    </div>
                                </div>

                                <div class="bg-slate-50 rounded-xl border border-slate-200 overflow-hidden mb-6">
                                    <div class="p-3 bg-slate-100/70 border-b border-slate-200">
                                        <span class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">Histori Status Pendaftaran Sistem</span>
                                    </div>
                                    <div class="p-4 space-y-2.5">
                                        <?php foreach ($riwayat_pendaftaran as $r): ?>
                                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center p-3 bg-white border border-slate-200 rounded-lg gap-2 shadow-2xs">
                                                <div>
                                                    <span class="text-xs font-mono font-bold text-[#0e74bb] block"><?= htmlspecialchars($r['cpmi_id']) ?></span>
                                                    <span class="text-xs text-slate-500">Gelombang: <?= htmlspecialchars($r['batch_pelatihan']) ?></span>
                                                </div>
                                                <div class="text-left sm:text-right">
                                                    <?php 
                                                        $st = strtoupper($r['status_verifikasi'] ?? 'BELUM DIVERIFIKASI');
                                                        if ($st === 'MEMENUHI PERSYARATAN') {
                                                            echo '<span class="inline-block px-2.5 py-0.5 text-[9px] font-black uppercase tracking-wider bg-rose-50 text-rose-700 rounded border border-rose-200">DIBLOKIR SISTEM (Lolos)</span>';
                                                        } elseif ($st === 'TIDAK MEMENUHI PERSYARATAN') {
                                                            echo '<span class="inline-block px-2.5 py-0.5 text-[9px] font-black uppercase tracking-wider bg-slate-100 text-slate-500 rounded border border-slate-200">TMS</span>';
                                                        } else {
                                                            echo '<span class="inline-block px-2.5 py-0.5 text-[9px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600 rounded border border-slate-200">' . htmlspecialchars($st) . '</span>';
                                                        }
                                                    ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <form method="POST" action="master_pemutihan.php" class="bg-white p-5 rounded-xl border border-slate-200 shadow-xs">
                                    <input type="hidden" name="action" value="proses_pemutihan">
                                    <input type="hidden" name="nik_proses" value="<?= htmlspecialchars($data_penduduk['nik_sesuai_ktp']) ?>">
                                    
                                    <div class="mb-4">
                                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-2">Dasar Surat / Catatan Pemutihan (Wajib)</label>
                                        <textarea name="catatan_pemutihan" rows="3" required placeholder="Tulis dasar kebijakan (Contoh: Salah input domisili, minta izin daftar ulang gelombang depan...)" class="w-full p-3.5 rounded-lg border border-slate-200 text-xs font-medium bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] shadow-2xs"></textarea>
                                    </div>
                                    
                                    <div class="flex justify-end">
                                        <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-lg transition shadow-xs uppercase tracking-wider flex items-center gap-2" onclick="return confirm('PERINGATAN FINAL: Seluruh riwayat verifikasi NIK ini akan digugurkan dan izin pendaftaran baru akan dibuka. Lanjutkan?')">
                                            <i class="fas fa-check text-xs"></i>
                                            Eksekusi Pemutihan Sistem
                                        </button>
                                    </div>
                                </form>

                            <?php else: ?>
                                <div class="flex flex-col items-center justify-center text-center py-16 px-4">
                                    <div class="w-16 h-16 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mb-4 text-2xl">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <h4 class="text-base font-black text-slate-800">Menunggu Input Pencarian</h4>
                                    <p class="text-xs text-slate-500 mt-2 max-w-sm leading-relaxed">Gunakan panel identifikasi di sebelah kiri untuk mencari NIK. Detail profil pendaftaran yang terkunci dan opsi reset akan muncul di area ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
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