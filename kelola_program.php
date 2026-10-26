<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

if (file_exists(__DIR__ . '/../api/helper_log.php')) {
    require_once __DIR__ . '/../api/helper_log.php';
}

$namaAdmin    = $_SESSION['admin_nama'];
$levelAdmin   = $_SESSION['admin_level'];
$isSuperAdmin = in_array(strtolower($levelAdmin), ['superadmin', 'admin']);

$pesan = '';
$tipePesan = '';

$stmtP = $pdo->query("
    SELECT * FROM tb_periode_gelombang 
    WHERE UPPER(TRIM(COALESCE(status, ''))) = 'AKTIF'
    ORDER BY id_periode DESC 
    LIMIT 1
");
$periodeAktif = $stmtP->fetch(PDO::FETCH_ASSOC);
$idPeriodeAktif = $periodeAktif ? (int)$periodeAktif['id_periode'] : 0;
$namaBatchAktif = $periodeAktif ? $periodeAktif['nama_periode'] : 'Belum Ada Gelombang Aktif';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'tambah_program_buka') {
        if ($idPeriodeAktif <= 0) {
            $pesan = 'Gagal: Tidak ada periode gelombang pendaftaran yang sedang aktif.';
            $tipePesan = 'error';
        } else {
            $idLembaga  = (int)($_POST['id_lembaga'] ?? 0);
            $idKejuruan = (int)($_POST['id_kejuruan'] ?? 0);

            if ($idLembaga > 0 && $idKejuruan > 0) {
                try {
                    $stmtCek = $pdo->prepare("SELECT COUNT(*) FROM tb_program_buka WHERE id_periode = ? AND id_lembaga = ? AND id_kejuruan = ?");
                    $stmtCek->execute([$idPeriodeAktif, $idLembaga, $idKejuruan]);
                    if ($stmtCek->fetchColumn() == 0) {
                        $stmtIns = $pdo->prepare("INSERT INTO tb_program_buka (id_periode, id_lembaga, id_kejuruan, status_program) VALUES (?, ?, ?, 'AKTIF')");
                        $stmtIns->execute([$idPeriodeAktif, $idLembaga, $idKejuruan]);
                        
                        if (function_exists('catatLog')) {
                            catatLog($pdo, "Membuka program pelatihan (Lembaga ID: {$idLembaga}, Kejuruan ID: {$idKejuruan}) pada gelombang {$namaBatchAktif}", 'PROGRAM');
                        }

                        $pesan = "Kombinasi program berhasil dibuka pada gelombang “{$namaBatchAktif}”.";
                        $tipePesan = 'success';
                    } else {
                        $pesan = 'Program dengan kombinasi lembaga dan kejuruan tersebut sudah ada.';$tipePesan = 'error';
                    }
                } catch (Throwable $e) {$pesan = 'Gagal menyimpan: ' . $e->getMessage();$tipePesan = 'error';
                }
            }
        }
    }

    if ($action === 'hapus_program_buka') {
        $idProgram = (int)($_POST['id_program'] ?? 0);
        if ($idProgram > 0) {
            try {
                $stmtDel =$pdo->prepare("DELETE FROM tb_program_buka WHERE id_program = ?");
                $stmtDel->execute([$idProgram]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menutup program buka dengan ID #{$idProgram} dari gelombang aktif", 'PROGRAM');
                }

                $pesan = 'Program berhasil ditutup dari gelombang aktif.';$tipePesan = 'success';
            } catch (Throwable $e) {$pesan = 'Gagal menghapus: ' . $e->getMessage();$tipePesan = 'error';
            }
        }
    }

    if ($action === 'tambah_lembaga') {
        $namaLembaga = trim($_POST['nama_lembaga'] ?? '');
        if (!empty($namaLembaga)) {
            try {
                $stmt =$pdo->prepare("INSERT INTO tb_master_lembaga (nama_lembaga, status_aktif) VALUES (?, 'Y')");
                $stmt->execute([$namaLembaga]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menambahkan lembaga pelatihan baru: {$namaLembaga}", 'LEMBAGA');
                }

                $pesan = "Lembaga pelatihan “{$namaLembaga}” berhasil ditambahkan.";
                $tipePesan = 'success';
            } catch (Throwable $e) {$pesan = 'Gagal menambah lembaga: ' . $e->getMessage();$tipePesan = 'error';
            }
        }
    }

    if ($action === 'hapus_lembaga') {
        $idLembaga = (int)($_POST['id_lembaga'] ?? 0);
        if ($idLembaga > 0) {
            try {
                $pdo->prepare("DELETE FROM tb_master_lembaga WHERE id_lembaga = ?")->execute([$idLembaga]);
                $pdo->prepare("DELETE FROM tb_program_buka WHERE id_lembaga = ?")->execute([$idLembaga]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menghapus lembaga pelatihan ID #{$idLembaga} beserta program terkait", 'LEMBAGA');
                }

                $pesan = 'Lembaga pelatihan berhasil dihapus.';$tipePesan = 'success';
            } catch (Throwable $e) {$pesan = 'Gagal menghapus lembaga: ' . $e->getMessage();$tipePesan = 'error';
            }
        }
    }

    if ($action === 'tambah_kejuruan') {
        $namaKejuruan = trim($_POST['nama_kejuruan'] ?? '');
        if (!empty($namaKejuruan)) {
            try {
                $stmt =$pdo->prepare("INSERT INTO tb_master_kejuruan (nama_kejuruan, status_aktif) VALUES (?, 'Y')");
                $stmt->execute([$namaKejuruan]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menambahkan jenis pelatihan/kejuruan baru: {$namaKejuruan}", 'KEJURUAN');
                }

                $pesan = "Jenis pelatihan “{$namaKejuruan}” berhasil ditambahkan.";
                $tipePesan = 'success';
            } catch (Throwable $e) {$pesan = 'Gagal menambah kejuruan: ' . $e->getMessage();$tipePesan = 'error';
            }
        }
    }

    if ($action === 'hapus_kejuruan') {
        $idKejuruan = (int)($_POST['id_kejuruan'] ?? 0);
        if ($idKejuruan > 0) {
            try {
                $pdo->prepare("DELETE FROM tb_master_kejuruan WHERE id_kejuruan = ?")->execute([$idKejuruan]);
                $pdo->prepare("DELETE FROM tb_program_buka WHERE id_kejuruan = ?")->execute([$idKejuruan]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menghapus jenis pelatihan/kejuruan ID #{$idKejuruan} beserta program terkait", 'KEJURUAN');
                }

                $pesan = 'Jenis pelatihan berhasil dihapus.';$tipePesan = 'success';
            } catch (Throwable $e) {$pesan = 'Gagal menghapus kejuruan: ' . $e->getMessage();$tipePesan = 'error';
            }
        }
    }
}

