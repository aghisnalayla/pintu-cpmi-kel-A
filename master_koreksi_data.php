<?php
ob_start();
session_start();

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
    $uploadDir = __DIR__ . '/../storage/uploads/';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    $uploadDir = __DIR__ . '/storage/uploads/';
} else {
    die('Berkas config.php tidak ditemukan.');
}

if (file_exists(__DIR__ . '/../api/helper_log.php')) {
    require_once __DIR__ . '/../api/helper_log.php';
} elseif (file_exists(__DIR__ . '/api/helper_log.php')) {
    require_once __DIR__ . '/api/helper_log.php';
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$namaAdmin    = $_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin   = $_SESSION['admin_level'] ?? 'user';
$isSuperAdmin = in_array(strtolower($levelAdmin), ['superadmin', 'admin']);

if (!$isSuperAdmin) {
    header('Location: index.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Jakarta');

$pesan = '';
$tipePesan = '';
$data_peserta = null;
$list_berkas = [];
$list_kandidat = []; 
$keyword_cari = trim($_GET['cari'] ?? '');
$select_id    = trim($_GET['select_id'] ?? '');

if (!empty($select_id)) {
    try {
        $stmtCari = $pdo->prepare("
            SELECT p.*, v.status AS status_verifikasi, v.catatan AS catatan_verifikasi 
            FROM tb_pendaftaran p 
            LEFT JOIN tb_status_verifikasi v ON p.cpmi_id = v.cpmi_id 
            WHERE p.cpmi_id = ? 
            LIMIT 1
        ");
        $stmtCari->execute([$select_id]);
        $data_peserta = $stmtCari->fetch(PDO::FETCH_ASSOC);

        if ($data_peserta) {
            $keyword_cari = $data_peserta['cpmi_id']; 
            
            if (function_exists('catatLog')) {
                catatLog($pdo, "Superadmin mengakses panel koreksi untuk: {$data_peserta['cpmi_id']}", 'KOREKSI_DATA');
            }
            
            $stmtBerkas = $pdo->prepare("
                SELECT md.id_dokumen, md.nama_dokumen, md.is_mandatory, bp.file_path, bp.nama_file_asli 
                FROM tb_master_dokumen md 
                LEFT JOIN tb_berkas_pendaftaran bp ON md.id_dokumen = bp.id_dokumen AND bp.cpmi_id = ? 
                WHERE md.status_aktif = 'Y'
                ORDER BY md.urutan ASC, md.id_dokumen ASC
            ");
            $stmtBerkas->execute([$data_peserta['cpmi_id']]);
            $list_berkas = $stmtBerkas->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $pesan = "Kesalahan basis data: " . $e->getMessage();
        $tipePesan = "error";
    }

} elseif (!empty($keyword_cari)) {
    try {
        $kw_like = "%{$keyword_cari}%";
        $stmtCari = $pdo->prepare("
            SELECT p.*, v.status AS status_verifikasi, v.catatan AS catatan_verifikasi 
            FROM tb_pendaftaran p 
            LEFT JOIN tb_status_verifikasi v ON p.cpmi_id = v.cpmi_id 
            WHERE p.cpmi_id = ? OR p.nik_sesuai_ktp = ? OR p.nama_lengkap LIKE ?
            ORDER BY p.cpmi_id DESC
        ");
        $stmtCari->execute([$keyword_cari, $keyword_cari, $kw_like]);
        $hasil = $stmtCari->fetchAll(PDO::FETCH_ASSOC);

        if (count($hasil) === 1) {
            $data_peserta = $hasil[0];
            if (function_exists('catatLog')) {
                catatLog($pdo, "Superadmin mengakses panel koreksi untuk: {$data_peserta['cpmi_id']}", 'KOREKSI_DATA');
            }
            
            $stmtBerkas = $pdo->prepare("
                SELECT md.id_dokumen, md.nama_dokumen, md.is_mandatory, bp.file_path, bp.nama_file_asli 
                FROM tb_master_dokumen md 
                LEFT JOIN tb_berkas_pendaftaran bp ON md.id_dokumen = bp.id_dokumen AND bp.cpmi_id = ? 
                WHERE md.status_aktif = 'Y'
                ORDER BY md.urutan ASC, md.id_dokumen ASC
            ");
            $stmtBerkas->execute([$data_peserta['cpmi_id']]);
            $list_berkas = $stmtBerkas->fetchAll(PDO::FETCH_ASSOC);

        } elseif (count($hasil) > 1) {
            $list_kandidat = $hasil;
            $pesan = "Ditemukan " . count($hasil) . " peserta dengan nama/data serupa. Silakan pilih target spesifik dari tabel di bawah.";
            $tipePesan = "info";
        } else {
            $pesan = "Data pendaftar dengan ID/NIK/Nama '{$keyword_cari}' tidak ditemukan.";
            $tipePesan = "error";
        }
    } catch (Throwable $e) {
        $pesan = "Kesalahan basis data: " . $e->getMessage();
        $tipePesan = "error";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' AND isset($_POST['hapus_dokumen'])) {
    $id_dok_hapus = (int)$_POST['hapus_dokumen'];
    $cpmi_id_edit = trim($_POST['cpmi_id_edit']);

    try {
        $pdo->beginTransaction();
        
        $stmtHapus = $pdo->prepare("DELETE FROM tb_berkas_pendaftaran WHERE cpmi_id = ? AND id_dokumen = ?");
        $stmtHapus->execute([$cpmi_id_edit, $id_dok_hapus]);

        $pdo->commit();

        if (function_exists('catatLog')) {
            catatLog($pdo, "Superadmin MENGHAPUS tautan dokumen ID: {$id_dok_hapus} untuk CPMI: {$cpmi_id_edit}. Fisik file aman dipertahankan untuk Audit.", 'KOREKSI_DATA');
        }

        header("Location: master_koreksi_data.php?select_id={$cpmi_id_edit}&sukses_hapus=1");
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pesan = "Gagal menghapus dokumen: " . $e->getMessage();
        $tipePesan = "error";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' AND isset($_POST['action']) AND $_POST['action'] === 'update_pendaftar' AND !isset($_POST['hapus_dokumen'])) {
    $cpmi_id_edit = $_POST['cpmi_id_edit'];
    
    try {
        $pdo->beginTransaction();

        $sqlUpdate = "
            UPDATE tb_pendaftaran 
            SET nik_sesuai_ktp = ?, nama_lengkap = ?, tanggal_lahir = ?, jenis_kelamin = ?, 
                pendidikan = ?, email = ?, domisili = ?, alamat_lengkap = ?, no_jkn = ?, 
                no_hp_wa = ?, batch_pelatihan = ?, lembaga_pelatihan = ?, ref_pelatihan_dimin = ?
            WHERE cpmi_id = ?
        ";
        $stmtUp = $pdo->prepare($sqlUpdate);
        $stmtUp->execute([
            trim($_POST['nik']), trim($_POST['nama_lengkap']), trim($_POST['tanggal_lahir']), 
            trim($_POST['jenis_kelamin']), trim($_POST['pendidikan']), trim($_POST['email']), 
            trim($_POST['domisili']), trim($_POST['alamat_lengkap']), trim($_POST['no_jkn']), 
            trim($_POST['no_hp_wa']), trim($_POST['batch_pelatihan']), trim($_POST['lembaga_pelatihan']), 
            trim($_POST['ref_pelatihan_dimin']), $cpmi_id_edit
        ]);

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        
        $stmtCekFileLama = $pdo->prepare("SELECT file_path FROM tb_berkas_pendaftaran WHERE cpmi_id = ? AND id_dokumen = ?");
        $stmtInsertBerkas = $pdo->prepare("INSERT INTO tb_berkas_pendaftaran (cpmi_id, id_dokumen, file_path, nama_file_asli, file_size) VALUES (?, ?, ?, ?, ?)");
        $stmtUpdateBerkas = $pdo->prepare("UPDATE tb_berkas_pendaftaran SET file_path = ?, nama_file_asli = ?, file_size = ? WHERE cpmi_id = ? AND id_dokumen = ?");

        foreach ($_FILES as $inputName => $fileData) {
            if (strpos($inputName, 'dokumen_') === 0 && $fileData['error'] === UPLOAD_ERR_OK) {
                $id_dok = str_replace('dokumen_', '', $inputName);
                
                $tmpName  = $fileData['tmp_name'];
                $fileName = $fileData['name']; 
                $fileSize = $fileData['size']; 
                $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png']) && $fileSize <= 10485760) { 
                    
                    $stmtCekFileLama->execute([$cpmi_id_edit, $id_dok]);
                    $fileLama = $stmtCekFileLama->fetchColumn();
                    
                    $newName = "CPMI_{$cpmi_id_edit}_DOK_{$id_dok}_BARU_" . time() . ".{$ext}";
                    $destPath = $uploadDir . $newName;

                    if (move_uploaded_file($tmpName, $destPath)) {
                        if ($fileLama) {
                            $stmtUpdateBerkas->execute([$newName, $fileName, $fileSize, $cpmi_id_edit, $id_dok]);
                        } else {
                            $stmtInsertBerkas->execute([$cpmi_id_edit, $id_dok, $newName, $fileName, $fileSize]);
                        }
                    }
                }
            }
        }

        if (isset($_POST['status_verifikasi']) && !empty($_POST['status_verifikasi'])) {
            $statusBaru = trim($_POST['status_verifikasi']);
            $catatanBaru = trim($_POST['catatan_verifikasi'] ?? '');

            $stmtCekV = $pdo->prepare("SELECT COUNT(*) FROM tb_status_verifikasi WHERE cpmi_id = ?");
            $stmtCekV->execute([$cpmi_id_edit]);
            if ((int)$stmtCekV->fetchColumn() > 0) {
                $stmtUpdV = $pdo->prepare("UPDATE tb_status_verifikasi SET status = ?, catatan = ? WHERE cpmi_id = ?");
                $stmtUpdV->execute([$statusBaru, $catatanBaru, $cpmi_id_edit]);
            } else {
                $stmtInsV = $pdo->prepare("INSERT INTO tb_status_verifikasi (cpmi_id, status, catatan) VALUES (?, ?, ?)");
                $stmtInsV->execute([$cpmi_id_edit, $statusBaru, $catatanBaru]);
            }
        }

        $pdo->commit();

        if (function_exists('catatLog')) {
            $logStatus = isset($statusBaru) ? " & status: {$statusBaru}" : "";
            catatLog($pdo, "Superadmin berhasil melakukan koreksi data biodata/berkas{$logStatus} untuk ID: {$cpmi_id_edit}. Dokumen lama disimpan sebagai Log.", 'KOREKSI_DATA');
        }

        header("Location: master_koreksi_data.php?select_id={$cpmi_id_edit}&sukses=1");
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        $pesan = "Gagal memproses perbaikan data: " . $e->getMessage();
        $tipePesan = "error";
    }
}

if (isset($_GET['sukses']) && $_GET['sukses'] == 1) {
    $pesan = "Pembaruan data pendaftar, dokumen, dan status verifikasi berhasil disimpan ke dalam sistem.";
    $tipePesan = "success";
} elseif (isset($_GET['sukses_hapus']) && $_GET['sukses_hapus'] == 1) {
    $pesan = "Dokumen berhasil dihapus dari profil pendaftar. Dokumen fisik lama diamankan di server.";
    $tipePesan = "success";
}

$listBatchKoreksi = [];
$listLembagaKoreksi = [];
$listKejuruanKoreksi = [];

if ($data_peserta) {
    try {
        $listBatchKoreksi = $pdo->query("SELECT DISTINCT batch_pelatihan FROM tb_pendaftaran WHERE batch_pelatihan != '' ORDER BY batch_pelatihan DESC")->fetchAll(PDO::FETCH_COLUMN);
        $listLembagaKoreksi = $pdo->query("SELECT nama_lembaga FROM tb_master_lembaga ORDER BY nama_lembaga ASC")->fetchAll(PDO::FETCH_COLUMN);
        $listKejuruanKoreksi = $pdo->query("SELECT nama_kejuruan FROM tb_master_kejuruan ORDER BY nama_kejuruan ASC")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array($data_peserta['batch_pelatihan'], $listBatchKoreksi)) $listBatchKoreksi[] = $data_peserta['batch_pelatihan'];
        if (!in_array($data_peserta['lembaga_pelatihan'], $listLembagaKoreksi)) $listLembagaKoreksi[] = $data_peserta['lembaga_pelatihan'];
        if (!in_array($data_peserta['ref_pelatihan_dimin'], $listKejuruanKoreksi)) $listKejuruanKoreksi[] = $data_peserta['ref_pelatihan_dimin'];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Koreksi Data Pendaftar — Super Admin</title>
    
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
                            <i class="fas fa-user-edit"></i>
                        </span>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight uppercase">Master Koreksi Data</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Modul koreksi biodata & dokumen milik pendaftar.</p>
                </div>
                <div class="text-right bg-slate-50 px-4 py-2.5 rounded-xl border border-slate-200 shadow-2xs">
                    <div id="liveClock" class="text-sm font-bold text-slate-900 font-mono">--:--:-- WIB</div>
                    <div id="liveDate" class="text-[9px] font-bold text-[#0e74bb] uppercase tracking-widest mt-0.5">Memuat Tanggal...</div>
                </div>
            </div>

            <?php if (!empty($pesan)): ?>
                <?php 
                    $warnaNotif = 'bg-rose-50 text-rose-700 border-rose-200';
                    if ($tipePesan === 'success') $warnaNotif = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                    if ($tipePesan === 'info') $warnaNotif = 'bg-sky-50 text-sky-700 border-sky-200';
                ?>
                <div class="p-4 rounded-xl text-xs font-bold border <?= $warnaNotif ?> shadow-2xs">
                    <?= htmlspecialchars($pesan) ?>
                </div>
            <?php endif; ?>

            <div class="bg-white p-5 md:p-6 rounded-xl border border-slate-200 shadow-xs">
                <form method="GET" action="master_koreksi_data.php" class="flex flex-col sm:flex-row gap-4 items-end">
                    <div class="flex-1 w-full">
                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-2">Identifikasi Peserta (ID / NIK / Nama)</label>
                        <input type="text" name="cari" value="<?= htmlspecialchars($keyword_cari) ?>" placeholder="Contoh: CPMI-0015, 332..., atau Ketik Nama Peserta" required class="w-full h-[46px] px-3.5 rounded-lg border border-slate-200 text-sm font-mono tracking-widest bg-white focus:outline-none focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] shadow-2xs placeholder:tracking-normal placeholder:font-sans">
                    </div>
                    <button type="submit" class="w-full sm:w-auto h-[46px] px-7 bg-[#0e74bb] hover:bg-[#0b5e99] text-white text-xs font-bold rounded-lg transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-2">
                        <i class="fas fa-search text-xs"></i>
                        Tarik Data
                    </button>
                </form>
            </div>

            <?php if (!empty($list_kandidat)): ?>
                <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col mb-6">
                    <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70">
                        <h3 class="text-xs font-extrabold uppercase tracking-wider text-[#0e74bb] flex items-center gap-2">
                            <i class="fas fa-users text-xs"></i>
                            Pilih Peserta Spesifik
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-700">
                            <thead>
                                <tr class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                                    <th class="py-3 px-4 w-40">CPMI-ID / NIK</th>
                                    <th class="py-3 px-4">Nama Lengkap</th>
                                    <th class="py-3 px-4">Lembaga & Batch Target</th>
                                    <th class="py-3 px-4 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white text-xs font-medium">
                                <?php foreach ($list_kandidat as $k): ?>
                                <tr class="hover:bg-slate-50/80 transition">
                                    <td class="py-3 px-4">
                                        <span class="block font-mono font-bold text-[#0e74bb]"><?= htmlspecialchars($k['cpmi_id']) ?></span>
                                        <span class="text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($k['nik_sesuai_ktp']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 font-bold text-slate-900"><?= htmlspecialchars($k['nama_lengkap']) ?></td>
                                    <td class="py-3 px-4">
                                        <span class="block text-slate-800"><?= htmlspecialchars($k['lembaga_pelatihan']) ?></span>
                                        <span class="text-[9px] text-slate-500 font-bold uppercase"><?= htmlspecialchars($k['batch_pelatihan']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <a href="master_koreksi_data.php?select_id=<?= htmlspecialchars($k['cpmi_id']) ?>" class="inline-block px-3.5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg transition text-[10px] uppercase tracking-wider shadow-2xs">
                                            Buka Editor Koreksi
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($data_peserta): ?>
                <form method="POST" enctype="multipart/form-data" class="space-y-6 pb-12">
                    <input type="hidden" name="action" value="update_pendaftar">
                    <input type="hidden" name="cpmi_id_edit" value="<?= htmlspecialchars($data_peserta['cpmi_id']) ?>">

                    <?php
                    $rawCatatan = trim($data_peserta['catatan_verifikasi'] ?? '');
                    $currStatus = strtoupper(trim($data_peserta['status_verifikasi'] ?? ''));
                    if (empty($currStatus)) {
                        $currStatus = 'MENUNGGU VERIFIKASI';
                    }

                    $nonCompliantItems = [];
                    $otherNotes = [];

                    if (!empty($rawCatatan)) {
                        $lines = explode("\n", str_replace("\r", "", $rawCatatan));
                        $isDocSection = false;
                        foreach ($lines as $line) {
                            $trimmed = trim($line);
                            if ($trimmed === '') continue;
                            if (stripos($trimmed, 'Dokumen Tidak Sesuai') !== false || stripos($trimmed, 'Tidak Memenuhi') !== false) {
                                $isDocSection = true;
                                continue;
                            }
                            if (stripos($trimmed, 'Catatan Verifikator') !== false || stripos($trimmed, 'Catatan Tambahan') !== false) {
                                $isDocSection = false;
                                continue;
                            }
                            if (preg_match('/^[\•\-\*]\s*(.+)$/u', $trimmed, $m)) {
                                $nonCompliantItems[] = $m[1];
                            } elseif ($isDocSection) {
                                $nonCompliantItems[] = $trimmed;
                            } else {
                                $otherNotes[] = $trimmed;
                            }
                        }
                    }
                    ?>

                    <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden">
                        <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex flex-wrap justify-between items-center gap-2">
                            <div class="flex items-center gap-2.5">
                                <span class="w-8 h-8 rounded-lg bg-blue-100/80 text-[#0e74bb] flex items-center justify-center text-sm shadow-2xs">
                                    <i class="fas fa-clipboard-check"></i>
                                </span>
                                <div>
                                    <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Status & Catatan Verifikasi Dokumen</h3>
                                    <p class="text-[11px] text-slate-500 font-medium">Tinjau rekam jejak verifikasi dan perbarui status kelayakan peserta</p>
                                </div>
                            </div>
                            <div>
                                <?php if ($currStatus === 'MEMENUHI PERSYARATAN'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs">
                                        <i class="fas fa-check-circle text-emerald-600"></i> Memenuhi Syarat
                                    </span>
                                <?php elseif ($currStatus === 'TIDAK MEMENUHI PERSYARATAN'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs">
                                        <i class="fas fa-times-circle text-rose-600"></i> Tidak Memenuhi Syarat
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 shadow-2xs">
                                        <i class="fas fa-clock text-amber-600"></i> Belum Diverifikasi
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="p-5 sm:p-6 space-y-5">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                    <i class="fas fa-history text-slate-400"></i>
                                    Catatan Verifikator Eksisting
                                </label>

                                <?php if (!empty($nonCompliantItems) || !empty($otherNotes) || !empty($rawCatatan)): ?>
                                    <div class="bg-rose-50/50 border border-rose-200/80 rounded-xl p-4 space-y-3">
                                        <?php if (!empty($nonCompliantItems)): ?>
                                            <div>
                                                <div class="text-xs font-extrabold text-rose-800 flex items-center gap-2 mb-2">
                                                    <i class="fas fa-circle-exclamation text-rose-600"></i>
                                                    Dokumen / Persyaratan Yang Tidak Memenuhi Syarat:
                                                </div>
                                                <div class="space-y-1.5 pl-1">
                                                    <?php foreach ($nonCompliantItems as $item): ?>
                                                        <div class="flex items-start gap-2 text-xs text-rose-900 bg-white/90 border border-rose-200/70 rounded-lg p-2.5 shadow-2xs">
                                                            <i class="fas fa-xmark text-rose-500 mt-0.5 shrink-0"></i>
                                                            <span class="font-medium"><?= htmlspecialchars($item) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($otherNotes)): ?>
                                            <div class="pt-1">
                                                <div class="text-[11px] font-bold text-slate-700 mb-1 flex items-center gap-1.5">
                                                    <i class="fas fa-comment-dots text-slate-400"></i>
                                                    Catatan Tambahan Verifikator:
                                                </div>
                                                <div class="text-xs text-slate-700 bg-white/90 border border-slate-200 rounded-lg p-2.5 leading-relaxed font-sans shadow-2xs">
                                                    <?= nl2br(htmlspecialchars(implode("\n", $otherNotes))) ?>
                                                </div>
                                            </div>
                                        <?php elseif (empty($nonCompliantItems) && !empty($rawCatatan)): ?>
                                            <div class="text-xs text-slate-700 bg-white/90 border border-slate-200 rounded-lg p-2.5 leading-relaxed font-sans shadow-2xs">
                                                <?= nl2br(htmlspecialchars($rawCatatan)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="bg-slate-50 border border-dashed border-slate-200 rounded-xl p-3.5 text-xs text-slate-500 flex items-center gap-2">
                                        <i class="fas fa-info-circle text-slate-400"></i>
                                        <span>Tidak ada catatan ketidaksesuaian berkas sebelumnya (data bersih / belum pernah diberikan catatan penolakan).</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="pt-2 border-t border-slate-100">
                                <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                    <i class="fas fa-pen-to-square text-[#0e74bb]"></i>
                                    Perbarui Status Verifikasi Dokumen
                                </label>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <label class="relative flex items-center p-3.5 rounded-xl border-2 cursor-pointer transition-all hover:bg-emerald-50/30 <?= $currStatus === 'MEMENUHI PERSYARATAN' ? 'border-emerald-500 bg-emerald-50/40 shadow-xs' : 'border-slate-200 bg-white' ?>">
                                        <input type="radio" name="status_verifikasi" value="MEMENUHI PERSYARATAN" <?= $currStatus === 'MEMENUHI PERSYARATAN' ? 'checked' : '' ?> class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-300">
                                        <div class="ml-3 flex flex-col">
                                            <span class="text-xs font-extrabold text-emerald-700 flex items-center gap-1.5">
                                                <i class="fas fa-check-circle"></i>
                                                Memenuhi Syarat
                                            </span>
                                            <span class="text-[10px] text-slate-500 font-medium">Dokumen lengkap & valid</span>
                                        </div>
                                    </label>

                                    <label class="relative flex items-center p-3.5 rounded-xl border-2 cursor-pointer transition-all hover:bg-rose-50/30 <?= $currStatus === 'TIDAK MEMENUHI PERSYARATAN' ? 'border-rose-500 bg-rose-50/40 shadow-xs' : 'border-slate-200 bg-white' ?>">
                                        <input type="radio" name="status_verifikasi" value="TIDAK MEMENUHI PERSYARATAN" <?= $currStatus === 'TIDAK MEMENUHI PERSYARATAN' ? 'checked' : '' ?> class="h-4 w-4 text-rose-600 focus:ring-rose-500 border-slate-300">
                                        <div class="ml-3 flex flex-col">
                                            <span class="text-xs font-extrabold text-rose-700 flex items-center gap-1.5">
                                                <i class="fas fa-times-circle"></i>
                                                Tidak Memenuhi Syarat
                                            </span>
                                            <span class="text-[10px] text-slate-500 font-medium">Ada berkas tidak sesuai</span>
                                        </div>
                                    </label>

                                    <label class="relative flex items-center p-3.5 rounded-xl border-2 cursor-pointer transition-all hover:bg-amber-50/30 <?= in_array($currStatus, ['MENUNGGU VERIFIKASI', 'BELUM DIVERIFIKASI']) ? 'border-amber-500 bg-amber-50/40 shadow-xs' : 'border-slate-200 bg-white' ?>">
                                        <input type="radio" name="status_verifikasi" value="MENUNGGU VERIFIKASI" <?= in_array($currStatus, ['MENUNGGU VERIFIKASI', 'BELUM DIVERIFIKASI']) ? 'checked' : '' ?> class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-slate-300">
                                        <div class="ml-3 flex flex-col">
                                            <span class="text-xs font-extrabold text-amber-700 flex items-center gap-1.5">
                                                <i class="fas fa-clock"></i>
                                                Belum Diverifikasi
                                            </span>
                                            <span class="text-[10px] text-slate-500 font-medium">Kembalikan ke antrean verifikasi</span>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider">
                                        Catatan / Alasan Verifikator (Dapat Diedit Langsung)
                                    </label>
                                    <span class="text-[9px] font-semibold text-slate-400">Tersimpan ke tb_status_verifikasi</span>
                                </div>
                                <textarea name="catatan_verifikasi" rows="3" placeholder="Tuliskan catatan verifikasi, daftar kekurangan berkas, atau alasan penolakan..." class="w-full text-xs p-3 rounded-lg border border-slate-200 bg-white focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs font-sans leading-relaxed"><?= htmlspecialchars($rawCatatan) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        
                        <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col">
                            <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex justify-between items-center">
                                <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800 flex items-center gap-2">
                                    <i class="fas fa-edit text-[#0e74bb] text-xs"></i>
                                    Editor Biodata & Relasi
                                </h3>
                                <span class="px-2.5 py-0.5 bg-blue-50 text-[#0e74bb] border border-blue-200/60 text-[10px] font-black rounded-md font-mono"><?= htmlspecialchars($data_peserta['cpmi_id']) ?></span>
                            </div>
                            
                            <div class="p-5 sm:p-6 space-y-4">
                                <div class="grid grid-cols-2 gap-3.5">
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">NIK (Bisa Direvisi)</label>
                                        <input type="text" name="nik" value="<?= htmlspecialchars($data_peserta['nik_sesuai_ktp']) ?>" required class="w-full h-[40px] px-3 rounded-lg border border-slate-200 bg-white text-xs font-mono focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">No JKN / BPJS</label>
                                        <input type="text" name="no_jkn" value="<?= htmlspecialchars($data_peserta['no_jkn']) ?>" class="w-full h-[40px] px-3 rounded-lg border border-slate-200 bg-white text-xs font-mono focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Nama Lengkap</label>
                                    <input type="text" name="nama_lengkap" value="<?= htmlspecialchars($data_peserta['nama_lengkap']) ?>" required class="w-full h-[40px] px-3 rounded-lg border border-slate-200 bg-white text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                                </div>

                                <div class="grid grid-cols-3 gap-3">
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Tgl Lahir</label>
                                        <input type="date" name="tanggal_lahir" value="<?= htmlspecialchars($data_peserta['tanggal_lahir']) ?>" required class="w-full h-[40px] px-2.5 rounded-lg border border-slate-200 bg-white text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Gender</label>
                                        <select name="jenis_kelamin" class="w-full h-[40px] px-2 rounded-lg border border-slate-200 bg-white text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs cursor-pointer">
                                            <option value="Laki-laki" <?= $data_peserta['jenis_kelamin']=='Laki-laki'?'selected':'' ?>>Laki-laki</option>
                                            <option value="Perempuan" <?= $data_peserta['jenis_kelamin']=='Perempuan'?'selected':'' ?>>Perempuan</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">No. WA</label>
                                        <input type="text" name="no_hp_wa" value="<?= htmlspecialchars($data_peserta['no_hp_wa']) ?>" required class="w-full h-[40px] px-2.5 rounded-lg border border-slate-200 bg-white text-xs font-mono focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3.5">
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Pendidikan</label>
                                        <input type="text" name="pendidikan" value="<?= htmlspecialchars($data_peserta['pendidikan']) ?>" class="w-full h-[40px] px-3 rounded-lg border border-slate-200 bg-white text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Email</label>
                                        <input type="email" name="email" value="<?= htmlspecialchars($data_peserta['email']) ?>" class="w-full h-[40px] px-3 rounded-lg border border-slate-200 bg-white text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Wilayah Domisili & Alamat Lengkap</label>
                                    <input type="text" name="domisili" value="<?= htmlspecialchars($data_peserta['domisili']) ?>" class="w-full h-[40px] px-3 rounded-lg border border-slate-200 bg-white text-xs mb-2 focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                                    <textarea name="alamat_lengkap" rows="2" class="w-full p-2.5 rounded-lg border border-slate-200 bg-white text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs"><?= htmlspecialchars($data_peserta['alamat_lengkap']) ?></textarea>
                                </div>

                                <div class="pt-3 border-t border-slate-200 space-y-3">
                                    <span class="block text-[10px] font-extrabold text-[#0e74bb] uppercase tracking-wider">Perbaikan Relasi Target Program</span>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Gelombang / Batch</label>
                                        <select name="batch_pelatihan" required class="w-full h-[40px] px-3 rounded-lg border border-slate-200 text-xs font-bold bg-white focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none cursor-pointer shadow-2xs">
                                            <?php foreach($listBatchKoreksi as $b): ?>
                                                <option value="<?= htmlspecialchars($b) ?>" <?= ($data_peserta['batch_pelatihan'] === $b) ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Lembaga Pelatihan (Wajib Persis Sama)</label>
                                        <select name="lembaga_pelatihan" required class="w-full h-[40px] px-3 rounded-lg border border-slate-200 text-xs font-bold bg-white focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none cursor-pointer shadow-2xs">
                                            <?php foreach($listLembagaKoreksi as $l): ?>
                                                <option value="<?= htmlspecialchars($l) ?>" <?= ($data_peserta['lembaga_pelatihan'] === $l) ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Kejuruan / Program Target</label>
                                        <select name="ref_pelatihan_dimin" required class="w-full h-[40px] px-3 rounded-lg border border-slate-200 text-xs font-bold bg-white focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none cursor-pointer shadow-2xs">
                                            <?php foreach($listKejuruanKoreksi as $k): ?>
                                                <option value="<?= htmlspecialchars($k) ?>" <?= ($data_peserta['ref_pelatihan_dimin'] === $k) ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col">
                            <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex justify-between items-center">
                                <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800 flex items-center gap-2">
                                    <i class="fas fa-file-invoice text-emerald-600 text-xs"></i>
                                    Susulan & Timpa Dokumen
                                </h3>
                            </div>
                            
                            <div class="p-5 sm:p-6 space-y-3.5 bg-slate-50/40 flex-1">
                                <p class="text-[10px] text-slate-600 font-medium leading-relaxed mb-2 bg-blue-50 border border-blue-200/70 p-3 rounded-lg"><b>Informasi:</b> Biarkan input file kosong jika dokumen tidak ingin diganti. File mentah lama akan tetap disimpan di *server* sebagai jejak log (Audit).</p>
                                
                                <?php foreach ($list_berkas as $dok): ?>
                                    <div class="bg-white p-3.5 rounded-xl border border-slate-200 shadow-2xs relative overflow-hidden">
                                        <?php if ($dok['file_path']): ?>
                                            <div class="absolute top-0 right-0 w-1.5 h-full bg-emerald-500"></div>
                                        <?php else: ?>
                                            <div class="absolute top-0 right-0 w-1.5 h-full bg-rose-500"></div>
                                        <?php endif; ?>
                                        
                                        <div class="flex justify-between items-start mb-2 pr-3">
                                            <div>
                                                <span class="block text-xs font-bold text-slate-800"><?= htmlspecialchars($dok['nama_dokumen']) ?></span>
                                                <span class="text-[9px] uppercase font-bold <?= $dok['is_mandatory']=='Y'?'text-rose-600':'text-slate-400' ?>"><?= $dok['is_mandatory']=='Y'?'Wajib':'Opsional' ?></span>
                                            </div>
                                            
                                            <?php if ($dok['file_path']): ?>
                                                <div class="flex flex-col sm:flex-row gap-1.5 items-end sm:items-center">
                                                    <a href="../storage/uploads/<?= htmlspecialchars($dok['file_path']) ?>" target="_blank" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-[10px] font-extrabold rounded-md flex items-center gap-1 transition border border-emerald-200/60">
                                                        Lihat Berkas
                                                    </a>
                                                    <button type="submit" name="hapus_dokumen" value="<?= $dok['id_dokumen'] ?>" onclick="return confirm('Yakin ingin menghapus dokumen ini dari sistem? File fisik tetap disimpan sebagai log audit.')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 text-[10px] font-extrabold rounded-md flex items-center gap-1 transition border border-rose-200/60">
                                                        Hapus
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 bg-rose-50 text-rose-700 text-[10px] font-extrabold rounded-md border border-rose-200">
                                                    Belum Masuk / Hilang
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1">Ganti / Susulkan File Baru (Max 10MB)</label>
                                        <input type="file" name="dokumen_<?= $dok['id_dokumen'] ?>" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-[10px] text-slate-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-[10px] file:font-bold file:uppercase file:bg-blue-50 file:text-[#0e74bb] hover:file:bg-blue-100 cursor-pointer">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>

                    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-md sticky bottom-6 z-40 flex justify-end">
                        <button type="submit" class="px-6 py-3 bg-[#0e74bb] hover:bg-[#0b5e99] text-white text-xs sm:text-sm font-bold rounded-lg transition shadow-xs uppercase tracking-wider flex items-center gap-2" onclick="return confirm('Peringatan: Perubahan ini bersifat final dan merubah struktur data serta status verifikasi pendaftar. Lanjutkan?')">
                            <i class="fas fa-save text-sm"></i>
                            Simpan Perbaikan Data, Dokumen & Status Verifikasi
                        </button>
                    </div>

                </form>
            <?php endif; ?>

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

        document.querySelectorAll('input[name="status_verifikasi"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.querySelectorAll('input[name="status_verifikasi"]').forEach(function(r) {
                    const card = r.closest('label');
                    if (card) {
                        card.classList.remove('border-emerald-500', 'bg-emerald-50/40', 'border-rose-500', 'bg-rose-50/40', 'border-amber-500', 'bg-amber-50/40', 'shadow-xs');
                        card.classList.add('border-slate-200', 'bg-white');
                    }
                });
                const currentCard = this.closest('label');
                if (currentCard) {
                    currentCard.classList.remove('border-slate-200', 'bg-white');
                    if (this.value === 'MEMENUHI PERSYARATAN') {
                        currentCard.classList.add('border-emerald-500', 'bg-emerald-50/40', 'shadow-xs');
                    } else if (this.value === 'TIDAK MEMENUHI PERSYARATAN') {
                        currentCard.classList.add('border-rose-500', 'bg-rose-50/40', 'shadow-xs');
                    } else {
                        currentCard.classList.add('border-amber-500', 'bg-amber-50/40', 'shadow-xs');
                    }
                }
            });
        });
    </script>
</body>
</html>