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
$isSuperAdmin = (strtolower($levelAdmin) === 'superadmin');

if (!$isSuperAdmin) {
    header('Location: index.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' AND isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action =$_POST['action'];

    try {
        if ($action === 'simpan_user') {$id       = trim($_POST['id'] ?? '');$nama     = trim($_POST['nama'] ?? '');$username = trim($_POST['username'] ?? '');$password = trim($_POST['password'] ?? '');$level    = trim($_POST['level'] ?? 'admin');$lembaga  = ($level === 'lembaga') ? trim($_POST['lembaga_pelatihan'] ?? '') : NULL;

            if (empty($nama) OR empty($username)) {
                throw new Exception("Nama dan Username wajib diisi.");
            }
            if ($level === 'lembaga' AND empty($lembaga)) {
                throw new Exception("Nama Lembaga wajib diisi untuk Admin Lembaga.");
            }

            if (empty($id)) {
                if (empty($password)) throw new Exception("Password wajib diisi untuk pengguna baru.");
                
                $cek =$pdo->prepare("SELECT COUNT(*) FROM tb_admin WHERE username = ?");
                $cek->execute([$username]);
                if ($cek->fetchColumn() > 0) throw new Exception("Username sudah digunakan.");

                $hashPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt =$pdo->prepare("INSERT INTO tb_admin (nama, username, password, level, lembaga_pelatihan) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nama, $username,$hashPassword, $level,$lembaga]);
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Menambahkan akun pengguna admin baru: @{$username} (Level: {$level})", 'ADMIN');
                }

                echo json_encode(['status' => 'success', 'pesan' => 'Pengguna berhasil ditambahkan.']);
            } else {
                $cek =$pdo->prepare("SELECT COUNT(*) FROM tb_admin WHERE username = ? AND id != ?");
                $cek->execute([$username,$id]);
                if ($cek->fetchColumn() > 0) throw new Exception("Username sudah digunakan oleh akun lain.");

                if (!empty($password)) {
                    $hashPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt =$pdo->prepare("UPDATE tb_admin SET nama = ?, username = ?, password = ?, level = ?, lembaga_pelatihan = ? WHERE id = ?");
                    $stmt->execute([$nama,$username, $hashPassword,$level, $lembaga,$id]);
                } else {
                    $stmt =$pdo->prepare("UPDATE tb_admin SET nama = ?, username = ?, level = ?, lembaga_pelatihan = ? WHERE id = ?");
                    $stmt->execute([$nama, $username,$level, $lembaga,$id]);
                }

                if (function_exists('catatLog')) {
                    catatLog($pdo, "Memperbarui data akun pengguna admin ID #{$id} (@{$username})", 'ADMIN');
                }

                echo json_encode(['status' => 'success', 'pesan' => 'Data pengguna berhasil diperbarui.']);
            }
            exit;
        }

        if ($action === 'hapus_user') {
            $id = trim($_POST['id'] ?? '');
            
            if ($id ==$_SESSION['admin_id']) {
                throw new Exception("Anda tidak bisa menghapus akun Anda sendiri saat sedang login.");
            }

            $stmt =$pdo->prepare("DELETE FROM tb_admin WHERE id = ?");
            $stmt->execute([$id]);

            if (function_exists('catatLog')) {
                catatLog($pdo, "Menghapus akun pengguna admin ID #{$id}", 'ADMIN');
            }

            echo json_encode(['status' => 'success', 'pesan' => 'Akun pengguna berhasil dihapus.']);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'pesan' => $e->getMessage()]);
        exit;
    }
}