$listLembaga =$pdo->query("SELECT * FROM tb_master_lembaga ORDER BY nama_lembaga ASC")->fetchAll(PDO::FETCH_ASSOC);
$listKejuruan =$pdo->query("SELECT * FROM tb_master_kejuruan ORDER BY nama_kejuruan ASC")->fetchAll(PDO::FETCH_ASSOC);

$listProgramBuka = [];
if ($idPeriodeAktif > 0) {
    $stmtBuka =$pdo->prepare("
        SELECT pb.id_program, ml.nama_lembaga, mk.nama_kejuruan, pb.status_program
        FROM tb_program_buka pb
        JOIN tb_master_lembaga ml ON pb.id_lembaga = ml.id_lembaga
        JOIN tb_master_kejuruan mk ON pb.id_kejuruan = mk.id_kejuruan
        WHERE pb.id_periode = ?
        ORDER BY ml.nama_lembaga ASC, mk.nama_kejuruan ASC
    ");
    $stmtBuka->execute([$idPeriodeAktif]);
    $listProgramBuka =$stmtBuka->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Program & Jenis Pelatihan — BP3MI Jawa Tengah</title>
    
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
                            <i class="fas fa-graduation-cap"></i>
                        </span>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight uppercase">Kelola Program & Jenis Pelatihan</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Pengaturan lembaga dan kejuruan pelatihan yang aktif ditampilkan pada formulir pendaftaran peserta.</p>
                </div>
                
                <div class="text-right bg-slate-50 px-4 py-2.5 rounded-xl border border-slate-200 shadow-2xs">
                    <div id="liveClock" class="text-sm font-bold text-slate-900 font-mono">--:--:-- WIB</div>
                    <div id="liveDate" class="text-[9px] font-bold text-[#0e74bb] uppercase tracking-widest mt-0.5">Memuat Tanggal...</div>
                </div>
            </div>

            <?php if (!empty($pesan)): ?>
                <div class="p-4 rounded-xl text-xs font-bold border <?= $tipePesan === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?> shadow-2xs">
                    <?= htmlspecialchars($pesan) ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4">
                <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex flex-wrap justify-between items-center gap-3">
                    <div>
                        <div class="flex items-center space-x-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Program Dibuka pada: <span class="text-[#0e74bb]"><?= htmlspecialchars($namaBatchAktif) ?></span></h3>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-0.5">Hanya kombinasi di bawah ini yang dapat dipilih pendaftar di portal registrasi publik.</p>
                    </div>
                    <?php if ($idPeriodeAktif > 0): ?>
                        <button type="button" onclick="bukaModalPlotting()" class="px-3.5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold text-xs rounded-lg transition shadow-xs uppercase tracking-wider flex items-center space-x-1.5">
                            <i class="fas fa-plus text-[11px]"></i>
                            <span>+ Buka Program di Gelombang Ini</span>
                        </button>
                    <?php else: ?>
                        <span class="text-xs text-rose-500 font-bold bg-rose-50 px-3 py-1.5 rounded-lg border border-rose-200">Aktifkan Periode Terlebih Dahulu</span>
                    <?php endif; ?>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-[10px] uppercase font-bold text-slate-600 tracking-wider">
                                <th class="py-3 px-4 text-center w-16">No</th>
                                <th class="py-3 px-4">Program / Lembaga Pelatihan</th>
                                <th class="py-3 px-4">Jenis Pelatihan / Kejuruan</th>
                                <th class="py-3 px-4 text-center w-36">Status</th>
                                <th class="py-3 px-4 text-center w-28">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white text-xs">
                            <?php if (empty($listProgramBuka)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-8 text-slate-400 font-medium">Belum ada program pelatihan yang dibuka pada gelombang aktif ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listProgramBuka as $idx => $pb): ?>
                                    <tr class="hover:bg-slate-50/80 transition">
                                        <td class="py-3 px-4 text-center font-bold text-slate-400"><?= $idx + 1 ?></td>
                                        <td class="py-3 px-4 font-bold text-slate-900"><?= htmlspecialchars($pb['nama_lembaga']) ?></td>
                                        <td class="py-3 px-4 font-semibold text-[#0e74bb]"><?= htmlspecialchars($pb['nama_kejuruan']) ?></td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                DIBUKA
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <form method="POST" action="kelola_program.php" onsubmit="return confirm('Tutup program ini dari gelombang aktif?')">
                                                <input type="hidden" name="action" value="hapus_program_buka">
                                                <input type="hidden" name="id_program" value="<?= (int)$pb['id_program'] ?>">
                                                <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold px-3 py-1.5 rounded-lg text-xs transition border border-rose-200/60 shadow-2xs">
                                                    Tutup
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-12">
                
                <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4">
                    <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex justify-between items-center">
                        <div>
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Master Lembaga Pelatihan</h3>
                            <p class="text-[11px] text-slate-500">Total: <?= count($listLembaga) ?> Lembaga</p>
                        </div>
                        <button type="button" onclick="bukaModalLembaga()" class="px-3 py-1.5 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold text-xs rounded-lg transition shadow-xs uppercase tracking-wider flex items-center gap-1">
                            <i class="fas fa-plus text-[10px]"></i>
                            <span>+ Lembaga</span>
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-[10px] uppercase font-bold text-slate-600 tracking-wider">
                                    <th class="py-2.5 px-4 text-center w-12">No</th>
                                    <th class="py-2.5 px-4">Nama Lembaga</th>
                                    <th class="py-2.5 px-4 text-center w-20">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium bg-white text-xs">
                                <?php if (empty($listLembaga)): ?>
                                    <tr><td colspan="3" class="text-center py-6 text-slate-400">Belum ada master lembaga.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($listLembaga as $i => $l): ?>
                                        <tr class="hover:bg-slate-50/70 transition">
                                            <td class="py-2.5 px-4 text-center text-slate-400 font-bold"><?= $i + 1 ?></td>
                                            <td class="py-2.5 px-4 font-bold text-slate-800"><?= htmlspecialchars($l['nama_lembaga']) ?></td>
                                            <td class="py-2.5 px-4 text-center">
                                                <form method="POST" action="kelola_program.php" onsubmit="return confirm('Hapus lembaga ini? Semua program terkait akan ikut terhapus.')">
                                                    <input type="hidden" name="action" value="hapus_lembaga">
                                                    <input type="hidden" name="id_lembaga" value="<?= (int)$l['id_lembaga'] ?>">
                                                    <button type="submit" class="text-rose-600 hover:text-rose-800 font-bold text-[11px]">Hapus</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4">
                    <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex justify-between items-center">
                        <div>
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Master Jenis Pelatihan (Kejuruan)</h3>
                            <p class="text-[11px] text-slate-500">Total: <?= count($listKejuruan) ?> Kejuruan</p>
                        </div>
                        <button type="button" onclick="bukaModalKejuruan()" class="px-3 py-1.5 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold text-xs rounded-lg transition shadow-xs uppercase tracking-wider flex items-center gap-1">
                            <i class="fas fa-plus text-[10px]"></i>
                            <span>+ Kejuruan</span>
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-[10px] uppercase font-bold text-slate-600 tracking-wider">
                                    <th class="py-2.5 px-4 text-center w-12">No</th>
                                    <th class="py-2.5 px-4">Nama Kejuruan</th>
                                    <th class="py-2.5 px-4 text-center w-20">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium bg-white text-xs">
                                <?php if (empty($listKejuruan)): ?>
                                    <tr><td colspan="3" class="text-center py-6 text-slate-400">Belum ada master kejuruan.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($listKejuruan as $k => $j): ?>
                                        <tr class="hover:bg-slate-50/70 transition">
                                            <td class="py-2.5 px-4 text-center text-slate-400 font-bold"><?= $k + 1 ?></td>
                                            <td class="py-2.5 px-4 font-bold text-slate-800"><?= htmlspecialchars($j['nama_kejuruan']) ?></td>
                                            <td class="py-2.5 px-4 text-center">
                                                <form method="POST" action="kelola_program.php" onsubmit="return confirm('Hapus jenis pelatihan ini? Semua program terkait akan ikut terhapus.')">
                                                    <input type="hidden" name="action" value="hapus_kejuruan">
                                                    <input type="hidden" name="id_kejuruan" value="<?= (int)$j['id_kejuruan'] ?>">
                                                    <button type="submit" class="text-rose-600 hover:text-rose-800 font-bold text-[11px]">Hapus</button>
                                                </form>
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
    </main>

    <div id="modalPlotting" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-lg w-full p-6 sm:p-7 shadow-2xl border border-slate-200 relative overflow-hidden">
            <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
            <button type="button" onclick="tutupModal('modalPlotting')" class="absolute top-4 right-4 w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-lg flex items-center justify-center font-bold transition text-xs">
                &#10005;
            </button>
            <div class="pb-3 border-b border-slate-200 mb-5 pr-10">
                <span class="text-[10px] font-extrabold text-[#0e74bb] uppercase tracking-widest bg-blue-50 px-2.5 py-1 rounded-md border border-blue-100">Plotting Gelombang</span>
                <h3 class="font-black text-slate-900 text-lg tracking-tight mt-2">Buka Program Baru</h3>
                <p class="text-xs text-slate-500 mt-1">Gelombang: <b class="text-slate-800"><?= htmlspecialchars($namaBatchAktif) ?></b></p>
            </div>

            <form method="POST" action="kelola_program.php" class="space-y-4 text-xs">
                <input type="hidden" name="action" value="tambah_program_buka">

                <div>
                    <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Pilih Lembaga Pelatihan <span class="text-rose-500">*</span></label>
                    <select name="id_lembaga" required class="w-full h-[42px] px-3 rounded-lg border border-slate-200 bg-white font-semibold text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none">
                        <option value="">-- Pilih Lembaga --</option>
                        <?php foreach ($listLembaga as $l): ?>
                            <option value="<?= $l['id_lembaga'] ?>"><?= htmlspecialchars($l['nama_lembaga']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Pilih Jenis Pelatihan (Kejuruan) <span class="text-rose-500">*</span></label>
                    <select name="id_kejuruan" required class="w-full h-[42px] px-3 rounded-lg border border-slate-200 bg-white font-semibold text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none">
                        <option value="">-- Pilih Kejuruan --</option>
                        <?php foreach ($listKejuruan as $k): ?>
                            <option value="<?= $k['id_kejuruan'] ?>"><?= htmlspecialchars($k['nama_kejuruan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex justify-end space-x-2 pt-4 border-t border-slate-200 mt-5">
                    <button type="button" onclick="tutupModal('modalPlotting')" class="px-4 py-2 bg-slate-100 border border-slate-200 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition text-xs">Batal</button>
                    <button type="submit" class="px-5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg transition shadow-xs uppercase tracking-wider text-xs">Buka Program</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalLembaga" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-md w-full p-6 sm:p-7 shadow-2xl border border-slate-200 relative overflow-hidden">
            <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
            <button type="button" onclick="tutupModal('modalLembaga')" class="absolute top-4 right-4 w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-lg flex items-center justify-center font-bold transition text-xs">&#10005;</button>
            <div class="pb-3 border-b border-slate-200 mb-5 pr-10">
                <h3 class="font-black text-slate-900 text-lg tracking-tight">Tambah Lembaga Pelatihan</h3>
            </div>
            <form method="POST" action="kelola_program.php" class="space-y-4 text-xs">
                <input type="hidden" name="action" value="tambah_lembaga">
                <div>
                    <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Nama Lembaga <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_lembaga" required placeholder="Contoh: BLK Surakarta / LPK X" class="w-full h-[42px] px-3.5 rounded-lg border border-slate-200 bg-white font-bold text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none">
                </div>
                <div class="flex justify-end space-x-2 pt-4 border-t border-slate-200">
                    <button type="button" onclick="tutupModal('modalLembaga')" class="px-4 py-2 bg-slate-100 border border-slate-200 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition text-xs">Batal</button>
                    <button type="submit" class="px-5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg transition uppercase tracking-wider text-xs">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalKejuruan" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-md w-full p-6 sm:p-7 shadow-2xl border border-slate-200 relative overflow-hidden">
            <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
            <button type="button" onclick="tutupModal('modalKejuruan')" class="absolute top-4 right-4 w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-lg flex items-center justify-center font-bold transition text-xs">&#10005;</button>
            <div class="pb-3 border-b border-slate-200 mb-5 pr-10">
                <h3 class="font-black text-slate-900 text-lg tracking-tight">Tambah Jenis Pelatihan (Kejuruan)</h3>
            </div>
            <form method="POST" action="kelola_program.php" class="space-y-4 text-xs">
                <input type="hidden" name="action" value="tambah_kejuruan">
                <div>
                    <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Nama Jenis Pelatihan <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_kejuruan" required placeholder="Contoh: Welder / Caregiver / Tata Boga" class="w-full h-[42px] px-3.5 rounded-lg border border-slate-200 bg-white font-bold text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none">
                </div>
                <div class="flex justify-end space-x-2 pt-4 border-t border-slate-200">
                    <button type="button" onclick="tutupModal('modalKejuruan')" class="px-4 py-2 bg-slate-100 border border-slate-200 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition text-xs">Batal</button>
                    <button type="submit" class="px-5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg transition uppercase tracking-wider text-xs">Simpan</button>
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

        function bukaModalPlotting() { document.getElementById('modalPlotting').classList.remove('hidden'); }
        function bukaModalLembaga() { document.getElementById('modalLembaga').classList.remove('hidden'); }
        function bukaModalKejuruan() { document.getElementById('modalKejuruan').classList.remove('hidden'); }
        function tutupModal(id) { document.getElementById(id).classList.add('hidden'); }
    </script>
</body>
</html>