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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action =$_POST['action'];

    if ($action === 'tambah_dokumen') {
        $namaDokumen = trim($_POST['nama_dokumen'] ?? '');
        $deskripsi   = trim($_POST['deskripsi'] ?? '');
        $isMandatory = trim($_POST['is_mandatory'] ?? 'Y');
        $urutan      = (int)($_POST['urutan'] ?? 1);
        $statusAktif = trim($_POST['status_aktif'] ?? 'Y');

        if (!empty($namaDokumen)) {
            try {
                $stmt =$pdo->prepare("
                    INSERT INTO tb_master_dokumen (nama_dokumen, deskripsi, is_mandatory, urutan, status_aktif) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$namaDokumen, $deskripsi,$isMandatory, $urutan,$statusAktif]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menambahkan dokumen persyaratan baru: {$namaDokumen}", 'DOKUMEN');
                }

                $pesan = "Dokumen persyaratan “{$namaDokumen}” berhasil ditambahkan.";
                $tipePesan = "success";
            } catch (Throwable $e) {$pesan = "Gagal menambah dokumen: " . $e->getMessage();$tipePesan = "error";
            }
        }
    }

    if ($action === 'edit_dokumen') {
        $idDokumen   = (int)($_POST['id_dokumen'] ?? 0);
        $namaDokumen = trim($_POST['nama_dokumen'] ?? '');
        $deskripsi   = trim($_POST['deskripsi'] ?? '');
        $isMandatory = trim($_POST['is_mandatory'] ?? 'Y');
        $urutan      = (int)($_POST['urutan'] ?? 1);
        $statusAktif = trim($_POST['status_aktif'] ?? 'Y');

        if ($idDokumen > 0 && !empty($namaDokumen)) {
            try {
                $stmt =$pdo->prepare("
                    UPDATE tb_master_dokumen 
                    SET nama_dokumen = ?, deskripsi = ?, is_mandatory = ?, urutan = ?, status_aktif = ? 
                    WHERE id_dokumen = ?
                ");
                $stmt->execute([$namaDokumen,$deskripsi, $isMandatory,$urutan, $statusAktif,$idDokumen]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Memperbarui dokumen persyaratan ID #{$idDokumen} ({$namaDokumen})", 'DOKUMEN');
                }

                $pesan = "Dokumen persyaratan berhasil diperbarui.";
                $tipePesan = "success";
            } catch (Throwable $e) {$pesan = "Gagal memperbarui dokumen: " . $e->getMessage();$tipePesan = "error";
            }
        }
    }

    if ($action === 'hapus_dokumen') {
        $idDokumen = (int)($_POST['id_dokumen'] ?? 0);
        if ($idDokumen > 0) {
            try {
                $stmt =$pdo->prepare("DELETE FROM tb_master_dokumen WHERE id_dokumen = ?");
                $stmt->execute([$idDokumen]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menghapus dokumen persyaratan ID #{$idDokumen}", 'DOKUMEN');
                }

                $pesan = "Dokumen persyaratan berhasil dihapus.";
                $tipePesan = "success";
            } catch (Throwable $e) {$pesan = "Gagal menghapus dokumen: " . $e->getMessage();$tipePesan = "error";
            }
        }
    }
}

