<?php
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

$namaAdmin    = $_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin   = $_SESSION['admin_level'] ?? 'user';
$isSuperAdmin = in_array(strtolower($levelAdmin), ['superadmin', 'admin']);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        echo "<div style='padding:20px; background:#fee2e2; color:#991b1b; font-family:sans-serif; margin:20px; border-radius:10px;'>";
        echo "<h3>Terjadi Kesalahan Fatal PHP:</h3>";
        echo "<p><b>Pesan:</b> " . htmlspecialchars($error['message']) . "</p>";
        echo "<p><b>Berkas:</b> " . htmlspecialchars($error['file']) . " (Baris " . $error['line'] . ")</p>";
        echo "</div>";
    }
});

$selectedBatch = trim($_GET['batch'] ?? 'all');
$listBatch = [];
try {
    $stmtBatch = $pdo->query("SELECT DISTINCT nama_periode FROM tb_periode_gelombang ORDER BY id_periode DESC");
    $listBatch = $stmtBatch->fetchAll(PDO::FETCH_COLUMN);
    if (empty($listBatch)) {
        $stmtBatchPendaftar = $pdo->query("SELECT DISTINCT batch_pelatihan FROM tb_pendaftaran WHERE batch_pelatihan IS NOT NULL AND batch_pelatihan != '' ORDER BY batch_pelatihan DESC");
        $listBatch = $stmtBatchPendaftar->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) { $listBatch = []; }

$whereSql = "WHERE ((kabupaten IS NOT NULL AND TRIM(kabupaten) != '') OR (domisili IS NOT NULL AND TRIM(domisili) != ''))";
$params = [];
if ($selectedBatch !== 'all' && !empty($selectedBatch)) {
    $whereSql .= " AND batch_pelatihan = ?";
    $params[] = $selectedBatch;
}

$totTerpetakan       = 0;
$totWilayah          = 0;
$domisiliTerbanyak   = '-';
$domisiliTerbanyakJml= 0;
$dataWilayahSemua    = [];
$top10Wilayah        = [];

try {
    $stmtTot = $pdo->prepare("SELECT COUNT(*) FROM tb_pendaftaran {$whereSql}");
    $stmtTot->execute($params);
    $totTerpetakan = (int)$stmtTot->fetchColumn();

    $sqlAgregasi = "
        SELECT 
            COALESCE(NULLIF(TRIM(kabupaten), ''), NULLIF(TRIM(domisili), ''), 'Lainnya / Tidak Diketahui') AS kabupaten, 
            COUNT(*) AS total
        FROM tb_pendaftaran
        {$whereSql}
        GROUP BY COALESCE(NULLIF(TRIM(kabupaten), ''), NULLIF(TRIM(domisili), ''), 'Lainnya / Tidak Diketahui')
        ORDER BY total DESC
    ";
    $stmtAgregasi = $pdo->prepare($sqlAgregasi);
    $stmtAgregasi->execute($params);
    $dataWilayahSemua = $stmtAgregasi->fetchAll(PDO::FETCH_ASSOC);

    $totWilayah = count($dataWilayahSemua);
    if ($totWilayah > 0) {
        $domisiliTerbanyak    = $dataWilayahSemua[0]['kabupaten'];
        $domisiliTerbanyakJml = (int)$dataWilayahSemua[0]['total'];
    }
    $top10Wilayah = array_slice($dataWilayahSemua, 0, 10);
} catch (Throwable $e) { $dataWilayahSemua = []; }

$koordinatJateng = [
    'Kabupaten Banjarnegara' => [-7.3987, 109.6974], 'Kabupaten Banyumas'     => [-7.5165, 109.2959],
    'Kabupaten Batang'       => [-7.0275, 109.8978], 'Kabupaten Blora'        => [-7.0673, 111.4186],
    'Kabupaten Boyolali'     => [-7.5312, 110.5962], 'Kabupaten Brebes'       => [-6.8705, 109.0435],
    'Kabupaten Cilacap'      => [-7.7279, 109.0076], 'Kabupaten Demak'        => [-6.8944, 110.6385],
    'Kabupaten Grobogan'     => [-7.1082, 110.9169], 'Kabupaten Jepara'       => [-6.5891, 110.6782],
    'Kabupaten Karanganyar'  => [-7.5967, 110.9525], 'Kabupaten Kebumen'      => [-7.6699, 109.6521],
    'Kabupaten Kendal'       => [-7.0253, 110.2057], 'Kabupaten Klaten'       => [-7.7058, 110.6067],
    'Kabupaten Kudus'        => [-6.8048, 110.8405], 'Kabupaten Magelang'     => [-7.4808, 110.2198],
    'Kabupaten Pati'         => [-6.7554, 111.0379], 'Kabupaten Pekalongan'   => [-7.0425, 109.6389],
    'Kabupaten Pemalang'     => [-6.8906, 109.3808], 'Kabupaten Purbalingga'  => [-7.3891, 109.3639],
    'Kabupaten Purworejo'    => [-7.7144, 110.0079], 'Kabupaten Rembang'      => [-6.7063, 111.3444],
    'Kabupaten Semarang'     => [-7.2064, 110.4384], 'Kabupaten Sragen'       => [-7.4268, 111.0223],
    'Kabupaten Sukoharjo'    => [-7.6833, 110.8333], 'Kabupaten Tegal'        => [-7.0069, 109.1394],
    'Kabupaten Temanggung'   => [-7.3175, 110.1764], 'Kabupaten Wonogiri'     => [-7.8139, 110.9255],
    'Kabupaten Wonosobo'     => [-7.3611, 109.9000], 'Kota Magelang'          => [-7.4706, 110.2178],
    'Kota Pekalongan'        => [-6.8886, 109.6753], 'Kota Salatiga'          => [-7.3305, 110.5084],
    'Kota Semarang'          => [-6.9667, 110.4167], 'Kota Surakarta (Solo)'  => [-7.5666, 110.8166],
    'Kota Tegal'             => [-6.8694, 109.1402], 'Lainnya'                => [-7.0000, 108.5000],
    'Lainnya / Tidak Diketahui' => [-7.0000, 108.5000]
];

$heatPoints   = [];
$bubblePoints = [];
$maxVal       = $domisiliTerbanyakJml > 0 ? $domisiliTerbanyakJml : 1;

foreach ($dataWilayahSemua as $row) {
    $kab = $row['kabupaten'];
    $cnt = (int)$row['total'];
    $lat = null; $lng = null;
    
    if (isset($koordinatJateng[$kab])) {
        $lat = $koordinatJateng[$kab][0];
        $lng = $koordinatJateng[$kab][1];
    } else {
        foreach ($koordinatJateng as $namaRef => $coord) {
            if (stripos($kab, str_replace(['Kabupaten ', 'Kota '], '', $namaRef)) !== false) {
                $lat = $coord[0]; $lng = $coord[1];
                break;
            }
        }
    }

    if ($lat !== null && $lng !== null) {
        $intensity  = round(min(max($cnt / $maxVal, 0.15), 1.0), 2);
        $multiplier = min(max((int)($cnt / 40), 1), 6);
        for ($i = 0; $i < $multiplier; $i++) {
            $offsetLat    = ($i === 0) ? 0 : (sin($i) * 0.02);
            $offsetLng    = ($i === 0) ? 0 : (cos($i) * 0.02);
            $heatPoints[] = [$lat + $offsetLat, $lng + $offsetLng, $intensity];
        }
        $bubblePoints[] = ['nama' => $kab, 'lat' => $lat, 'lng' => $lng, 'total' => $cnt];
    }
}

$chartTop10  = array_reverse($top10Wilayah);
$chartLabels = [];
$chartValues = [];
foreach ($chartTop10 as $t) {
    $chartLabels[] = str_replace(['Kabupaten ', 'Kota '], '', $t['kabupaten']);
    $chartValues[] = (int)$t['total'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peta Sebaran Wilayah — BP3MI Jawa Tengah</title>
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
    
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
        #leafletMap { height: 460px; width: 100%; border-radius: 0.75rem; z-index: 10; }
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
                        <span class="text-slate-600">Sebaran Wilayah</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="w-2.5 h-6 bg-[#0e74bb] rounded-full"></div>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight uppercase">PETA SEBARAN WILAYAH</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium pl-5">Visualisasi spasial dan pemetaan asal pendaftar program peningkatan kapasitas CPMI.</p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full lg:w-auto">
                    <form method="GET" action="sebaran_wilayah.php" class="w-full sm:w-64">
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

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white p-5 rounded-xl border border-slate-200 border-l-4 border-l-[#0e74bb] shadow-xs flex items-center justify-between hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div>
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest block mb-1">Total Wilayah</span>
                        <div class="flex items-baseline space-x-2">
                            <span class="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight"><?= number_format($totWilayah, 0, ',', '.') ?></span>
                            <span class="text-xs font-semibold text-slate-500">Kab/Kota Terdata</span>
                        </div>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-sky-50 text-[#0e74bb] border border-sky-100 flex items-center justify-center text-lg shadow-2xs">
                        <i class="fas fa-map-location-dot"></i>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-xl border border-slate-200 border-l-4 border-l-emerald-600 shadow-xs flex items-center justify-between hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div>
                        <span class="text-[10px] font-bold text-emerald-700 uppercase tracking-widest block mb-1">Total Pendaftar Terpetakan</span>
                        <div class="flex items-baseline space-x-2">
                            <span class="text-2xl sm:text-3xl font-black text-emerald-600 font-mono tracking-tight"><?= number_format($totTerpetakan, 0, ',', '.') ?></span>
                            <span class="text-xs font-semibold text-slate-500">Orang CPMI</span>
                        </div>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center text-lg shadow-2xs">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-xl border border-slate-200 border-l-4 border-l-amber-500 shadow-xs flex items-center justify-between hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div class="min-w-0 pr-3">
                        <span class="text-[10px] font-bold text-amber-700 uppercase tracking-widest block mb-1">Domisili Terbanyak</span>
                        <div class="truncate" title="<?= htmlspecialchars($domisiliTerbanyak) ?>">
                            <span class="text-lg font-black text-slate-900 block truncate"><?= htmlspecialchars($domisiliTerbanyak) ?></span>
                            <span class="text-xs font-bold text-amber-600 font-mono"><?= number_format($domisiliTerbanyakJml, 0, ',', '.') ?> Orang</span>
                        </div>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center text-lg shadow-2xs shrink-0">
                        <i class="fas fa-location-dot"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3.5">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Visualisasi Geografis Jawa Tengah</h3>
                            <span id="judulMapSubtitle" class="text-[10px] text-slate-500 font-medium">Intensitas Pendaftar Berdasarkan Kepadatan Area</span>
                        </div>
                    </div>
                    <div class="flex items-center bg-slate-100 p-1 rounded-lg border border-slate-200 self-start sm:self-auto">
                        <button type="button" id="tabHeatmap" onclick="switchMapMode('heat')" class="px-3 py-1.5 text-xs font-bold rounded-md bg-[#0e74bb] text-white shadow-xs transition flex items-center gap-1.5">
                            <i class="fas fa-fire text-[11px]"></i>
                            <span>Titik Panas (Heatmap)</span>
                        </button>
                        <button type="button" id="tabBubble" onclick="switchMapMode('bubble')" class="px-3 py-1.5 text-xs font-semibold rounded-md text-slate-600 hover:text-slate-900 transition flex items-center gap-1.5">
                            <i class="fas fa-circle-dot text-[11px]"></i>
                            <span>Gelembung (Bubble)</span>
                        </button>
                    </div>
                </div>
                <div class="relative border border-slate-200 rounded-xl overflow-hidden shadow-inner">
                    <div id="leafletMap"></div>
                    <div id="heatmapLegend" class="absolute top-3 right-3 bg-white/95 backdrop-blur-xs px-3.5 py-2.5 rounded-lg shadow-md border border-slate-200 z-[400] text-[10px] text-slate-600 flex flex-col items-center">
                        <span class="font-bold text-[9px] uppercase tracking-wider mb-1.5 text-slate-700">Kepadatan CPMI</span>
                        <div class="w-3.5 h-24 rounded-md" style="background: linear-gradient(to top, blue, cyan, lime, yellow, red);"></div>
                        <div class="flex justify-between w-full mt-1.5 text-[8px] font-bold text-slate-500">
                            <span>Rendah</span><span>Tinggi</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 pb-8">
                <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs flex flex-col">
                    <div class="flex items-center justify-between pb-3.5 mb-4 border-b border-slate-100">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                                <i class="fas fa-ranking-star"></i>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-800">Top 10 Kabupaten/Kota Terbanyak</h3>
                                <p class="text-[10px] text-slate-400 font-medium">Peringkat daerah asal dominan</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-sky-50 text-[#0e74bb] border border-sky-100">Top 10</span>
                    </div>
                    <div class="h-80 w-full relative flex-1"><canvas id="chartTop10"></canvas></div>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col">
                    <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-50/70 flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-sky-50 text-[#0e74bb] flex items-center justify-center text-xs">
                                <i class="fas fa-table-list"></i>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-800">Rekapitulasi Sebaran Daerah</h3>
                                <p class="text-[10px] text-slate-400 font-medium">Daftar lengkap pendaftar per kabupaten/kota</p>
                            </div>
                        </div>
                        <span class="text-[10px] font-bold text-slate-600 bg-white px-2.5 py-1 rounded-md border border-slate-200 font-mono"><?= count($dataWilayahSemua) ?> Daerah</span>
                    </div>
                    <div class="flex-1 overflow-y-auto sidebar-scroll max-h-80">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead class="sticky top-0 bg-slate-100/90 backdrop-blur-xs text-[10px] uppercase font-bold text-slate-600 border-b border-slate-200">
                                <tr>
                                    <th class="py-2.5 px-4 w-12 text-center">No</th>
                                    <th class="py-2.5 px-4">Kabupaten / Kota</th>
                                    <th class="py-2.5 px-4 text-right">Jumlah Pendaftar</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium">
                                <?php if (empty($dataWilayahSemua)): ?>
                                    <tr><td colspan="3" class="text-center py-8 text-slate-400">Belum ada data pendaftar pada batch yang dipilih.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($dataWilayahSemua as $idx => $d): ?>
                                        <tr class="hover:bg-sky-50/40 transition">
                                            <td class="py-2.5 px-4 text-center font-bold text-slate-400 font-mono"><?= $idx + 1 ?></td>
                                            <td class="py-2.5 px-4 text-slate-800 font-bold"><?= htmlspecialchars($d['kabupaten']) ?></td>
                                            <td class="py-2.5 px-4 text-right font-bold font-mono text-[#0e74bb]"><?= number_format($d['total'], 0, ',', '.') ?> <span class="text-[10px] text-slate-400 font-sans">Org</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
            let hh = now.getHours().toString().padStart(2, '0'), mm = now.getMinutes().toString().padStart(2, '0'), ss = now.getSeconds().toString().padStart(2, '0');
            document.getElementById('liveClock').textContent = `${hh}:${mm}:${ss} WIB`;
            document.getElementById('liveDate').textContent = `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
        }
        setInterval(updateClock, 1000); updateClock();

        const centerJateng = [-7.150975, 110.140259];
        const map = L.map('leafletMap', { center: centerJateng, zoom: 8, zoomControl: true });
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', { maxZoom: 13, minZoom: 7 }).addTo(map);

        const heatData   = <?= json_encode($heatPoints) ?>;
        const bubbleData = <?= json_encode($bubblePoints) ?>;

        const heatLayer = L.heatLayer(heatData, { radius: 35, blur: 25, maxZoom: 10, max: 1.0, gradient: { 0.2: 'blue', 0.4: 'cyan', 0.6: 'lime', 0.8: 'yellow', 1.0: 'red' } }).addTo(map);
        const bubbleLayerGroup = L.layerGroup();
        bubbleData.forEach(item => {
            const rad = Math.min(Math.max(item.total * 0.08, 6), 32);
            const circle = L.circleMarker([item.lat, item.lng], { radius: rad, fillColor: "#0e74bb", color: "#ffffff", weight: 2, fillOpacity: 0.7 });
            circle.bindPopup(`<div style="font-family: 'Plus Jakarta Sans', sans-serif; padding: 2px;"><b style="font-size: 12px; color: #1e293b;">${item.nama}</b><br><span style="font-size: 11px; color: #0e74bb; font-weight: bold;">${item.total} Peserta Pendaftar</span></div>`);
            bubbleLayerGroup.addLayer(circle);
        });

        function switchMapMode(mode) {
            const tabHeat  = document.getElementById('tabHeatmap'), tabBubble= document.getElementById('tabBubble');
            const subtitle = document.getElementById('judulMapSubtitle'), legend   = document.getElementById('heatmapLegend');
            if (mode === 'heat') {
                if (map.hasLayer(bubbleLayerGroup)) map.removeLayer(bubbleLayerGroup);
                if (!map.hasLayer(heatLayer)) map.addLayer(heatLayer);
                tabHeat.className = "px-3.5 py-2 text-xs font-bold rounded-lg bg-[#0e74bb] text-white shadow-xs transition";
                tabBubble.className = "px-3.5 py-2 text-xs font-semibold rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition";
                subtitle.textContent = "Intensitas Pendaftar Berdasarkan Kepadatan Area";
                legend.classList.remove('hidden');
            } else {
                if (map.hasLayer(heatLayer)) map.removeLayer(heatLayer);
                if (!map.hasLayer(bubbleLayerGroup)) map.addLayer(bubbleLayerGroup);
                tabBubble.className = "px-3.5 py-2 text-xs font-bold rounded-lg bg-[#0e74bb] text-white shadow-xs transition";
                tabHeat.className = "px-3.5 py-2 text-xs font-semibold rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition";
                subtitle.textContent = "Sebaran Gelembung Berdasarkan Volume Pendaftar";
                legend.classList.add('hidden');
            }
        }

        new Chart(document.getElementById('chartTop10').getContext('2d'), {
            type: 'bar',
            data: { 
                labels: <?= json_encode($chartLabels) ?>, 
                datasets: [{ 
                    label: 'Jumlah Pendaftar', 
                    data: <?= json_encode($chartValues) ?>, 
                    backgroundColor: '#0e74bb', 
                    hoverBackgroundColor: '#0b5e99',
                    borderRadius: 5, 
                    barPercentage: 0.65 
                }] 
            },
            options: { 
                indexAxis: 'y', 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { legend: { display: false } }, 
                scales: { 
                    x: { grid: { display: true, color: '#e2e8f0' }, ticks: { color: '#64748b' } }, 
                    y: { grid: { display: false }, ticks: { color: '#334155', font: { weight: '600' } } } 
                } 
            }
        });
    </script>
</body>
</html>