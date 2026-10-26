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

if (file_exists(__DIR__ . '/../api/helper_log.php')) {
    require_once __DIR__ . '/../api/helper_log.php';
}

$namaAdmin    = $_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin   = $_SESSION['admin_level'] ?? 'user';
$isSuperAdmin = in_array(strtolower($levelAdmin), ['superadmin', 'admin']);

error_reporting(E_ALL);
ini_set('display_errors', 0);

$notif = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_timer') {
        $jenisUjian        = trim($_POST['jenis_ujian'] ?? '');
        $durasiBaru        = (int)($_POST['nilai_pengaturan'] ?? 60);
        $namaPengaturanKey = 'durasi_' . $jenisUjian;

        try {
            $stmtUpd = $pdo->prepare("UPDATE tb_pengaturan SET nilai_pengaturan = ? WHERE nama_pengaturan = ?");
            $stmtUpd->execute([$durasiBaru, $namaPengaturanKey]);

            if (function_exists('catatLog')) {
                catatLog($pdo, "Memperbarui durasi ujian " . ucfirst($jenisUjian) . " menjadi {$durasiBaru} menit", 'UJIAN');
            }

            $notif = ['type' => 'success', 'msg' => "Durasi ujian " . ucfirst($jenisUjian) . " berhasil diperbarui menjadi {$durasiBaru} menit."];
        } catch (Throwable $e) {
            $notif = ['type' => 'error', 'msg' => 'Gagal memperbarui durasi: ' . $e->getMessage()];
        }
    }

    if ($action === 'tambah_soal') {
        $jenis      = $_POST['jenis_ujian'] ?? 'tertulis';
        $pertanyaan = trim($_POST['pertanyaan'] ?? '');
        $opsi_a     = trim($_POST['opsi_a'] ?? '');
        $opsi_b     = trim($_POST['opsi_b'] ?? '');
        $opsi_c     = trim($_POST['opsi_c'] ?? '');
        $opsi_d     = trim($_POST['opsi_d'] ?? '');
        $kunci      = ($jenis === 'tertulis') ? strtoupper(trim($_POST['kunci'] ?? 'A')) : null;
        $poin_a     = ($jenis === 'interview') ? (int)($_POST['poin_a'] ?? 0) : 0;
        $poin_b     = ($jenis === 'interview') ? (int)($_POST['poin_b'] ?? 0) : 0;
        $poin_c     = ($jenis === 'interview') ? (int)($_POST['poin_c'] ?? 0) : 0;
        $poin_d     = ($jenis === 'interview') ? (int)($_POST['poin_d'] ?? 0) : 0;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO tb_soal (jenis_ujian, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, kunci, poin_a, poin_b, poin_c, poin_d)
                VALUES (:jenis, :tanya, :a, :b, :c, :d, :kunci, :pa, :pb, :pc, :pd)
            ");
            $stmt->execute([
                'jenis' => $jenis,
                'tanya' => $pertanyaan,
                'a'     => $opsi_a,
                'b'     => $opsi_b,
                'c'     => $opsi_c,
                'd'     => $opsi_d,
                'kunci' => $kunci,
                'pa'    => $poin_a,
                'pb'    => $poin_b,
                'pc'    => $poin_c,
                'pd'    => $poin_d,
            ]);

            $idBaru = $pdo->lastInsertId();
            if (function_exists('catatLog')) {
                catatLog($pdo, "Menambahkan butir soal baru ID #{$idBaru} (Jenis: " . ucfirst($jenis) . ")", 'SOAL');
            }

            $notif = ['type' => 'success', 'msg' => 'Soal baru berhasil ditambahkan ke bank soal.'];
        } catch (Throwable $e) {
            $notif = ['type' => 'error', 'msg' => 'Gagal menambah soal: ' . $e->getMessage()];
        }
    }

    if ($action === 'edit_soal') {
        $id         = (int)($_POST['id'] ?? 0);
        $jenis      = $_POST['jenis_ujian'] ?? 'tertulis';
        $pertanyaan = trim($_POST['pertanyaan'] ?? '');
        $opsi_a     = trim($_POST['opsi_a'] ?? '');
        $opsi_b     = trim($_POST['opsi_b'] ?? '');
        $opsi_c     = trim($_POST['opsi_c'] ?? '');
        $opsi_d     = trim($_POST['opsi_d'] ?? '');
        $kunci      = ($jenis === 'tertulis') ? strtoupper(trim($_POST['kunci'] ?? 'A')) : '-';
        $poin_a     = ($jenis === 'interview') ? (int)($_POST['poin_a'] ?? 0) : 0;
        $poin_b     = ($jenis === 'interview') ? (int)($_POST['poin_b'] ?? 0) : 0;
        $poin_c     = ($jenis === 'interview') ? (int)($_POST['poin_c'] ?? 0) : 0;
        $poin_d     = ($jenis === 'interview') ? (int)($_POST['poin_d'] ?? 0) : 0;

        try {
            $stmt = $pdo->prepare("
                UPDATE tb_soal SET 
                    jenis_ujian = :jenis,
                    pertanyaan  = :tanya,
                    opsi_a      = :a,
                    opsi_b      = :b,
                    opsi_c      = :c,
                    opsi_d      = :d,
                    kunci       = :kunci,
                    poin_a      = :pa,
                    poin_b      = :pb,
                    poin_c      = :pc,
                    poin_d      = :pd
                WHERE id = :id
            ");
            $stmt->execute([
                'jenis' => $jenis,
                'tanya' => $pertanyaan,
                'a'     => $opsi_a,
                'b'     => $opsi_b,
                'c'     => $opsi_c,
                'd'     => $opsi_d,
                'kunci' => $kunci,
                'pa'    => $poin_a,
                'pb'    => $poin_b,
                'pc'    => $poin_c,
                'pd'    => $poin_d,
                'id'    => $id,
            ]);

            if (function_exists('catatLog')) {
                catatLog($pdo, "Memperbarui data butir soal ID #{$id} (Jenis: " . ucfirst($jenis) . ")", 'SOAL');
            }

            $notif = ['type' => 'success', 'msg' => "Soal nomor ID #{$id} berhasil diperbarui."];
        } catch (Throwable $e) {
            $notif = ['type' => 'error', 'msg' => 'Gagal memperbarui soal: ' . $e->getMessage()];
        }
    }

    if ($action === 'hapus_soal') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE tb_soal SET status_aktif = 'N' WHERE id = :id");
            $stmt->execute(['id' => $id]);

            if (function_exists('catatLog')) {
                catatLog($pdo, "Mengarsipkan (menyembunyikan) butir soal ID #{$id}", 'SOAL');
            }

            $notif = ['type' => 'success', 'msg' => "Soal nomor ID #{$id} telah diarsipkan dan disembunyikan dari peserta."];
        } catch (Throwable $e) {
            $notif = ['type' => 'error', 'msg' => 'Gagal mengarsipkan soal: ' . $e->getMessage()];
        }
    }

    if ($action === 'pulihkan_soal') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE tb_soal SET status_aktif = 'Y' WHERE id = :id");
            $stmt->execute(['id' => $id]);

            if (function_exists('catatLog')) {
                catatLog($pdo, "Menayangkan kembali butir soal ID #{$id}", 'SOAL');
            }

            $notif = ['type' => 'success', 'msg' => "Soal nomor ID #{$id} berhasil ditayangkan kembali untuk peserta."];
        } catch (Throwable $e) {
            $notif = ['type' => 'error', 'msg' => 'Gagal menayangkan soal: ' . $e->getMessage()];
        }
    }
}

