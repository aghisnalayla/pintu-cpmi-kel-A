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
if (file_exists(__DIR__ . '/helpers.php')) {
    require_once __DIR__ . '/helpers.php';
}

if (file_exists(__DIR__ . '/api/helper_log.php')) {
    require_once __DIR__ . '/api/helper_log.php';
}

if (!function_exists('makeClickableLinks')) {
    function makeClickableLinks($text) {
        if (empty($text) OR $text === '-') return '-';
        $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $pattern = '/\b(?:https?):\/\/[a-z0-9-+&@#\/%?=~_|!:,.;]*[a-z0-9-+&@#\/%=~_|]/i';
        $replacement = '<a href="$0" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-blue-700 hover:text-blue-900 font-semibold underline transition">Link - Klik Disini <svg class="w-3.5 h-3.5 inline text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a>';
        return nl2br(preg_replace($pattern, $replacement, $safeText));
    }
}

$periodeAktif = null;
try {
    $stmtP = $pdo->query("SELECT * FROM tb_periode_gelombang WHERE UPPER(TRIM(COALESCE(status, ''))) = 'AKTIF' OR UPPER(TRIM(COALESCE(status_buka, ''))) = 'BUKA' ORDER BY id_periode DESC LIMIT 1");
    $periodeAktif = $stmtP->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$periodeAktif AND function_exists('getPeriodeAktif')) {
    $periodeAktif = getPeriodeAktif($pdo);
}

$isPendaftaranBuka = false;
if ($periodeAktif) {
    $statusVal = strtoupper(trim((string)($periodeAktif['status'] ?? $periodeAktif['status_buka'] ?? '')));
    if ($statusVal === 'AKTIF' OR $statusVal === 'BUKA') $isPendaftaranBuka = true;
}

$batchAktifNama = $periodeAktif ? $periodeAktif['nama_periode'] : 'Belum Ada Gelombang Aktif';

if ($_SERVER['REQUEST_METHOD'] === 'POST' AND isset($_POST['action']) AND $_POST['action'] === 'cek_nik') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $maxRequests = 5;
    $timeWindow = 60;
    $lockoutTime = 180;
    $currentTime = time();

    if (isset($_SESSION['rl_lockout_until']) && $currentTime < $_SESSION['rl_lockout_until']) {
        $sisaWaktu = $_SESSION['rl_lockout_until'] - $currentTime;
        $menitSisa = ceil($sisaWaktu / 60);
        echo json_encode(['status' => 'error', 'pesan' => "Terlalu banyak percobaan. Harap tunggu {$menitSisa} menit sebelum mencoba lagi."]);
        exit;
    }

    if (!isset($_SESSION['rl_requests'])) {
        $_SESSION['rl_requests'] = [];
    }

    $_SESSION['rl_requests'] = array_filter($_SESSION['rl_requests'], function($timestamp) use ($currentTime, $timeWindow) {
        return ($currentTime - $timestamp) < $timeWindow;
    });

    $_SESSION['rl_requests'][] = $currentTime;

    if (count($_SESSION['rl_requests']) > $maxRequests) {
        $_SESSION['rl_lockout_until'] = $currentTime + $lockoutTime;
        
        if (function_exists('catatLog')) {
            catatLog($pdo, "IP {$_SERVER['REMOTE_ADDR']} diblokir sementara karena aktivitas spam pengecekan NIK", 'KEAMANAN');
        }
        
        echo json_encode(['status' => 'error', 'pesan' => "Aktivitas mencurigakan terdeteksi. Sistem mengunci pengecekan NIK Anda selama 3 menit."]);
        exit;
    }

    $nik = trim($_POST['nik'] ?? '');
    if (strlen($nik) !== 16 OR !ctype_digit($nik)) {
        if (function_exists('catatLog')) {
            catatLog($pdo, "Percobaan pemeriksaan NIK gagal (Format tidak valid): {$nik}", 'PENDAFTARAN');
        }
        echo json_encode(['status' => 'error', 'pesan' => 'Format NIK tidak valid. Harap masukkan tepat 16 digit angka sesuai KTP.']);
        exit;
    }

    try {
        $isPemutihan = false;
        try {
            $stmtPem = $pdo->prepare("SELECT COUNT(*) FROM tb_master_pemutihan WHERE nik = ? AND status_aktif = 'Y'");
            $stmtPem->execute([$nik]);
            if ($stmtPem->fetchColumn() > 0) $isPemutihan = true;
        } catch (Throwable $e) {}

        if (!$isPemutihan) {
            $stmtLolos = $pdo->prepare("
                SELECT p.cpmi_id, p.nama_lengkap, p.batch_pelatihan, v.status
                FROM tb_pendaftaran p
                LEFT JOIN tb_status_verifikasi v ON p.cpmi_id = v.cpmi_id
                WHERE p.nik_sesuai_ktp = ?
                ORDER BY p.cpmi_id DESC LIMIT 1
            ");
            $stmtLolos->execute([$nik]);
            $dataLolos = $stmtLolos->fetch(PDO::FETCH_ASSOC);

            if ($dataLolos) {
                $statusVerif = strtoupper(trim((string)($dataLolos['status'] ?? '')));
                
                if ($statusVerif === 'MEMENUHI PERSYARATAN') {
                    if (function_exists('catatLog')) {
                        catatLog($pdo, "Pendaftaran ditolak untuk NIK {$nik}: sudah terdaftar dan lolos administrasi", 'PENDAFTARAN');
                    }
                    echo json_encode(['status' => 'error', 'pesan' => "Pendaftaran Ditolak: NIK Anda sudah pernah terdaftar pada Batch / Gelombang Pelatihan sebelumnya. Anda tidak dapat melakukan pendaftaran ganda."]);
                    exit;
                } elseif (in_array($statusVerif, ['BELUM DIVERIFIKASI', 'MENUNGGU VERIFIKASI', ''])) {
                    if (function_exists('catatLog')) {
                        catatLog($pdo, "Pendaftaran ditolak untuk NIK {$nik}: masih dalam antrean verifikasi", 'PENDAFTARAN');
                    }
                    echo json_encode(['status' => 'error', 'pesan' => "Pendaftaran Ditolak: NIK Anda sudah pernah terdaftar pada Batch / Gelombang Pelatihan sebelumnya. Anda tidak dapat melakukan pendaftaran ganda."]);
                    exit;
                }
            }
        }

        if (function_exists('catatLog')) {
            catatLog($pdo, "Pemeriksaan NIK berhasil dan valid untuk pendaftaran: {$nik}", 'PENDAFTARAN');
        }

        echo json_encode(['status' => 'success', 'pesan' => 'NIK anda belum pernah terdaftar pada Batch / Gelombang Pelatihan sebelumnya, silahkan untuk melanjutkan pendaftaran.']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'pesan' => 'Terjadi kendala basis data saat memeriksa NIK: ' . $e->getMessage()]);
    }
    exit;
}

