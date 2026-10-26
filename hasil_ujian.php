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

if (isset($_GET['ajax_detail'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cpmiId = trim((string)$_GET['ajax_detail']);
        
        if (function_exists('catatLog')) {
            catatLog($pdo, "Melihat rincian lembar jawaban ujian peserta CPMI ID #{$cpmiId}", 'UJIAN');
        }

        $stmtJwb = $pdo->prepare("SELECT soal_id, jawaban_pilihan FROM tb_jawaban_peserta WHERE cpmi_id = :cpmi_id");
        $stmtJwb->execute(['cpmi_id' => $cpmiId]);
        $jawabanUser = $stmtJwb->fetchAll(PDO::FETCH_KEY_PAIR);

        $detailList = [];
        
        if (!empty($jawabanUser)) {
            $inClause = implode(',', array_fill(0, count($jawabanUser), '?'));
            $stmtSoal = $pdo->prepare("SELECT * FROM tb_soal WHERE id IN ({$inClause}) ORDER BY id ASC");
            $stmtSoal->execute(array_keys($jawabanUser));
            
            while ($sm = $stmtSoal->fetch(PDO::FETCH_ASSOC)) {
                $sid = (int)$sm['id'];
                $detailList[] = [
                    'id'              => $sid,
                    'pertanyaan'      => (string)($sm['pertanyaan'] ?? ''),
                    'jenis_ujian'     => strtolower(trim((string)($sm['jenis_ujian'] ?? 'tertulis'))),
                    'kunci'           => strtoupper(trim((string)($sm['kunci'] ?? ''))),
                    'opsi_a'          => (string)($sm['opsi_a'] ?? ''),
                    'opsi_b'          => (string)($sm['opsi_b'] ?? ''),
                    'opsi_c'          => (string)($sm['opsi_c'] ?? ''),
                    'opsi_d'          => (string)($sm['opsi_d'] ?? ''),
                    'poin_a'          => (int)($sm['poin_a'] ?? 0),
                    'poin_b'          => (int)($sm['poin_b'] ?? 0),
                    'poin_c'          => (int)($sm['poin_c'] ?? 0),
                    'poin_d'          => (int)($sm['poin_d'] ?? 0),
                    'jawaban_pilihan' => strtoupper(trim((string)($jawabanUser[$sid] ?? '')))
                ];
            }
        }
        echo json_encode($detailList);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$soalMap = [];
$stmtSoal = $pdo->query("SELECT * FROM tb_soal ORDER BY id ASC");
while ($s = $stmtSoal->fetch(PDO::FETCH_ASSOC)) {
    $soalMap[$s['id']] = $s;
}

$keyword      = trim((string)($_GET['q'] ?? ''));
$filterBatch  = trim((string)($_GET['batch'] ?? ''));
$filterLpk    = trim((string)($_GET['lpk'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? 'semua'));
$halaman      = max(1, (int)($_GET['page'] ?? 1));
$limit        = 50; 
$offset       = ($halaman - 1) * $limit;

$whereClause = " WHERE 1=1";
$params = [];

if ($keyword !== '') {
    $whereClause .= " AND (nama_lengkap LIKE :kw1 OR nik_sesuai_ktp LIKE :kw2 OR cpmi_id LIKE :kw3)";
    $params[':kw1'] = "%{$keyword}%";
    $params[':kw2'] = "%{$keyword}%";
    $params[':kw3'] = "%{$keyword}%";
}

if ($filterBatch !== '') {
    $whereClause .= " AND batch_pelatihan = :batch";
    $params[':batch'] = $filterBatch;
}

if ($filterLpk !== '') {
    $whereClause .= " AND lembaga_pelatihan = :lpkfilter";
    $params[':lpkfilter'] = $filterLpk;
}

$stmtAll = $pdo->prepare("SELECT cpmi_id, status_tertulis, status_interview FROM tb_pendaftaran {$whereClause} ORDER BY cpmi_id ASC");
$stmtAll->execute($params);
$semuaPesertaMaster = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

$stmtCekJwb = $pdo->query("SELECT DISTINCT cpmi_id FROM tb_jawaban_peserta");
$sudahJwbIds = $stmtCekJwb->fetchAll(PDO::FETCH_COLUMN);

$stmtJwbTypes = $pdo->query("
    SELECT DISTINCT jp.cpmi_id, LOWER(TRIM(COALESCE(s.jenis_ujian, 'tertulis'))) AS jenis_ujian 
    FROM tb_jawaban_peserta jp 
    JOIN tb_soal s ON jp.soal_id = s.id
");
$jwbTertulisMap = [];
$jwbInterviewMap = [];
while ($rj = $stmtJwbTypes->fetch(PDO::FETCH_ASSOC)) {
    if ($rj['jenis_ujian'] === 'interview') {
        $jwbInterviewMap[$rj['cpmi_id']] = true;
    } else {
        $jwbTertulisMap[$rj['cpmi_id']] = true;
    }
}

$statTotalTerdaftar  = count($semuaPesertaMaster);
$statBelumTertulis   = 0;
$statBelumInterview  = 0;
$statSedangTertulis  = 0;
$statSedangInterview = 0;
$statSelesaiTertulis = 0;
$statSelesaiInterview= 0;

$kategoriPeserta = [];
$statusPesertaDetail = [];
$statSudah = 0;
$statSedang = 0;
$statBelum = 0;

foreach ($semuaPesertaMaster as $pm) {
    $cid = $pm['cpmi_id'];
    $stT = strtolower(trim((string)($pm['status_tertulis'] ?? 'belum')));
    $stI = strtolower(trim((string)($pm['status_interview'] ?? 'belum')));
    if ($stT === '') $stT = 'belum';
    if ($stI === '') $stI = 'belum';

    if ($stT === 'belum' && isset($jwbTertulisMap[$cid])) {
        $stT = 'sedang';
    }
    if ($stI === 'belum' && isset($jwbInterviewMap[$cid])) {
        $stI = 'sedang';
    }

    if ($stT === 'selesai') {
        $statSelesaiTertulis++;
    } elseif ($stT === 'sedang') {
        $statSedangTertulis++;
    } else {
        $statBelumTertulis++;
    }

    if ($stI === 'selesai') {
        $statSelesaiInterview++;
    } elseif ($stI === 'sedang') {
        $statSedangInterview++;
    } else {
        $statBelumInterview++;
    }

    if ($stT === 'selesai' || $stI === 'selesai') {
        $kat = 'sudah';
        $statSudah++;
    } elseif ($stT === 'sedang' || $stI === 'sedang') {
        $kat = 'sedang';
        $statSedang++;
    } else {
        $kat = 'belum';
        $statBelum++;
    }

    $kategoriPeserta[$cid] = $kat;
    $statusPesertaDetail[$cid] = [
        'tertulis'  => $stT,
        'interview' => $stI
    ];
}

$filteredCpmiIds = [];
foreach ($semuaPesertaMaster as $pm) {
    $cid = $pm['cpmi_id'];
    $stT = $statusPesertaDetail[$cid]['tertulis'];
    $stI = $statusPesertaDetail[$cid]['interview'];
    $kat = $kategoriPeserta[$cid];

    if ($filterStatus === 'semua' || $filterStatus === '') {
        $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'belum_tertulis') {
        if ($stT === 'belum') $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'belum_interview') {
        if ($stI === 'belum') $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'sedang_tertulis') {
        if ($stT === 'sedang') $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'sedang_interview') {
        if ($stI === 'sedang') $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'selesai_tertulis') {
        if ($stT === 'selesai') $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'selesai_interview') {
        if ($stI === 'selesai') $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'belum') {
        if ($stT === 'belum' || $stI === 'belum') $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'sedang') {
        if ($stT === 'sedang' || $stI === 'sedang') $filteredCpmiIds[] = $cid;
    } elseif ($filterStatus === 'sudah' || $filterStatus === 'selesai') {
        if ($stT === 'selesai' || $stI === 'selesai') $filteredCpmiIds[] = $cid;
    } else {
        $filteredCpmiIds[] = $cid;
    }
}

$stmtAllJwb = $pdo->query("
    SELECT jp.cpmi_id, jp.jawaban_pilihan, s.jenis_ujian, s.kunci, s.poin_a, s.poin_b, s.poin_c, s.poin_d 
    FROM tb_jawaban_peserta jp 
    JOIN tb_soal s ON jp.soal_id = s.id
");
$allJwb = $stmtAllJwb->fetchAll(PDO::FETCH_ASSOC);

$userScoresT = [];
$userScoresI = [];

foreach ($allJwb as $r) {
    $cid = $r['cpmi_id'];
    $jns = strtolower(trim((string)$r['jenis_ujian']));
    $pil = strtoupper(trim((string)$r['jawaban_pilihan']));
    
    if ($jns === 'interview') {
        if (!isset($userScoresI[$cid])) $userScoresI[$cid] = 0;
        if ($pil === 'A') $userScoresI[$cid] += (int)$r['poin_a'];
        elseif ($pil === 'B') $userScoresI[$cid] += (int)$r['poin_b'];
        elseif ($pil === 'C') $userScoresI[$cid] += (int)$r['poin_c'];
        elseif ($pil === 'D') $userScoresI[$cid] += (int)$r['poin_d'];
    } else {
        if (!isset($userScoresT[$cid])) $userScoresT[$cid] = ['benar' => 0, 'total' => 0];
        $userScoresT[$cid]['total']++;
        $kunci = strtoupper(trim((string)$r['kunci']));
        if ($pil !== '' && $pil === $kunci) {
            $userScoresT[$cid]['benar']++;
        }
    }
}

$avgT = 0;
if (count($userScoresT) > 0) {
    $sumT = 0;
    foreach ($userScoresT as $sc) {
        $sumT += ($sc['benar'] / $sc['total']) * 100;
    }
    $avgT = round($sumT / count($userScoresT), 1);
}

$avgI = 0;
if (count($userScoresI) > 0) {
    $avgI = round(array_sum($userScoresI) / count($userScoresI), 1);
}

$totalPeserta = count($filteredCpmiIds);
$totalHalaman = max(1, ceil($totalPeserta / $limit));

$daftarHasil = [];
if ($totalPeserta > 0) {
    $pageCpmiIds = array_slice($filteredCpmiIds, $offset, $limit);
    $placeholdersPage = implode(',', array_fill(0, count($pageCpmiIds), '?'));
    
    $stmtDataPage = $pdo->prepare("SELECT * FROM tb_pendaftaran WHERE cpmi_id IN ({$placeholdersPage}) ORDER BY cpmi_id ASC");
    $stmtDataPage->execute($pageCpmiIds);
    $rawPeserta = $stmtDataPage->fetchAll(PDO::FETCH_ASSOC);

    $stmtJawaban = $pdo->prepare("SELECT cpmi_id, soal_id, jawaban_pilihan FROM tb_jawaban_peserta WHERE cpmi_id IN ({$placeholdersPage})");
    $stmtJawaban->execute($pageCpmiIds);
    
    $jawabanPerPeserta = [];
    while ($rowJ = $stmtJawaban->fetch(PDO::FETCH_ASSOC)) {
        $cid = (string)$rowJ['cpmi_id'];
        $jawabanPerPeserta[$cid][$rowJ['soal_id']] = strtoupper(trim((string)$rowJ['jawaban_pilihan']));
    }

    foreach ($rawPeserta as $p) {
        $cid = (string)$p['cpmi_id'];
        $userAnswers = $jawabanPerPeserta[$cid] ?? [];

        $benarTertulis = 0;
        $skorInterview = 0;
        $jumlahTertulis = 0;

        foreach ($userAnswers as $sid => $pilihan) {
            if (!isset($soalMap[$sid])) continue;
            $s = $soalMap[$sid];
            $jns = strtolower(trim((string)($s['jenis_ujian'] ?? 'tertulis')));

            if ($jns === 'interview') {
                if ($pilihan === 'A') $skorInterview += (int)($s['poin_a'] ?? 0);
                elseif ($pilihan === 'B') $skorInterview += (int)($s['poin_b'] ?? 0);
                elseif ($pilihan === 'C') $skorInterview += (int)($s['poin_c'] ?? 0);
                elseif ($pilihan === 'D') $skorInterview += (int)($s['poin_d'] ?? 0);
            } else {
                $jumlahTertulis++;
                $kunci = strtoupper(trim((string)($s['kunci'] ?? '')));
                if ($pilihan !== '' && $pilihan === $kunci) {
                    $benarTertulis++;
                }
            }
        }

        $daftarHasil[] = [
            'cpmi_id'           => $p['cpmi_id'],
            'nama_lengkap'      => $p['nama_lengkap'],
            'nik_sesuai_ktp'    => $p['nik_sesuai_ktp'],
            'batch_pelatihan'   => $p['batch_pelatihan'] ?? '',
            'lembaga_pelatihan' => $p['lembaga_pelatihan'] ?? '',
            'status_tertulis'   => strtolower(trim((string)($p['status_tertulis'] ?? 'belum'))),
            'status_interview'  => strtolower(trim((string)($p['status_interview'] ?? 'belum'))),
            'total_dijawab'     => count($userAnswers),
            'benar_tertulis'    => $benarTertulis,
            'jumlah_tertulis'   => $jumlahTertulis,
            'skor_interview'    => $skorInterview
        ];
    }
}

$listBatch = $pdo->query("SELECT DISTINCT batch_pelatihan FROM tb_pendaftaran WHERE batch_pelatihan IS NOT NULL AND batch_pelatihan != '' ORDER BY batch_pelatihan ASC")->fetchAll(PDO::FETCH_COLUMN);

$stmtBatchLpk = $pdo->query("
    SELECT DISTINCT batch_pelatihan, lembaga_pelatihan 
    FROM tb_pendaftaran 
    WHERE lembaga_pelatihan IS NOT NULL AND lembaga_pelatihan != ''
");
$batchLpkMap = [];
$semuaLpkRaw = [];
while($row = $stmtBatchLpk->fetch(PDO::FETCH_ASSOC)) {
    $b = $row['batch_pelatihan'] ?: '';
    $l = $row['lembaga_pelatihan'];
    if ($b !== '') {
        $batchLpkMap[$b][] = $l;
    }
    $semuaLpkRaw[] = $l;
}
$semuaLpkList = array_values(array_unique($semuaLpkRaw));
sort($semuaLpkList);
foreach($batchLpkMap as $b => $lpkArr) {
    $batchLpkMap[$b] = array_values(array_unique($lpkArr));
    sort($batchLpkMap[$b]);
}

$stmtLpkUjian = $pdo->query("
    SELECT DISTINCT lembaga_pelatihan 
    FROM tb_pendaftaran 
    WHERE lembaga_pelatihan IS NOT NULL 
      AND lembaga_pelatihan != '' 
    ORDER BY lembaga_pelatihan ASC
");
$listLpkUjian = $stmtLpkUjian->fetchAll(PDO::FETCH_COLUMN);

$stmtLpkJenis = $pdo->query("
    SELECT DISTINCT lembaga_pelatihan, ref_pelatihan_dimin 
    FROM tb_pendaftaran 
    WHERE lembaga_pelatihan IS NOT NULL AND lembaga_pelatihan != ''
");
$lpkJenisMap = [];
while($row = $stmtLpkJenis->fetch(PDO::FETCH_ASSOC)) {
    $lpk = $row['lembaga_pelatihan'];
    $jns = $row['ref_pelatihan_dimin'] ?: 'Lainnya';
    $lpkJenisMap[$lpk][] = $jns;
}

if (isset($_GET['export_report']) && $_GET['export_report'] === 'excel') {
    if (function_exists('catatLog')) {
        catatLog($pdo, "Mengunduh Report Laporan Akhir Ujian (Excel)", 'UJIAN');
    }

    if (ob_get_length()) {
        ob_clean();
    }
    
    $lpkFilterReport = trim((string)($_GET['lpk_report'] ?? ''));
    $jenisFilterReport = trim((string)($_GET['jenis_pel_report'] ?? ''));

    $whereReport = " WHERE ((UPPER(sv.status) LIKE '%MEMENUHI%' AND UPPER(sv.status) NOT LIKE '%TIDAK%') OR UPPER(sv.status) = 'MS')";
    $paramsRep = [];
    if ($lpkFilterReport !== '') {
        $whereReport .= " AND p.lembaga_pelatihan = :lpk";
        $paramsRep[':lpk'] = $lpkFilterReport;
    }
    if ($jenisFilterReport !== '') {
        $whereReport .= " AND p.ref_pelatihan_dimin = :jns";
        $paramsRep[':jns'] = $jenisFilterReport;
    }

    $stmtRep = $pdo->prepare("
        SELECT p.*, sv.status as status_verifikasi 
        FROM tb_pendaftaran p 
        JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id 
        {$whereReport} 
        ORDER BY p.lembaga_pelatihan ASC, p.nama_lengkap ASC
    ");
    $stmtRep->execute($paramsRep);
    $pesertaRep = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

    $repJwb = [];
    if (!empty($pesertaRep)) {
        $repCpmiIds = array_column($pesertaRep, 'cpmi_id');
        $phRep = implode(',', array_fill(0, count($repCpmiIds), '?'));
        
        $stmtRepJwb = $pdo->prepare("SELECT cpmi_id, soal_id, jawaban_pilihan FROM tb_jawaban_peserta WHERE cpmi_id IN ({$phRep})");
        $stmtRepJwb->execute($repCpmiIds);
        
        while ($rj = $stmtRepJwb->fetch(PDO::FETCH_ASSOC)) {
            $repJwb[$rj['cpmi_id']][$rj['soal_id']] = strtoupper(trim((string)$rj['jawaban_pilihan']));
        }
    }

    $filenameStr = $lpkFilterReport !== '' ? "Laporan Ujian - " . preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $lpkFilterReport) : "Laporan Ujian - Semua Lembaga Pelatihan";
    $headerLpk = $lpkFilterReport !== '' ? htmlspecialchars($lpkFilterReport) : 'Semua Lembaga Pelatihan';

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"{$filenameStr}.xls\"");
    
    echo "<table border='1'>";
    echo "<tr><th colspan='11' style='font-size:16px; text-align:center; border:none;'>Laporan Akhir Ujian</th></tr>";
    echo "<tr><th colspan='11' style='font-size:14px; text-align:center; border:none;'>Pendaftaran Peserta Peningkatan Kapasitas CPMI</th></tr>";
    echo "<tr><th colspan='11' style='font-size:14px; text-align:center; border:none;'>BP3MI Jawa Tengah</th></tr>";
    echo "<tr><th colspan='11' style='font-size:14px; text-align:center; border:none;'>{$headerLpk}</th></tr>";
    echo "<tr><td colspan='11' style='border:none;'></td></tr>";
    
    echo "<tr style='background-color:#4f46e5; color:#ffffff; font-weight:bold;'>
            <th>No</th>
            <th>ID-CPMI</th>
            <th>Nama</th>
            <th>NIK</th>
            <th>Status Verifikasi Dokumen</th>
            <th>Jenis Pelatihan</th>
            <th>Nilai Ujian Tertulis</th>
            <th>Status Kelulusan Ujian Tertulis</th>
            <th>Nilai Ujian Interview</th>
            <th>Status Kelulusan Ujian Interview</th>
            <th>Status Akhir</th>
        </tr>";
            
    foreach ($pesertaRep as $idx => $r) {
        $cid = (string)$r['cpmi_id'];
        $userAnswers = $repJwb[$cid] ?? [];
        
        $benarTertulis = 0;
        $skorInterview = 0;
        $jumlahTertulis = 0;

        foreach ($userAnswers as $sid => $pilihan) {
            if (!isset($soalMap[$sid])) continue;
            $s = $soalMap[$sid];
            $jns = strtolower(trim((string)($s['jenis_ujian'] ?? 'tertulis')));

            if ($jns === 'interview') {
                if ($pilihan === 'A') $skorInterview += (int)($s['poin_a'] ?? 0);
                elseif ($pilihan === 'B') $skorInterview += (int)($s['poin_b'] ?? 0);
                elseif ($pilihan === 'C') $skorInterview += (int)($s['poin_c'] ?? 0);
                elseif ($pilihan === 'D') $skorInterview += (int)($s['poin_d'] ?? 0);
            } else {
                $jumlahTertulis++;
                $kunci = strtoupper(trim((string)($s['kunci'] ?? '')));
                if ($pilihan !== '' && $pilihan === $kunci) {
                    $benarTertulis++;
                }
            }
        }

        $nilaiTertulis = ($jumlahTertulis > 0) ? round(($benarTertulis / $jumlahTertulis) * 100, 1) : 0;
        
        $lulusT = ($nilaiTertulis >= 75);
        $lulusI = ($skorInterview >= 26);
        
        $ketTertulis = $lulusT ? 'LULUS' : 'TIDAK LULUS';
        $ketInterview = $lulusI ? 'LULUS' : 'TIDAK LULUS';
        $statusAkhir = ($lulusT && $lulusI) ? 'LULUS' : 'TIDAK LULUS';
        
        $rowStyle = "";
        if ($statusAkhir === 'TIDAK LULUS') {
            $rowStyle = " style='background-color: #ffffe0;'";
        }
        
        $namaCaps = strtoupper(htmlspecialchars((string)$r['nama_lengkap']));
        
        echo "<tr{$rowStyle}>
                <td>" . ($idx + 1) . "</td>
                <td style=\"mso-number-format:'\@';\">{$r['cpmi_id']}</td>
                <td>{$namaCaps}</td>
                <td style=\"mso-number-format:'\@';\">{$r['nik_sesuai_ktp']}</td>
                <td>" . htmlspecialchars($r['status_verifikasi'] ?? 'BELUM DIVERIFIKASI') . "</td>
                <td>" . htmlspecialchars($r['ref_pelatihan_dimin'] ?? '-') . "</td>
                <td>{$nilaiTertulis}</td>
                <td>{$ketTertulis}</td>
                <td>{$skorInterview}</td>
                <td>{$ketInterview}</td>
                <td><strong>{$statusAkhir}</strong></td>
              </tr>";
    }
    echo "</table>";
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    if (function_exists('catatLog')) {
        catatLog($pdo, "Mengunduh rekapitulasi nilai dan hasil ujian peserta (Excel)", 'UJIAN');
    }

    if (ob_get_length()) {
        ob_clean();
    }
    $exportCpmiIds = !empty($filteredCpmiIds) ? array_slice($filteredCpmiIds, 0, 5000) : [];
    $expPeserta = [];
    if (!empty($exportCpmiIds)) {
        $phExp = implode(',', array_fill(0, count($exportCpmiIds), '?'));
        $stmtExp = $pdo->prepare("SELECT * FROM tb_pendaftaran WHERE cpmi_id IN ({$phExp}) ORDER BY cpmi_id ASC");
        $stmtExp->execute($exportCpmiIds);
        $expPeserta = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

        $stmtExpJwb = $pdo->prepare("SELECT cpmi_id, soal_id, jawaban_pilihan FROM tb_jawaban_peserta WHERE cpmi_id IN ({$phExp})");
        $stmtExpJwb->execute($exportCpmiIds);
        $expJawabanPerPeserta = [];
        while ($rowJ = $stmtExpJwb->fetch(PDO::FETCH_ASSOC)) {
            $cid = (string)$rowJ['cpmi_id'];
            $expJawabanPerPeserta[$cid][$rowJ['soal_id']] = strtoupper(trim((string)$rowJ['jawaban_pilihan']));
        }
    }

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Rekap_Nilai_" . date('Ymd_His') . ".xls");
    echo "<table border='1'>
            <tr style='background-color:#4f46e5; color:#ffffff; font-weight:bold;'>
                <th>No</th><th>ID CPMI</th><th>NIK</th><th>Nama Lengkap</th>
                <th>Batch</th><th>Lembaga</th><th>Status Tertulis</th><th>Nilai Tertulis (Skala 100)</th><th>Keterangan Tertulis</th>
                <th>Status Interview</th><th>Skor Interview (pt)</th><th>Keterangan Interview</th>
            </tr>";
    foreach ($expPeserta as $idx => $r) {
        $cid = (string)$r['cpmi_id'];
        $userAnswers = $expJawabanPerPeserta[$cid] ?? [];
        $benarTertulis = 0;
        $skorInterview = 0;
        $jumlahTertulis = 0;

        foreach ($userAnswers as $sid => $pilihan) {
            if (!isset($soalMap[$sid])) continue;
            $s = $soalMap[$sid];
            $jns = strtolower(trim((string)($s['jenis_ujian'] ?? 'tertulis')));

            if ($jns === 'interview') {
                if ($pilihan === 'A') $skorInterview += (int)($s['poin_a'] ?? 0);
                elseif ($pilihan === 'B') $skorInterview += (int)($s['poin_b'] ?? 0);
                elseif ($pilihan === 'C') $skorInterview += (int)($s['poin_c'] ?? 0);
                elseif ($pilihan === 'D') $skorInterview += (int)($s['poin_d'] ?? 0);
            } else {
                $jumlahTertulis++;
                $kunci = strtoupper(trim((string)($s['kunci'] ?? '')));
                if ($pilihan !== '' && $pilihan === $kunci) {
                    $benarTertulis++;
                }
            }
        }

        $nilaiTertulis = ($jumlahTertulis > 0) ? round(($benarTertulis / $jumlahTertulis) * 100, 1) : 0;
        $stT = strtoupper(trim((string)($r['status_tertulis'] ?? 'belum')));
        $stI = strtoupper(trim((string)($r['status_interview'] ?? 'belum')));
        
        $ketTertulis = ($nilaiTertulis >= 75) ? 'LULUS' : 'TIDAK LULUS';
        $ketInterview = ($skorInterview >= 26) ? 'LULUS' : 'TIDAK LULUS';

        echo "<tr>
                <td>" . ($idx + 1) . "</td>
                <td style=\"mso-number-format:'\@';\">{$r['cpmi_id']}</td>
                <td style=\"mso-number-format:'\@';\">{$r['nik_sesuai_ktp']}</td>
                <td>" . htmlspecialchars($r['nama_lengkap']) . "</td>
                <td>" . htmlspecialchars($r['batch_pelatihan']) . "</td>
                <td>" . htmlspecialchars($r['lembaga_pelatihan']) . "</td>
                <td>{$stT}</td>
                <td>{$nilaiTertulis} ({$benarTertulis}/{$jumlahTertulis})</td>
                <td>{$ketTertulis}</td>
                <td>{$stI}</td>
                <td>{$skorInterview} pt</td>
                <td>{$ketInterview}</td>
              </tr>";
    }
    echo "</table>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil & Nilai Peserta — BP3MI Jawa Tengah</title>
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
                            <i class="fas fa-clipboard-check"></i>
                        </span>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight uppercase">Hasil & Nilai Peserta Ujian</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Rekapitulasi hasil penilaian ujian tertulis dan wawancara peserta CPMI.</p>
                </div>
                
                <div class="text-right bg-slate-50 px-4 py-2.5 rounded-xl border border-slate-200 shadow-2xs">
                    <div id="liveClock" class="text-sm font-bold text-slate-900 font-mono">--:--:-- WIB</div>
                    <div id="liveDate" class="text-[9px] font-bold text-[#0e74bb] uppercase tracking-widest mt-0.5">Memuat Tanggal...</div>
                </div>
            </div>

            <?php
            $isSemuaAktif = ($filterStatus === 'semua' || $filterStatus === '');
            $isBelumGroupAktif = in_array($filterStatus, ['belum', 'belum_tertulis', 'belum_interview']);
            $isSedangGroupAktif = in_array($filterStatus, ['sedang', 'sedang_tertulis', 'sedang_interview']);
            $isSelesaiGroupAktif = in_array($filterStatus, ['sudah', 'selesai', 'selesai_tertulis', 'selesai_interview']);
            ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="hasil_ujian.php?status=semua&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                   class="p-4 md:p-5 rounded-xl border transition flex flex-col justify-between text-left <?php echo $isSemuaAktif ? 'border-[#0e74bb] bg-blue-50/70 shadow-xs ring-2 ring-[#0e74bb]/20' : 'border-slate-200 bg-white hover:border-[#0e74bb]/40 hover:bg-slate-50/60 shadow-xs'; ?>">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[10px] text-slate-500 font-extrabold uppercase tracking-widest">Total Peserta Terdaftar</span>
                        <span class="w-7 h-7 rounded-lg bg-blue-50 text-[#0e74bb] flex items-center justify-center text-xs">
                            <i class="fas fa-users"></i>
                        </span>
                    </div>
                    <div class="mt-auto">
                        <span class="text-2xl md:text-3xl font-black text-[#0e74bb] font-mono block"><?php echo number_format($statTotalTerdaftar, 0, ',', '.'); ?></span>
                        <span class="text-[11px] text-slate-400 font-semibold block mt-1">Total seluruh peserta terdata</span>
                    </div>
                </a>

                <div class="p-4 md:p-5 rounded-xl border transition flex flex-col justify-between text-left <?php echo $isBelumGroupAktif ? 'border-rose-400 bg-rose-50/30 shadow-xs ring-2 ring-rose-500/20' : 'border-slate-200 bg-white shadow-xs'; ?>">
                    <div class="flex items-center justify-between mb-2">
                        <a href="hasil_ujian.php?status=belum&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="text-[10px] text-slate-500 hover:text-rose-600 font-extrabold uppercase tracking-widest transition" title="Klik untuk filter semua Belum Ujian">
                            Belum Ujian
                        </a>
                        <span class="w-7 h-7 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center text-xs">
                            <i class="fas fa-clock"></i>
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 mt-auto">
                        <a href="hasil_ujian.php?status=belum_tertulis&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="p-2.5 rounded-lg border text-center transition block group <?php echo $filterStatus === 'belum_tertulis' ? 'bg-rose-100/80 border-rose-400 ring-1 ring-rose-400 shadow-2xs' : 'bg-slate-50/80 hover:bg-rose-50 border-slate-200 hover:border-rose-300'; ?>" title="Filter Belum Ujian Tes Tertulis">
                            <span class="text-[9px] font-bold text-slate-500 uppercase block truncate group-hover:text-rose-700">Tes Tertulis</span>
                            <span class="text-lg md:text-xl font-black text-rose-600 font-mono block mt-0.5"><?php echo number_format($statBelumTertulis, 0, ',', '.'); ?></span>
                        </a>
                        <a href="hasil_ujian.php?status=belum_interview&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="p-2.5 rounded-lg border text-center transition block group <?php echo $filterStatus === 'belum_interview' ? 'bg-rose-100/80 border-rose-400 ring-1 ring-rose-400 shadow-2xs' : 'bg-slate-50/80 hover:bg-rose-50 border-slate-200 hover:border-rose-300'; ?>" title="Filter Belum Ujian Interview">
                            <span class="text-[9px] font-bold text-slate-500 uppercase block truncate group-hover:text-rose-700">Interview</span>
                            <span class="text-lg md:text-xl font-black text-rose-600 font-mono block mt-0.5"><?php echo number_format($statBelumInterview, 0, ',', '.'); ?></span>
                        </a>
                    </div>
                </div>

                <div class="p-4 md:p-5 rounded-xl border transition flex flex-col justify-between text-left <?php echo $isSedangGroupAktif ? 'border-amber-400 bg-amber-50/30 shadow-xs ring-2 ring-amber-500/20' : 'border-slate-200 bg-white shadow-xs'; ?>">
                    <div class="flex items-center justify-between mb-2">
                        <a href="hasil_ujian.php?status=sedang&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="text-[10px] text-slate-500 hover:text-amber-600 font-extrabold uppercase tracking-widest transition" title="Klik untuk filter semua Sedang Ujian">
                            Sedang Ujian
                        </a>
                        <span class="w-7 h-7 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center text-xs">
                            <i class="fas fa-spinner fa-spin"></i>
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 mt-auto">
                        <a href="hasil_ujian.php?status=sedang_tertulis&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="p-2.5 rounded-lg border text-center transition block group <?php echo $filterStatus === 'sedang_tertulis' ? 'bg-amber-100/80 border-amber-400 ring-1 ring-amber-400 shadow-2xs' : 'bg-slate-50/80 hover:bg-amber-50 border-slate-200 hover:border-amber-300'; ?>" title="Filter Sedang Ujian Tes Tertulis">
                            <span class="text-[9px] font-bold text-slate-500 uppercase block truncate group-hover:text-amber-700">Tes Tertulis</span>
                            <span class="text-lg md:text-xl font-black text-amber-600 font-mono block mt-0.5"><?php echo number_format($statSedangTertulis, 0, ',', '.'); ?></span>
                        </a>
                        <a href="hasil_ujian.php?status=sedang_interview&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="p-2.5 rounded-lg border text-center transition block group <?php echo $filterStatus === 'sedang_interview' ? 'bg-amber-100/80 border-amber-400 ring-1 ring-amber-400 shadow-2xs' : 'bg-slate-50/80 hover:bg-amber-50 border-slate-200 hover:border-amber-300'; ?>" title="Filter Sedang Ujian Interview">
                            <span class="text-[9px] font-bold text-slate-500 uppercase block truncate group-hover:text-amber-700">Interview</span>
                            <span class="text-lg md:text-xl font-black text-amber-600 font-mono block mt-0.5"><?php echo number_format($statSedangInterview, 0, ',', '.'); ?></span>
                        </a>
                    </div>
                </div>

                <div class="p-4 md:p-5 rounded-xl border transition flex flex-col justify-between text-left <?php echo $isSelesaiGroupAktif ? 'border-emerald-400 bg-emerald-50/30 shadow-xs ring-2 ring-emerald-500/20' : 'border-slate-200 bg-white shadow-xs'; ?>">
                    <div class="flex items-center justify-between mb-2">
                        <a href="hasil_ujian.php?status=selesai&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="text-[10px] text-slate-500 hover:text-emerald-600 font-extrabold uppercase tracking-widest transition" title="Klik untuk filter semua Selesai Ujian">
                            Selesai Ujian
                        </a>
                        <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-xs">
                            <i class="fas fa-check-circle"></i>
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 mt-auto">
                        <a href="hasil_ujian.php?status=selesai_tertulis&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="p-2.5 rounded-lg border text-center transition block group <?php echo $filterStatus === 'selesai_tertulis' ? 'bg-emerald-100/80 border-emerald-400 ring-1 ring-emerald-400 shadow-2xs' : 'bg-slate-50/80 hover:bg-emerald-50 border-slate-200 hover:border-emerald-300'; ?>" title="Filter Selesai Ujian Tes Tertulis">
                            <span class="text-[9px] font-bold text-slate-500 uppercase block truncate group-hover:text-emerald-700">Tes Tertulis</span>
                            <span class="text-lg md:text-xl font-black text-emerald-600 font-mono block mt-0.5"><?php echo number_format($statSelesaiTertulis, 0, ',', '.'); ?></span>
                        </a>
                        <a href="hasil_ujian.php?status=selesai_interview&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="p-2.5 rounded-lg border text-center transition block group <?php echo $filterStatus === 'selesai_interview' ? 'bg-emerald-100/80 border-emerald-400 ring-1 ring-emerald-400 shadow-2xs' : 'bg-slate-50/80 hover:bg-emerald-50 border-slate-200 hover:border-emerald-300'; ?>" title="Filter Selesai Ujian Interview">
                            <span class="text-[9px] font-bold text-slate-500 uppercase block truncate group-hover:text-emerald-700">Interview</span>
                            <span class="text-lg md:text-xl font-black text-emerald-600 font-mono block mt-0.5"><?php echo number_format($statSelesaiInterview, 0, ',', '.'); ?></span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 md:p-5 rounded-xl border border-slate-200 shadow-xs flex items-center justify-between bg-white">
                    <div>
                        <div class="flex items-center gap-1.5 mb-1">
                            <span class="w-2 h-2 rounded-full bg-[#0e74bb]"></span>
                            <span class="text-[10px] text-slate-600 font-extrabold uppercase tracking-widest">Rata-Rata Nilai Tertulis</span>
                        </div>
                        <span class="text-xs text-slate-500 font-medium block">Dari seluruh peserta yang telah menjawab</span>
                    </div>
                    <span class="text-3xl md:text-4xl font-black text-[#0e74bb] font-mono"><?php echo $avgT; ?></span>
                </div>
                <div class="p-4 md:p-5 rounded-xl border border-slate-200 shadow-xs flex items-center justify-between bg-white">
                    <div>
                        <div class="flex items-center gap-1.5 mb-1">
                            <span class="w-2 h-2 rounded-full bg-purple-600"></span>
                            <span class="text-[10px] text-slate-600 font-extrabold uppercase tracking-widest">Rata-Rata Poin Interview</span>
                        </div>
                        <span class="text-xs text-slate-500 font-medium block">Dari seluruh peserta yang telah menjawab</span>
                    </div>
                    <span class="text-3xl md:text-4xl font-black text-purple-700 font-mono"><?php echo $avgI; ?></span>
                </div>
            </div>

            <div class="bg-white p-5 md:p-6 rounded-xl border border-slate-200 shadow-xs">
                <form method="GET" action="hasil_ujian.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-4 items-end">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                    
                    <div class="lg:col-span-3 sm:col-span-2">
                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Pencarian Peserta</label>
                        <input type="text" name="q" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="Nama, NIK, atau CPMI-ID..." 
                               class="w-full h-[42px] px-3.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] shadow-2xs">
                    </div>
                    
                    <div class="lg:col-span-2 sm:col-span-1">
                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Batch Pelatihan</label>
                        <select name="batch" id="filter_batch" onchange="perbaruiFilterLpk()" class="w-full h-[42px] px-3 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] shadow-2xs cursor-pointer">
                            <option value="">Semua Batch</option>
                            <?php foreach ($listBatch as $b): ?>
                            <option value="<?php echo htmlspecialchars((string)$b); ?>" <?php echo $filterBatch === (string)$b ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$b); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lg:col-span-3 sm:col-span-1">
                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Lembaga Pelatihan</label>
                        <select name="lpk" id="filter_lpk" class="w-full h-[42px] px-3 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] shadow-2xs cursor-pointer">
                            <option value="">Semua Lembaga Pelatihan</option>
                        </select>
                    </div>
                    
                    <div class="lg:col-span-4 sm:col-span-2 flex gap-2 h-[42px] items-center">
                        <button type="submit" class="flex-1 h-full bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg text-xs transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-1.5">
                            <i class="fas fa-filter text-xs"></i>
                            Filter
                        </button>
                        
                        <button type="button" onclick="bukaModalReport()" class="px-3.5 h-full bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg text-xs transition flex items-center justify-center shadow-xs uppercase tracking-wider text-center" style="line-height: 1.1;" title="Unduh Laporan Akhir">
                            Laporan<br>Ujian
                        </button>

                        <a href="hasil_ujian.php?export=excel&status=<?php echo urlencode($filterStatus); ?>&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>" 
                           class="px-3.5 h-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs transition flex items-center justify-center shadow-xs uppercase tracking-wider text-center" style="line-height: 1.1;" title="Unduh Excel Ringkas">
                            Laporan<br>All
                        </a>
                        
                        <?php if (!empty($keyword) || !empty($filterBatch) || !empty($filterLpk) || $filterStatus !== 'semua'): ?>
                        <a href="hasil_ujian.php" class="px-3.5 h-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg text-xs transition flex items-center justify-center uppercase tracking-wider shadow-xs" title="Reset Filter">
                            Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4 mb-12">
                <div class="p-4 border-b border-slate-200 bg-slate-50/70 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 text-xs font-semibold text-slate-600">
                    <?php
                    $mapNamaKategori = [
                        'semua'             => 'Semua Peserta Terdaftar',
                        'belum'             => 'Belum Ujian (Semua)',
                        'belum_tertulis'    => 'Belum Ujian Tes Tertulis',
                        'belum_interview'   => 'Belum Ujian Interview',
                        'sedang'            => 'Sedang Ujian (Semua)',
                        'sedang_tertulis'   => 'Sedang Ujian Tes Tertulis',
                        'sedang_interview'  => 'Sedang Ujian Interview',
                        'sudah'             => 'Selesai Ujian (Semua)',
                        'selesai'           => 'Selesai Ujian (Semua)',
                        'selesai_tertulis'  => 'Selesai Ujian Tes Tertulis',
                        'selesai_interview' => 'Selesai Ujian Interview',
                    ];
                    $labelStatusAktif = $mapNamaKategori[$filterStatus] ?? ucwords(str_replace('_', ' ', $filterStatus));
                    ?>
                    <span>Menampilkan: <b class="text-[#0e74bb]"><?php echo htmlspecialchars($labelStatusAktif); ?></b></span>
                    <span>Total data tersaring: <b><?php echo number_format($totalPeserta, 0, ',', '.'); ?></b></span>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="py-3 px-4 w-28 whitespace-nowrap">CPMI-ID</th>
                                <th class="py-3 px-4 min-w-[220px]">Peserta & NIK</th>
                                <th class="py-3 px-4 min-w-[200px]">Batch / Lembaga</th>
                                <th class="py-3 px-4 text-center w-36 whitespace-nowrap">Ujian Tertulis</th>
                                <th class="py-3 px-4 text-center w-36 whitespace-nowrap">Skor Interview</th>
                                <th class="py-3 px-4 text-center w-24 whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white text-xs">
                            <?php if (empty($daftarHasil)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-slate-400 text-xs">
                                    Tidak ada data peserta yang cocok dengan kriteria filter.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($daftarHasil as $idx => $row): ?>
                            <?php 
                                $benar = $row['benar_tertulis'];
                                $jmlTertulis = $row['jumlah_tertulis'];
                                $nilaiTertulis = ($jmlTertulis > 0) ? round(($benar / $jmlTertulis) * 100, 1) : 0;
                                $skorInterview = $row['skor_interview'];

                                $lulusTertulis = ($nilaiTertulis >= 75);
                                $lulusInterview = ($skorInterview >= 26);
                            ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="py-3 px-4 align-middle whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md font-mono text-xs font-bold bg-blue-50 text-[#0e74bb] border border-blue-200/60 shadow-2xs">
                                        <?php echo htmlspecialchars($row['cpmi_id']); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 align-middle">
                                    <span class="block font-bold text-slate-800 text-sm leading-snug"><?php echo htmlspecialchars($row['nama_lengkap']); ?></span>
                                    <span class="font-mono text-xs text-slate-500 block mt-0.5"><?php echo htmlspecialchars($row['nik_sesuai_ktp']); ?></span>
                                </td>
                                <td class="py-3 px-4 align-middle">
                                    <span class="font-semibold text-slate-800 block text-xs leading-snug"><?php echo htmlspecialchars($row['batch_pelatihan'] ?: '-'); ?></span>
                                    <span class="text-xs text-slate-500 block mt-0.5"><?php echo htmlspecialchars($row['lembaga_pelatihan'] ?: '-'); ?></span>
                                </td>
                                <td class="py-3 px-4 text-center align-middle whitespace-nowrap">
                                    <?php if ($row['status_tertulis'] === 'selesai' || $row['total_dijawab'] > 0): ?>
                                        <div class="flex flex-col items-center">
                                            <span class="block text-base font-black <?php echo $lulusTertulis ? 'text-emerald-600' : 'text-rose-600'; ?> font-mono">
                                                <?php echo $nilaiTertulis; ?>
                                            </span>
                                            <span class="text-[9px] font-extrabold <?php echo $lulusTertulis ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'; ?> border px-2 py-0.5 rounded-md mt-1 uppercase">
                                                <?php echo $lulusTertulis ? 'Lulus KKM' : 'Gagal'; ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-300 font-semibold text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-center align-middle whitespace-nowrap">
                                    <?php if ($row['status_interview'] === 'selesai' || $row['total_dijawab'] > 0): ?>
                                        <div class="flex flex-col items-center">
                                            <span class="block text-base font-black <?php echo $lulusInterview ? 'text-emerald-600' : 'text-rose-600'; ?> font-mono">
                                                <?php echo $skorInterview; ?> pt
                                            </span>
                                            <span class="text-[9px] font-extrabold <?php echo $lulusInterview ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'; ?> border px-2 py-0.5 rounded-md mt-1 uppercase">
                                                <?php echo $lulusInterview ? 'Lulus KKM' : 'Gagal'; ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-300 font-semibold text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-center align-middle whitespace-nowrap">
                                    <button type="button" 
                                            onclick="bukaModalDetail('<?php echo $row['cpmi_id']; ?>', '<?php echo htmlspecialchars(addslashes($row['nama_lengkap'])); ?>')" 
                                            class="bg-blue-50 hover:bg-blue-100 text-[#0e74bb] font-bold px-3 py-1.5 rounded-lg text-xs transition border border-blue-200/60 shadow-2xs flex items-center justify-center gap-1.5 mx-auto">
                                        <i class="fas fa-eye text-[11px]"></i>
                                        Detail
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalHalaman > 1): ?>
                <div class="p-4 border-t border-slate-200 flex items-center justify-between text-xs mt-auto bg-slate-50/50">
                    <span class="text-slate-500">
                        Halaman <b><?php echo $halaman; ?></b> dari <b><?php echo $totalHalaman; ?></b> (Total <?php echo number_format($totalPeserta, 0, ',', '.'); ?> data)
                    </span>
                    <div class="flex space-x-1.5">
                        <?php if ($halaman > 1): ?>
                        <a href="?status=<?php echo urlencode($filterStatus); ?>&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>&page=<?php echo $halaman - 1; ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg hover:bg-slate-100 font-bold shadow-2xs">&larr; Sebelumnya</a>
                        <?php endif; ?>

                        <?php if ($halaman < $totalHalaman): ?>
                        <a href="?status=<?php echo urlencode($filterStatus); ?>&q=<?php echo urlencode($keyword); ?>&batch=<?php echo urlencode($filterBatch); ?>&lpk=<?php echo urlencode($filterLpk); ?>&page=<?php echo $halaman + 1; ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg hover:bg-slate-100 font-bold shadow-2xs">Selanjutnya &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <div id="modalReport" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-lg w-full p-6 sm:p-7 shadow-2xl border border-slate-200 flex flex-col relative overflow-hidden">
            <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
            
            <button type="button" onclick="tutupModalReport()" class="absolute top-4 right-4 w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-lg flex items-center justify-center font-bold transition text-xs">
                &#10005;
            </button>
            <h3 class="font-black text-slate-900 text-lg uppercase tracking-tight mb-1">Unduh Laporan Hasil Ujian</h3>
            <p class="text-xs text-slate-500 mb-5 leading-relaxed">Silakan tentukan spesifikasi data Lembaga Pelatihan yang ingin diekstraksi ke dalam format pelaporan Excel formal.</p>
            
            <form method="GET" action="hasil_ujian.php" class="space-y-4">
                <input type="hidden" name="export_report" value="excel">
                
                <div>
                    <label class="block text-[11px] font-extrabold text-slate-700 uppercase mb-1.5 tracking-wider">Lembaga Pelatihan (LPK)</label>
                    <select name="lpk_report" id="lpk_report" onchange="perbaruiJenisPelatihan()" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] shadow-2xs cursor-pointer text-slate-700 font-medium">
                        <option value="">-- Seluruh Lembaga Pelatihan --</option>
                        <?php foreach ($listLpkUjian as $lpkU): ?>
                        <option value="<?php echo htmlspecialchars((string)$lpkU); ?>"><?php echo htmlspecialchars((string)$lpkU); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-extrabold text-slate-700 uppercase mb-1.5 tracking-wider">Jenis Pelatihan</label>
                    <select name="jenis_pel_report" id="jenis_pel_report" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] shadow-2xs cursor-pointer text-slate-700 font-medium">
                        <option value="">-- Seluruh Jenis Pelatihan --</option>
                    </select>
                </div>
                
                <div class="pt-2">
                    <button type="submit" onclick="tutupModalReport()" class="w-full py-3 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold text-xs rounded-lg transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-2">
                        <i class="fas fa-file-excel text-sm"></i>
                        Ekstrak Data Laporan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalDetail" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-3xl w-full p-6 sm:p-7 shadow-2xl border border-slate-200 max-h-[90vh] flex flex-col relative overflow-hidden">
            <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
            
            <button type="button" onclick="tutupModalDetail()" class="absolute top-4 right-4 w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-lg flex items-center justify-center font-bold transition text-xs">
                &#10005;
            </button>

            <div class="pb-3 border-b border-slate-200 mb-4 flex-shrink-0 pr-10">
                <span class="text-[10px] font-extrabold text-[#0e74bb] uppercase tracking-widest bg-blue-50 px-2.5 py-1 rounded-md border border-blue-100">Evaluasi Jawaban</span>
                <h3 class="font-black text-slate-900 text-lg tracking-tight mt-2">Lembar Jawaban Peserta</h3>
                <p id="modalSubJudul" class="text-xs text-slate-500 font-mono mt-0.5 font-bold"></p>
            </div>

            <div id="kontenDetailJawaban" class="overflow-y-auto flex-grow space-y-3 pr-1 text-sm">
                <div class="text-center py-10 text-slate-400">Memuat rincian jawaban...</div>
            </div>

            <div class="pt-4 border-t border-slate-200 flex justify-end flex-shrink-0 mt-4">
                <button type="button" onclick="tutupModalDetail()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition uppercase tracking-wider">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <script>
        const petaBatchLpkUtama = <?php echo json_encode($batchLpkMap); ?>;
        const semuaLpkList = <?php echo json_encode($semuaLpkList); ?>;
        const selectedLpk = "<?php echo addslashes($filterLpk); ?>";
        const petaLpkJenis = <?php echo json_encode($lpkJenisMap); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            if(document.getElementById('filter_batch')) {
                perbaruiFilterLpk();
            }
        });

        function perbaruiFilterLpk() {
            const batch = document.getElementById('filter_batch').value;
            const lpkDropdown = document.getElementById('filter_lpk');
            
            lpkDropdown.innerHTML = '<option value="">Semua Lembaga Pelatihan</option>';
            
            let lpkOptions = [];
            if (batch === '') {
                lpkOptions = semuaLpkList;
            } else {
                lpkOptions = petaBatchLpkUtama[batch] || [];
            }

            lpkOptions.forEach(function(lpk) {
                const opsi = document.createElement('option');
                opsi.value = lpk;
                opsi.textContent = lpk;
                if (lpk === selectedLpk) opsi.selected = true;
                lpkDropdown.appendChild(opsi);
            });
        }

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

        function bukaModalReport() {
            document.getElementById('modalReport').classList.remove('hidden');
        }

        function tutupModalReport() {
            document.getElementById('modalReport').classList.add('hidden');
        }

        function perbaruiJenisPelatihan() {
            const lpk = document.getElementById('lpk_report').value;
            const jenisDropdown = document.getElementById('jenis_pel_report');
            
            jenisDropdown.innerHTML = '<option value="">-- Seluruh Jenis Pelatihan --</option>';
            
            if (lpk && petaLpkJenis[lpk]) {
                const jenisUnik = [...new Set(petaLpkJenis[lpk])];
                jenisUnik.forEach(function(jns) {
                    const opsi = document.createElement('option');
                    opsi.value = jns;
                    opsi.textContent = jns;
                    jenisDropdown.appendChild(opsi);
                });
            }
        }

        async function bukaModalDetail(cpmiId, nama) {
            document.getElementById('modalSubJudul').textContent = nama + ' (CPMI-ID: ' + cpmiId + ')';
            const container = document.getElementById('kontenDetailJawaban');
            container.innerHTML = '<div class="text-center py-10 text-slate-400">Memuat rincian jawaban...</div>';
            document.getElementById('modalDetail').classList.remove('hidden');

            try {
                const response = await fetch('hasil_ujian.php?ajax_detail=' + encodeURIComponent(cpmiId));
                const data = await response.json();

                if (!data || data.length === 0 || data.error) {
                    container.innerHTML = '<div class="text-center py-10 text-rose-500">' + (data.error || 'Belum ada data lembar jawaban tersimpan.') + '</div>';
                    return;
                }

                container.innerHTML = data.map(function(item, idx) {
                    var isInterview = (item.jenis_ujian === 'interview');
                    var dijawab = item.jawaban_pilihan || '-';
                    var badgeSkor = '';

                    if (isInterview) {
                        var poin = 0;
                        if (dijawab === 'A') poin = item.poin_a;
                        else if (dijawab === 'B') poin = item.poin_b;
                        else if (dijawab === 'C') poin = item.poin_c;
                        else if (dijawab === 'D') poin = item.poin_d;
                        badgeSkor = '<span class="bg-purple-50 text-purple-700 border border-purple-200 text-xs font-bold px-2 py-0.5 rounded-md">Bobot: ' + poin + ' pt</span>';
                    } else {
                        var isBenar = (dijawab !== '-' && dijawab === item.kunci);
                        badgeSkor = isBenar 
                            ? '<span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold px-2 py-0.5 rounded-md">Benar (+1)</span>'
                            : '<span class="bg-rose-50 text-rose-700 border border-rose-200 text-xs font-bold px-2 py-0.5 rounded-md">Salah (Kunci: ' + (item.kunci || '-') + ')</span>';
                    }

                    return '<div class="p-4 rounded-xl border border-slate-200 bg-slate-50/70">' +
                           '<div class="flex justify-between items-start mb-2 gap-2">' +
                           '<span class="text-xs font-extrabold uppercase text-slate-500">Soal #' + (idx + 1) + ' (' + item.jenis_ujian + ')</span>' +
                           '<div>' + badgeSkor + '</div>' +
                           '</div>' +
                           '<p class="font-bold text-slate-800 mb-3 text-xs sm:text-sm">' + item.pertanyaan + '</p>' +
                           '<div class="text-xs font-medium text-slate-600 bg-white p-2.5 rounded-lg border border-slate-200">' +
                           'Pilihan: <strong class="text-[#0e74bb] text-sm font-mono">' + dijawab + '</strong> ' +
                           (dijawab !== '-' ? '— ' + (item['opsi_' + dijawab.toLowerCase()] || '') : '(Belum dijawab)') +
                           '</div>' +
                           '</div>';
                }).join('');

            } catch (err) {
                container.innerHTML = '<div class="text-center py-10 text-rose-500">Gagal mengambil rincian: ' + err.message + '</div>';
            }
        }

        function tutupModalDetail() {
            document.getElementById('modalDetail').classList.add('hidden');
        }
    </script>
</body>
</html>