$durasiTertulis  = 60;
$durasiInterview = 30;
try {
    $stmtT = $pdo->prepare("SELECT nilai_pengaturan FROM tb_pengaturan WHERE nama_pengaturan = 'durasi_tertulis'");
    $stmtT->execute();
    $resT = $stmtT->fetchColumn();
    if ($resT !== false) $durasiTertulis = (int)$resT;

    $stmtI = $pdo->prepare("SELECT nilai_pengaturan FROM tb_pengaturan WHERE nama_pengaturan = 'durasi_interview'");
    $stmtI->execute();
    $resI = $stmtI->fetchColumn();
    if ($resI !== false) $durasiInterview = (int)$resI;
} catch (Throwable $e) {}

$keyword     = trim($_GET['q'] ?? '');
$filterJenis = trim($_GET['jenis'] ?? '');

$daftarSoal    = [];
$statTotal     = 0;
$statTertulis  = 0;
$statInterview = 0;
$t_aktif       = 0;
$t_nonaktif    = 0;
$i_aktif       = 0;
$i_nonaktif    = 0;

try {
    $sqlStats = "
        SELECT 
            COUNT(*) as tot_all,
            SUM(CASE WHEN jenis_ujian = 'tertulis' OR jenis_ujian IS NULL THEN 1 ELSE 0 END) as tot_t,
            SUM(CASE WHEN jenis_ujian = 'interview' THEN 1 ELSE 0 END) as tot_i,
            SUM(CASE WHEN (jenis_ujian = 'tertulis' OR jenis_ujian IS NULL) AND (status_aktif = 'Y' OR status_aktif IS NULL) THEN 1 ELSE 0 END) as t_aktif,
            SUM(CASE WHEN (jenis_ujian = 'tertulis' OR jenis_ujian IS NULL) AND status_aktif = 'N' THEN 1 ELSE 0 END) as t_nonaktif,
            SUM(CASE WHEN jenis_ujian = 'interview' AND (status_aktif = 'Y' OR status_aktif IS NULL) THEN 1 ELSE 0 END) as i_aktif,
            SUM(CASE WHEN jenis_ujian = 'interview' AND status_aktif = 'N' THEN 1 ELSE 0 END) as i_nonaktif
        FROM tb_soal
    ";
    $stmtS = $pdo->query($sqlStats);
    $rowS = $stmtS->fetch(PDO::FETCH_ASSOC);

    $statTotal     = (int)($rowS['tot_all'] ?? 0);
    $statTertulis  = (int)($rowS['tot_t'] ?? 0);
    $statInterview = (int)($rowS['tot_i'] ?? 0);
    $t_aktif       = (int)($rowS['t_aktif'] ?? 0);
    $t_nonaktif    = (int)($rowS['t_nonaktif'] ?? 0);
    $i_aktif       = (int)($rowS['i_aktif'] ?? 0);
    $i_nonaktif    = (int)($rowS['i_nonaktif'] ?? 0);

    $sql    = "SELECT * FROM tb_soal WHERE 1=1";
    $params = [];

    if (!empty($keyword)) {
        $sql .= " AND (pertanyaan LIKE :kw OR opsi_a LIKE :kw OR opsi_b LIKE :kw OR opsi_c LIKE :kw OR opsi_d LIKE :kw)";
        $params['kw'] = "%{$keyword}%";
    }

    if (!empty($filterJenis)) {
        $sql .= " AND jenis_ujian = :jns";
        $params['jns'] = $filterJenis;
    }

    $sql .= " ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $daftarSoal = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $notif = ['type' => 'error', 'msg' => 'Kesalahan Database: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Bank Soal & Durasi Ujian — BP3MI Jawa Tengah</title>
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
    </style>
</head>
<body class="bg-[#f4f6f9] text-slate-800 min-h-screen md:h-screen flex flex-col md:flex-row overflow-x-hidden md:overflow-hidden selection:bg-[#0e74bb] selection:text-white">

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-h-0 md:h-screen overflow-y-auto relative scroll-smooth bg-[#f4f6f9]">
        <div class="p-4 sm:p-7 max-w-7xl mx-auto w-full space-y-6">
            
            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs relative overflow-hidden flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
                <div>
                    <div class="flex items-center gap-2.5">
                        <div class="w-2.5 h-6 bg-[#0e74bb] rounded-full"></div>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight uppercase">Manajemen Bank Soal & Waktu Ujian</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium pl-5">Pusat pengelolaan soal asesmen tertulis dan wawancara beserta durasi waktu pengerjaan.</p>
                </div>
                <div class="inline-flex items-center gap-3 bg-slate-50 px-3.5 py-2 rounded-lg border border-slate-200 shadow-2xs shrink-0">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                    <div class="text-right">
                        <div id="liveClock" class="text-xs sm:text-sm font-bold text-[#0e74bb] font-mono leading-none">--:--:-- WIB</div>
                        <div id="liveDate" class="text-[9px] font-bold text-slate-500 uppercase tracking-wider mt-0.5 leading-none">Memuat Tanggal...</div>
                    </div>
                </div>
            </div>

            <?php if ($notif): ?>
            <div class="p-4 rounded-xl text-xs font-bold border shadow-xs <?php echo $notif['type'] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800'; ?>">
                <?php echo htmlspecialchars($notif['msg']); ?>
            </div>
            <?php endif; ?>

            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs space-y-4">
                <h3 class="text-xs font-extrabold uppercase tracking-widest text-[#0e74bb]">Pengaturan Durasi Waktu Ujian</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <form method="POST" action="bank_soal.php" class="p-4 rounded-xl border border-slate-200 bg-slate-50 space-y-3">
                        <input type="hidden" name="action" value="update_timer">
                        <input type="hidden" name="jenis_ujian" value="tertulis">
                        <div>
                            <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1">Durasi Ujian Tertulis (Menit)</label>
                            <input type="number" name="nilai_pengaturan" value="<?php echo $durasiTertulis; ?>" min="5" max="300" required
                                   class="w-full h-11 px-4 rounded-lg border border-slate-300 bg-white text-sm font-bold font-mono text-[#0e74bb] focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none transition">
                        </div>
                        <button type="submit" class="w-full bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold py-2.5 rounded-lg text-xs transition shadow-xs uppercase tracking-wider">
                            Simpan Durasi Tertulis
                        </button>
                    </form>

                    <form method="POST" action="bank_soal.php" class="p-4 rounded-xl border border-slate-200 bg-slate-50 space-y-3">
                        <input type="hidden" name="action" value="update_timer">
                        <input type="hidden" name="jenis_ujian" value="interview">
                        <div>
                            <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1">Durasi Ujian Interview (Menit)</label>
                            <input type="number" name="nilai_pengaturan" value="<?php echo $durasiInterview; ?>" min="5" max="300" required
                                   class="w-full h-11 px-4 rounded-lg border border-slate-300 bg-white text-sm font-bold font-mono text-amber-600 focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 focus:outline-none transition">
                        </div>
                        <button type="submit" class="w-full bg-amber-600 hover:bg-amber-700 text-white font-bold py-2.5 rounded-lg text-xs transition shadow-xs uppercase tracking-wider">
                            Simpan Durasi Interview
                        </button>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-5 rounded-xl border border-slate-200 border-l-4 border-l-[#0e74bb] bg-white shadow-xs flex flex-col justify-center text-left hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <span class="text-[10px] text-slate-500 font-extrabold uppercase tracking-widest block mb-1">Total Bank Soal</span>
                    <span class="text-3xl font-black text-slate-900 font-mono leading-none"><?php echo number_format($statTotal, 0, ',', '.'); ?></span>
                </div>

                <div class="p-5 rounded-xl border border-slate-200 border-l-4 border-l-sky-500 bg-white shadow-xs flex flex-col justify-between text-left hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div class="mb-4">
                        <span class="text-[10px] text-sky-600 font-extrabold uppercase tracking-widest block mb-1">Total Soal Tertulis</span>
                        <span class="text-3xl font-black text-[#0e74bb] font-mono leading-none"><?php echo number_format($statTertulis, 0, ',', '.'); ?></span>
                    </div>
                    <div class="border-t border-slate-200 pt-3 flex justify-between">
                        <div class="w-1/2 pr-2 border-r border-slate-200 text-left">
                            <span class="text-[8px] font-extrabold text-emerald-600 uppercase tracking-wider block mb-0.5">TAYANG (AKTIF)</span>
                            <span class="block text-lg font-black text-emerald-600 font-mono leading-none"><?php echo number_format($t_aktif, 0, ',', '.'); ?></span>
                        </div>
                        <div class="w-1/2 pl-3 text-left">
                            <span class="text-[8px] font-extrabold text-slate-500 uppercase tracking-wider block mb-0.5">DIARSIPKAN</span>
                            <span class="block text-lg font-black text-slate-500 font-mono leading-none"><?php echo number_format($t_nonaktif, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="p-5 rounded-xl border border-slate-200 border-l-4 border-l-amber-500 bg-white shadow-xs flex flex-col justify-between text-left hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div class="mb-4">
                        <span class="text-[10px] text-amber-600 font-extrabold uppercase tracking-widest block mb-1">Total Soal Interview</span>
                        <span class="text-3xl font-black text-amber-700 font-mono leading-none"><?php echo number_format($statInterview, 0, ',', '.'); ?></span>
                    </div>
                    <div class="border-t border-slate-200 pt-3 flex justify-between">
                        <div class="w-1/2 pr-2 border-r border-slate-200 text-left">
                            <span class="text-[8px] font-extrabold text-emerald-600 uppercase tracking-wider block mb-0.5">TAYANG (AKTIF)</span>
                            <span class="block text-lg font-black text-emerald-600 font-mono leading-none"><?php echo number_format($i_aktif, 0, ',', '.'); ?></span>
                        </div>
                        <div class="w-1/2 pl-3 text-left">
                            <span class="text-[8px] font-extrabold text-slate-500 uppercase tracking-wider block mb-0.5">DIARSIPKAN</span>
                            <span class="block text-lg font-black text-slate-500 font-mono leading-none"><?php echo number_format($i_nonaktif, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-xs flex flex-col md:flex-row justify-between items-center gap-4 mt-6">
                <form method="GET" action="bank_soal.php" class="flex flex-col sm:flex-row gap-3 w-full md:w-auto flex-grow items-center">
                    <div class="relative w-full sm:w-80">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="Cari isi pertanyaan atau opsi..." 
                               class="w-full h-11 pl-9 pr-4 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                        <i class="fas fa-search text-slate-400 absolute left-3 top-3.5 text-xs"></i>
                    </div>
                    
                    <select name="jenis" class="w-full sm:w-48 h-11 px-3.5 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                        <option value="">Semua Jenis Soal</option>
                        <option value="tertulis" <?php echo $filterJenis === 'tertulis' ? 'selected' : ''; ?>>Tertulis Standar</option>
                        <option value="interview" <?php echo $filterJenis === 'interview' ? 'selected' : ''; ?>>Interview Bertingkat</option>
                    </select>

                    <div class="flex gap-2 w-full sm:w-auto h-11 items-center">
                        <button type="submit" class="flex-1 sm:flex-none h-full bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold px-5 rounded-lg text-xs transition uppercase tracking-wider shadow-xs">
                            Cari
                        </button>
                        <?php if (!empty($keyword) || !empty($filterJenis)): ?>
                        <a href="bank_soal.php" class="px-4 h-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg text-xs transition flex items-center justify-center uppercase tracking-wider border border-slate-200">
                            Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>

                <button type="button" onclick="bukaModalTambah()" 
                        class="w-full md:w-auto h-11 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold px-5 rounded-lg text-xs shadow-xs transition flex items-center justify-center gap-2 uppercase tracking-wider shrink-0">
                    <i class="fas fa-plus text-xs"></i>
                    <span>Tambah Soal Baru</span>
                </button>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4 mb-10">
                <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-50/70 flex justify-between items-center text-xs font-semibold text-slate-600">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                            <i class="fas fa-clipboard-question"></i>
                        </div>
                        <div>
                            <span class="font-bold text-slate-800 uppercase tracking-wider block">Daftar Soal Tersedia</span>
                            <span class="text-[10px] text-slate-400 font-medium">Bank soal ujian tertulis dan interview</span>
                        </div>
                    </div>
                    <span class="text-[10px] font-bold text-slate-600 bg-white px-2.5 py-1 rounded-md border border-slate-200 font-mono">Total: <b><?php echo count($daftarSoal); ?></b> soal</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead class="bg-slate-100/80 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="py-3 px-5 text-center w-16">No</th>
                                <th class="py-3 px-5 w-32">Jenis & Status</th>
                                <th class="py-3 px-5">Pertanyaan & Opsi Pilihan</th>
                                <th class="py-3 px-5 w-44 text-center">Kunci / Bobot</th>
                                <th class="py-3 px-5 text-center w-36">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white text-xs">
                            <?php if (empty($daftarSoal)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-slate-400">
                                    Tidak ada soal yang ditemukan. Silakan tambahkan soal baru.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($daftarSoal as $idx => $s): ?>
                            <tr class="hover:bg-sky-50/40 transition align-top">
                                <td class="py-3 px-5 text-center font-bold text-slate-400 font-mono">
                                    <?php echo $idx + 1; ?>
                                </td>
                                <td class="py-3 px-5">
                                    <?php if (($s['jenis_ujian'] ?? 'tertulis') === 'interview'): ?>
                                        <span class="inline-block px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase bg-amber-50 text-amber-700 border border-amber-200">
                                            Interview
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-block px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase bg-sky-50 text-[#0e74bb] border border-sky-200">
                                            Tertulis
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (($s['status_aktif'] ?? 'Y') === 'Y'): ?>
                                        <span class="inline-block mt-2 px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200 block w-max">
                                            TAYANG
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-block mt-2 px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase bg-slate-100 text-slate-500 border border-slate-200 block w-max">
                                            DIARSIPKAN
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-5">
                                    <p class="font-bold text-slate-900 mb-3 leading-relaxed text-sm">
                                        <?php echo nl2br(htmlspecialchars($s['pertanyaan'])); ?>
                                    </p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-600">
                                        <div class="p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                                            <strong class="text-[#0e74bb] mr-1 font-bold">A.</strong> <?php echo htmlspecialchars($s['opsi_a']); ?>
                                        </div>
                                        <div class="p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                                            <strong class="text-[#0e74bb] mr-1 font-bold">B.</strong> <?php echo htmlspecialchars($s['opsi_b']); ?>
                                        </div>
                                        <div class="p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                                            <strong class="text-[#0e74bb] mr-1 font-bold">C.</strong> <?php echo htmlspecialchars($s['opsi_c']); ?>
                                        </div>
                                        <div class="p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                                            <strong class="text-[#0e74bb] mr-1 font-bold">D.</strong> <?php echo htmlspecialchars($s['opsi_d']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-5 text-center align-middle">
                                    <?php if (($s['jenis_ujian'] ?? 'tertulis') === 'interview'): ?>
                                        <div class="text-[11px] font-semibold text-slate-600 space-y-1 bg-amber-50/60 p-2.5 rounded-xl border border-amber-100">
                                            <div>A: <strong class="text-amber-700"><?php echo (int)($s['poin_a'] ?? 0); ?> pt</strong></div>
                                            <div>B: <strong class="text-amber-700"><?php echo (int)($s['poin_b'] ?? 0); ?> pt</strong></div>
                                            <div>C: <strong class="text-amber-700"><?php echo (int)($s['poin_c'] ?? 0); ?> pt</strong></div>
                                            <div>D: <strong class="text-amber-700"><?php echo (int)($s['poin_d'] ?? 0); ?> pt</strong></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-black bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Kunci: <?php echo htmlspecialchars($s['kunci'] ?? '-'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-5 text-center align-middle">
                                    <div class="inline-flex items-center space-x-2">
                                        <button type="button" 
                                                onclick='bukaModalEdit(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' 
                                                class="bg-sky-50 hover:bg-[#0e74bb] hover:text-white text-[#0e74bb] font-bold px-3 py-1.5 rounded-lg text-xs transition border border-sky-100">
                                            Ubah
                                        </button>

                                        <?php if (($s['status_aktif'] ?? 'Y') === 'Y'): ?>
                                            <form method="POST" action="bank_soal.php" onsubmit="return confirm('Arsipkan dan sembunyikan soal ini dari ujian peserta?')">
                                                <input type="hidden" name="action" value="hapus_soal">
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                                <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold px-3 py-1.5 rounded-lg text-xs transition border border-rose-200">
                                                    Arsipkan
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="bank_soal.php" onsubmit="return confirm('Tayangkan kembali soal ini ke dalam ujian peserta?')">
                                                <input type="hidden" name="action" value="pulihkan_soal">
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                                <button type="submit" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold px-3 py-1.5 rounded-lg text-xs transition border border-emerald-200">
                                                    Tayangkan
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div id="modalSoal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center pb-4 border-b border-slate-100 mb-6">
                <h3 id="modalJudul" class="font-extrabold text-slate-900 text-lg">Tambah Soal Baru</h3>
                <button type="button" onclick="tutupModal()" class="text-slate-400 hover:text-slate-600 p-2 text-xl font-bold">&times;</button>
            </div>

            <form method="POST" action="bank_soal.php" class="space-y-4 text-sm">
                <input type="hidden" name="action" id="form_action" value="tambah_soal">
                <input type="hidden" name="id" id="form_id" value="">

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Jenis Ujian</label>
                    <select name="jenis_ujian" id="form_jenis" onchange="toggleFormJenis()" 
                            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 bg-white font-semibold focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none text-xs transition">
                        <option value="tertulis">Ujian Tertulis Standar (Kunci Benar / Salah)</option>
                        <option value="interview">Ujian Interview Tertulis (Bobot Poin Bertingkat)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Isi Pertanyaan</label>
                    <textarea name="pertanyaan" id="form_pertanyaan" rows="3" required placeholder="Tuliskan pertanyaan ujian di sini..." 
                              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none text-xs transition"></textarea>
                </div>

                <div class="space-y-3 pt-2">
                    <div>
                        <label class="block text-xs font-bold text-[#0e74bb] uppercase mb-1">Opsi Jawaban A</label>
                        <input type="text" name="opsi_a" id="form_opsi_a" required class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none text-xs transition">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-[#0e74bb] uppercase mb-1">Opsi Jawaban B</label>
                        <input type="text" name="opsi_b" id="form_opsi_b" required class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none text-xs transition">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-[#0e74bb] uppercase mb-1">Opsi Jawaban C</label>
                        <input type="text" name="opsi_c" id="form_opsi_c" required class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none text-xs transition">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-[#0e74bb] uppercase mb-1">Opsi Jawaban D</label>
                        <input type="text" name="opsi_d" id="form_opsi_d" required class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none text-xs transition">
                    </div>
                </div>

                <div id="boxKunciJawaban" class="pt-2">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Kunci Jawaban Benar</label>
                    <select name="kunci" id="form_kunci" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 bg-white font-bold text-emerald-700 focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none text-xs transition">
                        <option value="A">Opsi A</option>
                        <option value="B">Opsi B</option>
                        <option value="C">Opsi C</option>
                        <option value="D">Opsi D</option>
                    </select>
                </div>

                <div id="boxPoinInterview" class="hidden pt-2">
                    <label class="block text-xs font-bold text-amber-700 uppercase mb-1.5">Bobot Poin Tiap Opsi (Skala Bertingkat)</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div>
                            <span class="text-xs text-slate-500 block mb-1 font-semibold">Poin A</span>
                            <input type="number" name="poin_a" id="form_poin_a" value="10" min="0" max="100" class="w-full px-3 py-2 rounded-lg border border-slate-300 font-bold text-xs focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none">
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 block mb-1 font-semibold">Poin B</span>
                            <input type="number" name="poin_b" id="form_poin_b" value="7" min="0" max="100" class="w-full px-3 py-2 rounded-lg border border-slate-300 font-bold text-xs focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none">
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 block mb-1 font-semibold">Poin C</span>
                            <input type="number" name="poin_c" id="form_poin_c" value="4" min="0" max="100" class="w-full px-3 py-2 rounded-lg border border-slate-300 font-bold text-xs focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none">
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 block mb-1 font-semibold">Poin D</span>
                            <input type="number" name="poin_d" id="form_poin_d" value="1" min="0" max="100" class="w-full px-3 py-2 rounded-lg border border-slate-300 font-bold text-xs focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] focus:outline-none">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 pt-6 border-t border-slate-100">
                    <button type="button" onclick="tutupModal()" class="px-5 py-2.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold transition text-xs">
                        Batal
                    </button>
                    <button type="submit" class="bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold px-6 py-2.5 rounded-lg transition shadow-xs text-xs uppercase tracking-wider">
                        Simpan Soal
                    </button>
                </div>
            </form>
        </div>
    </div>

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

        function toggleFormJenis() {
            const jenis = document.getElementById('form_jenis').value;
            const boxKunci = document.getElementById('boxKunciJawaban');
            const boxPoin = document.getElementById('boxPoinInterview');

            if (jenis === 'interview') {
                boxKunci.classList.add('hidden');
                boxPoin.classList.remove('hidden');
            } else {
                boxKunci.classList.remove('hidden');
                boxPoin.classList.add('hidden');
            }
        }

        function bukaModalTambah() {
            document.getElementById('modalJudul').textContent = 'Tambah Soal Baru';
            document.getElementById('form_action').value = 'tambah_soal';
            document.getElementById('form_id').value = '';
            document.getElementById('form_jenis').value = 'tertulis';
            document.getElementById('form_pertanyaan').value = '';
            document.getElementById('form_opsi_a').value = '';
            document.getElementById('form_opsi_b').value = '';
            document.getElementById('form_opsi_c').value = '';
            document.getElementById('form_opsi_d').value = '';
            document.getElementById('form_kunci').value = 'A';
            document.getElementById('form_poin_a').value = 10;
            document.getElementById('form_poin_b').value = 7;
            document.getElementById('form_poin_c').value = 4;
            document.getElementById('form_poin_d').value = 1;

            toggleFormJenis();
            document.getElementById('modalSoal').classList.remove('hidden');
        }

        function bukaModalEdit(s) {
            document.getElementById('modalJudul').textContent = 'Ubah Soal (ID #' + s.id + ')';
            document.getElementById('form_action').value = 'edit_soal';
            document.getElementById('form_id').value = s.id;
            document.getElementById('form_jenis').value = s.jenis_ujian || 'tertulis';
            document.getElementById('form_pertanyaan').value = s.pertanyaan || '';
            document.getElementById('form_opsi_a').value = s.opsi_a || '';
            document.getElementById('form_opsi_b').value = s.opsi_b || '';
            document.getElementById('form_opsi_c').value = s.opsi_c || '';
            document.getElementById('form_opsi_d').value = s.opsi_d || '';
            document.getElementById('form_kunci').value = (s.kunci || 'A').toUpperCase();
            document.getElementById('form_poin_a').value = s.poin_a || 0;
            document.getElementById('form_poin_b').value = s.poin_b || 0;
            document.getElementById('form_poin_c').value = s.poin_c || 0;
            document.getElementById('form_poin_d').value = s.poin_d || 0;

            toggleFormJenis();
            document.getElementById('modalSoal').classList.remove('hidden');
        }

        function tutupModal() {
            document.getElementById('modalSoal').classList.add('hidden');
        }
    </script>
</body>
</html>