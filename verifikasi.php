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

if (file_exists(__DIR__ . '/../api/helper_log.php')) {
    require_once __DIR__ . '/../api/helper_log.php';
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$namaAdmin    = $_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin   = strtolower($_SESSION['admin_level'] ?? 'user');
$lembagaAdmin = $_SESSION['admin_lembaga'] ?? '';
$isSuperAdmin = in_array($levelAdmin, ['superadmin', 'admin']);

error_reporting(E_ALL);
ini_set('display_errors', 0);

$pesan = '';
$tipePesan = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' AND isset($_POST['action']) AND $_POST['action'] === 'simpan_verifikasi') {
    $targetCpmiId = trim($_POST['cpmi_id'] ?? '');
    $statusBaru   = trim($_POST['status'] ?? '');
    $catatanBaru  = trim($_POST['catatan'] ?? '');
    $isAjax       = isset($_POST['ajax']) ? true : false;

    if (!empty($targetCpmiId) AND !empty($statusBaru)) {
        try {
            $stmtCek = $pdo->prepare("SELECT COUNT(*) FROM tb_status_verifikasi WHERE cpmi_id = ?");
            $stmtCek->execute([$targetCpmiId]);
            if ($stmtCek->fetchColumn() > 0) {
                $stmtUpd = $pdo->prepare("UPDATE tb_status_verifikasi SET status = ?, catatan = ? WHERE cpmi_id = ?");
                $stmtUpd->execute([$statusBaru, $catatanBaru, $targetCpmiId]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO tb_status_verifikasi (cpmi_id, status, catatan) VALUES (?, ?, ?)");
                $stmtIns->execute([$targetCpmiId, $statusBaru, $catatanBaru]);
            }

            if (function_exists('catatLog')) {
                catatLog($pdo, "Memperbarui status verifikasi berkas CPMI ID #{$targetCpmiId} menjadi '{$statusBaru}'", 'VERIFIKASI');
            }

            $pesan = "Status verifikasi {$targetCpmiId} diperbarui.";
            $tipePesan = "success";
        } catch (Throwable $e) {
            $pesan = "Gagal menyimpan: " . $e->getMessage();
            $tipePesan = "error";
        }

        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => $tipePesan, 'pesan' => $pesan]);
            exit;
        }
    }
}

$selectedBatch   = trim($_GET['batch'] ?? 'all');
$selectedLembaga = trim($_GET['lembaga'] ?? 'all');
$selectedKejuruan= trim($_GET['kejuruan'] ?? 'all');
$selectedStatus  = trim($_GET['status'] ?? 'all');
$keyword         = trim($_GET['q'] ?? '');
$inspectId       = trim($_GET['inspect'] ?? '');
$page            = max(1, (int)($_GET['page'] ?? 1));
$limit           = 15;
$offset          = ($page - 1) * $limit;