$listProgram = []; 
$listLembagaUnik = []; 
$listDokumen = [];

if ($isPendaftaranBuka) {
    try {
        $stmtProg = $pdo->prepare("SELECT pb.id_program, ml.nama_lembaga, mk.nama_kejuruan, pb.id_lembaga, pb.id_kejuruan FROM tb_program_buka pb JOIN tb_master_lembaga ml ON pb.id_lembaga = ml.id_lembaga JOIN tb_master_kejuruan mk ON pb.id_kejuruan = mk.id_kejuruan WHERE pb.id_periode = ? AND UPPER(TRIM(pb.status_program)) = 'AKTIF' ORDER BY ml.nama_lembaga ASC, mk.nama_kejuruan ASC");
        $stmtProg->execute([(int)$periodeAktif['id_periode']]);
        $listProgram = $stmtProg->fetchAll(PDO::FETCH_ASSOC);

        if (empty($listProgram)) {
            $listProgram = $pdo->query("SELECT pb.id_program, ml.nama_lembaga, mk.nama_kejuruan, pb.id_lembaga, pb.id_kejuruan FROM tb_program_buka pb JOIN tb_master_lembaga ml ON pb.id_lembaga = ml.id_lembaga JOIN tb_master_kejuruan mk ON pb.id_kejuruan = mk.id_kejuruan WHERE UPPER(TRIM(pb.status_program)) = 'AKTIF' ORDER BY ml.nama_lembaga ASC, mk.nama_kejuruan ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($listProgram)) {
            $lembagas = $pdo->query("SELECT id_lembaga, nama_lembaga FROM tb_master_lembaga WHERE status_aktif = 'Y' ORDER BY nama_lembaga ASC")->fetchAll(PDO::FETCH_ASSOC);
            $kejuruans = $pdo->query("SELECT id_kejuruan, nama_kejuruan FROM tb_master_kejuruan WHERE status_aktif = 'Y' ORDER BY nama_kejuruan ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lembagas as $l) { 
                foreach ($kejuruans as $k) { 
                    $listProgram[] = ['id_program' => 0, 'id_lembaga' => $l['id_lembaga'], 'nama_lembaga' => $l['nama_lembaga'], 'id_kejuruan' => $k['id_kejuruan'], 'nama_kejuruan' => $k['nama_kejuruan']]; 
                } 
            }
        }

        foreach ($listProgram as $p) {
            if (!in_array($p['nama_lembaga'],$listLembagaUnik)) $listLembagaUnik[] =$p['nama_lembaga'];
        }
    } catch (Throwable $e) {}

    try {
        $listDokumen =$pdo->query("SELECT * FROM tb_master_dokumen WHERE status_aktif = 'Y' ORDER BY urutan ASC, id_dokumen ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PINTU-DAFTAR | Pusat Informasi Pendaftaran Peningkatan Kapasitas CPMI — BP3MI Jawa Tengah</title>
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f1f5f9; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .gov-card { background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); }
        .gov-input { 
            background: #ffffff; 
            border: 1px solid #cbd5e1; 
            color: #334155;
            transition: all 0.15s ease-in-out;
        }
        .gov-input:focus { 
            border-color: #2563eb; 
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2); 
            outline: none; 
        }
        .gov-input:disabled, .gov-input[readonly] {
            background-color: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
            border-color: #e2e8f0;
        }
        .locked-section { opacity: 0.5; pointer-events: none; }
    </style>
</head>
<body class="text-slate-800 min-h-screen flex flex-col selection:bg-blue-200 selection:text-blue-900 antialiased relative">

    <div id="fullLoader" class="fixed inset-0 z-50 flex-col items-center justify-center bg-slate-900/80 backdrop-blur-sm hidden">
        <div class="w-12 h-12 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mb-5"></div>
        <h3 class="text-white font-bold text-lg tracking-wide">Sedang Memproses...</h3>
        <p class="text-slate-300 text-sm mt-2 font-medium text-center px-6 max-w-md">Dokumen Anda sedang diunggah ke dalam sistem. <br><b class="text-white">Mohon jangan menutup atau me-refresh halaman ini.</b></p>
    </div>

    <div id="fileSizeModal" class="fixed inset-0 z-[60] flex-col items-center justify-center bg-slate-900/80 backdrop-blur-sm hidden">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4 shadow-xl border border-slate-200 relative">
            <div class="w-12 h-12 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </div>
            <h3 class="text-center font-bold text-slate-900 text-lg mb-2">Upload Gagal! Ukuran Berkas Terlalu Besar</h3>
            <p class="text-center text-sm text-slate-600 mb-6" id="fileSizeMessage">Ukuran total atau per berkas melebihi batas (3 MB).</p>
            <button onclick="document.getElementById('fileSizeModal').classList.remove('flex'); document.getElementById('fileSizeModal').classList.add('hidden');" class="w-full bg-slate-800 hover:bg-slate-900 text-white font-semibold py-2.5 rounded-md transition shadow-sm">
                Perbaiki File
            </button>
        </div>
    </div>

    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-4">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" alt="Logo BP2MI" class="h-10 sm:h-12 w-auto">
                <div class="border-l border-slate-300 pl-4 py-1">
                    <span class="block font-bold text-slate-800 text-sm sm:text-base leading-tight">PINTU-DAFTAR | Pusat Informasi Pendaftaran Peningkatan Kapasitas CPMI</span>
                    <span class="block text-xs text-slate-500 font-medium mt-0.5">BP3MI Jawa Tengah</span>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-4xl mx-auto px-4 py-8 sm:py-10 w-full">
        
        <div class="mb-6 text-center">
            <span class="inline-block px-3 py-1 bg-blue-100 text-blue-800 border border-blue-200 text-xs font-bold rounded mb-3 uppercase tracking-wide">
                <?= htmlspecialchars($batchAktifNama) ?>
            </span>
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">Formulir Pendaftaran Program Peningkatan Kapasitas CPMI</h1>
        </div>

        <div class="gov-card rounded-xl p-6 sm:p-8">
            
            <?php if (!$isPendaftaranBuka): ?>
                <div class="bg-slate-50 border border-slate-200 p-8 rounded-lg text-center my-6">
                    <div class="w-14 h-14 bg-slate-200 text-slate-500 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800">Pendaftaran Belum Dibuka</h3>
                    <p class="text-sm mt-2 text-slate-600 max-w-md mx-auto">Saat ini BP3MI Jawa Tengah belum membuka periode pendaftaran.</p>
                </div>
            <?php else: ?>
                <form id="formPendaftaran" action="proses_daftar.php" method="POST" enctype="multipart/form-data" class="space-y-8 mt-2">
                    <input type="hidden" name="id_periode" value="<?= (int)($periodeAktif['id_periode'] ?? 0) ?>">
                    <input type="hidden" name="batch_pelatihan" value="<?= htmlspecialchars($batchAktifNama) ?>">
                    <input type="hidden" name="id_program" id="hiddenIdProgram" value="0">
                    <input type="hidden" name="lembaga_pelatihan" id="hiddenLembaga" value="">
                    <input type="hidden" name="ref_pelatihan_dimin" id="hiddenKejuruan" value="">

                    <div class="bg-slate-50 rounded-lg p-5 border border-slate-200">
                        <label class="block text-sm font-bold text-slate-800 mb-3">Verifikasi Riwayat Pendaftaran</label>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <input type="text" id="inputNik" name="nik" maxlength="16" required placeholder="Masukkan 16 Digit NIK." class="gov-input flex-1 px-4 py-2.5 rounded-md font-mono text-sm tracking-widest placeholder:tracking-normal placeholder:font-sans">
                            <button type="button" id="btnCekNik" class="px-6 py-2.5 bg-blue-700 hover:bg-blue-800 text-white font-semibold text-sm rounded-md transition shadow-sm">Periksa NIK</button>
                        </div>
                        <div id="pesanNik" class="hidden mt-3 p-3 rounded-md text-xs font-medium border leading-relaxed"></div>
                    </div>

                    <div id="sectionData" class="locked-section transition-opacity duration-300 space-y-5">
                        <h3 class="text-sm font-bold text-slate-800 border-b border-slate-200 pb-2">A. Informasi Biodata Peserta</h3>
                        
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">Nama Lengkap (Sesuai KTP) <span class="text-red-500">*</span></label>
                            <input type="text" name="nama_lengkap" required placeholder="Tulis nama lengkap tanpa gelar" class="gov-input w-full px-4 py-2.5 rounded-md text-sm">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Email Aktif <span class="text-red-500">*</span></label>
                                <input type="email" name="email" required placeholder="contoh@email.com" class="gov-input w-full px-4 py-2.5 rounded-md text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Pendidikan Terakhir <span class="text-red-500">*</span></label>
                                <select name="pendidikan" required class="gov-input w-full px-4 py-2.5 rounded-md text-sm cursor-pointer">
                                    <option value="">-- Pilih Pendidikan --</option>
                                    <option value="SD">SD / Sederajat</option>
                                    <option value="SMP">SMP / Sederajat</option>
                                    <option value="SMA">SMA / Sederajat</option>
                                    <option value="SMK">SMK / Sederajat</option>
                                    <option value="DIPLOMA">Diploma (D1-D4)</option>
                                    <option value="SARJANA">Sarjana (S1-S3)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-1">
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Jenis Kelamin <span class="text-red-500">*</span></label>
                                <select name="jenis_kelamin" required class="gov-input w-full px-4 py-2.5 rounded-md text-sm cursor-pointer">
                                    <option value="">Pilih</option>
                                    <option value="Laki-laki">Laki-laki</option>
                                    <option value="Perempuan">Perempuan</option>
                                </select>
                            </div>
                            <div class="sm:col-span-1">
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Tanggal Lahir <span class="text-red-500">*</span></label>
                                <input type="date" id="inputTglLahir" name="tanggal_lahir" required class="gov-input w-full px-4 py-2.5 rounded-md text-sm">
                            </div>
                            <div class="sm:col-span-1">
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Usia (Tahun)</label>
                                <input type="number" id="inputUsia" name="usia" readonly class="gov-input w-full px-4 py-2.5 rounded-md text-sm font-semibold" placeholder="Otomatis">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Kab / Kota Domisili <span class="text-red-500">*</span></label>
                                <select name="domisili" required class="gov-input w-full px-4 py-2.5 rounded-md text-sm cursor-pointer">
                                    <option value="">-- Pilih Wilayah --</option>
                                    <option value="Kabupaten Banjarnegara">Kabupaten Banjarnegara</option>
                                    <option value="Kabupaten Banyumas">Kabupaten Banyumas</option>
                                    <option value="Kabupaten Batang">Kabupaten Batang</option>
                                    <option value="Kabupaten Blora">Kabupaten Blora</option>
                                    <option value="Kabupaten Boyolali">Kabupaten Boyolali</option>
                                    <option value="Kabupaten Brebes">Kabupaten Brebes</option>
                                    <option value="Kabupaten Cilacap">Kabupaten Cilacap</option>
                                    <option value="Kabupaten Demak">Kabupaten Demak</option>
                                    <option value="Kabupaten Grobogan">Kabupaten Grobogan</option>
                                    <option value="Kabupaten Jepara">Kabupaten Jepara</option>
                                    <option value="Kabupaten Karanganyar">Kabupaten Karanganyar</option>
                                    <option value="Kabupaten Kebumen">Kabupaten Kebumen</option>
                                    <option value="Kabupaten Kendal">Kabupaten Kendal</option>
                                    <option value="Kabupaten Klaten">Kabupaten Klaten</option>
                                    <option value="Kabupaten Kudus">Kabupaten Kudus</option>
                                    <option value="Kabupaten Magelang">Kabupaten Magelang</option>
                                    <option value="Kabupaten Pati">Kabupaten Pati</option>
                                    <option value="Kabupaten Pekalongan">Kabupaten Pekalongan</option>
                                    <option value="Kabupaten Pemalang">Kabupaten Pemalang</option>
                                    <option value="Kabupaten Purbalingga">Kabupaten Purbalingga</option>
                                    <option value="Kabupaten Purworejo">Kabupaten Purworejo</option>
                                    <option value="Kabupaten Rembang">Kabupaten Rembang</option>
                                    <option value="Kabupaten Semarang">Kabupaten Semarang</option>
                                    <option value="Kabupaten Sragen">Kabupaten Sragen</option>
                                    <option value="Kabupaten Sukoharjo">Kabupaten Sukoharjo</option>
                                    <option value="Kabupaten Tegal">Kabupaten Tegal</option>
                                    <option value="Kabupaten Temanggung">Kabupaten Temanggung</option>
                                    <option value="Kabupaten Wonogiri">Kabupaten Wonogiri</option>
                                    <option value="Kabupaten Wonosobo">Kabupaten Wonosobo</option>
                                    <option value="Kota Magelang">Kota Magelang</option>
                                    <option value="Kota Pekalongan">Kota Pekalongan</option>
                                    <option value="Kota Salatiga">Kota Salatiga</option>
                                    <option value="Kota Semarang">Kota Semarang</option>
                                    <option value="Kota Surakarta">Kota Surakarta</option>
                                    <option value="Kota Tegal">Kota Tegal</option>
                                    <option value="Lainnya">Lainnya (Luar Jawa Tengah)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Alamat Lengkap KTP <span class="text-red-500">*</span></label>
                                <textarea name="alamat_lengkap" rows="1" required placeholder="Jalan, RT/RW, Desa" class="gov-input w-full px-4 py-2.5 rounded-md text-sm resize-none"></textarea>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Nomor JKN / BPJS <span class="text-red-500">*</span></label>
                                <input type="text" name="no_jkn" required placeholder="13 Digit Angka" class="gov-input w-full px-4 py-2.5 rounded-md text-sm font-mono tracking-widest placeholder:tracking-normal placeholder:font-sans">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Nomor WhatsApp <span class="text-red-500">*</span></label>
                                <input type="tel" name="no_hp_wa" required placeholder="Contoh: 081234567890" class="gov-input w-full px-4 py-2.5 rounded-md text-sm font-mono tracking-widest placeholder:tracking-normal placeholder:font-sans">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-3 border-t border-slate-200">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Lembaga Pelatihan <span class="text-red-500">*</span></label>
                                <select id="selectLembaga" required class="gov-input w-full px-4 py-2.5 rounded-md text-sm cursor-pointer">
                                    <option value="">-- Pilih Lembaga --</option>
                                    <?php foreach ($listLembagaUnik as$lem): ?>
                                        <option value="<?= htmlspecialchars($lem) ?>"><?= htmlspecialchars($lem) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Program Pelatihan <span class="text-red-500">*</span></label>
                                <select id="selectKejuruan" required disabled class="gov-input w-full px-4 py-2.5 rounded-md text-sm">
                                    <option value="">-- Pilih Lembaga Terlebih Dahulu --</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="sectionDokumen" class="locked-section transition-opacity duration-300 space-y-4 pt-3">
                        <div class="border-b border-slate-200 pb-2 mb-4 flex justify-between items-end">
                            <h3 class="text-sm font-bold text-slate-800">B. Unggah Dokumen Syarat</h3>
                            <span class="text-xs font-medium text-slate-500">Maksimal 3 MB per File (PDF/JPG/PNG)</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <?php foreach ($listDokumen as $dok): ?>
                                <?php $isWajib = (strtoupper(trim((string)($dok['is_mandatory'] ?? ''))) === 'Y'); ?>
                                <div class="bg-slate-50 p-4 rounded-lg border border-slate-200 doc-box" data-id="<?= $dok['id_dokumen'] ?>">
                                    <div class="flex justify-between items-start mb-1.5">
                                        <label class="block text-sm font-semibold text-slate-800 leading-tight" data-namadok="<?= htmlspecialchars($dok['nama_dokumen']) ?>" for="dokumen_<?= $dok['id_dokumen'] ?>"><?= htmlspecialchars($dok['nama_dokumen']) ?></label>
                                        <?php if ($isWajib): ?>
                                            <span class="text-[10px] font-bold text-red-700 bg-red-100 px-1.5 py-0.5 rounded border border-red-200">Wajib</span>
                                        <?php else: ?>
                                            <span class="text-[10px] font-bold text-slate-600 bg-slate-200 px-1.5 py-0.5 rounded border border-slate-300">Opsional</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-slate-500 mb-3 leading-relaxed break-words">
                                        <?= makeClickableLinks($dok['deskripsi'] ?? '-') ?>
                                    </div>
                                    <input type="file" 
                                           id="dokumen_<?= $dok['id_dokumen'] ?>" 
                                           name="dokumen_<?= $dok['id_dokumen'] ?>" 
                                           accept=".pdf,.jpg,.jpeg,.png" 
                                           <?= $isWajib ? 'required' : '' ?> 
                                           data-wajib="<?= $isWajib ? '1' : '0' ?>"
                                           class="input-dokumen w-full text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-[11px] file:font-semibold file:bg-blue-100 file:text-blue-700 hover:file:bg-blue-200 cursor-pointer border border-slate-300 rounded bg-white p-1 transition">
                                    
                                    <div id="preview_<?= $dok['id_dokumen'] ?>" class="hidden mt-2 p-2 bg-white rounded border border-slate-200 flex items-center justify-between gap-2 shadow-2xs">
                                        <div class="flex items-center gap-2 min-w-0 flex-1">
                                            <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            <span class="text-xs font-semibold text-slate-700 truncate nama-file-info">-</span>
                                            <span class="text-[10px] font-mono text-slate-400 shrink-0 ukuran-file-info">(0 MB)</span>
                                        </div>
                                        <button type="button" 
                                                onclick="hapusDokumen('dokumen_<?= $dok['id_dokumen'] ?>')" 
                                                class="inline-flex items-center gap-1 text-[11px] font-bold text-red-600 hover:text-red-700 bg-red-50 hover:bg-red-100 border border-red-200 px-2 py-1 rounded transition shrink-0" 
                                                title="Hapus / Ganti File">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            Hapus File
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" id="btnSubmitForm" class="locked-section w-full bg-slate-800 hover:bg-slate-900 text-white font-bold py-3.5 rounded-md transition shadow-md text-sm tracking-wide">
                            Kirim Pendaftaran & Cetak Tanda Bukti
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4">
            <p class="font-bold text-slate-700 text-[11px]">
                © <?= date('Y') ?> Balai Pelayanan Pelindungan Pekerja Migran Indonesia (BP3MI) Jawa Tengah
            </p>
            <p class="text-[10px] text-slate-400 mt-0.5">
                Prima Ammaray, Tim Teknologi Informasi
            </p>
        </div>
    </footer>

    <script>
        const dataProgram = <?= json_encode($listProgram) ?>;
        const selectLembaga = document.getElementById('selectLembaga');
        const selectKejuruan = document.getElementById('selectKejuruan');
        const hiddenIdProgram = document.getElementById('hiddenIdProgram');
        const hiddenLembaga = document.getElementById('hiddenLembaga');
        const hiddenKejuruan = document.getElementById('hiddenKejuruan');

        if (selectLembaga && selectKejuruan) {
            selectLembaga.addEventListener('change', function() {
                const lembagaTerpilih = this.value;
                hiddenLembaga.value = lembagaTerpilih;
                hiddenKejuruan.value = '';
                hiddenIdProgram.value = '0';
                selectKejuruan.innerHTML = '<option value="">-- Pilih Jenis Pelatihan (Kejuruan) --</option>';

                if (!lembagaTerpilih) {
                    selectKejuruan.disabled = true;
                    return;
                }

                const kejuruanTersedia = dataProgram.filter(p => p.nama_lembaga === lembagaTerpilih);
                kejuruanTersedia.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.nama_kejuruan;
                    opt.textContent = p.nama_kejuruan;
                    opt.dataset.idProgram = p.id_program || '0';
                    selectKejuruan.appendChild(opt);
                });
                selectKejuruan.disabled = false;
            });

            selectKejuruan.addEventListener('change', function() {
                const optTerpilih = this.options[this.selectedIndex];
                hiddenKejuruan.value = this.value;
                hiddenIdProgram.value = optTerpilih ? (optTerpilih.dataset.idProgram || '0') : '0';
            });
        }

        const btnCekNik = document.getElementById('btnCekNik');
        if(btnCekNik) {
            btnCekNik.addEventListener('click', async () => {
                const nikInput = document.getElementById('inputNik');
                const nik = nikInput.value.trim();
                const pesan = document.getElementById('pesanNik');

                if(nik.length !== 16 || !/^\d+$/.test(nik)) {
                    pesan.textContent = "NIK tidak valid. Harap masukkan tepat 16 digit angka sesuai KTP.";
                    pesan.className = 'mt-3 p-3 rounded-md text-xs font-semibold border bg-red-50 text-red-700 border-red-200';
                    pesan.classList.remove('hidden');
                    return;
                }

                btnCekNik.textContent = "Memverifikasi...";
                btnCekNik.disabled = true;

                const formData = new FormData();
                formData.append('action', 'cek_nik');
                formData.append('nik', nik);

                try {
                    const res = await fetch('index.php', { method: 'POST', body: formData });
                    const data = await res.json();

                    pesan.textContent = data.pesan;
                    pesan.classList.remove('hidden');

                    if(data.status === 'success') {
                        pesan.className = 'mt-3 p-3 rounded-md text-xs font-semibold border bg-green-50 text-green-800 border-green-200';
                        nikInput.readOnly = true;
                        btnCekNik.classList.add('hidden');
                        
                        document.getElementById('sectionData').classList.remove('locked-section');
                        document.getElementById('sectionDokumen').classList.remove('locked-section');
                        document.getElementById('btnSubmitForm').classList.remove('locked-section');
                    } else {
                        pesan.className = 'mt-3 p-3 rounded-md text-xs font-semibold border bg-red-50 text-red-700 border-red-200';
                    }
                } catch (e) {
                    pesan.textContent = 'Gagal menghubungi peladen saat memverifikasi NIK.';
                    pesan.className = 'mt-3 p-3 rounded-md text-xs font-semibold border bg-red-50 text-red-700 border-red-200';
                    pesan.classList.remove('hidden');
                } finally {
                    if(!nikInput.readOnly) {
                        btnCekNik.textContent = "Periksa NIK";
                        btnCekNik.disabled = false;
                    }
                }
            });
        }

        const inputTgl = document.getElementById('inputTglLahir');
        const inputUsia = document.getElementById('inputUsia');
        if (inputTgl) {
            inputTgl.addEventListener('change', function() {
                if(!this.value) return;
                const tglLahir = new Date(this.value);
                const hariIni = new Date();
                let usia = hariIni.getFullYear() - tglLahir.getFullYear();
                const m = hariIni.getMonth() - tglLahir.getMonth();
                if (m < 0 || (m === 0 && hariIni.getDate() < tglLahir.getDate())) usia--;
                
                inputUsia.value = usia;
                if (usia > 45 || usia < 18) {
                    alert('Pemberitahuan: Program peningkatan kapasitas CPMI ditujukan bagi peserta berusia 18 hingga 45 tahun.');
                    this.value = ''; inputUsia.value = '';
                }
            });
        }

        function hapusDokumen(inputId) {
            const input = document.getElementById(inputId);
            if (input) {
                input.value = '';
                const docId = inputId.replace('dokumen_', '');
                const preview = document.getElementById('preview_' + docId);
                const docBox = input.closest('.doc-box');
                if (docBox) {
                    docBox.classList.remove('border-red-400', 'bg-red-50/40');
                }
                if (preview) {
                    preview.classList.add('hidden');
                    const nameEl = preview.querySelector('.nama-file-info');
                    const sizeEl = preview.querySelector('.ukuran-file-info');
                    if (nameEl) nameEl.textContent = '-';
                    if (sizeEl) sizeEl.textContent = '(0 MB)';
                }
            }
        }

        const MAX_FILE_SIZE = 3 * 1024 * 1024;
        const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

        document.querySelectorAll('.input-dokumen').forEach(input => {
            input.addEventListener('change', function() {
                const docId = this.id.replace('dokumen_', '');
                const preview = document.getElementById('preview_' + docId);
                const docBox = this.closest('.doc-box');
                const docLabel = docBox ? docBox.querySelector('label[data-namadok]') : null;
                const docName = docLabel ? docLabel.getAttribute('data-namadok') : 'Dokumen';

                if (docBox) {
                    docBox.classList.remove('border-red-400', 'bg-red-50/40');
                }

                if (this.files && this.files.length > 0) {
                    const file = this.files[0];
                    const ext = file.name.split('.').pop().toLowerCase();
                    const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);

                    if (!ALLOWED_EXT.includes(ext)) {
                        alert(`Format berkas "${file.name}" tidak diizinkan. Harap unggah berkas dengan format PDF, JPG, JPEG, atau PNG.`);
                        hapusDokumen(this.id);
                        return;
                    }

                    if (file.size > MAX_FILE_SIZE) {
                        const modal = document.getElementById('fileSizeModal');
                        const modalMsg = document.getElementById('fileSizeMessage');
                        if (modal && modalMsg) {
                            modalMsg.innerHTML = `Ukuran berkas <b>${docName}</b> adalah <b class="text-red-600">${fileSizeMB} MB</b>, melebihi batas maksimal (<b>3 MB</b>).<br><br>Silahkan kompres berkas tersebut lalu unggah kembali.`;
                            modal.classList.remove('hidden');
                            modal.classList.add('flex');
                        } else {
                            alert(`Ukuran berkas "${docName}" (${fileSizeMB} MB) melebihi batas maksimal 3 MB. Silakan kompres berkas Anda.`);
                        }
                        hapusDokumen(this.id);
                        return;
                    }

                    if (file.size === 0) {
                        alert(`Berkas "${file.name}" terdeteksi kosong (0 Byte). Silakan pilih berkas yang valid.`);
                        hapusDokumen(this.id);
                        return;
                    }

                    if (preview) {
                        const nameEl = preview.querySelector('.nama-file-info');
                        const sizeEl = preview.querySelector('.ukuran-file-info');
                        if (nameEl) nameEl.textContent = file.name;
                        if (sizeEl) sizeEl.textContent = `(${fileSizeMB} MB)`;
                        preview.classList.remove('hidden');
                    }
                } else {
                    hapusDokumen(this.id);
                }
            });
        });

        const formPendaftaran = document.getElementById('formPendaftaran');
        if (formPendaftaran) {
            formPendaftaran.addEventListener('submit', function(e) {
                const allDocInputs = formPendaftaran.querySelectorAll('.input-dokumen');
                let missingDocs = [];
                let oversizedFiles = [];
                let totalUploadSize = 0;

                allDocInputs.forEach(input => {
                    const isWajib = input.getAttribute('data-wajib') === '1' || input.hasAttribute('required');
                    const docBox = input.closest('.doc-box');
                    const docLabel = docBox ? docBox.querySelector('label[data-namadok]') : null;
                    const docName = docLabel ? docLabel.getAttribute('data-namadok') : input.name;

                    if (docBox) {
                        docBox.classList.remove('border-red-400', 'bg-red-50/40');
                    }

                    if (isWajib) {
                        if (!input.files || input.files.length === 0 || input.files[0].size === 0) {
                            missingDocs.push(docName);
                            if (docBox) {
                                docBox.classList.add('border-red-400', 'bg-red-50/40');
                            }
                        }
                    }

                    if (input.files && input.files.length > 0) {
                        const file = input.files[0];
                        totalUploadSize += file.size;
                        if (file.size > MAX_FILE_SIZE) {
                            const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
                            oversizedFiles.push(`<b>${docName}</b> (${fileSizeMB} MB)`);
                            if (docBox) {
                                docBox.classList.add('border-red-400', 'bg-red-50/40');
                            }
                        }
                    }
                });

                if (missingDocs.length > 0) {
                    e.preventDefault();
                    alert('Peringatan: Terdapat DOKUMEN WAJIB yang belum Anda unggah:\n\n• ' + missingDocs.join('\n• ') + '\n\nSilakan periksa dan pastikan seluruh berkas wajib telah dipilih dan diunggah.');
                    for (let i = 0; i < allDocInputs.length; i++) {
                        const input = allDocInputs[i];
                        const isWajib = input.getAttribute('data-wajib') === '1' || input.hasAttribute('required');
                        if (isWajib && (!input.files || input.files.length === 0 || input.files[0].size === 0)) {
                            input.focus();
                            input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            break;
                        }
                    }
                    return false;
                }

                if (oversizedFiles.length > 0) {
                    e.preventDefault();
                    const totalUploadSizeMB = (totalUploadSize / (1024 * 1024)).toFixed(2);
                    const modalMsg = document.getElementById('fileSizeMessage');
                    let pesanError = `Total besaran seluruh file unggahan Anda: <b class='text-slate-800'>${totalUploadSizeMB} MB</b>.<br><br>`;
                    pesanError += `<span class='text-red-600 font-bold'>Ditemukan file yang melebihi batas (3 MB):</span><br>`;
                    pesanError += `<ul class='text-left inline-block list-disc pl-5 mt-2'><li>` + oversizedFiles.join('</li><li>') + `</li></ul><br><br>`;
                    pesanError += `Silakan perkecil (kompres) ukuran file-file tersebut menggunakan layanan kompresi PDF/Foto daring lalu unggah kembali.`;
                    
                    modalMsg.innerHTML = pesanError;
                    document.getElementById('fileSizeModal').classList.remove('hidden');
                    document.getElementById('fileSizeModal').classList.add('flex');
                    return false;
                }

                const btn = document.getElementById('btnSubmitForm');
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
                btn.innerHTML = 'Sedang Mengunggah & Memproses...';

                document.getElementById('fullLoader').classList.remove('hidden');
                document.getElementById('fullLoader').classList.add('flex');
            });
        }
    </script>
</body>
</html>