$listDokumen = [];
try {
    $stmt =$pdo->query("SELECT * FROM tb_master_dokumen ORDER BY urutan ASC, id_dokumen ASC");
    $listDokumen =$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Dokumen Upload — BP3MI Jawa Tengah</title>
    
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
                            <i class="fas fa-folder-open"></i>
                        </span>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight uppercase">Master Dokumen Persyaratan Pendaftaran</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Pengaturan berkas persyaratan unggah bagi pendaftar yang otomatis tampil pada formulir publik.</p>
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

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4 mb-12">
                <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex flex-wrap justify-between items-center gap-3">
                    <div>
                        <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Daftar Dokumen Unggah Publik</h3>
                        <p class="text-[11px] text-slate-500 mt-0.5">Total: <b><?= count($listDokumen) ?></b> Dokumen Terkonfigurasi</p>
                    </div>
                    <button type="button" onclick="bukaModalTambah()" class="px-3.5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold text-xs rounded-lg transition shadow-xs uppercase tracking-wider flex items-center space-x-1.5">
                        <i class="fas fa-plus text-[11px]"></i>
                        <span>+ Tambah Dokumen Baru</span>
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-[10px] uppercase font-bold text-slate-600 tracking-wider">
                                <th class="py-3 px-4 text-center w-16">Urutan</th>
                                <th class="py-3 px-4">Nama Dokumen Syarat</th>
                                <th class="py-3 px-4 min-w-[240px]">Petunjuk / Deskripsi</th>
                                <th class="py-3 px-4 text-center w-28">Sifat</th>
                                <th class="py-3 px-4 text-center w-36">Status Form</th>
                                <th class="py-3 px-4 text-center w-28">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white text-xs">
                            <?php if (empty($listDokumen)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-10 text-slate-400 font-medium">Belum ada dokumen persyaratan yang terdaftar.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listDokumen as $d): ?>
                                    <tr class="hover:bg-slate-50/80 transition">
                                        <td class="py-3 px-4 text-center font-mono font-bold text-[#0e74bb]"><?= (int)$d['urutan'] ?></td>
                                        <td class="py-3 px-4 font-bold text-slate-900 text-sm"><?= htmlspecialchars($d['nama_dokumen']) ?></td>
                                        <td class="py-3 px-4 text-slate-500 leading-relaxed"><?= htmlspecialchars($d['deskripsi'] ?: '-') ?></td>
                                        <td class="py-3 px-4 text-center">
                                            <?php if (($d['is_mandatory'] ?? 'Y') === 'Y'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200">
                                                    WAJIB
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-600 border border-slate-200">
                                                    Opsional
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <?php if (($d['status_aktif'] ?? 'Y') === 'Y'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    AKTIF (Tampil)
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-500 border border-slate-200">
                                                    Disembunyikan
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <div class="inline-flex items-center space-x-1.5">
                                                <button type="button" 
                                                        onclick='bukaModalEdit(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' 
                                                        class="bg-blue-50 hover:bg-blue-100 text-[#0e74bb] font-bold px-3 py-1.5 rounded-lg text-xs transition border border-blue-200/60 shadow-2xs">
                                                    Ubah
                                                </button>
                                                <form method="POST" action="master_dokumen.php" onsubmit="return confirm('Hapus dokumen persyaratan ini?')" class="inline">
                                                    <input type="hidden" name="action" value="hapus_dokumen">
                                                    <input type="hidden" name="id_dokumen" value="<?= (int)$d['id_dokumen'] ?>">
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

    <div id="modalDokumen" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl max-w-lg w-full p-6 sm:p-7 shadow-2xl border border-slate-200 relative overflow-hidden">
            <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
            
            <button type="button" onclick="tutupModal()" class="absolute top-4 right-4 w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-lg flex items-center justify-center font-bold transition text-xs">
                &#10005;
            </button>

            <div class="pb-3 border-b border-slate-200 mb-5 pr-10">
                <span class="text-[10px] font-extrabold text-[#0e74bb] uppercase tracking-widest bg-blue-50 px-2.5 py-1 rounded-md border border-blue-100">Konfigurasi Berkas</span>
                <h3 id="modalJudul" class="font-black text-slate-900 text-lg tracking-tight mt-2">Tambah Dokumen Baru</h3>
            </div>

            <form method="POST" action="master_dokumen.php" class="space-y-4 text-xs">
                <input type="hidden" name="action" id="form_action" value="tambah_dokumen">
                <input type="hidden" name="id_dokumen" id="form_id" value="">

                <div>
                    <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Nama Dokumen Persyaratan <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_dokumen" id="form_nama" required placeholder="Contoh: Scan KTP Asli" 
                           class="w-full h-[42px] px-3.5 rounded-lg border border-slate-200 bg-white font-bold text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                </div>

                <div>
                    <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Petunjuk / Deskripsi Unggah</label>
                    <textarea name="deskripsi" id="form_deskripsi" rows="2" placeholder="Contoh: Format PDF/JPG, ukuran maksimal 3 MB dan terbaca jelas" 
                              class="w-full p-3 rounded-lg border border-slate-200 bg-white font-medium text-slate-700 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Urutan Tampil</label>
                        <input type="number" name="urutan" id="form_urutan" min="1" value="1" required
                               class="w-full h-[42px] px-3 rounded-lg border border-slate-200 bg-white font-mono font-bold text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none shadow-2xs">
                    </div>
                    <div>
                        <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Sifat Berkas</label>
                        <select name="is_mandatory" id="form_mandatory" class="w-full h-[42px] px-2.5 rounded-lg border border-slate-200 bg-white font-bold text-slate-800 text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none cursor-pointer shadow-2xs">
                            <option value="Y">Wajib</option>
                            <option value="N">Opsional</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Status Tampil</label>
                        <select name="status_aktif" id="form_status" class="w-full h-[42px] px-2.5 rounded-lg border border-slate-200 bg-white font-bold text-[#0e74bb] text-xs focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none cursor-pointer shadow-2xs">
                            <option value="Y">Aktif</option>
                            <option value="N">Nonaktif</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end space-x-2 pt-4 border-t border-slate-200 mt-5">
                    <button type="button" onclick="tutupModal()" class="px-4 py-2 bg-slate-100 border border-slate-200 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition text-xs">
                        Batal
                    </button>
                    <button type="submit" class="px-5 py-2 bg-[#0e74bb] hover:bg-[#0b5e99] text-white font-bold rounded-lg transition shadow-xs uppercase tracking-wider text-xs">
                        Simpan Dokumen
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
            document.getElementById('modalJudul').textContent = 'Tambah Dokumen Baru';
            document.getElementById('form_action').value = 'tambah_dokumen';
            document.getElementById('form_id').value = '';
            document.getElementById('form_nama').value = '';
            document.getElementById('form_deskripsi').value = '';
            document.getElementById('form_urutan').value = 1;
            document.getElementById('form_mandatory').value = 'Y';
            document.getElementById('form_status').value = 'Y';

            document.getElementById('modalDokumen').classList.remove('hidden');
        }

        function bukaModalEdit(d) {
            document.getElementById('modalJudul').textContent = 'Ubah Dokumen (ID #' + d.id_dokumen + ')';
            document.getElementById('form_action').value = 'edit_dokumen';
            document.getElementById('form_id').value = d.id_dokumen;
            document.getElementById('form_nama').value = d.nama_dokumen || '';
            document.getElementById('form_deskripsi').value = d.deskripsi || '';
            document.getElementById('form_urutan').value = d.urutan || 1;
            document.getElementById('form_mandatory').value = d.is_mandatory || 'Y';
            document.getElementById('form_status').value = d.status_aktif || 'Y';

            document.getElementById('modalDokumen').classList.remove('hidden');
        }

        function tutupModal() {
            document.getElementById('modalDokumen').classList.add('hidden');
        }
    </script>
</body>
</html>