try { $listBatch = $pdo->query("SELECT nama_periode FROM tb_periode_gelombang ORDER BY id_periode DESC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $listBatch = []; }
try { $listLembaga = $pdo->query("SELECT nama_lembaga FROM tb_master_lembaga ORDER BY nama_lembaga ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $listLembaga = []; }
try { $listKejuruan = $pdo->query("SELECT nama_kejuruan FROM tb_master_kejuruan ORDER BY nama_kejuruan ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $listKejuruan = []; }

$listGanda = [];
try {
    $pdo->exec("SET SESSION group_concat_max_len = 10000");
    $sqlGanda = "SELECT p.nik_sesuai_ktp, COUNT(*) as jml, GROUP_CONCAT(p.nama_lengkap SEPARATOR ', ') as daftar_nama FROM tb_pendaftaran p WHERE p.nik_sesuai_ktp IS NOT NULL AND TRIM(p.nik_sesuai_ktp) != '' ";
    $paramsGanda = [];
    
    if ($selectedBatch !== 'all' AND !empty($selectedBatch)) {
        $sqlGanda .= " AND p.batch_pelatihan = ? ";
        $paramsGanda[] = $selectedBatch;
    }
    
    if ($levelAdmin === 'lembaga') {
        $sqlGanda .= " AND p.lembaga_pelatihan = ? ";
        $paramsGanda[] = $lembagaAdmin;
    }
    
    $sqlGanda .= " GROUP BY p.nik_sesuai_ktp HAVING jml > 1 ORDER BY jml DESC";
    $stmtG = $pdo->prepare($sqlGanda);
    $stmtG->execute($paramsGanda);
    $listGanda = $stmtG->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$whereClauses = ["1=1"];
$params = [];

if ($levelAdmin === 'lembaga') {
    $whereClauses[] = "p.lembaga_pelatihan = ?";
    $params[]       = $lembagaAdmin;
} else {
    if ($selectedLembaga !== 'all' AND !empty($selectedLembaga)) { 
        $whereClauses[] = "p.lembaga_pelatihan = ?"; 
        $params[]       = $selectedLembaga; 
    }
}

if ($selectedBatch !== 'all' AND !empty($selectedBatch)) { 
    $whereClauses[] = "p.batch_pelatihan = ?"; 
    $params[]       = $selectedBatch; 
}
if ($selectedKejuruan !== 'all' AND !empty($selectedKejuruan)) { 
    $whereClauses[] = "p.ref_pelatihan_dimin = ?"; 
    $params[]       = $selectedKejuruan; 
}

if ($selectedStatus !== 'all' AND !empty($selectedStatus)) {
    if ($selectedStatus === 'BELUM DIVERIFIKASI') {
        $whereClauses[] = "(v.status IS NULL OR UPPER(v.status) = 'MENUNGGU VERIFIKASI' OR UPPER(v.status) = 'BELUM DIVERIFIKASI' OR TRIM(v.status) = '')";
    } else {
        $whereClauses[] = "UPPER(v.status) LIKE ?";
        $params[] = strtoupper($selectedStatus) . '%';
    }
}

if (!empty($keyword)) {
    $whereClauses[] = "(p.nama_lengkap LIKE ? OR p.nik_sesuai_ktp LIKE ? OR p.cpmi_id LIKE ?)";
    $kw = "%{$keyword}%";
    array_push($params, $kw, $kw, $kw);
}

$whereString = implode(" AND ", $whereClauses);

$totalData = 0;
try {
    $stmtC = $pdo->prepare("SELECT COUNT(p.cpmi_id) FROM tb_pendaftaran p LEFT JOIN tb_status_verifikasi v ON p.cpmi_id = v.cpmi_id WHERE {$whereString}");
    $stmtC->execute($params);
    $totalData = (int) $stmtC->fetchColumn();
} catch (Throwable $e) {}

$totalPages = max(1, ceil($totalData / $limit));
$page = min($page, $totalPages);

$listPeserta = [];
try {
    $stmtD = $pdo->prepare("
        SELECT p.cpmi_id, p.nik_sesuai_ktp, p.nama_lengkap, 
               COALESCE(NULLIF(TRIM(p.kabupaten), ''), NULLIF(TRIM(p.domisili), ''), 'Tidak Diketahui') AS domisili_peserta,
               COALESCE(v.status, 'MENUNGGU VERIFIKASI') AS status_verifikasi
        FROM tb_pendaftaran p 
        LEFT JOIN tb_status_verifikasi v ON p.cpmi_id = v.cpmi_id 
        WHERE {$whereString}
        ORDER BY p.cpmi_id ASC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmtD->execute($params);
    $listPeserta = $stmtD->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$detailPeserta = null;
$detailVerifikasi = null;
$listBerkas = [];
$berkasListJs = [];

if (!empty($inspectId)) {
    try {
        $stmtDetP = $pdo->prepare("SELECT * FROM tb_pendaftaran WHERE cpmi_id = ? LIMIT 1");
        $stmtDetP->execute([$inspectId]);
        $detailPeserta = $stmtDetP->fetch(PDO::FETCH_ASSOC);

        $stmtDetV = $pdo->prepare("SELECT * FROM tb_status_verifikasi WHERE cpmi_id = ? LIMIT 1");
        $stmtDetV->execute([$inspectId]);
        $detailVerifikasi = $stmtDetV->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'MENUNGGU VERIFIKASI', 'catatan' => ''];
        
        $stmtB = $pdo->prepare("
            SELECT b.file_path, b.nama_file_asli, m.nama_dokumen, m.id_dokumen, m.is_mandatory 
            FROM tb_berkas_pendaftaran b 
            JOIN tb_master_dokumen m ON b.id_dokumen = m.id_dokumen 
            WHERE b.cpmi_id = ?
            ORDER BY m.urutan ASC, m.id_dokumen ASC
        ");
        $stmtB->execute([$inspectId]);
        $listBerkas = $stmtB->fetchAll(PDO::FETCH_ASSOC);

        foreach ($listBerkas as $idx => $b) {
            $berkasListJs[] = [
                'index'     => $idx,
                'id'        => (string)$b['id_dokumen'],
                'name'      => $b['nama_dokumen'],
                'file_name' => $b['nama_file_asli'] ?? $b['file_path'],
                'url'       => '/storage/uploads/' . $b['file_path']
            ];
        }

        $initialDocReviews = [];
        $existingCatatan = $detailVerifikasi['catatan'] ?? '';
        $existingStatus = strtoupper($detailVerifikasi['status'] ?? '');

        foreach ($listBerkas as $b) {
            $docId = (string)$b['id_dokumen'];
            $docName = $b['nama_dokumen'];
            $escapedName = preg_quote($docName, '/');
            
            if (preg_match('/(?:^|\n)\s*[-*•]?\s*' . $escapedName . '\s*[:\-]\s*([^\n\r]+)/ui', $existingCatatan, $matches)) {
                $initialDocReviews[$docId] = [
                    'id' => $docId,
                    'name' => $docName,
                    'status' => 'tidak_sesuai',
                    'reason' => trim($matches[1])
                ];
            } elseif ($existingStatus === 'MEMENUHI PERSYARATAN') {
                $initialDocReviews[$docId] = [
                    'id' => $docId,
                    'name' => $docName,
                    'status' => 'sesuai',
                    'reason' => ''
                ];
            } else {
                $initialDocReviews[$docId] = [
                    'id' => $docId,
                    'name' => $docName,
                    'status' => 'belum',
                    'reason' => ''
                ];
            }
        }

        if (function_exists('catatLog')) {
            catatLog($pdo, "Membuka detail verifikasi peserta CPMI ID #{$inspectId}", 'VERIFIKASI');
        }

    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Dokumen Pendaftar — BP3MI Jawa Tengah</title>
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
        
        .zoom-wrapper {
            width: 100%; height: 100%; overflow: auto;
            background-color: #f8fafc; display: flex; justify-content: center;
            align-items: flex-start; border-radius: 0.75rem;
        }
        #previewFrame { width: 100%; height: 100%; border: none; transition: width 0.2s ease, height 0.2s ease, transform 0.2s ease; }
        #previewImage {
            width: 100%; max-width: 100%; height: auto; object-fit: contain;
            transition: width 0.2s ease, transform 0.2s ease, margin 0.2s ease; background-color: #ffffff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-[#f4f6f9] text-slate-800 min-h-screen md:h-screen flex flex-col md:flex-row overflow-x-hidden md:overflow-hidden selection:bg-[#0e74bb] selection:text-white">

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-h-0 md:h-screen overflow-y-auto relative scroll-smooth bg-[#f4f6f9]">
        <div class="p-4 sm:p-7 max-w-7xl mx-auto w-full space-y-6">
            
            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs relative overflow-hidden flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
                
                <div>
                    <div class="flex items-center gap-2 text-[11px] font-bold text-slate-400 mb-1.5">
                        <i class="fas fa-home text-[#0e74bb]"></i>
                        <span>/</span>
                        <span>Modul Pendaftaran</span>
                        <span>/</span>
                        <span class="text-slate-600">Verifikasi Dokumen</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="w-2.5 h-6 bg-[#0e74bb] rounded-full"></div>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight uppercase">VERIFIKASI DOKUMEN</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium pl-5">Verifikasi berkas syarat calon peserta peningkatan kapasitas CPMI.</p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full lg:w-auto">
                    <form method="GET" action="verifikasi.php" class="w-full sm:w-64">
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <i class="fas fa-filter text-xs"></i>
                            </div>
                            <select name="batch" onchange="this.form.submit()" class="w-full text-xs font-bold bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-lg pl-9 pr-9 py-2.5 text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] shadow-2xs appearance-none cursor-pointer transition">
                                <option value="all" <?= $selectedBatch === 'all' ? 'selected' : '' ?>>Semua Batch / Gelombang</option>
                                <?php foreach ($listBatch as $b): ?>
                                    <option value="<?= htmlspecialchars($b) ?>" <?= $selectedBatch === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-slate-400">
                                <i class="fas fa-chevron-down text-xs"></i>
                            </div>
                        </div>
                    </form>

                    <div class="inline-flex items-center gap-3 bg-slate-50 px-3.5 py-2 rounded-lg border border-slate-200 shadow-2xs shrink-0">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                        <div class="text-right">
                            <div id="liveClock" class="text-xs sm:text-sm font-bold text-[#0e74bb] font-mono leading-none">--:--:-- WIB</div>
                            <div id="liveDate" class="text-[9px] font-bold text-slate-500 uppercase tracking-wider mt-0.5 leading-none">Memuat Tanggal...</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($listGanda)): ?>
            <div class="rounded-xl border border-rose-200 bg-rose-50/70 overflow-hidden shadow-xs">
                <details class="group">
                    <summary class="p-4 cursor-pointer flex justify-between items-center text-xs font-bold text-rose-800 select-none list-none">
                        <div class="flex items-center space-x-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-pulse"></span>
                            <span>PERHATIAN: Ditemukan <?= count($listGanda) ?> kelompok pendaftar ganda!</span>
                        </div>
                        <span class="text-[10px] text-rose-600 font-semibold underline">Lihat Detail NIK</span>
                    </summary>
                    <div class="px-5 pb-5 pt-1 text-xs text-slate-700">
                        <?php foreach ($listGanda as $g): ?>
                            <div class="bg-white p-2.5 rounded-lg border border-rose-100 mb-1.5 font-mono text-xs">
                                <b class="text-rose-700">NIK: <?= htmlspecialchars($g['nik_sesuai_ktp']) ?></b> (<?= $g['jml'] ?> data) — <?= htmlspecialchars($g['daftar_nama']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
            <?php endif; ?>

            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs">
                <div class="flex items-center gap-2 mb-3.5 pb-2.5 border-b border-slate-100">
                    <div class="w-7 h-7 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                        <i class="fas fa-sliders"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Filter & Pencarian Dokumen</span>
                </div>
                <form id="filterForm" method="GET" action="verifikasi.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3.5 items-end">
                    <?php if($selectedBatch !== 'all'): ?><input type="hidden" name="batch" value="<?= htmlspecialchars($selectedBatch) ?>"><?php endif; ?>

                    <?php if ($levelAdmin !== 'lembaga'): ?>
                    <div class="w-full">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Lembaga Pelatihan</label>
                        <select name="lembaga" onchange="this.form.submit()" class="w-full h-11 text-xs font-semibold bg-white border border-slate-300 rounded-lg px-3 focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                            <option value="all">Semua Lembaga</option>
                            <?php foreach($listLembaga as $l): ?>
                                <option value="<?= htmlspecialchars($l) ?>" <?= $selectedLembaga === $l ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="w-full">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Lembaga Pelatihan</label>
                        <input type="text" disabled value="<?= htmlspecialchars($lembagaAdmin) ?>" class="w-full h-11 text-xs font-semibold bg-slate-100 border border-slate-300 rounded-lg px-3 text-slate-500 cursor-not-allowed">
                        <input type="hidden" name="lembaga" value="<?= htmlspecialchars($lembagaAdmin) ?>">
                    </div>
                    <?php endif; ?>

                    <div class="w-full">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Jenis Pelatihan</label>
                        <select name="kejuruan" onchange="this.form.submit()" class="w-full h-11 text-xs font-semibold bg-white border border-slate-300 rounded-lg px-3 focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                            <option value="all">Semua Jenis Pelatihan</option>
                            <?php foreach($listKejuruan as $k): ?>
                                <option value="<?= htmlspecialchars($k) ?>" <?= $selectedKejuruan === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="w-full">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Status Verifikasi</label>
                        <select name="status" onchange="this.form.submit()" class="w-full h-11 text-xs font-semibold bg-white border border-slate-300 rounded-lg px-3 focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                            <option value="all">Semua Status</option>
                            <option value="MEMENUHI PERSYARATAN" <?= $selectedStatus === 'MEMENUHI PERSYARATAN' ? 'selected' : '' ?>>Memenuhi Persyaratan</option>
                            <option value="TIDAK MEMENUHI PERSYARATAN" <?= $selectedStatus === 'TIDAK MEMENUHI PERSYARATAN' ? 'selected' : '' ?>>Tidak Memenuhi</option>
                            <option value="BELUM DIVERIFIKASI" <?= $selectedStatus === 'BELUM DIVERIFIKASI' ? 'selected' : '' ?>>Belum Diverifikasi</option>
                        </select>
                    </div>

                    <div class="w-full">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Pencarian Peserta</label>
                        <div class="relative">
                            <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="Nama, NIK, ID..." class="w-full h-11 text-xs font-medium bg-white border border-slate-300 rounded-lg pl-3 pr-8 focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                            <?php if (!empty($keyword)): ?>
                            <a href="verifikasi.php<?= $selectedBatch !== 'all' ? '?batch=' . urlencode($selectedBatch) : '' ?>" class="absolute right-2.5 top-3.5 text-slate-400 hover:text-slate-600 text-xs">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="w-full flex gap-2 h-11 items-center">
                        <button type="submit" class="flex-1 h-full bg-[#0e74bb] hover:bg-[#0b5e99] text-white text-xs font-bold rounded-lg transition shadow-xs flex items-center justify-center gap-1.5">
                            <i class="fas fa-search text-[11px]"></i> Cari
                        </button>
                        <a href="verifikasi.php<?= $selectedBatch !== 'all' ? '?batch=' . urlencode($selectedBatch) : '' ?>" class="px-3.5 h-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition flex items-center justify-center border border-slate-200">
                            Reset
                        </a>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4">
                <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-50/70 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-800">Daftar Pendaftar CPMI</h3>
                            <p class="text-[10px] text-slate-400 font-medium">Total <?= number_format($totalData, 0, ',', '.') ?> data peserta terdata</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-bold text-slate-600 bg-white px-2.5 py-1 rounded-md border border-slate-200 font-mono">Halaman <?= $page ?> / <?= $totalPages ?></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-100/80 text-[10px] uppercase font-bold text-slate-600 tracking-wider">
                                <th class="py-3 px-4">CPMI-ID</th>
                                <th class="py-3 px-4">NIK</th>
                                <th class="py-3 px-4">Nama Lengkap</th>
                                <th class="py-3 px-4">Status Verifikasi</th>
                                <th class="py-3 px-4">Domisili</th>
                                <th class="py-3 px-4 text-center">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php if (empty($listPeserta)): ?>
                                <tr><td colspan="6" class="text-center py-10 text-slate-400 font-medium">Tidak ada data pendaftar yang sesuai.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listPeserta as $p): ?>
                                    <tr class="hover:bg-sky-50/40 transition">
                                        <td class="py-3 px-4 font-mono font-bold text-[#0e74bb]"><?= htmlspecialchars($p['cpmi_id']) ?></td>
                                        <td class="py-3 px-4 font-mono text-slate-700"><?= htmlspecialchars($p['nik_sesuai_ktp']) ?></td>
                                        <td class="py-3 px-4 font-bold text-slate-900"><?= htmlspecialchars($p['nama_lengkap']) ?></td>
                                        <td class="py-3 px-4">
                                            <?php 
                                                $st = $p['status_verifikasi'];
                                                $stUpper = strtoupper($st);
                                                if (strpos($stUpper, 'TIDAK') !== false) {
                                                    echo '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200"><span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>' . htmlspecialchars($st) . '</span>';
                                                } elseif (strpos($stUpper, 'MEMENUHI') !== false) {
                                                    echo '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Memenuhi Syarat</span>';
                                                } else {
                                                    echo '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>Belum Diverifikasi</span>';
                                                }
                                            ?>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600"><?= htmlspecialchars($p['domisili_peserta']) ?></td>
                                        <td class="py-3 px-4 text-center">
                                            <?php 
                                                $currentQuery = $_GET;
                                                $currentQuery['inspect'] = $p['cpmi_id'];
                                                $inspectUrl = 'verifikasi.php?' . http_build_query($currentQuery);
                                            ?>
                                            <a href="<?= $inspectUrl ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-sky-50 hover:bg-[#0e74bb] hover:text-white text-[#0e74bb] rounded-lg font-bold text-[11px] transition shadow-2xs border border-sky-100">
                                                <i class="fas fa-file-circle-check text-[11px]"></i> <span>Verifikasi Detail</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="p-4 border-t border-slate-100 flex justify-center items-center space-x-1.5 mt-auto">
                    <?php 
                        $queryParams = $_GET;
                        unset($queryParams['page'], $queryParams['inspect']);
                        $queryString = http_build_query($queryParams);
                        $queryPrefix = $queryString ? '&' . $queryString : '';
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="verifikasi.php?page=1<?= $queryPrefix ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50 transition">&laquo;</a>
                    <?php endif; ?>
                    
                    <?php 
                        $startP = max(1, $page - 2); $endP = min($totalPages, $page + 2);
                        for ($i = $startP; $i <= $endP; $i++): 
                    ?>
                        <a href="verifikasi.php?page=<?= $i ?><?= $queryPrefix ?>" class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition <?= $i === $page ? 'bg-[#0e74bb] text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="verifikasi.php?page=<?= $totalPages ?><?= $queryPrefix ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50 transition">&raquo;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if (!empty($inspectId) AND $detailPeserta): ?>
    <div id="modalDetail" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
        <div class="bg-white w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-xl border border-slate-200 shadow-2xl p-6 sm:p-8 space-y-6 relative">
            
            <?php 
                $closeQuery = $_GET; unset($closeQuery['inspect']);
                $closeUrl = 'verifikasi.php?' . http_build_query($closeQuery);
            ?>
            <a href="<?= $closeUrl ?>" class="absolute top-5 right-5 w-9 h-9 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-500 rounded-lg flex items-center justify-center font-bold transition">&#10005;</a>

            <div>
                <span class="text-[10px] font-bold text-[#0e74bb] uppercase tracking-widest bg-sky-50 px-3 py-1 rounded-md border border-sky-100">Panel Verifikasi Dokumen</span>
                <h3 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight mt-2"><?= htmlspecialchars($detailPeserta['nama_lengkap']) ?></h3>
                <p class="text-xs font-mono text-slate-500 mt-0.5">ID: <?= htmlspecialchars($detailPeserta['cpmi_id']) ?> | NIK: <?= htmlspecialchars($detailPeserta['nik_sesuai_ktp']) ?></p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-200">
                    <span class="text-slate-400 font-bold block text-[9px] uppercase tracking-wider">Email</span>
                    <span class="font-semibold text-slate-800 truncate block"><?= htmlspecialchars($detailPeserta['email'] ?? '-') ?></span>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-200">
                    <span class="text-slate-400 font-bold block text-[9px] uppercase tracking-wider">No. WhatsApp</span>
                    <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars($detailPeserta['no_hp_wa'] ?? '-') ?></span>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-200">
                    <span class="text-slate-400 font-bold block text-[9px] uppercase tracking-wider">Jenis Kelamin / Usia</span>
                    <span class="font-semibold text-slate-800"><?= htmlspecialchars($detailPeserta['jenis_kelamin'] ?? '-') ?> (<?= htmlspecialchars($detailPeserta['usia'] ?? '-') ?> Thn)</span>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-200 sm:col-span-2">
                    <span class="text-slate-400 font-bold block text-[9px] uppercase tracking-wider">Alamat Sesuai KTP</span>
                    <span class="font-medium text-slate-800"><?= htmlspecialchars($detailPeserta['alamat_lengkap'] ?? '-') ?></span>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-200">
                    <span class="text-slate-400 font-bold block text-[9px] uppercase tracking-wider">Domisili / Kab</span>
                    <span class="font-semibold text-slate-800"><?= htmlspecialchars($detailPeserta['domisili'] ?? '-') ?></span>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-200">
                    <span class="text-slate-400 font-bold block text-[9px] uppercase tracking-wider">Pendidikan</span>
                    <span class="font-semibold text-slate-800"><?= htmlspecialchars($detailPeserta['pendidikan'] ?? '-') ?></span>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-200">
                    <span class="text-slate-400 font-bold block text-[9px] uppercase tracking-wider">Program / Batch</span>
                    <span class="font-bold text-[#0e74bb]"><?= htmlspecialchars($detailPeserta['batch_pelatihan'] ?? '-') ?></span>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-200 sm:col-span-3">
                    <span class="text-slate-400 font-bold block text-[9px] uppercase tracking-wider">Minat Pelatihan & Lembaga</span>
                    <span class="font-bold text-slate-900"><?= htmlspecialchars($detailPeserta['ref_pelatihan_dimin'] ?? '-') ?> &mdash; <span class="text-[#0e74bb]">(<?= htmlspecialchars($detailPeserta['lembaga_pelatihan'] ?? '-') ?>)</span></span>
                </div>
            </div>

            <div class="mt-5">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Berkas Persyaratan Unggahan Peserta</h4>
                    <span class="text-[10px] font-bold text-slate-400">Total <?= count($listBerkas) ?> berkas terunggah</span>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <?php if(empty($listBerkas)): ?>
                        <div class="p-4 bg-rose-50 text-rose-600 text-xs font-bold rounded-lg border border-rose-200 col-span-2">Tidak ada berkas yang diunggah.</div>
                    <?php else: ?>
                        <?php foreach($listBerkas as $b): 
                            $docId = (string)$b['id_dokumen'];
                        ?>
                            <div class="p-3.5 bg-white border border-slate-200 hover:border-slate-300 rounded-xl flex flex-col justify-between transition shadow-2xs" id="card_doc_<?= $docId ?>">
                                <div>
                                    <div class="flex items-start justify-between gap-2 mb-1.5">
                                        <div class="flex-1 min-w-0">
                                            <span class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider leading-tight truncate">
                                                <?= htmlspecialchars($b['nama_dokumen']) ?>
                                            </span>
                                        </div>
                                        <div id="badge_doc_<?= $docId ?>" class="shrink-0"></div>
                                    </div>
                                    
                                    <div class="text-xs font-semibold text-slate-700 truncate mb-2.5 flex items-center gap-1.5">
                                        <i class="fas fa-file-lines text-slate-400 text-xs shrink-0"></i>
                                        <span class="truncate"><?= htmlspecialchars($b['nama_file_asli'] ?? $b['file_path']) ?></span>
                                    </div>
                                    
                                    <div id="reason_box_doc_<?= $docId ?>" class="hidden mb-2.5 p-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-800">
                                        <div class="flex items-start gap-1.5">
                                            <i class="fas fa-circle-exclamation text-rose-500 mt-0.5 shrink-0 text-[10px]"></i>
                                            <div class="flex-1 min-w-0">
                                                <span class="text-[9px] font-bold uppercase tracking-wider text-rose-600 block mb-0.5">Alasan Tidak Sesuai:</span>
                                                <span id="reason_text_doc_<?= $docId ?>" class="text-[11px] font-medium leading-tight block"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="inline_input_doc_<?= $docId ?>" class="hidden mb-2.5">
                                        <input type="text" 
                                               id="input_reason_doc_<?= $docId ?>" 
                                               oninput="updateDocReason('<?= $docId ?>', this.value)"
                                               placeholder="Tulis alasan tidak sesuai..." 
                                               class="w-full h-8 px-2.5 text-xs bg-rose-50/50 border border-rose-300 rounded-md focus:outline-none focus:ring-1 focus:ring-rose-500 text-rose-900 placeholder:text-rose-300 font-medium">
                                    </div>
                                </div>
                                
                                <div class="pt-2.5 border-t border-slate-100 flex items-center justify-between gap-2 mt-auto">
                                    <button type="button" 
                                            onclick="bukaPreviewDokumen('/storage/uploads/<?= htmlspecialchars($b['file_path']) ?>', '<?= htmlspecialchars(addslashes($b['nama_dokumen'])) ?>', '<?= $docId ?>')" 
                                            class="px-2.5 py-1.5 rounded-lg bg-sky-50 hover:bg-[#0e74bb] text-[#0e74bb] hover:text-white font-bold text-xs transition flex items-center gap-1.5 border border-sky-100 shadow-2xs">
                                        <i class="fas fa-eye text-[11px]"></i>
                                        <span>Preview</span>
                                    </button>
                                    
                                    <div class="flex items-center gap-1">
                                        <button type="button" 
                                                id="btn_card_sesuai_<?= $docId ?>"
                                                onclick="setDocStatus('<?= $docId ?>', 'sesuai')" 
                                                class="px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 border border-slate-200 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 hover:border-emerald-300 shadow-2xs" 
                                                title="Tandai Sesuai">
                                            <i class="fas fa-check text-[10px]"></i>
                                            <span>Sesuai</span>
                                        </button>
                                        <button type="button" 
                                                id="btn_card_tidak_<?= $docId ?>"
                                                onclick="toggleCardReasonInput('<?= $docId ?>')" 
                                                class="px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 border border-slate-200 text-slate-600 hover:bg-rose-50 hover:text-rose-700 hover:border-rose-300 shadow-2xs" 
                                                title="Tandai Tidak Sesuai">
                                            <i class="fas fa-times text-[10px]"></i>
                                            <span>Tidak Sesuai</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <form id="formVerifikasi" method="POST" action="verifikasi.php" class="bg-slate-50 p-5 rounded-xl border border-slate-200 space-y-4">
                <input type="hidden" name="action" value="simpan_verifikasi">
                <input type="hidden" name="cpmi_id" value="<?= htmlspecialchars($detailPeserta['cpmi_id']) ?>">

                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-800">Keputusan Hasil Verifikasi Berkas</h4>
                
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label class="flex items-center space-x-3 p-3 rounded-lg border border-slate-200 bg-white cursor-pointer hover:border-emerald-400 transition">
                        <input type="radio" name="status" value="MEMENUHI PERSYARATAN" <?= strtoupper($detailVerifikasi['status']) === 'MEMENUHI PERSYARATAN' ? 'checked' : '' ?> required class="text-emerald-600">
                        <span class="text-xs font-bold text-emerald-700">Memenuhi Syarat</span>
                    </label>
                    <label class="flex items-center space-x-3 p-3 rounded-lg border border-slate-200 bg-white cursor-pointer hover:border-rose-400 transition">
                        <input type="radio" name="status" value="TIDAK MEMENUHI PERSYARATAN" <?= strtoupper($detailVerifikasi['status']) === 'TIDAK MEMENUHI PERSYARATAN' ? 'checked' : '' ?> required class="text-rose-600">
                        <span class="text-xs font-bold text-rose-700">Tidak Memenuhi</span>
                    </label>
                    <label class="flex items-center space-x-3 p-3 rounded-lg border border-slate-200 bg-white cursor-pointer hover:border-amber-400 transition">
                        <input type="radio" name="status" value="MENUNGGU VERIFIKASI" <?= in_array(strtoupper($detailVerifikasi['status']), ['MENUNGGU VERIFIKASI', 'BELUM DIVERIFIKASI']) ? 'checked' : '' ?> required class="text-amber-600">
                        <span class="text-xs font-bold text-amber-700">Belum Diverifikasi</span>
                    </label>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider">Catatan / Alasan (Otomatis terisi jika ada berkas tidak sesuai):</label>
                        <span class="text-[9px] font-bold text-slate-400 italic">Dapat ditambahkan catatan mandiri verifikator</span>
                    </div>
                    <textarea name="catatan" id="catatanVerifikasi" rows="3" placeholder="Tuliskan alasan penolakan atau catatan perbaikan berkas..." class="w-full text-xs bg-white border border-slate-300 rounded-lg p-3 text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] font-sans"><?= htmlspecialchars($detailVerifikasi['catatan'] ?? '') ?></textarea>
                </div>

                <div class="flex justify-end space-x-3 pt-2">
                    <a href="<?= $closeUrl ?>" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold text-xs rounded-lg hover:bg-slate-50 transition">Batal</a>
                    <button type="submit" id="btnSimpan" class="px-5 py-2.5 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold text-xs rounded-lg transition shadow-xs">Simpan Verifikasi</button>
                </div>
            </form>

        </div>
    </div>
    
    <div id="modalPreviewDokumen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-xs hidden">
        <div class="bg-white w-full max-w-5xl h-[85vh] flex flex-col rounded-xl border border-slate-200 shadow-2xl relative overflow-hidden">
            
            <div class="p-3.5 sm:p-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 shrink-0">
                <div class="flex items-center gap-2.5 min-w-0 mr-3">
                    <span class="w-2 h-2 rounded-full bg-[#0e74bb]"></span>
                    <h3 id="previewTitle" class="font-bold text-slate-800 text-xs sm:text-sm uppercase tracking-wider truncate">Preview Dokumen</h3>
                    <span id="previewDocCounter" class="text-[10px] font-bold font-mono px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 shrink-0">1 / 1</span>
                </div>
                
                <div class="flex space-x-1.5 sm:space-x-2 shrink-0 items-center">
                    <div class="flex items-center border border-slate-300 rounded-lg overflow-hidden bg-white shadow-2xs mr-1">
                        <button type="button" id="btnHeaderPrevDoc" onclick="navigasiDokumen(-1)" class="px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] transition disabled:opacity-30 disabled:pointer-events-none" title="Dokumen Sebelumnya (Arrow Left)">
                            <i class="fas fa-chevron-left text-[11px]"></i>
                        </button>
                        <div class="w-px h-5 bg-slate-200"></div>
                        <button type="button" id="btnHeaderNextDoc" onclick="navigasiDokumen(1)" class="px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] transition disabled:opacity-30 disabled:pointer-events-none" title="Dokumen Selanjutnya (Arrow Right)">
                            <i class="fas fa-chevron-right text-[11px]"></i>
                        </button>
                    </div>
                    <div class="flex items-center border border-slate-300 rounded-lg overflow-hidden bg-white shadow-2xs mr-1">
                        <button type="button" onclick="rotateDoc(-90)" class="px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] transition flex items-center gap-1" title="Putar 90° Kiri (Counter-Clockwise)">
                            <i class="fas fa-rotate-left text-[11px]"></i>
                        </button>
                        <div class="w-px h-5 bg-slate-200"></div>
                        <button type="button" onclick="rotateDoc(90)" class="px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] transition flex items-center gap-1" title="Putar 90° Kanan / Shortcut 'R' (Clockwise)">
                            <i class="fas fa-rotate-right text-[11px]"></i>
                        </button>
                    </div>
                    <button onclick="zoomOut()" class="px-2 sm:px-2.5 py-1.5 bg-white border border-slate-300 text-slate-600 text-xs font-bold rounded-lg hover:bg-slate-50 transition shadow-2xs">- Zoom</button>
                    <button onclick="resetZoom()" class="px-2 sm:px-2.5 py-1.5 bg-white border border-slate-300 text-slate-600 text-xs font-bold rounded-lg hover:bg-slate-50 transition shadow-2xs">Reset</button>
                    <button onclick="zoomIn()" class="px-2 sm:px-2.5 py-1.5 bg-white border border-slate-300 text-slate-600 text-xs font-bold rounded-lg hover:bg-slate-50 transition shadow-2xs">+ Zoom</button>
                    <div class="w-px h-6 bg-slate-300 mx-0.5 sm:mx-1"></div>
                    <button type="button" onclick="tutupPreviewDokumen()" class="w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-600 rounded-lg flex items-center justify-center font-bold transition text-xs">&#10005;</button>
                </div>
            </div>

            <div class="bg-gradient-to-r from-slate-50 via-white to-slate-50 px-4 py-3 border-b border-slate-200 flex flex-col md:flex-row md:items-center justify-between gap-3 shrink-0 shadow-2xs">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <span class="text-[10px] font-extrabold text-slate-500 uppercase tracking-wider">Verifikasi Berkas Ini:</span>
                    <div class="inline-flex rounded-lg p-0.5 bg-slate-100 border border-slate-200 gap-1 shadow-2xs">
                        <button type="button" id="btnPreviewSesuai" onclick="setDocStatusFromPreview('sesuai')" 
                                class="px-3 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 text-slate-600 hover:text-emerald-700">
                            <i class="fas fa-check-circle text-xs"></i>
                            <span>Sesuai</span>
                        </button>
                        <button type="button" id="btnPreviewTidakSesuai" onclick="setDocStatusFromPreview('tidak_sesuai')" 
                                class="px-3 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 text-slate-600 hover:text-rose-700">
                            <i class="fas fa-times-circle text-xs"></i>
                            <span>Tidak Sesuai</span>
                        </button>
                    </div>

                    <div class="flex items-center gap-1.5 sm:ml-2">
                        <button type="button" id="btnActionPrev" onclick="navigasiDokumen(-1)" 
                                class="px-2.5 py-1.5 rounded-lg text-xs font-bold bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 shadow-2xs" title="Dokumen Sebelumnya (Arrow Left)">
                            <i class="fas fa-chevron-left text-[10px]"></i>
                            <span class="hidden sm:inline">Prev</span>
                        </button>
                        <button type="button" id="btnActionNext" onclick="navigasiDokumen(1)" 
                                class="px-3 py-1.5 rounded-lg text-xs font-bold bg-[#0e74bb] hover:bg-[#0b5e99] text-white disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1.5 shadow-2xs" title="Dokumen Selanjutnya (Arrow Right)">
                            <span>Next Dokumen</span>
                            <i class="fas fa-chevron-right text-[10px]"></i>
                        </button>
                    </div>
                </div>
                
                <div id="previewAlasanContainer" class="hidden flex-1 md:max-w-md flex flex-col gap-1.5">
                    <div class="flex items-center gap-1.5">
                        <input type="text" id="previewAlasanInput" oninput="updateDocReasonFromPreview(this.value)" 
                               placeholder="Tulis alasan tidak sesuai..." 
                               class="w-full h-8 px-3 text-xs bg-rose-50/70 border border-rose-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 text-rose-900 font-medium placeholder:text-rose-300 shadow-2xs">
                    </div>
                    <div class="flex flex-wrap gap-1 items-center">
                        <span class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Alasan:</span>
                        <button type="button" onclick="setQuickReason('Tidak Ada Tanda Tangan')" class="text-[9px] px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-600 transition border border-slate-200">Tidak Ada Tanda Tangan</button>
                        <button type="button" onclick="setQuickReason('Format Tidak Sesuai')" class="text-[9px] px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-600 transition border border-slate-200">Format Tidak Sesuai</button>
                        <button type="button" onclick="setQuickReason('Tidak Ada Meterai')" class="text-[9px] px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-600 transition border border-slate-200">Tidak Ada Meterai</button>
                        <button type="button" onclick="setQuickReason('Dokumen Salah')" class="text-[9px] px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-600 transition border border-slate-200">Dokumen Salah</button>
                    </div>
                </div>
            </div>
            
            <div class="flex-1 w-full h-full p-2 sm:p-4 overflow-hidden bg-slate-100">
                <div class="zoom-wrapper border border-slate-200 rounded-lg shadow-inner bg-white">
                    <iframe id="previewFrame" src="" frameborder="0" class="hidden"></iframe>
                    <img id="previewImage" src="" alt="Dokumen Preview" class="hidden">
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        function updateClock() {
            const now = new Date();
            const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            
            let hh = now.getHours().toString().padStart(2, '0'), mm = now.getMinutes().toString().padStart(2, '0'), ss = now.getSeconds().toString().padStart(2, '0');
            const elClock = document.getElementById('liveClock');
            if (elClock) elClock.textContent = `${hh}:${mm}:${ss} WIB`;
            
            const elDate = document.getElementById('liveDate');
            if (elDate) elDate.textContent = `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
        }
        setInterval(updateClock, 1000); updateClock();

        let currentZoom = 1;
        let currentRotation = 0;
        const frameEl = document.getElementById('previewFrame');
        const imgEl = document.getElementById('previewImage');

        let docReviews = <?= json_encode($initialDocReviews ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || {};
        const daftarBerkasPeserta = <?= json_encode($berkasListJs ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let currentActiveDocId = null;
        let currentActiveDocName = '';
        let currentDocIndex = -1;

        function renderAllCardsUI() {
            for (const docId in docReviews) {
                updateCardUI(docId);
            }
        }

        function updateCardUI(docId) {
            const review = docReviews[docId];
            if (!review) return;

            const badgeEl = document.getElementById('badge_doc_' + docId);
            const reasonBoxEl = document.getElementById('reason_box_doc_' + docId);
            const reasonTextEl = document.getElementById('reason_text_doc_' + docId);
            const btnSesuai = document.getElementById('btn_card_sesuai_' + docId);
            const btnTidak = document.getElementById('btn_card_tidak_' + docId);
            const inlineInputEl = document.getElementById('inline_input_doc_' + docId);
            const inputReasonEl = document.getElementById('input_reason_doc_' + docId);

            if (inputReasonEl && review.reason !== undefined && document.activeElement !== inputReasonEl) {
                inputReasonEl.value = review.reason || '';
            }

            if (review.status === 'sesuai') {
                if (badgeEl) badgeEl.innerHTML = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"><i class="fas fa-check text-[9px]"></i> Sesuai</span>';
                if (reasonBoxEl) reasonBoxEl.classList.add('hidden');
                if (inlineInputEl) inlineInputEl.classList.add('hidden');

                if (btnSesuai) {
                    btnSesuai.className = 'px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 bg-emerald-600 text-white border border-emerald-600 shadow-2xs';
                }
                if (btnTidak) {
                    btnTidak.className = 'px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 border border-slate-200 text-slate-600 hover:bg-rose-50 hover:text-rose-700 hover:border-rose-300 shadow-2xs';
                }
            } else if (review.status === 'tidak_sesuai') {
                if (badgeEl) badgeEl.innerHTML = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200"><i class="fas fa-times text-[9px]"></i> Tidak Sesuai</span>';
                if (reasonBoxEl) {
                    reasonBoxEl.classList.remove('hidden');
                    if (reasonTextEl) {
                        reasonTextEl.textContent = review.reason && review.reason.trim() !== '' ? review.reason : 'Alasan belum diisi';
                    }
                }
                if (btnSesuai) {
                    btnSesuai.className = 'px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 border border-slate-200 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 hover:border-emerald-300 shadow-2xs';
                }
                if (btnTidak) {
                    btnTidak.className = 'px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 bg-rose-600 text-white border border-rose-600 shadow-2xs';
                }
            } else {
                if (badgeEl) badgeEl.innerHTML = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-500 border border-slate-200"><i class="fas fa-clock text-[9px]"></i> Belum Diperiksa</span>';
                if (reasonBoxEl) reasonBoxEl.classList.add('hidden');
                if (inlineInputEl) inlineInputEl.classList.add('hidden');

                if (btnSesuai) {
                    btnSesuai.className = 'px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 border border-slate-200 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 hover:border-emerald-300 shadow-2xs';
                }
                if (btnTidak) {
                    btnTidak.className = 'px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 border border-slate-200 text-slate-600 hover:bg-rose-50 hover:text-rose-700 hover:border-rose-300 shadow-2xs';
                }
            }
        }

        function toggleCardReasonInput(docId) {
            const inlineInputEl = document.getElementById('inline_input_doc_' + docId);
            const inputReasonEl = document.getElementById('input_reason_doc_' + docId);
            
            if (!docReviews[docId]) {
                docReviews[docId] = { id: docId, name: '', status: 'tidak_sesuai', reason: '' };
            }

            if (docReviews[docId].status === 'tidak_sesuai' && inlineInputEl && !inlineInputEl.classList.contains('hidden')) {
                inlineInputEl.classList.add('hidden');
                return;
            }

            docReviews[docId].status = 'tidak_sesuai';
            if (inlineInputEl) inlineInputEl.classList.remove('hidden');
            if (inputReasonEl) inputReasonEl.focus();

            updateCardUI(docId);
            sinkronkanCatatan();
        }

        function setDocStatus(docId, status) {
            if (!docReviews[docId]) {
                docReviews[docId] = { id: docId, name: '', status: status, reason: '' };
            }
            docReviews[docId].status = status;
            if (status === 'sesuai') {
                docReviews[docId].reason = '';
            }
            updateCardUI(docId);
            sinkronkanCatatan();
        }

        function updateDocReason(docId, val) {
            if (!docReviews[docId]) {
                docReviews[docId] = { id: docId, name: '', status: 'tidak_sesuai', reason: '' };
            }
            docReviews[docId].reason = val;
            updateCardUI(docId);
            sinkronkanCatatan();
        }

        function updatePreviewBarUI(review) {
            const btnS = document.getElementById('btnPreviewSesuai');
            const btnT = document.getElementById('btnPreviewTidakSesuai');
            const alasanContainer = document.getElementById('previewAlasanContainer');
            const alasanInput = document.getElementById('previewAlasanInput');

            if (!btnS || !btnT) return;

            if (review.status === 'sesuai') {
                btnS.className = 'px-3 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 bg-emerald-600 text-white border border-emerald-600 shadow-2xs';
                btnT.className = 'px-3 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 bg-white text-slate-600 hover:text-rose-700 border border-transparent';
                if (alasanContainer) alasanContainer.classList.add('hidden');
            } else if (review.status === 'tidak_sesuai') {
                btnS.className = 'px-3 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 bg-white text-slate-600 hover:text-emerald-700 border border-transparent';
                btnT.className = 'px-3 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 bg-rose-600 text-white border border-rose-600 shadow-2xs';
                if (alasanContainer) {
                    alasanContainer.classList.remove('hidden');
                    if (alasanInput) {
                        alasanInput.value = review.reason || '';
                        alasanInput.focus();
                    }
                }
            } else {
                btnS.className = 'px-3 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 bg-white text-slate-600 hover:text-emerald-700 border border-transparent';
                btnT.className = 'px-3 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 bg-white text-slate-600 hover:text-rose-700 border border-transparent';
                if (alasanContainer) alasanContainer.classList.add('hidden');
            }
        }

        function setDocStatusFromPreview(status) {
            if (!currentActiveDocId) return;
            if (!docReviews[currentActiveDocId]) {
                docReviews[currentActiveDocId] = { id: currentActiveDocId, name: currentActiveDocName, status: status, reason: '' };
            }
            docReviews[currentActiveDocId].status = status;
            if (status === 'sesuai') {
                docReviews[currentActiveDocId].reason = '';
            }
            updatePreviewBarUI(docReviews[currentActiveDocId]);
            updateCardUI(currentActiveDocId);
            sinkronkanCatatan();
        }

        function updateDocReasonFromPreview(val) {
            if (!currentActiveDocId) return;
            if (!docReviews[currentActiveDocId]) {
                docReviews[currentActiveDocId] = { id: currentActiveDocId, name: currentActiveDocName, status: 'tidak_sesuai', reason: '' };
            }
            docReviews[currentActiveDocId].reason = val;
            updateCardUI(currentActiveDocId);
            sinkronkanCatatan();
        }

        function setQuickReason(val) {
            const input = document.getElementById('previewAlasanInput');
            if (input) {
                input.value = val;
            }
            updateDocReasonFromPreview(val);
        }

        function sinkronkanCatatan() {
            const textarea = document.getElementById('catatanVerifikasi');
            if (!textarea) return;

            const nonCompliant = [];
            let allCompliant = true;
            let anyChecked = false;

            for (const docId in docReviews) {
                const item = docReviews[docId];
                if (item.status === 'tidak_sesuai') {
                    nonCompliant.push(item);
                    allCompliant = false;
                    anyChecked = true;
                } else if (item.status === 'sesuai') {
                    anyChecked = true;
                } else {
                    allCompliant = false;
                }
            }

            let currentVal = textarea.value.trim();
            let customNote = '';

            const autoBlockRegex = /(?:\[?DOKUMEN TIDAK SESUAI\]?:?[\s\S]*?)(?:(?:\r?\n\s*\r?\n(?:\[?CATATAN VERIFIKATOR\]?:?|Catatan Tambahan:?|Catatan Verifikator:?)\s*)|$)/i;
            const match = currentVal.match(autoBlockRegex);
            if (match) {
                customNote = currentVal.substring(match[0].length).trim();
            } else {
                customNote = currentVal;
            }

            customNote = customNote.replace(/^(?:\[?CATATAN VERIFIKATOR\]?:?|Catatan Tambahan:?|Catatan Verifikator:?)\s*/i, '').trim();

            let newText = '';
            if (nonCompliant.length > 0) {
                newText += 'Dokumen Tidak Sesuai:\n';
                nonCompliant.forEach(doc => {
                    const r = doc.reason && doc.reason.trim() !== '' ? doc.reason.trim() : 'Perlu perbaikan';
                    newText += '• ' + doc.name + ': ' + r + '\n';
                });

                if (customNote) {
                    newText += '\nCatatan Verifikator:\n' + customNote;
                }

                const radioTidak = document.querySelector('input[name="status"][value="TIDAK MEMENUHI PERSYARATAN"]');
                if (radioTidak) radioTidak.checked = true;

            } else {
                newText = customNote;
                if (anyChecked && allCompliant) {
                    const radioMemenuhi = document.querySelector('input[name="status"][value="MEMENUHI PERSYARATAN"]');
                    if (radioMemenuhi) radioMemenuhi.checked = true;
                }
            }

            textarea.value = newText;
        }

        function updateNavigasiDokumenUI() {
            const total = daftarBerkasPeserta ? daftarBerkasPeserta.length : 0;
            const counterEl = document.getElementById('previewDocCounter');
            const btnHeaderPrev = document.getElementById('btnHeaderPrevDoc');
            const btnHeaderNext = document.getElementById('btnHeaderNextDoc');
            const btnActionPrev = document.getElementById('btnActionPrev');
            const btnActionNext = document.getElementById('btnActionNext');

            if (total === 0) {
                if (counterEl) counterEl.textContent = '0 / 0';
                if (btnHeaderPrev) btnHeaderPrev.disabled = true;
                if (btnHeaderNext) btnHeaderNext.disabled = true;
                if (btnActionPrev) btnActionPrev.disabled = true;
                if (btnActionNext) btnActionNext.disabled = true;
                return;
            }

            const currentDisplay = currentDocIndex >= 0 ? (currentDocIndex + 1) : 1;
            if (counterEl) counterEl.textContent = `${currentDisplay} / ${total}`;

            const isFirst = currentDocIndex <= 0;
            const isLast = currentDocIndex >= total - 1;

            if (btnHeaderPrev) btnHeaderPrev.disabled = isFirst;
            if (btnActionPrev) btnActionPrev.disabled = isFirst;

            if (btnHeaderNext) btnHeaderNext.disabled = isLast;
            if (btnActionNext) btnActionNext.disabled = isLast;
        }

        function navigasiDokumen(arah) {
            if (!daftarBerkasPeserta || daftarBerkasPeserta.length === 0) return;
            const targetIdx = currentDocIndex + arah;
            if (targetIdx >= 0 && targetIdx < daftarBerkasPeserta.length) {
                const item = daftarBerkasPeserta[targetIdx];
                bukaPreviewDokumen(item.url, item.name, item.id);
            }
        }

        function bukaPreviewDokumen(url, title, docId) {
            currentActiveDocId = docId || null;
            currentActiveDocName = title || '';
            document.getElementById('previewTitle').textContent = title;

            if (daftarBerkasPeserta && daftarBerkasPeserta.length > 0) {
                const foundIdx = daftarBerkasPeserta.findIndex(function(item) {
                    return String(item.id) === String(docId);
                });
                if (foundIdx !== -1) {
                    currentDocIndex = foundIdx;
                }
            }
            updateNavigasiDokumenUI();

            const isImage = url.match(/\.(jpeg|jpg|png|gif)$/i);
            
            if (isImage) {
                frameEl.classList.add('hidden');
                frameEl.src = '';
                imgEl.classList.remove('hidden');
                imgEl.onload = function() { applyZoom(); };
                imgEl.src = url;
            } else {
                imgEl.classList.add('hidden');
                imgEl.src = '';
                frameEl.classList.remove('hidden');
                frameEl.src = url;
            }
            
            if (docId && docReviews[docId]) {
                updatePreviewBarUI(docReviews[docId]);
            } else if (docId) {
                docReviews[docId] = { id: docId, name: title, status: 'belum', reason: '' };
                updatePreviewBarUI(docReviews[docId]);
            }
            
            resetZoom();
            document.getElementById('modalPreviewDokumen').classList.remove('hidden');
        }

        function tutupPreviewDokumen() {
            document.getElementById('modalPreviewDokumen').classList.add('hidden');
            frameEl.src = ''; 
            imgEl.src = '';
            imgEl.style.transform = 'none';
            frameEl.style.transform = 'none';
            imgEl.style.marginTop = '0px';
            imgEl.style.marginBottom = '0px';
            imgEl.style.marginLeft = '0px';
            imgEl.style.marginRight = '0px';
            currentActiveDocId = null;
            currentActiveDocName = '';
            currentDocIndex = -1;
            currentRotation = 0;
            currentZoom = 1;
        }

        function rotateDoc(degrees) {
            currentRotation = (currentRotation + degrees) % 360;
            if (currentRotation < 0) currentRotation += 360;
            applyZoom();
        }

        function zoomIn() {
            currentZoom += 0.25;
            applyZoom();
        }

        function zoomOut() {
            if (currentZoom > 0.5) {
                currentZoom -= 0.25;
                applyZoom();
            }
        }

        function resetZoom() {
            currentZoom = 1;
            currentRotation = 0;
            applyZoom();
        }

        function applyZoom() {
            let percent = currentZoom * 100;
            const isRotatedSideways = (currentRotation % 180 !== 0);

            if (!frameEl.classList.contains('hidden')) {
                frameEl.style.transform = `rotate(${currentRotation}deg)`;
                frameEl.style.transformOrigin = 'center center';
                if (isRotatedSideways) {
                    const wrapper = document.querySelector('.zoom-wrapper');
                    const w = wrapper ? wrapper.clientWidth : 800;
                    const h = wrapper ? wrapper.clientHeight : 600;
                    frameEl.style.width = `${(h * currentZoom)}px`;
                    frameEl.style.height = `${(w * currentZoom)}px`;
                } else {
                    frameEl.style.width = `${percent}%`;
                    frameEl.style.height = `${percent}%`;
                }
            }

            if (!imgEl.classList.contains('hidden')) {
                imgEl.style.width = `${percent}%`;
                if (currentZoom > 1) {
                    imgEl.style.maxWidth = 'none';
                } else {
                    imgEl.style.maxWidth = isRotatedSideways ? 'none' : '100%';
                }
                imgEl.style.transform = `rotate(${currentRotation}deg)`;
                imgEl.style.transformOrigin = 'center center';

                if (isRotatedSideways) {
                    const imgWidth = imgEl.offsetWidth;
                    const imgHeight = imgEl.offsetHeight;
                    const marginY = Math.max(0, (imgWidth - imgHeight) / 2);
                    const marginX = Math.max(0, (imgHeight - imgWidth) / 2);
                    imgEl.style.marginTop = `${marginY}px`;
                    imgEl.style.marginBottom = `${marginY}px`;
                    imgEl.style.marginLeft = `${marginX}px`;
                    imgEl.style.marginRight = `${marginX}px`;
                } else {
                    imgEl.style.marginTop = '0px';
                    imgEl.style.marginBottom = '0px';
                    imgEl.style.marginLeft = '0px';
                    imgEl.style.marginRight = '0px';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            renderAllCardsUI();
        });

        const formVerif = document.getElementById('formVerifikasi');
        if (formVerif) {
            formVerif.addEventListener('submit', async function(e) {
                e.preventDefault();
                const targetUrl = this.getAttribute('action');
                document.getElementById('modalDetail').classList.add('hidden');
                
                const formData = new FormData(this);
                formData.append('ajax', '1');

                try {
                    const res = await fetch(targetUrl, { method: 'POST', body: formData });
                    const textData = await res.text();
                    
                    try {
                        const data = JSON.parse(textData);
                        if (data.status === 'success') {
                            let currentUrl = new URL(window.location.href);
                            currentUrl.searchParams.delete('inspect');
                            window.location.href = currentUrl.toString();
                        } else {
                            alert(data.pesan);
                            document.getElementById('modalDetail').classList.remove('hidden');
                        }
                    } catch(jsonErr) {
                        alert("Galat komunikasi dengan peladen. Terjadi kesalahan internal.");
                        document.getElementById('modalDetail').classList.remove('hidden');
                    }
                } catch(err) {
                    alert('Gagal mengirim data ke server. Periksa koneksi internet Anda.');
                    document.getElementById('modalDetail').classList.remove('hidden');
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            const modal = document.getElementById('modalPreviewDokumen');
            if (!modal || modal.classList.contains('hidden')) return;

            if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
                return;
            }

            if (e.key === 'ArrowRight') {
                e.preventDefault();
                navigasiDokumen(1);
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                navigasiDokumen(-1);
            } else if (e.key === 'r' || e.key === 'R') {
                e.preventDefault();
                rotateDoc(90);
            } else if (e.key === 'Escape') {
                tutupPreviewDokumen();
            }
        });
    </script>
</body>
</html>