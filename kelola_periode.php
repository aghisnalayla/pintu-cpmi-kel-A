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

$namaAdmin    =$_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin   =$_SESSION['admin_level'] ?? 'user';
$isSuperAdmin = in_array(strtolower($levelAdmin), ['superadmin', 'admin']);

if (!$isSuperAdmin) {
    header('Location: index.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

$pesan = '';$tipePesan = '';

if (($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) && isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];

    if ($action === 'toggle_maintenance') {
        $targetStatus = strtolower(trim($_REQUEST['target_status'] ?? ''));
        $nilai = ($targetStatus === 'aktif') ? '1' : '0';

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS tb_pengaturan (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nama_pengaturan VARCHAR(100) NOT NULL UNIQUE,
                    nilai_pengaturan TEXT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM tb_pengaturan WHERE nama_pengaturan = 'status_maintenance'");
            $stmtCheck->execute();
            if ((int)$stmtCheck->fetchColumn() === 0) {
                $stmtIns = $pdo->prepare("INSERT INTO tb_pengaturan (nama_pengaturan, nilai_pengaturan) VALUES ('status_maintenance', ?)");
                $stmtIns->execute([$nilai]);
            } else {
                $stmtUpd = $pdo->prepare("UPDATE tb_pengaturan SET nilai_pengaturan = ? WHERE nama_pengaturan = 'status_maintenance'");
                $stmtUpd->execute([$nilai]);
            }
        } catch (Throwable $e) {
            $pesan = "Gagal memperbarui database: " . $e->getMessage();
            $tipePesan = "error";
        }

        $flagFile = __DIR__ . '/../maintenance.flag';
        if ($targetStatus === 'aktif') {
            @file_put_contents($flagFile, json_encode([
                'active' => true,
                'activated_at' => date('Y-m-d H:i:s'),
                'activated_by' => $namaAdmin
            ]));
            if (function_exists('catatLog')) {
                catatLog($pdo, "Mengaktifkan mode Under Maintenance portal pendaftaran", 'PENGATURAN');
            }
            if (empty($pesan)) {
                $pesan = "Status Maintenance berhasil DIAKTIFKAN. Portal pendaftaran publik saat ini ditutup sementara.";
                $tipePesan = "success";
            }
        } else {
            if (file_exists($flagFile)) {
                @unlink($flagFile);
            }
            if (function_exists('catatLog')) {
                catatLog($pdo, "Menonaktifkan mode Under Maintenance portal pendaftaran", 'PENGATURAN');
            }
            if (empty($pesan)) {
                $pesan = "Status Maintenance berhasil DINONAKTIFKAN. Portal pendaftaran publik kembali normal.";
                $tipePesan = "success";
            }
        }
    }

    if ($action === 'tambah_periode') {
        $namaPeriode    = trim($_POST['nama_periode'] ?? '');
        $tanggalMulai   = trim($_POST['tanggal_mulai'] ?? '');
        $tanggalSelesai = trim($_POST['tanggal_selesai'] ?? '');
        $status         = trim($_POST['status'] ?? 'Aktif');

        if (!empty($namaPeriode)) {
            try {
                if ($status === 'Aktif') {$pdo->exec("UPDATE tb_periode_gelombang SET status = 'Nonaktif'");
                }

                $stmt =$pdo->prepare("
                    INSERT INTO tb_periode_gelombang (nama_periode, tanggal_mulai, tanggal_selesai, status) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$namaPeriode,$tanggalMulai ?: null, $tanggalSelesai ?: null, $status]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menambahkan periode gelombang baru: {$namaPeriode}", 'PERIODE');
                }

                $pesan = "Periode / gelombang “{$namaPeriode}” berhasil ditambahkan dan disinkronkan.";
                $tipePesan = "success";
            } catch (Throwable $e) {$pesan = "Gagal menambah periode: " . $e->getMessage();$tipePesan = "error";
            }
        }
    }

    if ($action === 'edit_periode') {
        $idPeriode      = (int)($_POST['id_periode'] ?? 0);
        $namaPeriode    = trim($_POST['nama_periode'] ?? '');
        $tanggalMulai   = trim($_POST['tanggal_mulai'] ?? '');
        $tanggalSelesai = trim($_POST['tanggal_selesai'] ?? '');
        $status         = trim($_POST['status'] ?? 'Aktif');

        if ($idPeriode > 0 && !empty($namaPeriode)) {
            try {
                if ($status === 'Aktif') {$pdo->exec("UPDATE tb_periode_gelombang SET status = 'Nonaktif'");
                }

                $stmt =$pdo->prepare("
                    UPDATE tb_periode_gelombang 
                    SET nama_periode = ?, tanggal_mulai = ?, tanggal_selesai = ?, status = ? 
                    WHERE id_periode = ?
                ");
                $stmt->execute([$namaPeriode, $tanggalMulai ?: null, $tanggalSelesai ?: null, $status,$idPeriode]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Memperbarui data periode gelombang ID #{$idPeriode} ({$namaPeriode})", 'PERIODE');
                }

                $pesan = "Periode gelombang berhasil diperbarui.";
                $tipePesan = "success";
            } catch (Throwable $e) {$pesan = "Gagal memperbarui periode: " . $e->getMessage();$tipePesan = "error";
            }
        }
    }

    if ($action === 'hapus_periode') {
        $idPeriode = (int)($_POST['id_periode'] ?? 0);
        if ($idPeriode > 0) {
            try {
                $stmt =$pdo->prepare("DELETE FROM tb_periode_gelombang WHERE id_periode = ?");
                $stmt->execute([$idPeriode]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menghapus periode gelombang ID #{$idPeriode}", 'PERIODE');
                }

                $pesan = "Periode gelombang berhasil dihapus dari sistem.";
                $tipePesan = "success";
            } catch (Throwable $e) {$pesan = "Gagal menghapus periode: " . $e->getMessage();$tipePesan = "error";
            }
        }
    }
}

$listPeriode = [];
try {
    $stmt =$pdo->query("SELECT * FROM tb_periode_gelombang ORDER BY id_periode ASC");
    $listPeriode =$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$isMaintenanceActive = false;
if (file_exists(__DIR__ . '/../maintenance.flag')) {
    $isMaintenanceActive = true;
}
if (isset($pdo)) {
    try {
        $stmtMaint = $pdo->prepare("SELECT nilai_pengaturan FROM tb_pengaturan WHERE nama_pengaturan = 'status_maintenance'");
        $stmtMaint->execute();
        $resMaint = $stmtMaint->fetchColumn();
        if ($resMaint !== false) {
            if (trim((string)$resMaint) === '1') {
                $isMaintenanceActive = true;
            }
        } else {
            $insInit = $pdo->prepare("INSERT INTO tb_pengaturan (nama_pengaturan, nilai_pengaturan) VALUES ('status_maintenance', '0')");
            $insInit->execute();
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Periode Gelombang — BP3MI Jawa Tengah</title>
    
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
                            <i class="fas fa-calendar-alt"></i>
                        </span>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight uppercase">Kelola Periode & Gelombang Pendaftaran</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Pengaturan batch dan gelombang aktif yang terhubung langsung dengan sistem portal registrasi calon peserta.</p>
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

            <?php if ($isMaintenanceActive): ?>
                <div class="p-4 rounded-xl text-xs font-bold border bg-amber-50 text-amber-800 border-amber-300 shadow-2xs flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-triangle-exclamation text-amber-600 text-sm"></i>
                        <span>Status Maintenance sedang <b>AKTIF</b>. Pendaftar yang mengakses halaman pendaftaran (index.php) saat ini diarahkan ke tampilan Under Maintenance.</span>
                    </div>
                    <form method="POST" action="" class="shrink-0" onsubmit="return confirm('Nonaktifkan status maintenance sekarang?')">
                        <input type="hidden" name="action" value="toggle_maintenance">
                        <input type="hidden" name="target_status" value="nonaktif">
                        <button type="submit" class="px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white text-[11px] font-bold rounded-lg transition">
                            Matikan Maintenance
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4 mb-12">
                <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex flex-wrap justify-between items-center gap-3">
                    <div>
                        <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Daftar Gelombang Sinkronisasi Pendaftaran</h3>
                        <p class="text-[11px] text-slate-500 mt-0.5">Total: <b><?= count($listPeriode) ?></b> Periode</p>
                    </div>
                    <div class="flex items-center flex-wrap gap-2.5">
                        <form method="POST" action="" class="inline" onsubmit="return confirm('<?= $isMaintenanceActive ? 'Nonaktifkan status maintenance? Portal pendaftaran akan dibuka kembali untuk publik.' : 'Aktifkan status maintenance? Portal pendaftaran akan ditutup dan menampilkan halaman pemeliharaan.' ?>')">
                            <input type="hidden" name="action" value="toggle_maintenance">
                            <input type="hidden" name="target_status" value="<?= $isMaintenanceActive ? 'nonaktif' : 'aktif' ?>">
                            <button type="submit" class="px-3.5 py-2 <?= $isMaintenanceActive ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-white hover:bg-slate-50 text-slate-700 border border-slate-300' ?> font-bold text-xs rounded-lg transition shadow-xs uppercase tracking-wider flex items-center space-x-1.5" title="Klik untuk mengubah Status Maintenance">
                                <i class="fas <?= $isMaintenanceActive ? 'fa-tools' : 'fa-power-off' ?> text-[11px]"></i>
                                <span>Status Maintenance: <b><?= $isMaintenanceActive ? 'Aktif' : 'Non Aktif' ?></b></span>
                            </button>
                        </form>
                        <button type="button" onclick="bukaModalTambah()" class="px-3.5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold text-xs rounded-lg transition shadow-xs uppercase tracking-wider flex items-center space-x-1.5">
                            <i class="fas fa-plus text-[11px]"></i>
                            <span>+ Tambah Periode Baru</span>
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-[10px] uppercase font-bold text-slate-600 tracking-wider">
                                <th class="py-3 px-4 text-center w-16">ID</th>
                                <th class="py-3 px-4">Nama Periode / Gelombang</th>
                                <th class="py-3 px-4">Tanggal Mulai</th>
                                <th class="py-3 px-4">Tanggal Selesai</th>
                                <th class="py-3 px-4 text-center">Status Pendaftaran</th>
                                <th class="py-3 px-4 text-center">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white text-xs">
                            <?php if (empty($listPeriode)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-10 text-slate-400 font-medium">Belum ada data periode gelombang yang terdaftar di basis data.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listPeriode as $p): ?>
                                    <tr class="hover:bg-slate-50/80 transition">
                                        <td class="py-3 px-4 text-center font-mono font-bold text-slate-500"><?= htmlspecialchars((string)$p['id_periode']) ?></td>
                                        <td class="py-3 px-4 font-bold text-slate-900 text-sm"><?= htmlspecialchars($p['nama_periode']) ?></td>
                                        <td class="py-3 px-4 font-mono text-slate-600"><?= htmlspecialchars($p['tanggal_mulai'] ?? '-') ?></td>
                                        <td class="py-3 px-4 font-mono text-slate-600"><?= htmlspecialchars($p['tanggal_selesai'] ?? '-') ?></td>
                                        <td class="py-3 px-4 text-center">
                                            <?php if (strcasecmp($p['status'] ?? '', 'Aktif') === 0): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    AKTIF (Buka Pendaftaran)
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-600 border border-slate-200">
                                                    Nonaktif (Tutup)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <div class="inline-flex items-center space-x-1.5">
                                                <button type="button" 
                                                        onclick='bukaModalEdit(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' 
                                                        class="bg-blue-50 hover:bg-blue-100 text-[#0e74bb] font-bold px-3 py-1.5 rounded-lg text-xs transition border border-blue-200/60 shadow-2xs">
                                                    Ubah
                                                </button>
                                                <form method="POST" action="kelola_periode.php" onsubmit="return confirm('Hapus periode ini? Data pendaftar yang terikat batch ini mungkin akan terpengaruh.')" class="inline">
                                                    <input type="hidden" name="action" value="hapus_periode">
                                                    <input type="hidden" name="id_periode" value="<?= (int)$p['id_periode'] ?>">
                                                    <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold px-3 py-1.5 rounded-lg text-xs transition border border-rose-200/60 shadow-2xs">
                                                        Hapus
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
    </main>

    <div id="modalPeriode" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-lg w-full p-6 sm:p-7 shadow-2xl border border-slate-200 relative overflow-hidden">
            <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
            
            <button type="button" onclick="tutupModal()" class="absolute top-4 right-4 w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-lg flex items-center justify-center font-bold transition text-xs">
                &#10005;
            </button>

            <div class="pb-3 border-b border-slate-200 mb-5 pr-10">
                <span class="text-[10px] font-extrabold text-[#0e74bb] uppercase tracking-widest bg-blue-50 px-2.5 py-1 rounded-md border border-blue-100">Konfigurasi Batch</span>
                <h3 id="modalJudul" class="font-black text-slate-900 text-lg tracking-tight mt-2">Tambah Periode Baru</h3>
            </div>

            <form method="POST" action="kelola_periode.php" class="space-y-4 text-xs">
                <input type="hidden" name="action" id="form_action" value="tambah_periode">
                <input type="hidden" name="id_periode" id="form_id" value="">

                <div>
                    <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Nama Batch / Gelombang <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_periode" id="form_nama" required placeholder="Contoh: Gelombang 1" 
                           class="w-full h-[42px] px-3.5 rounded-lg border border-slate-200 bg-white font-bold text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                    <span class="text-[10px] text-slate-400 mt-1 block">Nama periode disinkronkan otomatis sebagai pilihan gelombang pada formulir pendaftaran.</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Tanggal Mulai</label>
                        <input type="date" name="tanggal_mulai" id="form_mulai" 
                               class="w-full h-[42px] px-3.5 rounded-lg border border-slate-200 bg-white font-medium text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                    </div>
                    <div>
                        <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Tanggal Selesai</label>
                        <input type="date" name="tanggal_selesai" id="form_selesai" 
                               class="w-full h-[42px] px-3.5 rounded-lg border border-slate-200 bg-white font-medium text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                    </div>
                </div>

                <div>
                    <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Status Pendaftaran <span class="text-rose-500">*</span></label>
                    <select name="status" id="form_status" class="w-full h-[42px] px-3 rounded-lg border border-slate-200 bg-white font-bold text-[#0e74bb] text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none cursor-pointer shadow-2xs">
                        <option value="Aktif">AKTIF (Buka Pendaftaran Publik)</option>
                        <option value="Nonaktif">Nonaktif (Tutup Pendaftaran)</option>
                    </select>
                </div>

                <div class="flex justify-end space-x-2 pt-4 border-t border-slate-200 mt-5">
                    <button type="button" onclick="tutupModal()" class="px-4 py-2 bg-slate-100 border border-slate-200 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition text-xs">
                        Batal
                    </button>
                    <button type="submit" class="px-5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg transition shadow-xs uppercase tracking-wider text-xs">
                        Simpan Gelombang
                    </button>
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

        function bukaModalTambah() {
            document.getElementById('modalJudul').textContent = 'Tambah Periode Baru';
            document.getElementById('form_action').value = 'tambah_periode';
            document.getElementById('form_id').value = '';
            document.getElementById('form_nama').value = '';
            document.getElementById('form_mulai').value = '';
            document.getElementById('form_selesai').value = '';
            document.getElementById('form_status').value = 'Aktif';

            document.getElementById('modalPeriode').classList.remove('hidden');
        }

        function bukaModalEdit(p) {
            document.getElementById('modalJudul').textContent = 'Ubah Periode (ID #' + p.id_periode + ')';
            document.getElementById('form_action').value = 'edit_periode';
            document.getElementById('form_id').value = p.id_periode;
            document.getElementById('form_nama').value = p.nama_periode || '';
            document.getElementById('form_mulai').value = p.tanggal_mulai || '';
            document.getElementById('form_selesai').value = p.tanggal_selesai || '';
            document.getElementById('form_status').value = p.status || 'Aktif';

            document.getElementById('modalPeriode').classList.remove('hidden');
        }

        function tutupModal() {
            document.getElementById('modalPeriode').classList.add('hidden');
        }
    </script>
</body>
</html>