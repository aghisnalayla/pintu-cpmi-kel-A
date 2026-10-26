<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (file_exists(__DIR__ . '/../api/helper_log.php')) {
    require_once __DIR__ . '/../api/helper_log.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_peserta') {
        $cpmi_id           = trim($_POST['cpmi_id'] ?? '');
        $nama_lengkap      = trim($_POST['nama_lengkap'] ?? '');
        $nik_sesuai_ktp    = trim($_POST['nik_sesuai_ktp'] ?? '');
        $tanggal_lahir     = trim($_POST['tanggal_lahir'] ?? '');
        $jenis_kelamin     = trim($_POST['jenis_kelamin'] ?? '');
        $batch_pelatihan   = trim($_POST['batch_pelatihan'] ?? '');
        $lembaga_pelatihan = trim($_POST['lembaga_pelatihan'] ?? '');
        $status_verifikasi = trim($_POST['status_verifikasi'] ?? '');
        
        $sv_clean = strtoupper(trim($status_verifikasi));
        
        $akses_tertulis    = isset($_POST['akses_tertulis']) ? 1 : 0;
        $status_tertulis   = trim($_POST['status_tertulis'] ?? 'belum');
        $akses_interview   = isset($_POST['akses_interview']) ? 1 : 0;
        $status_interview  = trim($_POST['status_interview'] ?? 'belum');

        if ($sv_clean !== 'MEMENUHI PERSYARATAN') {
            $akses_tertulis  = 0;
            $akses_interview = 0;
        }

        if ($akses_tertulis === 0) {
            $status_tertulis = 'belum';
        }
        if ($akses_interview === 0) {
            $status_interview = 'belum';
        }

        $status_ujian_global = 'belum';
        $adaAksesAktif = ($akses_tertulis === 1 || $akses_interview === 1);
        if ($adaAksesAktif) {
            $semuaSelesai = true;
            if ($akses_tertulis === 1 && $status_tertulis !== 'selesai') {
                $semuaSelesai = false;
            }
            if ($akses_interview === 1 && $status_interview !== 'selesai') {
                $semuaSelesai = false;
            }

            if ($semuaSelesai) {
                $status_ujian_global = 'selesai';
            } elseif ($status_tertulis === 'sedang' || $status_interview === 'sedang' || $status_tertulis === 'selesai' || $status_interview === 'selesai') {
                $status_ujian_global = 'sedang';
            } else {
                $status_ujian_global = 'belum';
            }
        }

        try {
            $pdo->beginTransaction();

            $stmtUpdate = $pdo->prepare("
                UPDATE tb_pendaftaran SET 
                    nama_lengkap = ?, nik_sesuai_ktp = ?, tanggal_lahir = ?,
                    jenis_kelamin = ?, batch_pelatihan = ?, lembaga_pelatihan = ?,
                    akses_tertulis = ?, status_tertulis = ?, akses_interview = ?, status_interview = ?,
                    status_ujian = ?
                WHERE cpmi_id = ?
            ");
            $stmtUpdate->execute([
                $nama_lengkap, $nik_sesuai_ktp, $tanggal_lahir,
                $jenis_kelamin, $batch_pelatihan, $lembaga_pelatihan,
                $akses_tertulis, $status_tertulis, $akses_interview, $status_interview,
                $status_ujian_global, $cpmi_id
            ]);

            if ($sv_clean === 'MEMENUHI PERSYARATAN') {
                $stmtSV = $pdo->prepare("
                    INSERT INTO tb_status_verifikasi (cpmi_id, status, catatan)
                    VALUES (?, 'MEMENUHI PERSYARATAN', 'Diverifikasi admin')
                    ON DUPLICATE KEY UPDATE status = 'MEMENUHI PERSYARATAN', catatan = 'Diverifikasi admin'
                ");
                $stmtSV->execute([$cpmi_id]);

                if (!empty($nik_sesuai_ktp)) {
                    $stmtGugur = $pdo->prepare("
                        INSERT INTO tb_status_verifikasi (cpmi_id, status, catatan)
                        SELECT cpmi_id, 'TIDAK MEMENUHI PERSYARATAN', CONCAT('Gugur otomatis: Duplikasi NIK dengan CPMI ID #', ?)
                        FROM tb_pendaftaran
                        WHERE nik_sesuai_ktp = ? AND cpmi_id != ?
                        ON DUPLICATE KEY UPDATE 
                            status = 'TIDAK MEMENUHI PERSYARATAN', 
                            catatan = CONCAT('Gugur otomatis: Duplikasi NIK dengan CPMI ID #', ?)
                    ");
                    $stmtGugur->execute([$cpmi_id, $nik_sesuai_ktp, $cpmi_id, $cpmi_id]);

                    $stmtRevokeP = $pdo->prepare("
                        UPDATE tb_pendaftaran 
                        SET akses_tertulis = 0, status_tertulis = 'belum', akses_interview = 0, status_interview = 'belum', status_ujian = 'belum'
                        WHERE nik_sesuai_ktp = ? AND cpmi_id != ?
                    ");
                    $stmtRevokeP->execute([$nik_sesuai_ktp, $cpmi_id]);

                    $stmtRevokePU = $pdo->prepare("
                        DELETE pu FROM tb_peserta_ujian pu 
                        JOIN tb_pendaftaran p ON pu.cpmi_id = p.cpmi_id 
                        WHERE p.nik_sesuai_ktp = ? AND pu.cpmi_id != ?
                    ");
                    $stmtRevokePU->execute([$nik_sesuai_ktp, $cpmi_id]);
                }

            } elseif ($sv_clean === 'TIDAK MEMENUHI PERSYARATAN') {
                $stmtSV = $pdo->prepare("
                    INSERT INTO tb_status_verifikasi (cpmi_id, status, catatan)
                    VALUES (?, 'TIDAK MEMENUHI PERSYARATAN', 'Ditolak admin')
                    ON DUPLICATE KEY UPDATE status = 'TIDAK MEMENUHI PERSYARATAN', catatan = 'Ditolak admin'
                ");
                $stmtSV->execute([$cpmi_id]);

                $stmtDelPU = $pdo->prepare("DELETE FROM tb_peserta_ujian WHERE cpmi_id = ?");
                $stmtDelPU->execute([$cpmi_id]);
            }

            if ($akses_tertulis === 1 && $sv_clean === 'MEMENUHI PERSYARATAN') {
                $stUpper = strtoupper($status_tertulis);
                $stmtPlotT = $pdo->prepare("
                    INSERT INTO tb_peserta_ujian (cpmi_id, jenis_ujian, status_pengerjaan) 
                    VALUES (?, 'TERTULIS', ?) 
                    ON DUPLICATE KEY UPDATE status_pengerjaan = ?
                ");
                $stmtPlotT->execute([$cpmi_id, $stUpper, $stUpper]);
            } else {
                $stmtDelT = $pdo->prepare("DELETE FROM tb_peserta_ujian WHERE cpmi_id = ? AND jenis_ujian = 'TERTULIS'");
                $stmtDelT->execute([$cpmi_id]);
            }

            if ($akses_interview === 1 && $sv_clean === 'MEMENUHI PERSYARATAN') {
                $siUpper = strtoupper($status_interview);
                $stmtPlotI = $pdo->prepare("
                    INSERT INTO tb_peserta_ujian (cpmi_id, jenis_ujian, status_pengerjaan) 
                    VALUES (?, 'INTERVIEW', ?) 
                    ON DUPLICATE KEY UPDATE status_pengerjaan = ?
                ");
                $stmtPlotI->execute([$cpmi_id, $siUpper, $siUpper]);
            } else {
                $stmtDelI = $pdo->prepare("DELETE FROM tb_peserta_ujian WHERE cpmi_id = ? AND jenis_ujian = 'INTERVIEW'");
                $stmtDelI->execute([$cpmi_id]);
            }

            if (function_exists('catatLog')) {
                catatLog($pdo, "Memperbarui data dan hak akses peserta CPMI #{$cpmi_id}", 'PESERTA');
            }

            $pdo->commit();
            $_SESSION['notif'] = ['type' => 'success', 'msg' => "Data dan akses {$nama_lengkap} berhasil diperbarui."];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['notif'] = ['type' => 'error', 'msg' => 'Gagal memperbarui: ' . $e->getMessage()];
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'reset_ujian') {
        $cpmi_id = trim($_POST['cpmi_id'] ?? '');
        try {
            $pdo->beginTransaction();
            $stmtReset = $pdo->prepare("UPDATE tb_pendaftaran SET status_ujian = 'belum', status_tertulis = 'belum', status_interview = 'belum' WHERE cpmi_id = ?");
            $stmtReset->execute([$cpmi_id]);
            
            $stmtResetPU = $pdo->prepare("UPDATE tb_peserta_ujian SET status_pengerjaan = 'BELUM' WHERE cpmi_id = ?");
            $stmtResetPU->execute([$cpmi_id]);
            
            $stmtDel = $pdo->prepare("DELETE FROM tb_jawaban_peserta WHERE cpmi_id = ?");
            $stmtDel->execute([$cpmi_id]);

            if (function_exists('catatLog')) {
                catatLog($pdo, "Mereset total ujian peserta CPMI #{$cpmi_id}", 'UJIAN');
            }
            
            $pdo->commit();
            $_SESSION['notif'] = ['type' => 'success', 'msg' => "Status ujian {$cpmi_id} berhasil direset."];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['notif'] = ['type' => 'error', 'msg' => 'Gagal mereset: ' . $e->getMessage()];
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'saklar_ujian') {
        $lembaga_target = trim($_POST['lembaga_pelatihan'] ?? '');
        $status_saklar  = strtolower(trim($_POST['status_saklar'] ?? ''));

        if (empty($lembaga_target)) {
            $_SESSION['notif'] = ['type' => 'error', 'msg' => 'Pilih Lembaga Pelatihan terlebih dahulu.'];
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            if ($status_saklar === 'aktif') {
                $sqlPendaftaran = "
                    UPDATE tb_pendaftaran p
                    JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
                    SET 
                        p.akses_tertulis = 1,
                        p.status_tertulis = 'belum',
                        p.akses_interview = 1,
                        p.status_interview = 'belum',
                        p.status_ujian = 'belum'
                    WHERE TRIM(p.lembaga_pelatihan) = :lpk
                      AND (
                          (UPPER(TRIM(sv.status)) LIKE '%MEMENUHI%' AND UPPER(TRIM(sv.status)) NOT LIKE '%TIDAK%')
                          OR UPPER(TRIM(sv.status)) = 'MS'
                      )
                      AND p.cpmi_id IN (
                          SELECT id_terbaru FROM (
                              SELECT MAX(cpmi_id) AS id_terbaru
                              FROM tb_pendaftaran
                              WHERE TRIM(lembaga_pelatihan) = :lpk_sub
                              GROUP BY CASE WHEN nik_sesuai_ktp IS NOT NULL AND TRIM(nik_sesuai_ktp) != '' THEN TRIM(nik_sesuai_ktp) ELSE CONCAT('ID_', cpmi_id) END
                          ) AS sub
                      )
                ";
                $stmtP = $pdo->prepare($sqlPendaftaran);
                $stmtP->execute([
                    ':lpk'     => $lembaga_target,
                    ':lpk_sub' => $lembaga_target
                ]);

                $sqlTertulis = "
                    INSERT INTO tb_peserta_ujian (cpmi_id, jenis_ujian, status_pengerjaan)
                    SELECT 
                        p.cpmi_id, 
                        'TERTULIS', 
                        'BELUM'
                    FROM tb_pendaftaran p
                    JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
                    WHERE TRIM(p.lembaga_pelatihan) = :lpk
                      AND (
                          (UPPER(TRIM(sv.status)) LIKE '%MEMENUHI%' AND UPPER(TRIM(sv.status)) NOT LIKE '%TIDAK%')
                          OR UPPER(TRIM(sv.status)) = 'MS'
                      )
                      AND p.cpmi_id IN (
                          SELECT id_terbaru FROM (
                              SELECT MAX(cpmi_id) AS id_terbaru
                              FROM tb_pendaftaran
                              WHERE TRIM(lembaga_pelatihan) = :lpk_sub
                              GROUP BY CASE WHEN nik_sesuai_ktp IS NOT NULL AND TRIM(nik_sesuai_ktp) != '' THEN TRIM(nik_sesuai_ktp) ELSE CONCAT('ID_', cpmi_id) END
                          ) AS sub
                      )
                    ON DUPLICATE KEY UPDATE status_pengerjaan = 'BELUM'
                ";
                $stmtT = $pdo->prepare($sqlTertulis);
                $stmtT->execute([
                    ':lpk'     => $lembaga_target,
                    ':lpk_sub' => $lembaga_target
                ]);

                $sqlInterview = "
                    INSERT INTO tb_peserta_ujian (cpmi_id, jenis_ujian, status_pengerjaan)
                    SELECT 
                        p.cpmi_id, 
                        'INTERVIEW', 
                        'BELUM'
                    FROM tb_pendaftaran p
                    JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
                    WHERE TRIM(p.lembaga_pelatihan) = :lpk
                      AND (
                          (UPPER(TRIM(sv.status)) LIKE '%MEMENUHI%' AND UPPER(TRIM(sv.status)) NOT LIKE '%TIDAK%')
                          OR UPPER(TRIM(sv.status)) = 'MS'
                      )
                      AND p.cpmi_id IN (
                          SELECT id_terbaru FROM (
                              SELECT MAX(cpmi_id) AS id_terbaru
                              FROM tb_pendaftaran
                              WHERE TRIM(lembaga_pelatihan) = :lpk_sub
                              GROUP BY CASE WHEN nik_sesuai_ktp IS NOT NULL AND TRIM(nik_sesuai_ktp) != '' THEN TRIM(nik_sesuai_ktp) ELSE CONCAT('ID_', cpmi_id) END
                          ) AS sub
                      )
                    ON DUPLICATE KEY UPDATE status_pengerjaan = 'BELUM'
                ";
                $stmtI = $pdo->prepare($sqlInterview);
                $stmtI->execute([
                    ':lpk'     => $lembaga_target,
                    ':lpk_sub' => $lembaga_target
                ]);

                if (function_exists('catatLog')) {
                    catatLog($pdo, "Saklar Ujian DIAKTIFKAN untuk lembaga '{$lembaga_target}'", 'UJIAN');
                }

                $pdo->commit();
                $_SESSION['notif'] = [
                    'type' => 'success',
                    'msg'  => "Saklar Ujian berhasil DIAKTIFKAN untuk {$lembaga_target}. Akses ujian tertulis & interview telah dibuka untuk peserta yang Memenuhi Syarat (MS)."
                ];

            } elseif ($status_saklar === 'nonaktif') {
                $sqlNonP = "
                    UPDATE tb_pendaftaran p
                    JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
                    SET 
                        p.akses_tertulis = 0,
                        p.status_tertulis = 'belum',
                        p.akses_interview = 0,
                        p.status_interview = 'belum',
                        p.status_ujian = 'belum'
                    WHERE TRIM(p.lembaga_pelatihan) = :lpk
                      AND (
                          (UPPER(TRIM(sv.status)) LIKE '%MEMENUHI%' AND UPPER(TRIM(sv.status)) NOT LIKE '%TIDAK%')
                          OR UPPER(TRIM(sv.status)) = 'MS'
                      )
                ";
                $stmtNonP = $pdo->prepare($sqlNonP);
                $stmtNonP->execute([':lpk' => $lembaga_target]);

                $sqlDelPU = "
                    DELETE pu FROM tb_peserta_ujian pu
                    JOIN tb_pendaftaran p ON pu.cpmi_id = p.cpmi_id
                    JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
                    WHERE TRIM(p.lembaga_pelatihan) = :lpk
                      AND (
                          (UPPER(TRIM(sv.status)) LIKE '%MEMENUHI%' AND UPPER(TRIM(sv.status)) NOT LIKE '%TIDAK%')
                          OR UPPER(TRIM(sv.status)) = 'MS'
                      )
                ";
                $stmtDel = $pdo->prepare($sqlDelPU);
                $stmtDel->execute([':lpk' => $lembaga_target]);

                if (function_exists('catatLog')) {
                    catatLog($pdo, "Saklar Ujian DINONAKTIFKAN untuk lembaga '{$lembaga_target}'", 'UJIAN');
                }

                $pdo->commit();
                $_SESSION['notif'] = [
                    'type' => 'success',
                    'msg'  => "Saklar Ujian berhasil DINONAKTIFKAN untuk {$lembaga_target}. Akses ujian tertulis & interview telah ditutup."
                ];
            } else {
                $pdo->rollBack();
                $_SESSION['notif'] = ['type' => 'error', 'msg' => 'Aksi saklar ujian tidak valid.'];
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['notif'] = ['type' => 'error', 'msg' => 'Gagal memproses saklar ujian: ' . $e->getMessage()];
        }

        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$notif = $_SESSION['notif'] ?? null;
unset($_SESSION['notif']);

$statData = [
    'total' => 0, 'ms' => 0, 'tms' => 0, 'ganda' => 0,
    'ter_akses' => 0, 'ter_selesai' => 0, 'int_akses' => 0, 'int_selesai' => 0, 'keduanya' => 0
];

try {
    $stmtDupNik = $pdo->query("SELECT nik_sesuai_ktp, COUNT(*) AS jml FROM tb_pendaftaran WHERE nik_sesuai_ktp IS NOT NULL AND nik_sesuai_ktp != '' GROUP BY nik_sesuai_ktp HAVING jml > 1");
    $arrDupNik = [];
    while ($rd = $stmtDupNik->fetch(PDO::FETCH_ASSOC)) {
        $arrDupNik[$rd['nik_sesuai_ktp']] = (int)$rd['jml'];
    }
    $statData['ganda'] = count($arrDupNik);

    $stmtStat = $pdo->query("
        SELECT 
            COUNT(p.cpmi_id) AS total,
            SUM(CASE WHEN UPPER(TRIM(sv.status)) = 'MEMENUHI PERSYARATAN' THEN 1 ELSE 0 END) AS ms,
            SUM(CASE WHEN UPPER(TRIM(sv.status)) = 'TIDAK MEMENUHI PERSYARATAN' THEN 1 ELSE 0 END) AS tms,
            SUM(CASE WHEN p.akses_tertulis = 1 THEN 1 ELSE 0 END) AS ter_akses,
            SUM(CASE WHEN p.akses_tertulis = 1 AND p.status_tertulis = 'selesai' THEN 1 ELSE 0 END) AS ter_selesai,
            SUM(CASE WHEN p.akses_interview = 1 THEN 1 ELSE 0 END) AS int_akses,
            SUM(CASE WHEN p.akses_interview = 1 AND p.status_interview = 'selesai' THEN 1 ELSE 0 END) AS int_selesai,
            SUM(CASE WHEN p.akses_tertulis = 1 AND p.akses_interview = 1 THEN 1 ELSE 0 END) AS keduanya
        FROM tb_pendaftaran p
        LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
    ");
    $resStat = $stmtStat->fetch(PDO::FETCH_ASSOC);

    if ($resStat) {
        $statData['total']       = (int)$resStat['total'];
        $statData['ms']          = (int)$resStat['ms'];
        $statData['tms']         = (int)$resStat['tms'];
        $statData['ter_akses']   = (int)$resStat['ter_akses'];
        $statData['ter_selesai'] = (int)$resStat['ter_selesai'];
        $statData['int_akses']   = (int)$resStat['int_akses'];
        $statData['int_selesai'] = (int)$resStat['int_selesai'];
        $statData['keduanya']    = (int)$resStat['keduanya'];
    }
} catch (Throwable $e) {}

$keyword       = trim($_GET['q'] ?? '');
$filterBatch   = trim($_GET['batch'] ?? '');
$filterLembaga = trim($_GET['lembaga'] ?? '');
$filterAkses   = trim($_GET['akses'] ?? ''); 
$filterStatus  = trim($_GET['status'] ?? '');
$filterGanda   = trim($_GET['ganda'] ?? ''); 
$halaman       = max(1, (int)($_GET['page'] ?? 1));
$limit         = 50;
$offset        = ($halaman - 1) * $limit;

$daftarPeserta = [];
$totalPeserta  = 0;
$dataLembagaSaklar = [];

try {
    $sqlBase = " FROM tb_pendaftaran p LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id WHERE 1=1 ";
    $params = [];

    if (!empty($keyword)) {
        $sqlBase .= " AND (p.nama_lengkap LIKE ? OR p.nik_sesuai_ktp LIKE ? OR p.cpmi_id LIKE ?) ";
        $params[] = "%{$keyword}%";
        $params[] = "%{$keyword}%";
        $params[] = "%{$keyword}%";
    }
    if (!empty($filterBatch)) {
        $sqlBase .= " AND p.batch_pelatihan = ? ";
        $params[] = $filterBatch;
    }
    if (!empty($filterLembaga)) {
        $sqlBase .= " AND p.lembaga_pelatihan = ? ";
        $params[] = $filterLembaga;
    }
    if ($filterAkses === 'tertulis') {
        $sqlBase .= " AND p.akses_tertulis = 1 ";
    } elseif ($filterAkses === 'interview') {
        $sqlBase .= " AND p.akses_interview = 1 ";
    } elseif ($filterAkses === 'keduanya') {
        $sqlBase .= " AND p.akses_tertulis = 1 AND p.akses_interview = 1 ";
    } elseif ($filterAkses === 'belum') {
        $sqlBase .= " AND (p.akses_tertulis = 0 OR p.akses_tertulis IS NULL) AND (p.akses_interview = 0 OR p.akses_interview IS NULL) ";
    }
    
    if ($filterStatus === 'ms') {
        $sqlBase .= " AND UPPER(TRIM(sv.status)) = 'MEMENUHI PERSYARATAN' ";
    } elseif ($filterStatus === 'tms') {
        $sqlBase .= " AND UPPER(TRIM(sv.status)) = 'TIDAK MEMENUHI PERSYARATAN' ";
    } elseif ($filterStatus === 'belum') {
        $sqlBase .= " AND (sv.status IS NULL OR TRIM(sv.status) = '') ";
    }
    
    if ($filterGanda === '1') {
        if (!empty($arrDupNik)) {
            $inClauseNik = "'" . implode("','", array_keys($arrDupNik)) . "'";
            $sqlBase .= " AND p.nik_sesuai_ktp IN ({$inClauseNik}) ";
        } else {
            $sqlBase .= " AND 1=0 ";
        }
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(p.cpmi_id) " . $sqlBase);
    $stmtCount->execute($params);
    $totalPeserta = (int)$stmtCount->fetchColumn();
    $totalHalaman = max(1, ceil($totalPeserta / $limit));

    $sqlData = "
        SELECT p.*, sv.status AS status_verifikasi, sv.catatan AS catatan_verifikasi
        {$sqlBase} 
        ORDER BY p.cpmi_id ASC 
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmtData = $pdo->prepare($sqlData);
    $stmtData->execute($params);
    $rawDaftar = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawDaftar as $pd) {
        $pd['jml_duplikat'] = $arrDupNik[$pd['nik_sesuai_ktp']] ?? 1;
        $daftarPeserta[] = $pd;
    }

    $listBatch = $pdo->query("SELECT DISTINCT batch_pelatihan FROM tb_pendaftaran WHERE batch_pelatihan IS NOT NULL AND batch_pelatihan != '' ORDER BY batch_pelatihan ASC")->fetchAll(PDO::FETCH_COLUMN);
    $listLembaga = $pdo->query("SELECT DISTINCT lembaga_pelatihan FROM tb_pendaftaran WHERE lembaga_pelatihan IS NOT NULL AND lembaga_pelatihan != '' ORDER BY lembaga_pelatihan ASC")->fetchAll(PDO::FETCH_COLUMN);

    $stmtLembagaSaklar = $pdo->query("
        SELECT 
            TRIM(p.lembaga_pelatihan) AS lembaga_pelatihan,
            COUNT(p.cpmi_id) AS total_peserta,
            SUM(CASE WHEN ((UPPER(TRIM(sv.status)) LIKE '%MEMENUHI%' AND UPPER(TRIM(sv.status)) NOT LIKE '%TIDAK%') OR UPPER(TRIM(sv.status)) = 'MS') THEN 1 ELSE 0 END) AS total_ms,
            SUM(CASE WHEN ((UPPER(TRIM(sv.status)) LIKE '%MEMENUHI%' AND UPPER(TRIM(sv.status)) NOT LIKE '%TIDAK%') OR UPPER(TRIM(sv.status)) = 'MS') AND p.akses_tertulis = 1 AND p.akses_interview = 1 THEN 1 ELSE 0 END) AS ms_full_aktif,
            SUM(CASE WHEN ((UPPER(TRIM(sv.status)) LIKE '%MEMENUHI%' AND UPPER(TRIM(sv.status)) NOT LIKE '%TIDAK%') OR UPPER(TRIM(sv.status)) = 'MS') AND (p.akses_tertulis = 1 OR p.akses_interview = 1) THEN 1 ELSE 0 END) AS ms_any_aktif
        FROM tb_pendaftaran p
        LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
        WHERE p.lembaga_pelatihan IS NOT NULL AND TRIM(p.lembaga_pelatihan) != ''
        GROUP BY TRIM(p.lembaga_pelatihan)
        ORDER BY TRIM(p.lembaga_pelatihan) ASC
    ");
    $dataLembagaSaklar = $stmtLembagaSaklar ? $stmtLembagaSaklar->fetchAll(PDO::FETCH_ASSOC) : [];

} catch (Throwable $e) {
    $notif = ['type' => 'error', 'msg' => 'Kesalahan Database: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kontrol Peserta Ujian — BP3MI Jawa Tengah</title>
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
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight uppercase">Kontrol Peserta Ujian</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium pl-5">Monitoring data peserta, verifikasi kelayakan, dan pengelolaan hak akses ujian.</p>
                </div>
                
                <div class="flex items-center gap-2.5 flex-wrap">
                    <div class="inline-flex items-center gap-3 bg-slate-50 px-3.5 py-2 rounded-lg border border-slate-200 shadow-2xs shrink-0">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                        <div class="text-right">
                            <div id="liveClock" class="text-xs sm:text-sm font-bold text-[#0e74bb] font-mono leading-none">--:--:-- WIB</div>
                            <div id="liveDate" class="text-[9px] font-bold text-slate-500 uppercase tracking-wider mt-0.5 leading-none">Memuat Tanggal...</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($notif): ?>
            <div class="p-4 rounded-xl text-xs font-bold border shadow-xs <?php echo $notif['type'] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800'; ?>">
                <?php echo htmlspecialchars($notif['msg']); ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-7 gap-3">
                <a href="peserta_ujian.php" 
                   class="bg-white p-3.5 rounded-xl border-2 <?php echo ($filterAkses === '' && $filterStatus === '' && $filterGanda === '') ? 'border-[#0e74bb] shadow-sm' : 'border-slate-200'; ?> hover:border-slate-300 transition block shadow-xs">
                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block mb-1">Total Peserta</span>
                    <span class="text-xl font-extrabold text-slate-900 block font-mono"><?php echo number_format($statData['total'], 0, ',', '.'); ?></span>
                    <span class="text-[10px] text-slate-500 font-semibold block mt-1">Semua data &rarr;</span>
                </a>
                <a href="peserta_ujian.php?status=ms" 
                   class="bg-white p-3.5 rounded-xl border-2 <?php echo $filterStatus === 'ms' ? 'border-emerald-600 shadow-sm' : 'border-slate-200'; ?> hover:border-emerald-300 transition block shadow-xs">
                    <span class="text-[10px] text-emerald-600 font-bold uppercase tracking-wider block mb-1">Lolos Berkas</span>
                    <span class="text-xl font-extrabold text-emerald-600 block font-mono"><?php echo number_format($statData['ms'], 0, ',', '.'); ?></span>
                    <span class="text-[10px] text-emerald-600 font-semibold block mt-1">Status MS &rarr;</span>
                </a>
                <a href="peserta_ujian.php?status=tms" 
                   class="bg-white p-3.5 rounded-xl border-2 <?php echo $filterStatus === 'tms' ? 'border-rose-600 shadow-sm' : 'border-slate-200'; ?> hover:border-rose-300 transition block shadow-xs">
                    <span class="text-[10px] text-rose-600 font-bold uppercase tracking-wider block mb-1">Tidak Lolos</span>
                    <span class="text-xl font-extrabold text-rose-600 block font-mono"><?php echo number_format($statData['tms'], 0, ',', '.'); ?></span>
                    <span class="text-[10px] text-rose-600 font-semibold block mt-1">Status TMS &rarr;</span>
                </a>
                <a href="peserta_ujian.php?ganda=1" 
                   class="bg-white p-3.5 rounded-xl border-2 <?php echo $filterGanda === '1' ? 'border-amber-500 shadow-sm' : 'border-slate-200'; ?> hover:border-amber-300 transition block shadow-xs">
                    <span class="text-[10px] text-amber-600 font-bold uppercase tracking-wider block mb-1">NIK Ganda</span>
                    <span class="text-xl font-extrabold text-amber-600 block font-mono"><?php echo number_format($statData['ganda'], 0, ',', '.'); ?></span>
                    <span class="text-[10px] text-amber-600 font-semibold block mt-1">Duplikasi &rarr;</span>
                </a>
                <a href="peserta_ujian.php?akses=tertulis" 
                   class="bg-white p-3.5 rounded-xl border-2 <?php echo $filterAkses === 'tertulis' ? 'border-[#0e74bb] shadow-sm' : 'border-slate-200'; ?> hover:border-sky-300 transition block shadow-xs">
                    <span class="text-[10px] text-[#0e74bb] font-bold uppercase tracking-wider block mb-1">Tertulis</span>
                    <span class="text-xl font-extrabold text-[#0e74bb] block font-mono"><?php echo number_format($statData['ter_akses'], 0, ',', '.'); ?></span>
                    <span class="text-[10px] text-sky-600 font-semibold block mt-1"><?php echo $statData['ter_selesai']; ?> selesai &rarr;</span>
                </a>
                <a href="peserta_ujian.php?akses=interview" 
                   class="bg-white p-3.5 rounded-xl border-2 <?php echo $filterAkses === 'interview' ? 'border-purple-600 shadow-sm' : 'border-slate-200'; ?> hover:border-purple-300 transition block shadow-xs">
                    <span class="text-[10px] text-purple-600 font-bold uppercase tracking-wider block mb-1">Interview</span>
                    <span class="text-xl font-extrabold text-purple-600 block font-mono"><?php echo number_format($statData['int_akses'], 0, ',', '.'); ?></span>
                    <span class="text-[10px] text-purple-600 font-semibold block mt-1"><?php echo $statData['int_selesai']; ?> selesai &rarr;</span>
                </a>
                <a href="peserta_ujian.php?akses=keduanya" 
                   class="bg-white p-3.5 rounded-xl border-2 <?php echo $filterAkses === 'keduanya' ? 'border-teal-600 shadow-sm' : 'border-slate-200'; ?> hover:border-teal-300 transition block shadow-xs">
                    <span class="text-[10px] text-teal-600 font-bold uppercase tracking-wider block mb-1">Keduanya</span>
                    <span class="text-xl font-extrabold text-teal-600 block font-mono"><?php echo number_format($statData['keduanya'], 0, ',', '.'); ?></span>
                    <span class="text-[10px] text-teal-600 font-semibold block mt-1">Akses Penuh &rarr;</span>
                </a>
            </div>

            <div id="saklarUjianModul" class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden transition-all duration-200">
                <div class="h-1 w-full bg-gradient-to-r from-emerald-500 via-[#0e74bb] to-sky-600"></div>
                
                <div class="p-5 sm:p-6 border-b border-slate-100 bg-gradient-to-b from-slate-50/70 to-white flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div class="flex items-start gap-3.5">
                        <div class="w-10 h-10 rounded-xl bg-sky-50 text-[#0e74bb] border border-sky-100 flex items-center justify-center shrink-0 shadow-2xs">
                            <i class="fas fa-sliders text-base"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="text-base sm:text-lg font-black text-slate-900 tracking-tight uppercase">Modul Batch Ujian</h3>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-sky-50 text-[#0e74bb] border border-sky-200 tracking-wide uppercase">
                                    Khusus Peserta Memenuhi Syarat
                                </span>
                            </div>
                            <p class="text-xs text-slate-500 mt-1 font-medium">Aktivasi akses ujian tertulis & interview.</p>
                        </div>
                    </div>

                    <button type="button" onclick="toggleSaklarPanel()" id="btnToggleSaklar" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-[#0e74bb] bg-white hover:bg-slate-50 px-3.5 py-2 rounded-lg border border-slate-200 transition shadow-2xs shrink-0">
                        <span id="textToggleSaklar">Tampilkan Button</span>
                        <i id="iconToggleSaklar" class="fas fa-chevron-down text-[10px] transition-transform"></i>
                    </button>
                </div>

                <div id="contentSaklarPanel" class="p-5 sm:p-6 space-y-6 hidden">
                    <div class="bg-slate-50/80 p-4 sm:p-5 rounded-xl border border-slate-200/80 space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="text-[11px] font-black uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
                                 Eksekusi Batch
                            </label>
                            <span class="text-[10px] font-semibold text-slate-500">Pilih lembaga</span>
                        </div>

                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" id="formSaklarCepat" class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
                            <input type="hidden" name="action" value="saklar_ujian">
                            <input type="hidden" name="status_saklar" id="quick_status_saklar" value="aktif">
                            
                            <div class="flex-1 relative">
                                <select name="lembaga_pelatihan" id="quick_lembaga_pelatihan" required class="w-full h-11 px-3.5 rounded-lg border border-slate-300 text-xs bg-white font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition shadow-2xs">
                                    <option value="">Pilih Lembaga Pelatihan</option>
                                    <?php foreach ($dataLembagaSaklar as $ls): ?>
                                    <option value="<?php echo htmlspecialchars($ls['lembaga_pelatihan']); ?>" data-ms="<?php echo (int)$ls['total_ms']; ?>">
                                        <?php echo htmlspecialchars($ls['lembaga_pelatihan']); ?> &bull; (<?php echo (int)$ls['total_ms']; ?> Peserta MS / <?php echo (int)$ls['total_peserta']; ?> Total)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" onclick="submitSaklarCepat('aktif')" class="flex-1 sm:flex-initial h-11 bg-emerald-600 hover:bg-emerald-700 text-white font-black px-4 rounded-lg text-xs transition shadow-xs flex items-center justify-center gap-2 uppercase tracking-wider">
                                    <i class="fas fa-toggle-on text-sm"></i> Ujian Aktif
                                </button>
                                <button type="button" onclick="submitSaklarCepat('nonaktif')" class="flex-1 sm:flex-initial h-11 bg-rose-600 hover:bg-rose-700 text-white font-black px-4 rounded-lg text-xs transition shadow-xs flex items-center justify-center gap-2 uppercase tracking-wider">
                                    <i class="fas fa-toggle-off text-sm"></i> Ujian Tidak Aktif
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="border border-slate-200 rounded-xl overflow-hidden shadow-2xs">
                        <div class="px-4 py-3 bg-slate-100/70 border-b border-slate-200 flex flex-wrap justify-between items-center text-xs font-bold text-slate-700 gap-2">
                            <span class="flex items-center gap-2">
                                Daftar Lembaga Pelatihan (Total: <?php echo count($dataLembagaSaklar); ?> Lembaga)
                            </span>
                            <span class="text-[10px] text-slate-500 font-semibold">Action per Lembaga Pelatihan</span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs text-slate-700">
                                <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-extrabold tracking-wider border-b border-slate-200">
                                    <tr>
                                        <th class="py-3 px-4 w-12 text-center">No</th>
                                        <th class="py-3 px-4 min-w-[220px]">Lembaga Pelatihan</th>
                                        <th class="py-3 px-4 text-center w-28">Total CPMI</th>
                                        <th class="py-3 px-4 text-center w-32">Lolos Berkas</th>
                                        <th class="py-3 px-4 text-center min-w-[150px]">Status Ujian Saat Ini</th>
                                        <th class="py-3 px-4 text-center min-w-[240px]">Kontrol Akses Ujian</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white font-medium">
                                    <?php if (empty($dataLembagaSaklar)): ?>
                                    <tr><td colspan="6" class="px-6 py-8 text-center text-slate-400">Belum ada data Lembaga Pelatihan terdaftar.</td></tr>
                                    <?php else: ?>
                                    <?php $noSaklar = 1; foreach ($dataLembagaSaklar as $lpk): ?>
                                    <?php 
                                        $totMS = (int)$lpk['total_ms'];
                                        $totAktif = (int)$lpk['ms_full_aktif'];
                                        $anyAktif = (int)$lpk['ms_any_aktif'];
                                    ?>
                                    <tr class="hover:bg-sky-50/30 transition">
                                        <td class="py-3 px-4 text-center text-slate-400 font-mono text-xs"><?php echo $noSaklar++; ?></td>
                                        <td class="py-3 px-4">
                                            <span class="font-extrabold text-slate-900 block"><?php echo htmlspecialchars($lpk['lembaga_pelatihan']); ?></span>
                                            <a href="peserta_ujian.php?lembaga=<?php echo urlencode($lpk['lembaga_pelatihan']); ?>" class="text-[10px] text-[#0e74bb] hover:underline font-bold inline-flex items-center gap-1 mt-0.5">
                                                <i class="fas fa-filter text-[9px]"></i> Filter peserta lembaga ini &rarr;
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-center font-mono font-bold text-slate-700">
                                            <?php echo number_format($lpk['total_peserta'], 0, ',', '.'); ?>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md font-mono text-xs font-extrabold <?php echo $totMS > 0 ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500 border border-slate-200'; ?>">
                                                <?php echo $totMS; ?> Peserta
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-center whitespace-nowrap">
                                            <?php if ($totMS === 0): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-500 border border-slate-200">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>Belum Ada MS
                                                </span>
                                            <?php elseif ($totAktif === $totMS): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>Semua Aktif (<?php echo $totAktif; ?>/<?php echo $totMS; ?>)
                                                </span>
                                            <?php elseif ($anyAktif > 0): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>Sebagian Aktif (<?php echo $anyAktif; ?>/<?php echo $totMS; ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>Ujian Nonaktif
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-center whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1.5">
                                                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" onsubmit="return confirm('Aktifkan ujian tertulis & interview untuk seluruh peserta MS (<?php echo $totMS; ?> orang) pada lembaga \'<?php echo addslashes($lpk['lembaga_pelatihan']); ?>\'?');" class="inline">
                                                    <input type="hidden" name="action" value="saklar_ujian">
                                                    <input type="hidden" name="status_saklar" value="aktif">
                                                    <input type="hidden" name="lembaga_pelatihan" value="<?php echo htmlspecialchars($lpk['lembaga_pelatihan']); ?>">
                                                    <button type="submit" <?php echo $totMS === 0 ? 'disabled' : ''; ?> class="inline-flex items-center gap-1.5 <?php echo $totMS === 0 ? 'bg-slate-200 text-slate-400 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700 text-white'; ?> font-bold px-3 py-1.5 rounded-lg text-xs transition shadow-2xs">
                                                        <i class="fas fa-toggle-on text-[11px]"></i> Ujian Aktif
                                                    </button>
                                                </form>

                                                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" onsubmit="return confirm('Nonaktifkan / kunci ujian untuk seluruh peserta pada lembaga \'<?php echo addslashes($lpk['lembaga_pelatihan']); ?>\'?');" class="inline">
                                                    <input type="hidden" name="action" value="saklar_ujian">
                                                    <input type="hidden" name="status_saklar" value="nonaktif">
                                                    <input type="hidden" name="lembaga_pelatihan" value="<?php echo htmlspecialchars($lpk['lembaga_pelatihan']); ?>">
                                                    <button type="submit" class="inline-flex items-center gap-1.5 bg-rose-50 hover:bg-rose-600 hover:text-white text-rose-700 border border-rose-200 font-bold px-3 py-1.5 rounded-lg text-xs transition shadow-2xs">
                                                        <i class="fas fa-toggle-off text-[11px]"></i> Ujian Tidak Aktif
                                                    </button>
                                                </form>
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
            </div>

            <div class="bg-white p-5 sm:p-6 rounded-xl border border-slate-200 shadow-xs">
                <form method="GET" action="peserta_ujian.php" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                        <div class="md:col-span-6 lg:col-span-5">
                            <div class="relative">
                                <input type="text" name="q" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="Cari Nama Peserta, NIK KTP, atau CPMI-ID..." 
                                       class="w-full h-11 pl-10 pr-4 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                                <i class="fas fa-search text-slate-400 absolute left-3.5 top-3.5 text-xs"></i>
                            </div>
                        </div>
                        <div class="md:col-span-3 lg:col-span-4">
                            <select name="lembaga" class="w-full h-11 px-3 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                                <option value="">Semua Lembaga Pelatihan</option>
                                <?php foreach ($listLembaga as $l): ?>
                                <option value="<?php echo htmlspecialchars($l); ?>" <?php echo $filterLembaga === $l ? 'selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-3 lg:col-span-3">
                            <select name="batch" class="w-full h-11 px-3 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                                <option value="">Semua Batch Pelatihan</option>
                                <?php foreach ($listBatch as $b): ?>
                                <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $filterBatch === $b ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-12 gap-3">
                        <div class="md:col-span-3">
                            <select name="akses" class="w-full h-11 px-3 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                                <option value="">Semua Hak Akses Ujian</option>
                                <option value="tertulis" <?php echo $filterAkses === 'tertulis' ? 'selected' : ''; ?>>Akses Tertulis Saja</option>
                                <option value="interview" <?php echo $filterAkses === 'interview' ? 'selected' : ''; ?>>Akses Interview Saja</option>
                                <option value="keduanya" <?php echo $filterAkses === 'keduanya' ? 'selected' : ''; ?>>Akses Keduanya</option>
                                <option value="belum" <?php echo $filterAkses === 'belum' ? 'selected' : ''; ?>>Belum Memiliki Akses</option>
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <select name="status" class="w-full h-11 px-3 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                                <option value="">Semua Status Berkas</option>
                                <option value="ms" <?php echo $filterStatus === 'ms' ? 'selected' : ''; ?>>✓ Memenuhi Syarat (MS)</option>
                                <option value="tms" <?php echo $filterStatus === 'tms' ? 'selected' : ''; ?>>✗ Tidak Memenuhi (TMS)</option>
                                <option value="belum" <?php echo $filterStatus === 'belum' ? 'selected' : ''; ?>>⏳ Belum Diverifikasi</option>
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <select name="ganda" class="w-full h-11 px-3 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] transition">
                                <option value="">Semua Status NIK</option>
                                <option value="1" <?php echo $filterGanda === '1' ? 'selected' : ''; ?>>Hanya NIK Ganda</option>
                            </select>
                        </div>
                        <div class="md:col-span-3 flex gap-2 h-11 items-center">
                            <button type="submit" class="flex-1 h-full bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold px-4 rounded-lg text-xs transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-1.5">
                                <i class="fas fa-search text-[11px]"></i> Cari Data
                            </button>
                            <?php if (!empty($keyword) || !empty($filterBatch) || !empty($filterLembaga) || !empty($filterAkses) || !empty($filterStatus) || !empty($filterGanda)): ?>
                            <a href="peserta_ujian.php" class="px-4 h-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg text-xs transition flex items-center justify-center border border-slate-200 uppercase">Reset</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4 mb-10">
                <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-50/70 flex flex-wrap justify-between items-center text-xs font-semibold text-slate-600 gap-2">
                    <span>Filter aktif: <b class="uppercase text-[#0e74bb]"><?php 
                        $fLabel = [];
                        if ($filterAkses) $fLabel[] = 'Akses: ' . $filterAkses;
                        if ($filterStatus) $fLabel[] = 'Status: ' . strtoupper($filterStatus);
                        if ($filterGanda) $fLabel[] = 'Hanya NIK Ganda';
                        if ($filterBatch) $fLabel[] = 'Batch: ' . $filterBatch;
                        if ($filterLembaga) $fLabel[] = 'Lembaga: ' . $filterLembaga;
                        echo !empty($fLabel) ? implode(', ', $fLabel) : 'Semua Peserta';
                    ?></b></span>
                    <span>Total data ditemukan: <b><?php echo number_format($totalPeserta, 0, ',', '.'); ?></b></span>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead class="bg-slate-100/80 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="py-3 px-5 w-32 whitespace-nowrap">CPMI-ID</th>
                                <th class="py-3 px-5 min-w-[240px]">Peserta & NIK</th>
                                <th class="py-3 px-5 w-40 whitespace-nowrap">Status Berkas</th>
                                <th class="py-3 px-5 min-w-[220px]">Batch / Lembaga</th>
                                <th class="py-3 px-5 w-52 whitespace-nowrap">Hak Akses Ujian</th>
                                <th class="py-3 px-5 text-center w-28 whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white text-xs">
                            <?php if (empty($daftarPeserta)): ?>
                            <tr><td colspan="6" class="px-6 py-10 text-center text-slate-400">Tidak ada data peserta yang cocok dengan kriteria filter.</td></tr>
                            <?php else: ?>
                            <?php foreach ($daftarPeserta as $p): ?>
                            <?php $sv_clean = strtoupper(trim($p['status_verifikasi'] ?? '')); ?>
                            <tr class="hover:bg-sky-50/40 transition">
                                <td class="py-3 px-5 align-middle whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md font-mono text-xs font-bold bg-sky-50 text-[#0e74bb] border border-sky-200/80 tracking-wider">
                                        <?php echo htmlspecialchars($p['cpmi_id'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-5 align-middle">
                                    <span class="block font-bold text-slate-900 text-sm leading-snug"><?php echo htmlspecialchars($p['nama_lengkap'] ?? '-'); ?></span>
                                    <div class="flex items-center flex-wrap gap-2 mt-1">
                                        <span class="font-mono text-xs text-slate-500 font-medium"><?php echo htmlspecialchars($p['nik_sesuai_ktp'] ?? '-'); ?></span>
                                        <?php if ((int)$p['jml_duplikat'] > 1): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200 whitespace-nowrap tracking-wide">
                                                NIK Ganda (<?php echo $p['jml_duplikat']; ?>x)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-3 px-5 align-middle whitespace-nowrap">
                                    <?php if ($sv_clean === 'MEMENUHI PERSYARATAN'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Memenuhi Syarat
                                        </span>
                                    <?php elseif ($sv_clean === 'TIDAK MEMENUHI PERSYARATAN'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>Tidak Memenuhi
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>Belum Verifikasi
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-5 align-middle">
                                    <span class="font-semibold text-slate-800 block text-xs leading-snug"><?php echo htmlspecialchars($p['batch_pelatihan'] ?? '-'); ?></span>
                                    <span class="text-xs text-slate-500 block mt-0.5 line-clamp-2"><?php echo htmlspecialchars($p['lembaga_pelatihan'] ?? '-'); ?></span>
                                </td>
                                <td class="py-3 px-5 align-middle whitespace-nowrap">
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between bg-slate-50 border border-slate-200 px-2.5 py-1 rounded-lg text-xs">
                                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Tertulis</span>
                                            <?php if (($p['akses_tertulis'] ?? 0) == 1): ?>
                                                <?php $stT = $p['status_tertulis'] ?? 'belum'; ?>
                                                <?php if ($stT === 'selesai'): ?><span class="text-[11px] font-bold text-emerald-600">Selesai</span>
                                                <?php elseif ($stT === 'sedang'): ?><span class="text-[11px] font-bold text-amber-600">Sedang Ujian</span>
                                                <?php else: ?><span class="text-[11px] font-bold text-[#0e74bb]">Terbuka</span><?php endif; ?>
                                            <?php else: ?><span class="text-[11px] font-medium text-slate-400">Dikunci</span><?php endif; ?>
                                        </div>
                                        <div class="flex items-center justify-between bg-slate-50 border border-slate-200 px-2.5 py-1 rounded-lg text-xs">
                                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Interview</span>
                                            <?php if (($p['akses_interview'] ?? 0) == 1): ?>
                                                <?php $stI = $p['status_interview'] ?? 'belum'; ?>
                                                <?php if ($stI === 'selesai'): ?><span class="text-[11px] font-bold text-emerald-600">Selesai</span>
                                                <?php elseif ($stI === 'sedang'): ?><span class="text-[11px] font-bold text-amber-600">Sedang Ujian</span>
                                                <?php else: ?><span class="text-[11px] font-bold text-[#0e74bb]">Terbuka</span><?php endif; ?>
                                            <?php else: ?><span class="text-[11px] font-medium text-slate-400">Dikunci</span><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-5 text-center align-middle whitespace-nowrap">
                                    <div class="inline-flex flex-col space-y-1 w-24">
                                        <button type="button" onclick='bukaModalEdit(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' 
                                                class="bg-sky-50 hover:bg-[#0e74bb] hover:text-white text-[#0e74bb] font-bold px-3 py-1.5 rounded-lg text-xs transition border border-sky-100">
                                            Atur Akses
                                        </button>
                                        
                                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" onsubmit="return confirm('Reset ujian akan membuka kembali sesi ujian? Lanjutkan?')">
                                            <input type="hidden" name="action" value="reset_ujian">
                                            <input type="hidden" name="cpmi_id" value="<?php echo htmlspecialchars($p['cpmi_id']); ?>">
                                            <button type="submit" class="bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold px-3 py-1.5 rounded-lg text-xs transition w-full border border-amber-200">Reset</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalHalaman > 1): ?>
                <div class="p-4 border-t border-slate-100 flex items-center justify-between text-xs mt-auto">
                    <span class="text-slate-500">Halaman <b><?php echo $halaman; ?></b> dari <b><?php echo $totalHalaman; ?></b></span>
                    <div class="flex space-x-1.5">
                        <?php $queryBase = http_build_query(['akses' => $filterAkses, 'status' => $filterStatus, 'ganda' => $filterGanda, 'q' => $keyword, 'batch' => $filterBatch, 'lembaga' => $filterLembaga]); ?>
                        <?php if ($halaman > 1): ?><a href="?<?php echo $queryBase; ?>&page=<?php echo $halaman - 1; ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 font-bold text-slate-700 transition">&larr; Sebelumnya</a><?php endif; ?>
                        <?php if ($halaman < $totalHalaman): ?><a href="?<?php echo $queryBase; ?>&page=<?php echo $halaman + 1; ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 font-bold text-slate-700 transition">Selanjutnya &rarr;</a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <div id="modalEdit" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center pb-4 border-b border-slate-100 mb-6">
                <div>
                    <h3 class="font-extrabold text-slate-900 text-lg">Atur Akses & Verifikasi Berkas</h3>
                    <span id="modalCpmiId" class="inline-block mt-1 px-2.5 py-0.5 rounded-md text-xs font-mono font-bold bg-sky-50 text-[#0e74bb] border border-sky-100"></span>
                </div>
                <button type="button" onclick="tutupModalEdit()" class="text-slate-400 hover:text-slate-600 p-2 text-xl font-bold">&times;</button>
            </div>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" class="space-y-5 text-sm">
                <input type="hidden" name="action" value="update_peserta">
                <input type="hidden" name="cpmi_id" id="form_cpmi_id">
                <input type="hidden" name="jenis_kelamin" id="form_jk">
                <input type="hidden" name="batch_pelatihan" id="form_batch">
                <input type="hidden" name="lembaga_pelatihan" id="form_lembaga">

                <div id="modalWarningGanda" class="hidden p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs font-medium space-y-1">
                    <span class="font-bold block text-sm">Terdeteksi NIK Ganda!</span>
                    <p>Memilih status <b>"Memenuhi Persyaratan"</b> pada CPMI ID ini otomatis menggugurkan pendaftaran lain dengan NIK yang sama dan mencabut seluruh hak akses ujiannya.</p>
                </div>

                <div class="bg-sky-50/70 p-4 rounded-xl border border-sky-100">
                    <label class="block text-xs font-bold text-[#0e74bb] uppercase mb-1.5">Status Verifikasi Berkas</label>
                    <select name="status_verifikasi" id="form_status_verifikasi" class="w-full px-3.5 py-2.5 rounded-lg border border-sky-200 bg-white font-bold text-slate-800 text-xs focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb]">
                        <option value="">-- Belum Diverifikasi --</option>
                        <option value="MEMENUHI PERSYARATAN">✓ Memenuhi Persyaratan (Lolos Ujian)</option>
                        <option value="TIDAK MEMENUHI PERSYARATAN">✗ Tidak Memenuhi Persyaratan (TMS)</option>
                    </select>
                </div>

                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-3">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" id="form_nama" required class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb] text-xs">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">NIK</label>
                            <input type="text" name="nik_sesuai_ktp" id="form_nik" required maxlength="16" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white font-mono text-xs focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb]">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Tgl Lahir</label>
                            <input type="date" name="tanggal_lahir" id="form_tgl" required class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-xs focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb]">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-xs space-y-3">
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" name="akses_tertulis" id="form_akses_tertulis" value="1" class="w-4 h-4 text-[#0e74bb] rounded border-slate-300 focus:ring-[#0e74bb]">
                            <span class="font-bold text-slate-800 text-xs">Akses Tertulis</span>
                        </label>
                        <select name="status_tertulis" id="form_status_tertulis" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-slate-50 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb]">
                            <option value="belum">Belum / Terbuka</option>
                            <option value="sedang">Sedang Mengerjakan</option>
                            <option value="selesai">Selesai (Terkunci)</option>
                        </select>
                    </div>

                    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-xs space-y-3">
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" name="akses_interview" id="form_akses_interview" value="1" class="w-4 h-4 text-amber-500 rounded border-slate-300 focus:ring-amber-500">
                            <span class="font-bold text-slate-800 text-xs">Akses Interview</span>
                        </label>
                        <select name="status_interview" id="form_status_interview" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-slate-50 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-[#0e74bb]/20 focus:border-[#0e74bb]">
                            <option value="belum">Belum / Terbuka</option>
                            <option value="sedang">Sedang Mengerjakan</option>
                            <option value="selesai">Selesai (Terkunci)</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="tutupModalEdit()" class="px-5 py-2.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold transition text-xs">Batal</button>
                    <button type="submit" class="bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold px-6 py-2.5 rounded-lg transition shadow-xs text-xs">Simpan Perubahan</button>
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

        function bukaModalEdit(p) {
            document.getElementById('form_cpmi_id').value = p.cpmi_id || '';
            document.getElementById('modalCpmiId').textContent = 'CPMI-ID: ' + (p.cpmi_id || '-');
            document.getElementById('form_nama').value = p.nama_lengkap || '';
            document.getElementById('form_nik').value = p.nik_sesuai_ktp || '';
            document.getElementById('form_tgl').value = p.tanggal_lahir || '';
            document.getElementById('form_jk').value = p.jenis_kelamin || 'Laki-laki';
            document.getElementById('form_batch').value = p.batch_pelatihan || '';
            document.getElementById('form_lembaga').value = p.lembaga_pelatihan || '';
            
            const statAsli = (p.status_verifikasi || '').trim().toUpperCase();
            document.getElementById('form_status_verifikasi').value = statAsli;

            const hasAksesT = (p.akses_tertulis == 1);
            const hasAksesI = (p.akses_interview == 1);

            document.getElementById('form_akses_tertulis').checked = hasAksesT;
            document.getElementById('form_akses_interview').checked = hasAksesI;

            document.getElementById('form_status_tertulis').value = hasAksesT ? (p.status_tertulis || 'belum') : 'belum';
            document.getElementById('form_status_interview').value = hasAksesI ? (p.status_interview || 'belum') : 'belum';

            document.getElementById('modalWarningGanda').classList.toggle('hidden', parseInt(p.jml_duplikat || 1) <= 1);
            document.getElementById('modalEdit').classList.remove('hidden');
        }

        document.getElementById('form_status_verifikasi').addEventListener('change', function() {
            const val = this.value;
            const chkTertulis = document.getElementById('form_akses_tertulis');
            const chkInterview = document.getElementById('form_akses_interview');

            if (val === 'MEMENUHI PERSYARATAN') {
                chkTertulis.checked = true;
                chkInterview.checked = true;
                if (document.getElementById('form_status_tertulis').value === 'selesai') {
                    document.getElementById('form_status_tertulis').value = 'belum';
                }
                if (document.getElementById('form_status_interview').value === 'selesai') {
                    document.getElementById('form_status_interview').value = 'belum';
                }
            } else if (val === 'TIDAK MEMENUHI PERSYARATAN') {
                chkTertulis.checked = false;
                chkInterview.checked = false;
            }
        });

        document.getElementById('form_akses_tertulis').addEventListener('change', function() {
            if (this.checked && document.getElementById('form_status_tertulis').value === 'selesai') {
                document.getElementById('form_status_tertulis').value = 'belum';
            }
        });
        document.getElementById('form_akses_interview').addEventListener('change', function() {
            if (this.checked && document.getElementById('form_status_interview').value === 'selesai') {
                document.getElementById('form_status_interview').value = 'belum';
            }
        });

        function tutupModalEdit() { 
            document.getElementById('modalEdit').classList.add('hidden'); 
        }

        function toggleSaklarPanel() {
            const content = document.getElementById('contentSaklarPanel');
            const textToggle = document.getElementById('textToggleSaklar');
            const iconToggle = document.getElementById('iconToggleSaklar');
            
            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                textToggle.textContent = 'Sembunyikan Button';
                iconToggle.classList.remove('fa-chevron-down');
                iconToggle.classList.add('fa-chevron-up');
            } else {
                content.classList.add('hidden');
                textToggle.textContent = 'Tampilkan Button';
                iconToggle.classList.remove('fa-chevron-up');
                iconToggle.classList.add('fa-chevron-down');
            }
        }

        function submitSaklarCepat(status) {
            const sel = document.getElementById('quick_lembaga_pelatihan');
            const val = sel.value;
            if (!val) {
                alert('Silakan pilih Lembaga Pelatihan terlebih dahulu.');
                sel.focus();
                return;
            }
            const opt = sel.options[sel.selectedIndex];
            const msCount = opt.getAttribute('data-ms') || '0';
            
            let pesan = '';
            if (status === 'aktif') {
                pesan = `Konfirmasi Aktivasi Massal:\n\nAktifkan ujian (Tertulis & Interview) untuk ${msCount} peserta Memenuhi Syarat (MS) pada lembaga:\n"${val}"?\n\nLanjutkan?`;
            } else {
                pesan = `Konfirmasi Penonaktifan:\n\nNonaktifkan / Kunci hak akses ujian untuk seluruh peserta pada lembaga:\n"${val}"?\n\nLanjutkan?`;
            }
            
            if (confirm(pesan)) {
                document.getElementById('quick_status_saklar').value = status;
                document.getElementById('formSaklarCepat').submit();
            }
        }
    </script>
</body>
</html>