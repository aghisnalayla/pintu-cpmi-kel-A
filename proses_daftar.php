<?php
ob_start();
session_start();

if (file_exists(__DIR__ . '/maintenance.flag')) {
    require_once __DIR__ . '/maintenance.php';
    exit;
}

require_once __DIR__ . '/config.php';

try {
    if (isset($pdo)) {
        $stmtMaint = $pdo->prepare("SELECT nilai_pengaturan FROM tb_pengaturan WHERE nama_pengaturan = 'status_maintenance'");
        $stmtMaint->execute();
        $resMaint = $stmtMaint->fetchColumn();
        if ($resMaint !== false && trim((string)$resMaint) === '1') {
            require_once __DIR__ . '/maintenance.php';
            exit;
        }
    }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    die("
        <div style='max-width:560px; margin:50px auto; padding:24px; font-family:\"Plus Jakarta Sans\", sans-serif; background:#fff; border:1px solid #fee2e2; border-radius:12px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); text-align:center;'>
            <div style='width:48px; height:48px; background:#fee2e2; color:#dc2626; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto; font-size:22px; font-weight:bold;'>!</div>
            <h3 style='color:#991b1b; margin:0 0 8px 0; font-size:18px; font-weight:700;'>Ukuran Berkas Melebihi Batas</h3>
            <p style='color:#4b5563; font-size:13px; line-height:1.6; margin:0 0 20px 0;'>Ukuran total berkas yang Anda kirimkan melebihi batas muatan server (POST Size). Silakan perkecil / kompres berkas Anda dan coba kembali.</p>
            <a href='javascript:history.back()' style='display:inline-block; padding:10px 20px; background:#1e293b; color:#fff; text-decoration:none; border-radius:6px; font-size:13px; font-weight:600;'>&larr; Kembali ke Formulir</a>
        </div>
    ");
}

$id_periode          = (int)($_POST['id_periode'] ?? 0);
$batch_pelatihan     = trim($_POST['batch_pelatihan'] ?? '');
$nik                 = trim($_POST['nik'] ?? '');
$nama_lengkap        = trim($_POST['nama_lengkap'] ?? '');
$email               = trim($_POST['email'] ?? '');
$pendidikan          = trim($_POST['pendidikan'] ?? '');
$tanggal_lahir       = trim($_POST['tanggal_lahir'] ?? '');
$usia                = (int)($_POST['usia'] ?? 0);
$jenis_kelamin       = trim($_POST['jenis_kelamin'] ?? '');
$domisili            = trim($_POST['domisili'] ?? '');
$alamat_lengkap      = trim($_POST['alamat_lengkap'] ?? '');
$no_jkn              = trim($_POST['no_jkn'] ?? '');
$no_hp_wa            = trim($_POST['no_hp_wa'] ?? '');
$id_program          = (int)($_POST['id_program'] ?? 0);
$lembaga_pelatihan   = trim($_POST['lembaga_pelatihan'] ?? '');
$ref_pelatihan_dimin = trim($_POST['ref_pelatihan_dimin'] ?? '');

if (empty($nik) || empty($nama_lengkap) || empty($lembaga_pelatihan)) {
    die("<div style='padding:20px; font-family:sans-serif; color:red;'><b>Gagal:</b> Data formulir tidak lengkap.</div>");
}

$isPemutihan = false;
try {
    $stmtPem = $pdo->prepare("SELECT COUNT(*) FROM tb_master_pemutihan WHERE nik = ? AND status_aktif = 'Y'");
    $stmtPem->execute([$nik]);
    if ($stmtPem->fetchColumn() > 0) $isPemutihan = true;
} catch (Throwable $e) {}

if (!$isPemutihan) {
    try {
        $stmtLolos = $pdo->prepare("
            SELECT v.status
            FROM tb_pendaftaran p
            LEFT JOIN tb_status_verifikasi v ON p.cpmi_id = v.cpmi_id
            WHERE p.nik_sesuai_ktp = ?
            ORDER BY p.cpmi_id DESC LIMIT 1
        ");
        $stmtLolos->execute([$nik]);
        $statusVerif = strtoupper(trim((string)$stmtLolos->fetchColumn()));

        if (in_array($statusVerif, ['MEMENUHI PERSYARATAN', 'BELUM DIVERIFIKASI', 'MENUNGGU VERIFIKASI'])) {
            die("<div style='padding:20px; font-family:sans-serif; color:red;'><b>Pendaftaran Ditolak:</b> Sistem mendeteksi pengiriman ganda. NIK Anda baru saja berhasil terdaftar beberapa saat yang lalu. Jangan muat ulang (refresh) halaman ini.</div>");
        }
    } catch (Throwable $e) {
        die("<div style='padding:20px; font-family:sans-serif; color:red;'><b>Kesalahan Sistem:</b> Gagal memverifikasi keamanan NIK.</div>");
    }
}


try {
    $pdo->beginTransaction();
    $stmtCekId = $pdo->query("SELECT cpmi_id FROM tb_pendaftaran ORDER BY LENGTH(cpmi_id) DESC, cpmi_id DESC LIMIT 1");
    $lastIdRaw = $stmtCekId->fetchColumn();
    $lastNum = 0;
    if ($lastIdRaw) {
        $lastNum = (int) preg_replace('/[^0-9]/', '', (string)$lastIdRaw);
    }
    $cpmi_id = 'CPMI-' . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);

    $sqlInsert = "
        INSERT INTO tb_pendaftaran (
            cpmi_id, nik_sesuai_ktp, nama_lengkap, tanggal_lahir, usia, jenis_kelamin, pendidikan, email, 
            domisili, alamat_lengkap, no_jkn, no_hp_wa, 
            batch_pelatihan, lembaga_pelatihan, ref_pelatihan_dimin, 
            status_ujian, status_tertulis, status_interview
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, 
            ?, ?, ?, 
            'belum', 'belum', 'belum'
        )
    ";
    $stmt = $pdo->prepare($sqlInsert);
    $stmt->execute([
        $cpmi_id, $nik, $nama_lengkap, $tanggal_lahir, $usia, $jenis_kelamin, $pendidikan, $email,
        $domisili, $alamat_lengkap, $no_jkn, $no_hp_wa,
        $batch_pelatihan, $lembaga_pelatihan, $ref_pelatihan_dimin
    ]);

    $uploadDir = __DIR__ . '/storage/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

    $stmtDok = $pdo->query("SELECT id_dokumen, nama_dokumen, is_mandatory FROM tb_master_dokumen WHERE status_aktif = 'Y'");
    $masterDok = $stmtDok->fetchAll(PDO::FETCH_ASSOC);

    $stmtBerkas = $pdo->prepare("INSERT INTO tb_berkas_pendaftaran (cpmi_id, id_dokumen, file_path, nama_file_asli, file_size) VALUES (?, ?, ?, ?, ?)");
    $uploadedTempFiles = [];
    $totalWajibDibutuhkan = 0;

    foreach ($masterDok as $dok) {
        $inputName = 'dokumen_' . $dok['id_dokumen'];
        $isWajib   = (strtoupper(trim((string)($dok['is_mandatory'] ?? ''))) === 'Y');
        if ($isWajib) {
            $totalWajibDibutuhkan++;
        }

        if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
            $tmpName  = $_FILES[$inputName]['tmp_name'];
            $fileName = $_FILES[$inputName]['name']; 
            $fileSize = (int)$_FILES[$inputName]['size']; 
            $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) { 
                throw new Exception("Format berkas untuk '{$dok['nama_dokumen']}' ({$ext}) tidak diizinkan. Hanya format PDF, JPG, JPEG, atau PNG yang diperbolehkan.");
            }

            if ($fileSize > 4194304) {
                throw new Exception("Ukuran berkas '{$dok['nama_dokumen']}' melebihi batas maksimal (maksimal 3 MB).");
            }

            if ($fileSize <= 0) {
                throw new Exception("Berkas '{$dok['nama_dokumen']}' terdeteksi kosong (0 Byte).");
            }

            $newName  = "CPMI_{$cpmi_id}_DOK_{$dok['id_dokumen']}_" . time() . "_" . mt_rand(100, 999) . ".{$ext}";
            $destPath = $uploadDir . $newName;

            if (move_uploaded_file($tmpName, $destPath)) {
                $stmtBerkas->execute([$cpmi_id, $dok['id_dokumen'], $newName, $fileName, $fileSize]);
                $uploadedTempFiles[] = $destPath;
            } else {
                throw new Exception("Gagal menyimpan berkas '{$dok['nama_dokumen']}' ke server.");
            }
        } else {
            $errCode = $_FILES[$inputName]['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($isWajib) {
                if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                    $pesanErr = "Berkas wajib '{$dok['nama_dokumen']}' melebihi batas muatan server (maksimal 3 MB).";
                } elseif ($errCode === UPLOAD_ERR_PARTIAL) {
                    $pesanErr = "Proses unggah berkas wajib '{$dok['nama_dokumen']}' terputus.";
                } else {
                    $pesanErr = "Berkas wajib '{$dok['nama_dokumen']}' belum diunggah atau tidak ditemukan.";
                }
                throw new Exception($pesanErr);
            } else {
                if ($errCode !== UPLOAD_ERR_NO_FILE) {
                    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                        $pesanErr = "Berkas opsional '{$dok['nama_dokumen']}' melebihi batas muatan server (maksimal 3 MB).";
                    } elseif ($errCode === UPLOAD_ERR_PARTIAL) {
                        $pesanErr = "Proses unggah berkas opsional '{$dok['nama_dokumen']}' terputus.";
                    } else {
                        $pesanErr = "Gagal mengunggah berkas opsional '{$dok['nama_dokumen']}'.";
                    }
                    throw new Exception($pesanErr);
                }
            }
        }
    }

    if ($totalWajibDibutuhkan > 0) {
        $stmtCekWajib = $pdo->prepare("
            SELECT COUNT(*) 
            FROM tb_berkas_pendaftaran b 
            JOIN tb_master_dokumen m ON b.id_dokumen = m.id_dokumen 
            WHERE b.cpmi_id = ? AND UPPER(TRIM(m.is_mandatory)) = 'Y'
        ");
        $stmtCekWajib->execute([$cpmi_id]);
        $jumlahWajibTersimpan = (int)$stmtCekWajib->fetchColumn();

        if ($jumlahWajibTersimpan < $totalWajibDibutuhkan) {
            throw new Exception("Pendaftaran tidak dapat diproses karena terdapat berkas wajib yang belum lengkap tersimpan.");
        }
    }

    $stmtVerif = $pdo->prepare("INSERT INTO tb_status_verifikasi (cpmi_id, status, catatan) VALUES (?, 'BELUM DIVERIFIKASI', '')");
    $stmtVerif->execute([$cpmi_id]);

    $stmtCabutTiket = $pdo->prepare("UPDATE tb_master_pemutihan SET status_aktif = 'N' WHERE nik = ?");
    $stmtCabutTiket->execute([$nik]);

    $pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
    if (!empty($uploadedTempFiles)) {
        foreach ($uploadedTempFiles as $fl) {
            if (file_exists($fl)) @unlink($fl);
        }
    }
    die("
        <div style='max-width:560px; margin:50px auto; padding:24px; font-family:\"Plus Jakarta Sans\", sans-serif; background:#fff; border:1px solid #fee2e2; border-radius:12px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); text-align:center;'>
            <div style='width:48px; height:48px; background:#fee2e2; color:#dc2626; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto; font-size:22px; font-weight:bold;'>!</div>
            <h3 style='color:#991b1b; margin:0 0 8px 0; font-size:18px; font-weight:700;'>Pendaftaran Gagal Diproses</h3>
            <p style='color:#4b5563; font-size:13px; line-height:1.6; margin:0 0 20px 0;'>" . htmlspecialchars($e->getMessage()) . "</p>
            <a href='javascript:history.back()' style='display:inline-block; padding:10px 20px; background:#1e293b; color:#fff; text-decoration:none; border-radius:6px; font-size:13px; font-weight:600;'>&larr; Kembali dan Periksa Berkas</a>
        </div>
    ");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tanda Bukti Pendaftaran — BP3MI Jawa Tengah</title>
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .print-area { background: #ffffff; }
        @media print {
            body { background: #ffffff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .print-area { box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
            @page { margin: 1cm; }
        }
    </style>
</head>
<body class="text-slate-800 min-h-screen flex flex-col items-center py-10 px-4">

    <div class="max-w-2xl w-full flex justify-between items-center mb-6 no-print">
        <a href="index.php" class="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded-xl text-xs transition uppercase tracking-wider flex items-center gap-2">&larr; Kembali</a>
        <button onclick="window.print()" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-xs transition shadow-md shadow-indigo-200 uppercase tracking-wider flex items-center gap-2">
            <span>Cetak Tanda Bukti</span>
        </button>
    </div>

    <div class="print-area max-w-2xl w-full bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden relative">
        <div class="h-2 w-full bg-indigo-600"></div>
        <div class="p-8 sm:p-12">
            
            <div class="flex items-center justify-center space-x-4 border-b-2 border-slate-800 pb-6 mb-8 text-center">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" alt="Logo BP2MI" class="h-16 w-auto object-contain">
                <div>
                    <h1 class="font-extrabold text-slate-900 text-sm tracking-widest uppercase">Tanda Bukti Registrasi Peserta</h1>
                    <p class="font-black text-indigo-700 text-lg uppercase tracking-tight leading-none mt-1">Peningkatan Kapasitas CPMI</p>
                    <p class="text-xs font-bold text-slate-500 mt-1 uppercase">BP3MI Jawa Tengah</p>
                </div>
            </div>

            <div class="bg-emerald-50 border border-emerald-200 p-4 rounded-xl text-center mb-8">
                <span class="block text-emerald-600 font-extrabold text-sm mb-1 uppercase tracking-widest">Pendaftaran Berhasil!</span>
                <p class="text-xs text-slate-600 font-medium">Data dan berkas Anda telah masuk ke dalam sistem. Anda hanya dapat melakukan pendaftaran 1x. Silakan simpan tanda bukti ini dan tunggu informasi verifikasi dari tim BP3MI Jawa Tengah.</p>
            </div>

            <div class="border border-slate-300 rounded-2xl p-6 relative">
                <span class="absolute -top-3 left-6 bg-white px-2 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Identitas Pendaftar</span>
                
                <table class="w-full text-xs text-slate-800 font-medium">
                    <tbody>
                        <tr><td class="py-2 w-36 text-slate-500 font-bold uppercase tracking-wider">CPMI - ID</td><td class="py-2 w-4">:</td><td class="py-2 font-mono font-black text-indigo-700 text-sm"><?= htmlspecialchars($cpmi_id) ?></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">NIK KTP</td><td class="py-2">:</td><td class="py-2 font-mono font-bold text-slate-900"><?= htmlspecialchars($nik) ?></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Nama Lengkap</td><td class="py-2">:</td><td class="py-2 font-bold text-slate-900 text-sm"><?= htmlspecialchars($nama_lengkap) ?></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Email Akif</td><td class="py-2">:</td><td class="py-2 text-slate-800"><?= htmlspecialchars($email) ?></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Domisili</td><td class="py-2">:</td><td class="py-2 text-slate-800"><?= htmlspecialchars($domisili) ?></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Pendidikan</td><td class="py-2">:</td><td class="py-2 text-slate-800"><?= htmlspecialchars($pendidikan) ?></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">No. WhatsApp</td><td class="py-2">:</td><td class="py-2 font-mono font-bold text-slate-800"><?= htmlspecialchars($no_hp_wa) ?></td></tr>
                        <tr><td colspan="3" class="border-t border-dashed border-slate-200 my-2"></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Lembaga Pilihan</td><td class="py-2">:</td><td class="py-2 font-bold text-slate-900"><?= htmlspecialchars($lembaga_pelatihan) ?></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Kejuruan (Program)</td><td class="py-2">:</td><td class="py-2 font-bold text-slate-900"><?= htmlspecialchars($ref_pelatihan_dimin) ?></td></tr>
                        <tr><td class="py-2 text-slate-500 font-bold uppercase tracking-wider">Batch Gelombang</td><td class="py-2">:</td><td class="py-2 font-bold text-slate-800"><?= htmlspecialchars($batch_pelatihan) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-10 flex justify-between items-end text-[10px] text-slate-500 font-medium">
                <div><p>Waktu Daftar: <span class="font-mono font-bold text-slate-700"><?= date('d M Y - H:i:s') ?> WIB</span></p><p>Status: <span class="font-bold text-amber-600">PENDAFTARAN BERHASIL</span></p></div>
                <div class="text-right"><p class="font-bold text-slate-800">BP3MI Jawa Tengah</p><p>PINTU-DAFTAR | PUSAT INFORMASI PENDAFTARAN PENINGKATAN KAPASITAS CPMI</p></div>
            </div>

        </div>
    </div>
</body>
</html>