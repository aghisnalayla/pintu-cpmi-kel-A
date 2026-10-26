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
$levelAdmin = strtolower($_SESSION['admin_level'] ?? 'user');

if ($levelAdmin !== 'superadmin') {
    die('
    <div style="font-family: sans-serif; text-align: center; padding: 60px; background-color: #f8fafc; height: 100vh;">
        <h1 style="color: #ef4444; font-size: 2.5rem; margin-bottom: 10px;">🛑 AKSES DITOLAK</h1>
        <h3 style="color: #334155;">Halaman Audit Log ini dikhususkan mutlak untuk <b>Super Admin</b>.</h3>
        <p style="color: #64748b; max-width: 450px; margin: 20px auto; line-height: 1.6;">
            Level akun Anda saat ini adalah <b style="text-transform:uppercase; color:#0f172a;">' . htmlspecialchars($levelAdmin) . '</b>. Anda tidak memiliki izin untuk melihat riwayat audit transaksi sistem.
        </p>
        <a href="index.php" style="display: inline-block; padding: 12px 24px; background: #4f46e5; color: white; text-decoration: none; border-radius: 12px; font-weight: bold; font-size: 12px; text-transform: uppercase;">Kembali ke Dashboard</a>
    </div>
    ');
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

$keyword   = trim($_GET['q'] ?? '');$filterKat = trim($_GET['kategori'] ?? '');$sortBy    = trim($_GET['sort_by'] ?? 'terbaru');$halaman   = max(1, (int)($_GET['page'] ?? 1));$limit     = 30;
$offset    = ($halaman - 1) *$limit;

$listLog   = [];$totalData = 0;
$listKategori = [];

try {
    $listKategori =$pdo->query("SELECT DISTINCT kategori FROM tb_log WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori ASC")->fetchAll(PDO::FETCH_COLUMN);

    $whereClauses = ["1=1"];
    $params = [];

    if (!empty($keyword)) {$whereClauses[] = "(aktivitas LIKE ? OR actor LIKE ? OR ip_address LIKE ?)";
        $kw = "\%{$keyword}%";
        array_push($params,$kw, $kw,$kw);
    }

    if (!empty($filterKat)) {$whereClauses[] = "kategori = ?";
        $params[] =$filterKat;
    }

    $whereString = implode(" AND ", $whereClauses);

    $orderQuery = "ORDER BY id DESC";
    if ($sortBy === 'terlama') {$orderQuery = "ORDER BY id ASC";
    } elseif ($sortBy === 'aktor_asc') {$orderQuery = "ORDER BY actor ASC, id DESC";
    } elseif ($sortBy === 'aktor_desc') {$orderQuery = "ORDER BY actor DESC, id DESC";
    } elseif ($sortBy === 'kategori') {$orderQuery = "ORDER BY kategori ASC, id DESC";
    }

    $stmtC =$pdo->prepare("SELECT COUNT(*) FROM tb_log WHERE {$whereString}");
    $stmtC->execute($params);$totalData = (int)$stmtC->fetchColumn();$totalHalaman = max(1, ceil($totalData / $limit));

    $sqlData = "SELECT * FROM tb_log WHERE {$whereString} {$orderQuery} LIMIT {$limit} OFFSET {$offset}";
    $stmtD = $pdo->prepare($sqlData);
    $stmtD->execute($params);
    $listLog =$stmtD->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $errorMessage =$e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log Transaksi & Aktivitas — BP3MI Jawa Tengah</title>
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
                    <span class="inline-block px-2.5 py-0.5 rounded-full text-[9px] font-extrabold uppercase tracking-widest bg-blue-50 text-[#0e74bb] border border-blue-200/60 mb-2">Keamanan & Audit Sistem</span>
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-[#0e74bb] border border-blue-100 shadow-2xs text-sm">
                            <i class="fas fa-history"></i>
                        </span>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight uppercase">Audit Log Transaksi</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Rekam jejak komprehensif seluruh aktivitas verifikasi, modifikasi data, dan ujian.</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right bg-slate-50 px-4 py-2.5 rounded-xl border border-slate-200 shadow-2xs">
                        <div id="liveClock" class="text-sm font-bold text-slate-900 font-mono">--:--:-- WIB</div>
                        <div id="liveDate" class="text-[9px] font-bold text-[#0e74bb] uppercase tracking-widest mt-0.5">Memuat Tanggal...</div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-5 md:p-6 rounded-xl border border-slate-200 shadow-xs">
                <form method="GET" action="log.php" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                        <div class="md:col-span-5">
                            <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Pencarian Aktivitas</label>
                            <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="Cari aktivitas, aktor, atau IP..." class="w-full h-[42px] text-xs font-medium bg-white border border-slate-200 rounded-lg px-3.5 focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Filter Modul</label>
                            <select name="kategori" class="w-full h-[42px] text-xs font-semibold bg-white border border-slate-200 rounded-lg px-3.5 focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none cursor-pointer shadow-2xs">
                                <option value="">Semua Modul</option>
                                <?php foreach ($listKategori as $kat): ?>
                                    <option value="<?= htmlspecialchars($kat) ?>" <?= $filterKat === $kat ? 'selected' : '' ?>><?= htmlspecialchars($kat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Urutkan</label>
                            <select name="sort_by" class="w-full h-[42px] text-xs font-semibold bg-white border border-slate-200 rounded-lg px-3.5 focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none cursor-pointer shadow-2xs">
                                <option value="terbaru" <?= $sortBy === 'terbaru' ? 'selected' : '' ?>>Waktu (Terbaru)</option>
                                <option value="terlama" <?= $sortBy === 'terlama' ? 'selected' : '' ?>>Waktu (Terlama)</option>
                                <option value="aktor_asc" <?= $sortBy === 'aktor_asc' ? 'selected' : '' ?>>Aktor (A-Z)</option>
                                <option value="aktor_desc" <?= $sortBy === 'aktor_desc' ? 'selected' : '' ?>>Aktor (Z-A)</option>
                                <option value="kategori" <?= $sortBy === 'kategori' ? 'selected' : '' ?>>Modul (A-Z)</option>
                            </select>
                        </div>
                        <div class="md:col-span-2 flex gap-2">
                            <button type="submit" class="flex-1 h-[42px] bg-[#0e74bb] hover:bg-[#0b5e99] text-white text-xs font-bold rounded-lg transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-1.5">
                                <i class="fas fa-search text-xs"></i>
                                Cari
                            </button>
                            <a href="log.php" class="px-3 h-[42px] bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition flex items-center justify-center shadow-2xs uppercase">Reset</a>
                        </div>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4 mb-12">
                <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex flex-wrap justify-between items-center text-xs font-semibold text-slate-600 gap-2">
                    <span>
                        Filter aktif: <b class="uppercase text-[#0e74bb]">
                        <?php 
                            $fLabel = [];
                            if ($filterKat) $fLabel[] = 'Modul: ' . $filterKat;
                            if ($sortBy) $fLabel[] = 'Urutan: ' . str_replace('_', ' ', $sortBy);
                            echo !empty($fLabel) ? implode(', ', $fLabel) : 'Semua Transaksi';
                        ?>
                        </b>
                    </span>
                    <span>Total Ditemukan: <b class="text-[#0e74bb] font-mono"><?= number_format($totalData, 0, ',', '.') ?></b> Data</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-[10px] uppercase font-bold text-slate-600 tracking-wider">
                                <th class="py-3 px-4 w-16">ID</th>
                                <th class="py-3 px-4 w-44">Waktu (Timestamp)</th>
                                <th class="py-3 px-4 w-48">Aktor / Pengguna</th>
                                <th class="py-3 px-4 w-32">Kategori Modul</th>
                                <th class="py-3 px-4">Rincian Aktivitas / Transaksi</th>
                                <th class="py-3 px-4 w-32 text-center">IP Address</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white">
                            <?php if (isset($errorMessage)): ?>
                                <tr><td colspan="6" class="text-center py-10 text-rose-600 font-bold">Tabel log belum siap atau terjadi kendala: <?= htmlspecialchars($errorMessage) ?></td></tr>
                            <?php elseif (empty($listLog)): ?>
                                <tr><td colspan="6" class="text-center py-12 text-slate-400 font-medium">Belum ada catatan aktivitas transaksi yang cocok dengan filter Anda.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listLog as $log): ?>
                                    <tr class="hover:bg-slate-50/80 transition">
                                        <td class="py-3 px-4 font-mono text-slate-400 font-bold">#<?= htmlspecialchars($log['id']) ?></td>
                                        <td class="py-3 px-4 font-mono text-slate-600 whitespace-nowrap">
                                            <?= htmlspecialchars($log['waktu'] ?? $log['created_at'] ?? '-') ?>
                                        </td>
                                        <td class="py-3 px-4 font-bold text-slate-900">
                                            <?= htmlspecialchars($log['actor'] ?? $log['admin_nama'] ?? 'Sistem / Peserta') ?>
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap">
                                            <?php 
                                                $kat = strtoupper($log['kategori'] ?? 'UMUM');
                                                $badgeColor = 'bg-slate-100 text-slate-700 border-slate-200';
                                                if (strpos($kat, 'PESERTA') !== false || strpos($kat, 'PENDAFTARAN') !== false) {
                                                    $badgeColor = 'bg-blue-50 text-[#0e74bb] border-blue-200/60';
                                                } elseif (strpos($kat, 'UJIAN') !== false) {
                                                    $badgeColor = 'bg-purple-50 text-purple-700 border-purple-200/60';
                                                } elseif (strpos($kat, 'VERIFIKASI') !== false) {
                                                    $badgeColor = 'bg-emerald-50 text-emerald-700 border-emerald-200/60';
                                                } elseif (strpos($kat, 'ADMIN') !== false) {
                                                    $badgeColor = 'bg-amber-50 text-amber-700 border-amber-200/60';
                                                }
                                            ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border <?= $badgeColor ?>">
                                                <?= htmlspecialchars($kat) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-slate-800 leading-relaxed font-normal">
                                            <?= htmlspecialchars($log['aktivitas'] ?? '-') ?>
                                        </td>
                                        <td class="py-3 px-4 font-mono text-center text-slate-500 text-[11px]">
                                            <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalHalaman > 1): ?>
                <div class="p-4 border-t border-slate-200 flex justify-between items-center text-xs mt-auto bg-slate-50/50">
                    <span class="text-slate-500">Halaman <b><?= $halaman ?></b> dari <b><?= $totalHalaman ?></b></span>
                    <div class="flex space-x-1.5">
                        <?php 
                            $queryParams = $_GET;
                            unset($queryParams['page']);
                            $queryPrefix = http_build_query($queryParams) ? '&' . http_build_query($queryParams) : '';
                        ?>
                        <?php if ($halaman > 1): ?>
                            <a href="log.php?page=<?= $halaman - 1 ?><?= $queryPrefix ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg font-bold text-slate-600 hover:bg-slate-100 shadow-2xs transition">&larr; Sebelumnya</a>
                        <?php endif; ?>
                        <?php if ($halaman < $totalHalaman): ?>
                            <a href="log.php?page=<?= $halaman + 1 ?><?= $queryPrefix ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg font-bold text-slate-600 hover:bg-slate-100 shadow-2xs transition">Selanjutnya &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
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
        setInterval(updateClock, 1000); updateClock();
    </script>
</body>
</html>