$listUsers = [];
try {
    $stmt =$pdo->query("SELECT id, nama, username, level, lembaga_pelatihan FROM tb_admin ORDER BY id DESC");
    $listUsers =$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengguna Admin — BP3MI Jawa Tengah</title>
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
                            <i class="fas fa-users-cog"></i>
                        </span>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight uppercase">Manajemen Admin</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Kelola akun dan hak akses pengguna sistem.</p>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full lg:w-auto">
                    <button onclick="bukaModalForm()" class="bg-[#0e74bb] hover:bg-[#0b5e99] text-white px-4 py-2.5 rounded-lg text-xs font-bold transition shadow-xs uppercase tracking-wider flex items-center justify-center gap-1.5">
                        <i class="fas fa-user-plus text-xs"></i>
                        Tambah Akun Baru
                    </button>
                    <div class="text-right bg-slate-50 px-4 py-2 rounded-xl border border-slate-200 shadow-2xs">
                        <div id="liveClock" class="text-sm font-bold text-slate-900 font-mono">--:--:-- WIB</div>
                        <div id="liveDate" class="text-[9px] font-bold text-[#0e74bb] uppercase tracking-widest mt-0.5">Memuat Tanggal...</div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden flex flex-col pb-4 mb-12">
                <div class="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/70 flex justify-between items-center">
                    <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Daftar Pengguna Sistem (<?= count($listUsers) ?> Akun)</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-[10px] uppercase font-bold text-slate-600 tracking-wider">
                                <th class="py-3 px-4">ID</th>
                                <th class="py-3 px-4">Nama Lengkap</th>
                                <th class="py-3 px-4">Username</th>
                                <th class="py-3 px-4">Hak Akses (Level)</th>
                                <th class="py-3 px-4 text-center">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium bg-white">
                            <?php if (empty($listUsers)): ?>
                                <tr><td colspan="5" class="text-center py-10 text-slate-400 font-medium">Belum ada data pengguna.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listUsers as $u): ?>
                                    <tr class="hover:bg-slate-50/80 transition">
                                        <td class="py-3 px-4 font-mono font-bold text-slate-400">#<?= htmlspecialchars($u['id']) ?></td>
                                        <td class="py-3 px-4 font-bold text-slate-900"><?= htmlspecialchars($u['nama']) ?></td>
                                        <td class="py-3 px-4 font-mono text-[#0e74bb] font-bold">@<?= htmlspecialchars($u['username']) ?></td>
                                        <td class="py-3 px-4">
                                            <?php if (strtolower($u['level']) === 'superadmin'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-purple-50 text-purple-700 border border-purple-200 uppercase">Super Admin</span>
                                            <?php elseif (strtolower($u['level']) === 'magang'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-600 border border-slate-200 uppercase">Magang</span>
                                            <?php elseif (strtolower($u['level']) === 'lembaga'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-orange-50 text-orange-700 border border-orange-200 uppercase">Admin Lembaga</span>
                                                <div class="text-[9px] font-bold text-slate-500 mt-0.5 uppercase tracking-widest"><?= htmlspecialchars($u['lembaga_pelatihan'] ?? '-') ?></div>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-blue-50 text-[#0e74bb] border border-blue-200 uppercase">Admin</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-center space-x-1.5">
                                            <button onclick="bukaModalForm('<?= $u['id'] ?>', '<?= htmlspecialchars(addslashes($u['nama'])) ?>', '<?= htmlspecialchars(addslashes($u['username'])) ?>', '<?= htmlspecialchars($u['level']) ?>', '<?= htmlspecialchars(addslashes($u['lembaga_pelatihan'] ?? '')) ?>')" class="inline-flex items-center px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg font-bold text-[11px] transition shadow-2xs border border-amber-200/60">
                                                Edit
                                            </button>
                                            
                                            <?php if ($u['id'] != $_SESSION['admin_id']): ?>
                                            <button onclick="hapusUser('<?= $u['id'] ?>', '<?= htmlspecialchars(addslashes($u['nama'])) ?>')" class="inline-flex items-center px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-lg font-bold text-[11px] transition shadow-2xs border border-rose-200/60">
                                                Hapus
                                            </button>
                                            <?php endif; ?>
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

    <div id="modalFormUser" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white w-full max-w-md overflow-hidden rounded-xl border border-slate-200 shadow-2xl relative transform scale-95 opacity-0 transition-all duration-300" id="modalContent">
            <div class="h-1 w-full bg-gradient-to-r from-[#0e74bb] via-[#1b88d4] to-[#074b7a] absolute top-0 left-0"></div>
            
            <div class="p-5 border-b border-slate-200 bg-slate-50/70 flex justify-between items-center">
                <h3 id="modalTitle" class="font-black text-slate-800 text-sm uppercase tracking-wider">Tambah Pengguna</h3>
                <button type="button" onclick="tutupModalForm()" class="w-8 h-8 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-lg flex items-center justify-center font-bold transition text-xs">&#10005;</button>
            </div>

            <form id="formUser" class="p-6 space-y-4">
                <input type="hidden" name="action" value="simpan_user">
                <input type="hidden" name="id" id="inputId" value="">

                <div>
                    <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Nama Lengkap</label>
                    <input type="text" name="nama" id="inputNama" required placeholder="Cth: Budi Santoso" class="w-full h-[40px] text-xs font-semibold bg-white border border-slate-200 rounded-lg px-3 focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none transition shadow-2xs">
                </div>

                <div>
                    <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Username Login</label>
                    <input type="text" name="username" id="inputUsername" required placeholder="Cth: budi_admin" class="w-full h-[40px] text-xs font-semibold bg-white border border-slate-200 rounded-lg px-3 focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none transition font-mono shadow-2xs">
                </div>

                <div>
                    <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Password</label>
                    <input type="password" name="password" id="inputPassword" placeholder="Biarkan kosong jika tidak ingin diubah" class="w-full h-[40px] text-xs font-semibold bg-white border border-slate-200 rounded-lg px-3 focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none transition font-mono shadow-2xs">
                    <p id="hintPassword" class="text-[9px] font-bold text-amber-600 mt-1 hidden">* Kosongkan kolom ini jika Anda tidak ingin mengganti sandi pengguna ini.</p>
                </div>

                <div>
                    <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">Level Akses</label>
                    <select name="level" id="inputLevel" onchange="toggleLembagaInput()" class="w-full h-[40px] text-xs font-semibold bg-white border border-slate-200 rounded-lg px-3 focus:ring-2 focus:ring-[#0e74bb] focus:border-[#0e74bb] focus:outline-none transition cursor-pointer shadow-2xs">
                        <option value="superadmin">Super Admin (Akses Penuh)</option>
                        <option value="admin">Admin Standar (Operasional)</option>
                        <option value="magang">Magang (Hanya Baca)</option>
                        <option value="lembaga">Admin Lembaga Pelatihan</option>
                    </select>
                </div>

                <div id="wrapLembaga" class="hidden">
                    <label class="block text-[10px] font-extrabold text-orange-600 uppercase tracking-wider mb-1.5">Nama Lembaga Pelatihan</label>
                    <input type="text" name="lembaga_pelatihan" id="inputLembaga" placeholder="Cth: LPK Putra Jaya Mandiri" class="w-full h-[40px] text-xs font-semibold bg-orange-50 border border-orange-200 rounded-lg px-3 focus:ring-2 focus:ring-orange-500 focus:outline-none transition shadow-2xs">
                    <p class="text-[9px] font-bold text-slate-400 mt-1">* Pastikan penulisan sama persis dengan yang ada di pendaftaran.</p>
                </div>

                <div class="pt-3 flex gap-2">
                    <button type="button" onclick="tutupModalForm()" class="flex-1 px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-lg transition uppercase tracking-wider">Batal</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-[#0e74bb] hover:bg-[#0b5e99] text-white text-xs font-bold rounded-lg transition shadow-xs uppercase tracking-wider">Simpan Data</button>
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
            
            const clockEl = document.getElementById('liveClock');
            const dateEl = document.getElementById('liveDate');
            
            if (clockEl) clockEl.textContent = `${hh}:${mm}:${ss} WIB`;
            if (dateEl) dateEl.textContent = `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
        }
        setInterval(updateClock, 1000); updateClock();

        const modal = document.getElementById('modalFormUser');
        const modalContent = document.getElementById('modalContent');
        const formUser = document.getElementById('formUser');

        function toggleLembagaInput() {
            const level = document.getElementById('inputLevel').value;
            const wrap = document.getElementById('wrapLembaga');
            
            if (level === 'lembaga') {
                wrap.classList.remove('hidden');
                document.getElementById('inputLembaga').required = true;
            } else {
                wrap.classList.add('hidden');
                document.getElementById('inputLembaga').required = false;
                document.getElementById('inputLembaga').value = '';
            }
        }

        function bukaModalForm(id = '', nama = '', username = '', level = 'admin', lembaga = '') {
            document.getElementById('inputId').value = id;
            document.getElementById('inputNama').value = nama;
            document.getElementById('inputUsername').value = username;
            document.getElementById('inputLevel').value = level.toLowerCase();
            document.getElementById('inputLembaga').value = lembaga;
            document.getElementById('inputPassword').value = ''; 

            const isEdit = id !== '';
            document.getElementById('modalTitle').textContent = isEdit ? 'Edit Pengguna' : 'Tambah Pengguna Baru';
            document.getElementById('inputPassword').required = !isEdit; 
            
            if (isEdit) {
                document.getElementById('hintPassword').classList.remove('hidden');
                document.getElementById('inputPassword').placeholder = "Ketik sandi baru untuk mereset";
            } else {
                document.getElementById('hintPassword').classList.add('hidden');
                document.getElementById('inputPassword').placeholder = "Wajib buat sandi awal...";
            }

            toggleLembagaInput(); 

            modal.classList.remove('hidden');
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function tutupModalForm() {
            modalContent.classList.remove('scale-100', 'opacity-100');
            modalContent.classList.add('scale-95', 'opacity-0');
            setTimeout(() => { modal.classList.add('hidden'); }, 300);
        }

        formUser.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            try {
                const res = await fetch('kelola_user.php', { method: 'POST', body: formData });
                const data = await res.json();
                
                if (data.status === 'success') {
                    alert(data.pesan);
                    window.location.reload();
                } else {
                    alert('GAGAL: ' + data.pesan);
                }
            } catch (err) {
                alert('Terjadi kesalahan jaringan atau server.');
            }
        });

        async function hapusUser(id, nama) {
            if (confirm(`PERINGATAN!\n\nApakah Anda yakin ingin menghapus akun admin "${nama}" secara permanen?`)) {
                const formData = new FormData();
                formData.append('action', 'hapus_user');
                formData.append('id', id);

                try {
                    const res = await fetch('kelola_user.php', { method: 'POST', body: formData });
                    const data = await res.json();
                    
                    if (data.status === 'success') {
                        alert(data.pesan);
                        window.location.reload();
                    } else {
                        alert('GAGAL: ' + data.pesan);
                    }
                } catch (err) {
                    alert('Terjadi kesalahan jaringan atau server.');
                }
            }
        }
    </script>
</body>
</html>