<?php
// selesai.php
session_start();

if (!isset($_SESSION['cpmi_id'])) {
    header("Location: index.html");
    exit;
}

require_once __DIR__ . '/api/koneksi.php';

$cpmi_id = $_SESSION['cpmi_id'];
$jenisUjian = strtolower(trim($_GET['jenis'] ?? ''));

if ($jenisUjian !== 'tertulis' && $jenisUjian !== 'interview') {
    die("Parameter modul ujian tidak valid.");
}

$jenisUjianDB = strtoupper($jenisUjian); // 'TERTULIS' atau 'INTERVIEW'

try {
    $pdo->beginTransaction();

    if ($jenisUjian === 'tertulis') {
        $stmtSoalAktif = $pdo->query("SELECT id FROM tb_soal WHERE (LOWER(jenis_ujian) = 'tertulis' OR jenis_ujian IS NULL OR jenis_ujian = '') AND (status_aktif = 'Y' OR status_aktif IS NULL)");
    } else {
        $stmtSoalAktif = $pdo->query("SELECT id FROM tb_soal WHERE LOWER(jenis_ujian) = 'interview' AND (status_aktif = 'Y' OR status_aktif IS NULL)");
    }
    
    $listSoalId = $stmtSoalAktif->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($listSoalId)) {
        $stmtCek = $pdo->prepare("SELECT id FROM tb_jawaban_peserta WHERE cpmi_id = ? AND soal_id = ? LIMIT 1");
        $stmtUpdate = $pdo->prepare("UPDATE tb_jawaban_peserta SET jawaban_pilihan = ?, updated_at = NOW() WHERE id = ?");
        $stmtInsert = $pdo->prepare("INSERT INTO tb_jawaban_peserta (cpmi_id, soal_id, jawaban_pilihan, updated_at) VALUES (?, ?, ?, NOW())");

        foreach ($listSoalId as $sid) {
            $jwb = ''; 
            if (isset($_POST['jawaban'][$sid])) {
                $rawJwb = strtoupper(trim($_POST['jawaban'][$sid]));
                if (in_array($rawJwb, ['A', 'B', 'C', 'D'])) {
                    $jwb = $rawJwb;
                }
            }

            $stmtCek->execute([$cpmi_id, $sid]);
            $ada = $stmtCek->fetch(PDO::FETCH_ASSOC);
            if ($ada) {
                $stmtUpdate->execute([$jwb, $ada['id']]);
            } else {
                $stmtInsert->execute([$cpmi_id, $sid, $jwb]);
            }
        }
    }

    $stmtUpdatePU = $pdo->prepare("
        INSERT INTO tb_peserta_ujian (cpmi_id, jenis_ujian, status_pengerjaan)
        VALUES (?, ?, 'SELESAI')
        ON DUPLICATE KEY UPDATE status_pengerjaan = 'SELESAI'
    ");
    $stmtUpdatePU->execute([$cpmi_id, $jenisUjianDB]);

    if ($jenisUjian === 'tertulis') {
        $pdo->prepare("UPDATE tb_pendaftaran SET status_tertulis = 'selesai' WHERE cpmi_id = ?")
            ->execute([$cpmi_id]);
    } else {
        $pdo->prepare("UPDATE tb_pendaftaran SET status_interview = 'selesai' WHERE cpmi_id = ?")
            ->execute([$cpmi_id]);
    }

    $stmtCekAll = $pdo->prepare("
        SELECT akses_tertulis, status_tertulis, akses_interview, status_interview 
        FROM tb_pendaftaran 
        WHERE cpmi_id = ? 
        LIMIT 1
    ");
    $stmtCekAll->execute([$cpmi_id]);
    $statusAll = $stmtCekAll->fetch(PDO::FETCH_ASSOC);

    $adaTertulis   = ((int)($statusAll['akses_tertulis'] ?? 0) === 1);
    $adaInterview  = ((int)($statusAll['akses_interview'] ?? 0) === 1);

    $tertulisOk    = !$adaTertulis || ($statusAll['status_tertulis'] === 'selesai');
    $interviewOk   = !$adaInterview || ($statusAll['status_interview'] === 'selesai');

    if (($adaTertulis || $adaInterview) && $tertulisOk && $interviewOk) {
        $pdo->prepare("UPDATE tb_pendaftaran SET status_ujian = 'selesai' WHERE cpmi_id = ?")
            ->execute([$cpmi_id]);
    } else {
        $pdo->prepare("UPDATE tb_pendaftaran SET status_ujian = 'sedang' WHERE cpmi_id = ?")
            ->execute([$cpmi_id]);
    }

    unset($_SESSION['waktu_mulai_' . $jenisUjian . '_' . $cpmi_id]);

    $pdo->commit();

    $stmt = $pdo->prepare("SELECT nama_lengkap FROM tb_pendaftaran WHERE cpmi_id = ? LIMIT 1");
    $stmt->execute([$cpmi_id]);
    $peserta = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Terjadi kegagalan penyimpanan: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujian Selesai | PINTU BP3MI Jateng</title>
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
    <script>
        history.pushState(null, null, location.href);
        window.addEventListener('popstate', function () { history.pushState(null, null, location.href); });
    </script>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col justify-center items-center p-4 selection:bg-indigo-500 selection:text-white">

    <div class="bg-white rounded-3xl p-8 sm:p-10 shadow-xl max-w-lg w-full text-center border border-slate-200">
        <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-6">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
        </div>

        <h2 class="text-2xl font-extrabold text-slate-900 mb-2">Ujian Berhasil Disimpan!</h2>
        <p class="text-slate-600 text-sm mb-6 leading-relaxed">
            Seluruh jawaban <b>Ujian <?php echo ucfirst($jenisUjian); ?></b> atas nama <b><?php echo htmlspecialchars($peserta['nama_lengkap'] ?? 'Peserta'); ?></b> telah tersimpan dengan aman ke dalam sistem.
        </p>

        <a href="dashboard.php" class="inline-block w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3.5 px-8 rounded-xl shadow-md shadow-indigo-500/20 transition text-sm">
            Kembali ke Dashboard
        </a>
    </div>

</body>
</html>