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
    if (function_exists('catatLog')) {
        catatLog($pdo, "Mengakses Halaman Manajemen Laporan Pendaftaran", 'LAPORAN');
    }
}

date_default_timezone_set('Asia/Jakarta');

$namaAdmin = $_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin = $_SESSION['admin_level'] ?? 'user';
$lembagaAdmin = $_SESSION['admin_lembaga'] ?? '';
$isLembaga = (strtolower($levelAdmin) === 'lembaga');

error_reporting(E_ALL);
ini_set('display_errors', 0);

$filterBatch = trim($_GET['batch'] ?? '');
$filterLembaga = $isLembaga ? $lembagaAdmin : trim($_GET['lembaga'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$keyword = trim($_GET['q'] ?? '');
$halaman = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($halaman - 1) * $limit;

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $jenisExport = trim($_GET['jenis'] ?? 'all');
    
    if (function_exists('catatLog')) {
        catatLog($pdo, "Mengunduh Laporan Pendaftaran Excel (Jenis: {$jenisExport})", 'LAPORAN');
    }
    if (ob_get_length()) ob_clean();
    
    $sqlExp = "SELECT p.*, sv.*, sv.status AS status_verifikasi FROM tb_pendaftaran p LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id WHERE 1=1";
    $paramsExp = [];
    
    if (!empty($filterBatch)) { $sqlExp .= " AND p.batch_pelatihan = ?"; $paramsExp[] = $filterBatch; }
    if (!empty($filterLembaga)) { $sqlExp .= " AND p.lembaga_pelatihan = ?"; $paramsExp[] = $filterLembaga; }
    if (!empty($keyword)) {
        $sqlExp .= " AND (p.nama_lengkap LIKE ? OR p.nik_sesuai_ktp LIKE ? OR p.cpmi_id LIKE ?)";
        $kw = "%{$keyword}%";
        array_push($paramsExp, $kw, $kw, $kw);
    }

    if ($jenisExport === 'ms') { $sqlExp .= " AND UPPER(TRIM(sv.status)) = 'MEMENUHI PERSYARATAN'"; } 
    elseif ($jenisExport === 'tms') { $sqlExp .= " AND UPPER(TRIM(sv.status)) = 'TIDAK MEMENUHI PERSYARATAN'"; } 
    elseif ($jenisExport === 'tertulis' || $jenisExport === 'interview') { $sqlExp .= " AND UPPER(TRIM(sv.status)) = 'MEMENUHI PERSYARATAN'"; }

    $sqlExp .= " ORDER BY p.cpmi_id ASC LIMIT 10000";
    $stmtExp = $pdo->prepare($sqlExp);
    $stmtExp->execute($paramsExp);
    $dataExp = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Laporan_{$jenisExport}_" . date('Ymd_His') . ".xls");
    
    echo "<table border='1'>
            <tr style='background-color:#4f46e5; color:#ffffff; font-weight:bold;'>
                <th>No</th><th>ID CPMI</th><th>NIK</th><th>Nama Lengkap</th><th>Jenis Kelamin</th>
                <th>Domisili</th><th>Batch</th><th>Lembaga</th><th>Kejuruan</th><th>Status Verifikasi</th>";
                
    if ($jenisExport === 'tms') { echo "<th>Alasan / Keterangan Penolakan</th>"; } 
    elseif ($jenisExport === 'tertulis') { echo "<th>Nilai Ujian Tertulis (CAT)</th>"; } 
    elseif ($jenisExport === 'interview') { echo "<th>Nilai Interview</th><th>Keterangan Lulus/Gagal</th>"; }
    
    echo "</tr>";
    
    foreach ($dataExp as $idx => $row) {
        $stVerif = $row['status_verifikasi'] ?? 'Belum Diverifikasi';
        echo "<tr>
                <td>" . ($idx + 1) . "</td><td>'{$row['cpmi_id']}</td><td>'{$row['nik_sesuai_ktp']}</td>
                <td>" . htmlspecialchars($row['nama_lengkap'] ?? '-') . "</td><td>" . htmlspecialchars($row['jenis_kelamin'] ?? '-') . "</td>
                <td>" . htmlspecialchars($row['kabupaten'] ?? $row['domisili'] ?? '-') . "</td><td>" . htmlspecialchars($row['batch_pelatihan'] ?? '-') . "</td>
                <td>" . htmlspecialchars($row['lembaga_pelatihan'] ?? '-') . "</td><td>" . htmlspecialchars($row['ref_pelatihan_dimin'] ?? '-') . "</td>
                <td>{$stVerif}</td>";
                
        if ($jenisExport === 'tms') {
            $alasan = $row['keterangan'] ?? $row['catatan'] ?? $row['alasan'] ?? $row['alasan_penolakan'] ?? '-';
            echo "<td>" . htmlspecialchars($alasan) . "</td>";
        } elseif ($jenisExport === 'tertulis') { echo "<td></td>"; } 
        elseif ($jenisExport === 'interview') { echo "<td></td><td></td>"; }
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    if (function_exists('catatLog')) { catatLog($pdo, "Mencetak Laporan (PDF)", 'LAPORAN'); }
    if (ob_get_length()) ob_clean();

    $sqlPdf = "SELECT p.*, sv.status AS status_verifikasi, 
               (SELECT MIN(uploaded_at) FROM tb_berkas_pendaftaran WHERE cpmi_id = p.cpmi_id) as waktu_awal
               FROM tb_pendaftaran p LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id WHERE 1=1";
    $paramsPdf = [];
    
    if (!empty($filterBatch)) { $sqlPdf .= " AND p.batch_pelatihan = ?"; $paramsPdf[] = $filterBatch; }
    if (!empty($filterLembaga)) { $sqlPdf .= " AND p.lembaga_pelatihan = ?"; $paramsPdf[] = $filterLembaga; }
    if (!empty($keyword)) {
        $sqlPdf .= " AND (p.nama_lengkap LIKE ? OR p.nik_sesuai_ktp LIKE ? OR p.cpmi_id LIKE ?)";
        $kw = "%{$keyword}%"; array_push($paramsPdf, $kw, $kw, $kw);
    }
    $sqlPdf .= " ORDER BY p.cpmi_id ASC LIMIT 15000";
    $stmtPdf = $pdo->prepare($sqlPdf);
    $stmtPdf->execute($paramsPdf);
    $dataPdf = $stmtPdf->fetchAll(PDO::FETCH_ASSOC);

    $statHariIni = 0; $stat5Hari = 0;
    $statLaki = 0; $statPr = 0;
    $statVerif = ['MS' => 0, 'TMS' => 0, 'BELUM' => 0];
    
    $rekapLembaga = [];
    $rekapKejuruan = [];

    $today = date('Y-m-d');
    $fiveDaysAgo = date('Y-m-d', strtotime('-5 days'));

    foreach ($dataPdf as $row) {
        $waktu = $row['waktu_awal'] ? date('Y-m-d', strtotime($row['waktu_awal'])) : '';
        if ($waktu === $today) $statHariIni++;
        if ($waktu >= $fiveDaysAgo && $waktu <= $today) $stat5Hari++;

        $jk = strtolower(trim($row['jenis_kelamin'] ?? ''));
        if (strpos($jk, 'laki') !== false) $statLaki++;
        elseif (strpos($jk, 'perempuan') !== false) $statPr++;

        $stv = strtoupper(trim((string)($row['status_verifikasi'] ?? '')));
        $lmbg = trim($row['lembaga_pelatihan'] ?: 'Tanpa Lembaga / Mandiri');
        $kjr = trim($row['ref_pelatihan_dimin'] ?: 'Belum Memilih Kejuruan');

        if ($stv === 'MEMENUHI PERSYARATAN') $statVerif['MS']++;
        elseif ($stv === 'TIDAK MEMENUHI PERSYARATAN') $statVerif['TMS']++;
        else $statVerif['BELUM']++;

        if (!isset($rekapLembaga[$lmbg])) {
            $rekapLembaga[$lmbg] = ['TOTAL' => 0, 'MS' => 0, 'TMS' => 0, 'BELUM' => 0];
        }
        $rekapLembaga[$lmbg]['TOTAL']++;

        if (!isset($rekapKejuruan[$kjr])) {
            $rekapKejuruan[$kjr] = ['TOTAL' => 0, 'MS' => 0, 'TMS' => 0, 'BELUM' => 0];
        }
        $rekapKejuruan[$kjr]['TOTAL']++;

        if ($stv === 'MEMENUHI PERSYARATAN') {
            $rekapLembaga[$lmbg]['MS']++;
            $rekapKejuruan[$kjr]['MS']++;
        } elseif ($stv === 'TIDAK MEMENUHI PERSYARATAN') {
            $rekapLembaga[$lmbg]['TMS']++;
            $rekapKejuruan[$kjr]['TMS']++;
        } else {
            $rekapLembaga[$lmbg]['BELUM']++;
            $rekapKejuruan[$kjr]['BELUM']++;
        }
    }

    uasort($rekapLembaga, function($a, $b) { return $b['TOTAL'] <=> $a['TOTAL']; });
    uasort($rekapKejuruan, function($a, $b) { return $b['TOTAL'] <=> $a['TOTAL']; });

    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Laporan Rekapitulasi Pendaftaran CPMI</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            @media print {
                @page { size: portrait; margin: 12mm; }
                body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background-color: white !important; }
                .no-print { display: none !important; }
                .page-break { page-break-before: always; }
            }
            body { font-family: Arial, sans-serif; background-color: #f1f5f9; }
            .print-container { max-width: 210mm; min-height: 297mm; margin: 0 auto; background: white; padding: 15mm; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
            @media print { .print-container { box-shadow: none; margin: 0; padding: 0; width: 100%; min-height: auto; } }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #94a3b8; padding: 6px 8px; font-size: 11px; }
            th { background-color: #e2e8f0; font-weight: bold; text-align: center; color: #1e293b; }
            .td-angka { text-align: center; font-weight: bold; }
        </style>
    </head>
    <body onload="window.print()">
        <div class="print-container">
            <div class="no-print mb-6 text-right">
                <button onclick="window.print()" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold shadow hover:bg-indigo-700">Cetak / Simpan PDF</button>
                <button onclick="window.close()" class="bg-slate-300 text-slate-800 px-4 py-2 rounded font-bold shadow hover:bg-slate-400 ml-2">Tutup</button>
            </div>

            <div class="flex items-center justify-between border-b-[3px] border-slate-800 pb-4 mb-6">
                <div class="flex items-center space-x-4">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" alt="Logo BP2MI" class="h-16 w-auto">
                    <div>
                        <h1 class="text-xl font-black text-slate-900 uppercase">Laporan Rekapitulasi Pendaftaran</h1>
                        <p class="text-xs font-semibold text-slate-700 uppercase">Pusat Informasi Peningkatan Kapasitas CPMI - BP3MI Jawa Tengah</p>
                    </div>
                </div>
                <div class="text-right text-[10px] text-slate-600">
                    <p>Waktu Cetak: <b><?= date('d M Y, H:i') ?> WIB</b></p>
                    <p>Dicetak Oleh: <b><?= htmlspecialchars($namaAdmin) ?></b></p>
                    <p>Filter Aktif: <b><?= $filterBatch ?: 'Semua Batch' ?></b></p>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4 mb-8">
                <div class="border border-slate-300 rounded-lg p-3 bg-slate-50">
                    <h3 class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Tren Pendaftaran Global</h3>
                    <ul class="text-xs space-y-1 font-semibold text-slate-800">
                        <li class="flex justify-between"><span>Hari Ini:</span> <span><?= number_format($statHariIni, 0, ',', '.') ?> orang</span></li>
                        <li class="flex justify-between"><span>5 Hari Terakhir:</span> <span><?= number_format($stat5Hari, 0, ',', '.') ?> orang</span></li>
                        <li class="flex justify-between border-t border-slate-200 pt-1 mt-1 font-black text-indigo-700 text-sm"><span>TOTAL DATA:</span> <span><?= number_format(count($dataPdf), 0, ',', '.') ?></span></li>
                    </ul>
                </div>
                
                <div class="border border-slate-300 rounded-lg p-3 bg-slate-50">
                    <h3 class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Status Verifikasi Global</h3>
                    <ul class="text-xs space-y-1 font-semibold text-slate-800">
                        <li class="flex justify-between text-emerald-700"><span>Memenuhi Syarat (MS):</span> <span><?= number_format($statVerif['MS'], 0, ',', '.') ?></span></li>
                        <li class="flex justify-between text-rose-700"><span>Tidak Memenuhi (TMS):</span> <span><?= number_format($statVerif['TMS'], 0, ',', '.') ?></span></li>
                        <li class="flex justify-between border-t border-slate-200 pt-1 mt-1 text-slate-600"><span>Belum Diperiksa:</span> <span><?= number_format($statVerif['BELUM'], 0, ',', '.') ?></span></li>
                    </ul>
                </div>

                <div class="border border-slate-300 rounded-lg p-3 bg-slate-50">
                    <h3 class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Demografi Global</h3>
                    <ul class="text-xs space-y-1 font-semibold text-slate-800">
                        <li class="flex justify-between text-blue-700"><span>Laki-Laki:</span> <span><?= number_format($statLaki, 0, ',', '.') ?> orang</span></li>
                        <li class="flex justify-between text-pink-700"><span>Perempuan:</span> <span><?= number_format($statPr, 0, ',', '.') ?> orang</span></li>
                    </ul>
                </div>
            </div>

            <h3 class="text-sm font-black text-slate-900 border-b-2 border-slate-800 pb-1 mb-3 uppercase">A. Rekapitulasi Berdasarkan Lembaga Pelatihan</h3>
            <table class="mb-8">
                <thead>
                    <tr>
                        <th rowspan="2" class="w-10">No</th>
                        <th rowspan="2" class="text-left">Nama Lembaga Pelatihan / LPK</th>
                        <th rowspan="2" class="w-24">Total Pendaftar</th>
                        <th colspan="3">Distribusi Status Verifikasi Dokumen</th>
                    </tr>
                    <tr>
                        <th class="w-20 bg-emerald-100 text-emerald-800">Lulus (MS)</th>
                        <th class="w-20 bg-rose-100 text-rose-800">Gagal (TMS)</th>
                        <th class="w-20 bg-slate-200">Belum Verif</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rekapLembaga)): ?>
                        <tr><td colspan="6" class="text-center font-bold text-slate-500 py-4">Tidak ada data lembaga.</td></tr>
                    <?php else: ?>
                        <?php 
                        $i = 1; 
                        $grandLembaga = ['TOT' => 0, 'MS' => 0, 'TMS' => 0, 'BLM' => 0];
                        foreach ($rekapLembaga as $nama => $data): 
                            $grandLembaga['TOT'] += $data['TOTAL'];
                            $grandLembaga['MS'] += $data['MS'];
                            $grandLembaga['TMS'] += $data['TMS'];
                            $grandLembaga['BLM'] += $data['BELUM'];
                        ?>
                        <tr class="nth-child(even):bg-slate-50 hover:bg-slate-100">
                            <td class="td-angka"><?= $i++ ?></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($nama) ?></td>
                            <td class="td-angka text-indigo-700"><?= number_format($data['TOTAL'], 0, ',', '.') ?></td>
                            <td class="td-angka text-emerald-700"><?= number_format($data['MS'], 0, ',', '.') ?></td>
                            <td class="td-angka text-rose-700"><?= number_format($data['TMS'], 0, ',', '.') ?></td>
                            <td class="td-angka text-slate-500"><?= number_format($data['BELUM'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-slate-200 font-black">
                            <td colspan="2" class="text-right">GRAND TOTAL KESELURUHAN</td>
                            <td class="td-angka text-indigo-900"><?= number_format($grandLembaga['TOT'], 0, ',', '.') ?></td>
                            <td class="td-angka text-emerald-900"><?= number_format($grandLembaga['MS'], 0, ',', '.') ?></td>
                            <td class="td-angka text-rose-900"><?= number_format($grandLembaga['TMS'], 0, ',', '.') ?></td>
                            <td class="td-angka text-slate-900"><?= number_format($grandLembaga['BLM'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h3 class="text-sm font-black text-slate-900 border-b-2 border-slate-800 pb-1 mb-3 uppercase">B. Rekapitulasi Berdasarkan Jenis Pelatihan (Kejuruan)</h3>
            <table class="mb-4">
                <thead>
                    <tr>
                        <th rowspan="2" class="w-10">No</th>
                        <th rowspan="2" class="text-left">Program / Jenis Kejuruan Pelatihan</th>
                        <th rowspan="2" class="w-24">Total Pendaftar</th>
                        <th colspan="3">Distribusi Status Verifikasi Dokumen</th>
                    </tr>
                    <tr>
                        <th class="w-20 bg-emerald-100 text-emerald-800">Lulus (MS)</th>
                        <th class="w-20 bg-rose-100 text-rose-800">Gagal (TMS)</th>
                        <th class="w-20 bg-slate-200">Belum Verif</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rekapKejuruan)): ?>
                        <tr><td colspan="6" class="text-center font-bold text-slate-500 py-4">Tidak ada data kejuruan.</td></tr>
                    <?php else: ?>
                        <?php 
                        $j = 1; 
                        $grandKjr = ['TOT' => 0, 'MS' => 0, 'TMS' => 0, 'BLM' => 0];
                        foreach ($rekapKejuruan as $nama => $data): 
                            $grandKjr['TOT'] += $data['TOTAL'];
                            $grandKjr['MS'] += $data['MS'];
                            $grandKjr['TMS'] += $data['TMS'];
                            $grandKjr['BLM'] += $data['BELUM'];
                        ?>
                        <tr class="nth-child(even):bg-slate-50 hover:bg-slate-100">
                            <td class="td-angka"><?= $j++ ?></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($nama) ?></td>
                            <td class="td-angka text-indigo-700"><?= number_format($data['TOTAL'], 0, ',', '.') ?></td>
                            <td class="td-angka text-emerald-700"><?= number_format($data['MS'], 0, ',', '.') ?></td>
                            <td class="td-angka text-rose-700"><?= number_format($data['TMS'], 0, ',', '.') ?></td>
                            <td class="td-angka text-slate-500"><?= number_format($data['BELUM'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-slate-200 font-black">
                            <td colspan="2" class="text-right">GRAND TOTAL KESELURUHAN</td>
                            <td class="td-angka text-indigo-900"><?= number_format($grandKjr['TOT'], 0, ',', '.') ?></td>
                            <td class="td-angka text-emerald-900"><?= number_format($grandKjr['MS'], 0, ',', '.') ?></td>
                            <td class="td-angka text-rose-900"><?= number_format($grandKjr['TMS'], 0, ',', '.') ?></td>
                            <td class="td-angka text-slate-900"><?= number_format($grandKjr['BLM'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$sqlBase = " FROM tb_pendaftaran p LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id WHERE 1=1";
$params = [];

if (!empty($filterBatch)) { $sqlBase .= " AND p.batch_pelatihan = ?"; $params[] = $filterBatch; }
if (!empty($filterLembaga)) { $sqlBase .= " AND p.lembaga_pelatihan = ?"; $params[] = $filterLembaga; }
if (!empty($filterStatus)) { $sqlBase .= " AND UPPER(TRIM(sv.status)) = ?"; $params[] = strtoupper(trim($filterStatus)); }
if (!empty($keyword)) {
    $sqlBase .= " AND (p.nama_lengkap LIKE ? OR p.nik_sesuai_ktp LIKE ? OR p.cpmi_id LIKE ?)";
    $kw = "%{$keyword}%"; array_push($params, $kw, $kw, $kw);
}

$totalData = 0;
try {
    $stmtCount = $pdo->prepare("SELECT COUNT(p.cpmi_id) " . $sqlBase);
    $stmtCount->execute($params);
    $totalData = (int)$stmtCount->fetchColumn();
} catch (Throwable $e) {}

$totalHariIni = 0;
try {
    $hariIniWIB = date('Y-m-d');
    $sqlToday = "
        SELECT COUNT(DISTINCT p.cpmi_id) 
        FROM tb_pendaftaran p
        LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
        INNER JOIN (
            SELECT cpmi_id, MIN(uploaded_at) as waktu_awal 
            FROM tb_berkas_pendaftaran 
            GROUP BY cpmi_id
        ) b_time ON p.cpmi_id = b_time.cpmi_id
        WHERE DATE(b_time.waktu_awal) = ?
    ";
    
    $sqlTodayFilter = str_replace(" FROM tb_pendaftaran p LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id WHERE 1=1", "", $sqlBase);
    
    $paramsToday = [$hariIniWIB];
    foreach ($params as $paramValue) {
        $paramsToday[] = $paramValue;
    }
    
    $stmtToday = $pdo->prepare($sqlToday . $sqlTodayFilter);
    $stmtToday->execute($paramsToday);
    $totalHariIni = (int)$stmtToday->fetchColumn();
} catch (Throwable $e) {}

$totalHalaman = max(1, ceil($totalData / $limit));

$daftarLaporan = [];
try {
    $sqlData = "SELECT p.*, sv.status AS status_verifikasi " . $sqlBase . " ORDER BY p.cpmi_id ASC LIMIT {$limit} OFFSET {$offset}";
    $stmtData = $pdo->prepare($sqlData);
    $stmtData->execute($params);
    $daftarLaporan = $stmtData->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$listBatch = [];
try {
    $listBatch = $pdo->query("SELECT DISTINCT batch_pelatihan FROM tb_pendaftaran WHERE batch_pelatihan IS NOT NULL AND batch_pelatihan != '' ORDER BY batch_pelatihan ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$listLembagaMaster = [];
try {
    $listLembagaMaster = $pdo->query("SELECT DISTINCT lembaga_pelatihan FROM tb_pendaftaran WHERE lembaga_pelatihan IS NOT NULL AND lembaga_pelatihan != '' ORDER BY lembaga_pelatihan ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$qBaseExport = http_build_query([
    'batch' => $filterBatch, 
    'lembaga' => $filterLembaga, 
    'status' => $filterStatus, 
    'q' => $keyword
]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Laporan Pendaftaran — BP3MI Jawa Tengah</title>
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
                    <div class="flex items-center gap-2 text-[11px] font-bold text-slate-400 mb-1.5">
                        <i class="fas fa-home text-[#0e74bb]"></i>
                        <span>/</span>
                        <span>Modul Pendaftaran</span>
                        <span>/</span>
                        <span class="text-slate-600">Manajemen Laporan</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="w-2.5 h-6 bg-[#0e74bb] rounded-full"></div>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight uppercase">MANAJEMEN LAPORAN</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium pl-5">Rekapitulasi data pendaftar peningkatan kapasitas CPMI BP3MI Jawa Tengah.</p>
                </div>
                
                <div class="inline-flex items-center gap-3 bg-slate-50 px-3.5 py-2 rounded-lg border border-slate-200 shadow-2xs shrink-0">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                    <div class="text-right">
                        <div id="liveClock" class="text-xs sm:text-sm font-bold text-[#0e74bb] font-mono leading-none">--:--:-- WIB</div>
                        <div id="liveDate" class="text-[9px] font-bold text-slate-500 uppercase tracking-wider mt-0.5 leading-none">Memuat Tanggal...</div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-white p-5 rounded-xl border border-slate-200 border-l-4 border-l-sky-500 shadow-xs flex items-center justify-between hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div>
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1">Pendaftaran Hari Ini</p>
                        <p class="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight"><?= number_format($totalHariIni, 0, ',', '.') ?> <span class="text-xs font-semibold text-slate-400 font-sans">Orang</span></p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-sky-50 text-sky-600 border border-sky-100 flex items-center justify-center text-xl shadow-2xs">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-xl border border-slate-200 border-l-4 border-l-[#0e74bb] shadow-xs flex items-center justify-between hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div>
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1">Total Pendaftaran</p>
                        <p class="text-2xl sm:text-3xl font-black text-[#0e74bb] font-mono tracking-tight"><?= number_format($totalData, 0, ',', '.') ?> <span class="text-xs font-semibold text-slate-400 font-sans">Orang</span></p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-sky-50 text-[#0e74bb] border border-sky-100 flex items-center justify-center text-xl shadow-2xs">
                        <i class="fas fa-users-viewfinder"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs space-y-5">
                <div class="flex items-center gap-2 pb-2.5 border-b border-slate-100">
                    <div class="w-7 h-7 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                        <i class="fas fa-sliders"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Filter Kriteria Laporan</span>
                </div>
                <form method="GET" action="laporan_pendaftaran.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3.5 items-end">
                    <div class="lg:col-span-5 sm:col-span-2">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Pencarian Peserta</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <i class="fas fa-search text-xs"></i>
                            </div>
                            <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="Nama, NIK, atau CPMI-ID..." class="w-full h-11 pl-9 pr-3.5 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                        </div>
                    </div>
                    
                    <div class="lg:col-span-3 sm:col-span-1">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Filter Gelombang / Batch</label>
                        <select name="batch" class="w-full h-11 px-3 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] cursor-pointer transition">
                            <option value="">Semua Gelombang</option>
                            <?php foreach ($listBatch as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>" <?= $filterBatch === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!$isLembaga): ?>
                    <div class="lg:col-span-3 sm:col-span-1">
                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Filter Lembaga</label>
                        <select name="lembaga" class="w-full h-11 px-3 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] cursor-pointer transition">
                            <option value="">Semua Lembaga</option>
                            <?php foreach ($listLembagaMaster as $l): ?>
                            <option value="<?= htmlspecialchars($l) ?>" <?= $filterLembaga === $l ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="<?= $isLembaga ? 'lg:col-span-4' : 'lg:col-span-1' ?> sm:col-span-1 h-11">
                        <button type="submit" class="w-full h-full bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg text-xs transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-1.5">
                            <i class="fas fa-search text-[11px]"></i> Cari
                        </button>
                    </div>
                </form>

                <div class="pt-4 border-t border-slate-100 flex flex-wrap items-center gap-2">
                    <span class="block w-full text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Pusat Laporan & Unduhan Data :</span>
                    
                    <a href="laporan_pendaftaran.php?export=pdf&<?= $qBaseExport ?>" target="_blank" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-lg text-xs transition flex items-center gap-2 shadow-xs">
                        <i class="fas fa-file-pdf"></i>
                        <span>Laporan Rekapitulasi (PDF)</span>
                    </a>

                    <a href="laporan_pendaftaran.php?export=excel&jenis=all&<?= $qBaseExport ?>" class="px-3.5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg text-xs transition flex items-center gap-2 shadow-xs">
                        <i class="fas fa-file-excel"></i>
                        <span>Semua Data (Excel)</span>
                    </a>
                    
                    <a href="laporan_pendaftaran.php?export=excel&jenis=ms&<?= $qBaseExport ?>" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs transition flex items-center gap-2 shadow-xs">
                        <i class="fas fa-check-circle"></i>
                        <span>Memenuhi Syarat (MS)</span>
                    </a>
                    
                    <a href="laporan_pendaftaran.php?export=excel&jenis=tms&<?= $qBaseExport ?>" class="px-3.5 py-2 bg-slate-600 hover:bg-slate-700 text-white font-bold rounded-lg text-xs transition flex items-center gap-2 shadow-xs">
                        <i class="fas fa-times-circle"></i>
                        <span>Tidak Memenuhi (TMS)</span>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4 mb-10">
                <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-50/70 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                            <i class="fas fa-table-list"></i>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-800">Daftar Rekapitulasi Pendaftar</h3>
                            <p class="text-[10px] text-slate-400 font-medium">Data pendaftaran sesuai filter aktif</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-bold text-slate-600 bg-white px-2.5 py-1 rounded-md border border-slate-200 font-mono">Halaman <?= $halaman ?> / <?= $totalHalaman ?></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead>
                            <tr class="bg-slate-100/80 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                                <th class="py-3 px-4 w-32">CPMI-ID</th>
                                <th class="py-3 px-4 min-w-[200px]">Peserta & NIK</th>
                                <th class="py-3 px-4">Kabupaten / Domisili</th>
                                <th class="py-3 px-4">Batch / Lembaga</th>
                                <th class="py-3 px-4 text-center">Status Verifikasi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white">
                            <?php if (empty($daftarLaporan)): ?>
                            <tr><td colspan="5" class="px-6 py-10 text-center text-slate-400">Tidak ada data laporan yang ditemukan pada filter aktif.</td></tr>
                            <?php else: ?>
                            <?php foreach ($daftarLaporan as $row): ?>
                            <?php $stVerifClean = strtoupper(trim((string)($row['status_verifikasi'] ?? ''))); ?>
                            <tr class="hover:bg-sky-50/40 transition">
                                <td class="py-3 px-4 font-mono font-bold text-[#0e74bb]"><?= htmlspecialchars($row['cpmi_id']) ?></td>
                                <td class="py-3 px-4">
                                    <span class="block font-bold text-slate-900"><?= htmlspecialchars($row['nama_lengkap']) ?></span>
                                    <span class="font-mono text-[11px] text-slate-500"><?= htmlspecialchars($row['nik_sesuai_ktp']) ?></span>
                                </td>
                                <td class="py-3 px-4 font-medium text-slate-700"><?= htmlspecialchars($row['kabupaten'] ?? $row['domisili'] ?? '-') ?></td>
                                <td class="py-3 px-4">
                                    <span class="font-semibold text-slate-800 block"><?= htmlspecialchars($row['batch_pelatihan'] ?: '-') ?></span>
                                    <span class="text-[11px] text-slate-500"><?= htmlspecialchars($row['lembaga_pelatihan'] ?: '-') ?></span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php if ($stVerifClean === 'MEMENUHI PERSYARATAN'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Memenuhi Syarat</span>
                                    <?php elseif ($stVerifClean === 'TIDAK MEMENUHI PERSYARATAN'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200"><span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>Tidak Memenuhi</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>Belum Diverifikasi</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalHalaman > 1): ?>
                <div class="p-4 border-t border-slate-100 flex items-center justify-between text-xs mt-auto">
                    <span class="text-slate-500">Halaman <b><?= $halaman ?></b> dari <b><?= $totalHalaman ?></b></span>
                    <div class="flex space-x-1.5">
                        <?php if ($halaman > 1): ?>
                        <a href="laporan_pendaftaran.php?<?= $qBaseExport ?>&page=<?= $halaman - 1 ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg font-bold text-slate-700 hover:bg-slate-50 transition">&larr; Sebelumnya</a>
                        <?php endif; ?>
                        <?php if ($halaman < $totalHalaman): ?>
                        <a href="laporan_pendaftaran.php?<?= $qBaseExport ?>&page=<?= $halaman + 1 ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg font-bold text-slate-700 hover:bg-slate-50 transition">Selanjutnya &rarr;</a>
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