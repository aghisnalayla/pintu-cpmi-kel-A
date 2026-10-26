<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$namaAdmin   = $_SESSION['admin_nama'] ?? 'Admin';
$levelAdmin  = strtolower($_SESSION['admin_level'] ?? 'magang');

$isSuperAdmin = ($levelAdmin === 'superadmin');
$isAdmin      = ($levelAdmin === 'admin');
$isMagang     = ($levelAdmin === 'magang');
$isLembaga    = ($levelAdmin === 'lembaga');
?>

<header class="md:hidden w-full bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between sticky top-0 z-40 shadow-xs shrink-0 no-print">
    <div class="flex items-center gap-2.5 min-w-0">
        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" alt="Logo BP2MI" class="h-8 w-auto object-contain shrink-0">
        <div class="leading-tight min-w-0">
            <span class="font-extrabold text-[#0e74bb] text-xs tracking-tight uppercase block truncate">Dashboard Pusat Informasi Pendaftaran Peningkatan Kapasitas CPMI</span>
            <span class="text-[9px] font-bold text-slate-500 uppercase tracking-tight block truncate">BP3MI Jawa Tengah</span>
        </div>
    </div>
    <button type="button" onclick="toggleSidebar()" aria-label="Buka Menu Navigasi" class="p-2.5 bg-slate-50 hover:bg-[#0e74bb] hover:text-white border border-slate-200 text-slate-700 rounded-lg transition-colors shadow-xs shrink-0 flex items-center justify-center focus:outline-none">
        <i id="iconOpen" class="fas fa-bars text-sm"></i>
        <i id="iconClose" class="fas fa-times text-sm hidden"></i>
    </button>
</header>

<div id="sidebarOverlay" onclick="toggleSidebar()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 hidden md:hidden transition-opacity duration-300 opacity-0 no-print cursor-pointer"></div>

<aside id="mainSidebar" class="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] md:w-64 bg-white border-r border-slate-200 flex flex-col justify-between transform -translate-x-full md:translate-x-0 md:static transition-transform duration-300 ease-in-out shadow-2xl md:shadow-none h-screen no-print shrink-0">
    
    <div class="p-5 pb-4 border-b border-slate-200 bg-gradient-to-b from-slate-50/80 to-white relative">
        <button type="button" onclick="toggleSidebar()" aria-label="Tutup Menu" class="md:hidden absolute top-3.5 right-3.5 p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition">
            <i class="fas fa-times text-sm"></i>
        </button>

        <div class="flex flex-col items-center text-center">
            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" 
                 alt="Logo BP2MI" class="h-12 w-auto object-contain mb-2.5 drop-shadow-xs">
            <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-sky-50 border border-sky-100 mb-1.5">
                <span class="text-[9px] font-extrabold text-[#0e74bb] uppercase tracking-wider">Portal Administrator</span>
            </div>
            <h1 class="font-extrabold text-[#0e74bb] text-[11px] tracking-tight uppercase leading-snug">Pusat Informasi Pendaftaran Peningkatan Kapasitas CPMI</h1>
            <p class="text-[9px] text-slate-500 font-bold mt-1 uppercase tracking-wider">BP3MI Jawa Tengah</p>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto sidebar-scroll p-3.5 space-y-5">
        
        <div>
            <div class="flex items-center gap-2 mb-2 px-2">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest whitespace-nowrap">A. Modul Pendaftaran</span>
                <div class="h-px bg-slate-200 flex-1"></div>
            </div>
            <nav class="space-y-1">
                <?php if (!$isLembaga): ?>
                <a href="index.php" class="flex items-center space-x-2.5 px-3 py-2 <?= $currentPage === 'index.php' ? 'bg-[#0e74bb] text-white shadow-xs font-bold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] font-semibold' ?> rounded-lg text-xs transition group">
                    <span class="w-5 text-center"><i class="fas fa-gauge-high <?= $currentPage === 'index.php' ? 'text-white' : 'text-slate-400 group-hover:text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Dashboard PINTU</span>
                    <?php if ($currentPage === 'index.php'): ?><i class="fas fa-chevron-right text-[10px] text-white/70"></i><?php endif; ?>
                </a>
                
                <a href="sebaran_wilayah.php" class="flex items-center space-x-2.5 px-3 py-2 <?= $currentPage === 'sebaran_wilayah.php' ? 'bg-[#0e74bb] text-white shadow-xs font-bold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] font-semibold' ?> rounded-lg text-xs transition group">
                    <span class="w-5 text-center"><i class="fas fa-map-location-dot <?= $currentPage === 'sebaran_wilayah.php' ? 'text-white' : 'text-slate-400 group-hover:text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Sebaran Wilayah</span>
                    <?php if ($currentPage === 'sebaran_wilayah.php'): ?><i class="fas fa-chevron-right text-[10px] text-white/70"></i><?php endif; ?>
                </a>
                <?php endif; ?>
                
                <a href="verifikasi.php" class="flex items-center space-x-2.5 px-3 py-2 <?= $currentPage === 'verifikasi.php' ? 'bg-[#0e74bb] text-white shadow-xs font-bold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] font-semibold' ?> rounded-lg text-xs transition group">
                    <span class="w-5 text-center"><i class="fas fa-file-circle-check <?= $currentPage === 'verifikasi.php' ? 'text-white' : 'text-slate-400 group-hover:text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Verifikasi Dokumen</span>
                    <?php if ($currentPage === 'verifikasi.php'): ?><i class="fas fa-chevron-right text-[10px] text-white/70"></i><?php endif; ?>
                </a>

                <?php if (!$isLembaga): ?>
                <a href="cetak_bukti.php" class="flex items-center space-x-2.5 px-3 py-2 <?= $currentPage === 'cetak_bukti.php' ? 'bg-[#0e74bb] text-white shadow-xs font-bold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] font-semibold' ?> rounded-lg text-xs transition group">
                    <span class="w-5 text-center"><i class="fas fa-print <?= $currentPage === 'cetak_bukti.php' ? 'text-white' : 'text-slate-400 group-hover:text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Cetak Bukti Pendaftaran</span>
                    <?php if ($currentPage === 'cetak_bukti.php'): ?><i class="fas fa-chevron-right text-[10px] text-white/70"></i><?php endif; ?>
                </a>
                <?php endif; ?>
                
                <a href="laporan_pendaftaran.php" class="flex items-center space-x-2.5 px-3 py-2 <?= $currentPage === 'laporan_pendaftaran.php' ? 'bg-[#0e74bb] text-white shadow-xs font-bold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] font-semibold' ?> rounded-lg text-xs transition group">
                    <span class="w-5 text-center"><i class="fas fa-file-invoice <?= $currentPage === 'laporan_pendaftaran.php' ? 'text-white' : 'text-slate-400 group-hover:text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Manajemen Laporan</span>
                    <?php if ($currentPage === 'laporan_pendaftaran.php'): ?><i class="fas fa-chevron-right text-[10px] text-white/70"></i><?php endif; ?>
                </a>
            </nav>
        </div>

        <?php if (!$isLembaga): ?>
        <div>
            <div class="flex items-center gap-2 mb-2 px-2">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest whitespace-nowrap">B. Modul Ujian</span>
                <div class="h-px bg-slate-200 flex-1"></div>
            </div>
            <nav class="space-y-1">
                <a href="peserta_ujian.php" class="flex items-center space-x-2.5 px-3 py-2 <?= $currentPage === 'peserta_ujian.php' ? 'bg-[#0e74bb] text-white shadow-xs font-bold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] font-semibold' ?> rounded-lg text-xs transition group">
                    <span class="w-5 text-center"><i class="fas fa-users-gear <?= $currentPage === 'peserta_ujian.php' ? 'text-white' : 'text-slate-400 group-hover:text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Kontrol Peserta Ujian</span>
                    <?php if ($currentPage === 'peserta_ujian.php'): ?><i class="fas fa-chevron-right text-[10px] text-white/70"></i><?php endif; ?>
                </a>
                <a href="bank_soal.php" class="flex items-center space-x-2.5 px-3 py-2 <?= $currentPage === 'bank_soal.php' ? 'bg-[#0e74bb] text-white shadow-xs font-bold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] font-semibold' ?> rounded-lg text-xs transition group">
                    <span class="w-5 text-center"><i class="fas fa-clipboard-question <?= $currentPage === 'bank_soal.php' ? 'text-white' : 'text-slate-400 group-hover:text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Manajemen Bank Soal</span>
                    <?php if ($currentPage === 'bank_soal.php'): ?><i class="fas fa-chevron-right text-[10px] text-white/70"></i><?php endif; ?>
                </a>
                <a href="hasil_ujian.php" class="flex items-center space-x-2.5 px-3 py-2 <?= $currentPage === 'hasil_ujian.php' ? 'bg-[#0e74bb] text-white shadow-xs font-bold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0e74bb] font-semibold' ?> rounded-lg text-xs transition group">
                    <span class="w-5 text-center"><i class="fas fa-award <?= $currentPage === 'hasil_ujian.php' ? 'text-white' : 'text-slate-400 group-hover:text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Hasil & Nilai Peserta</span>
                    <?php if ($currentPage === 'hasil_ujian.php'): ?><i class="fas fa-chevron-right text-[10px] text-white/70"></i><?php endif; ?>
                </a>
            </nav>
        </div>
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
        <div>
            <div class="flex items-center gap-2 mb-2 px-2">
                <span class="text-[10px] font-bold text-slate-700 uppercase tracking-widest whitespace-nowrap">C. Super Admin</span>
                <div class="h-px bg-slate-300 flex-1"></div>
            </div>
            <nav class="space-y-1 bg-slate-50/80 p-2 rounded-xl border border-slate-200">
                <a href="kelola_periode.php" class="flex items-center space-x-2 px-2.5 py-2 <?= $currentPage === 'kelola_periode.php' ? 'bg-[#0e74bb] text-white font-bold' : 'text-slate-600 hover:text-[#0e74bb] hover:bg-white' ?> rounded-lg text-xs font-semibold transition group">
                    <span class="w-4 text-center"><i class="fas fa-calendar-days text-[11px] <?= $currentPage === 'kelola_periode.php' ? 'text-white' : 'text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Master Periode Gelombang</span>
                </a>
                <a href="kelola_program.php" class="flex items-center space-x-2 px-2.5 py-2 <?= $currentPage === 'kelola_program.php' ? 'bg-[#0e74bb] text-white font-bold' : 'text-slate-600 hover:text-[#0e74bb] hover:bg-white' ?> rounded-lg text-xs font-semibold transition group">
                    <span class="w-4 text-center"><i class="fas fa-graduation-cap text-[11px] <?= $currentPage === 'kelola_program.php' ? 'text-white' : 'text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Master Program & Pelatihan</span>
                </a>
                <a href="master_dokumen.php" class="flex items-center space-x-2 px-2.5 py-2 <?= $currentPage === 'master_dokumen.php' ? 'bg-[#0e74bb] text-white font-bold' : 'text-slate-600 hover:text-[#0e74bb] hover:bg-white' ?> rounded-lg text-xs font-semibold transition group">
                    <span class="w-4 text-center"><i class="fas fa-folder-closed text-[11px] <?= $currentPage === 'master_dokumen.php' ? 'text-white' : 'text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Master Dokumen Upload</span>
                </a>
                <a href="master_pemutihan.php" class="flex items-center space-x-2 px-2.5 py-2 <?= $currentPage === 'master_pemutihan.php' ? 'bg-[#0e74bb] text-white font-bold' : 'text-slate-600 hover:text-[#0e74bb] hover:bg-white' ?> rounded-lg text-xs font-semibold transition group">
                    <span class="w-4 text-center"><i class="fas fa-id-card-clip text-[11px] <?= $currentPage === 'master_pemutihan.php' ? 'text-white' : 'text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Master Reset NIK</span>
                </a>
                <a href="master_koreksi_data.php" class="flex items-center space-x-2 px-2.5 py-2 <?= $currentPage === 'master_koreksi_data.php' ? 'bg-[#0e74bb] text-white font-bold' : 'text-slate-600 hover:text-[#0e74bb] hover:bg-white' ?> rounded-lg text-xs font-semibold transition group">
                    <span class="w-4 text-center"><i class="fas fa-user-pen text-[11px] <?= $currentPage === 'master_koreksi_data.php' ? 'text-white' : 'text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Master Koreksi Data</span>
                </a>
                <a href="kelola_user.php" class="flex items-center space-x-2 px-2.5 py-2 <?= $currentPage === 'kelola_user.php' ? 'bg-[#0e74bb] text-white font-bold' : 'text-slate-600 hover:text-[#0e74bb] hover:bg-white' ?> rounded-lg text-xs font-semibold transition group">
                    <span class="w-4 text-center"><i class="fas fa-user-shield text-[11px] <?= $currentPage === 'kelola_user.php' ? 'text-white' : 'text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Kelola Pengguna Admin</span>
                </a>
                <a href="log.php" class="flex items-center space-x-2 px-2.5 py-2 <?= $currentPage === 'log.php' ? 'bg-[#0e74bb] text-white font-bold' : 'text-slate-600 hover:text-[#0e74bb] hover:bg-white' ?> rounded-lg text-xs font-semibold transition group">
                    <span class="w-4 text-center"><i class="fas fa-clock-rotate-left text-[11px] <?= $currentPage === 'log.php' ? 'text-white' : 'text-[#0e74bb]' ?>"></i></span>
                    <span class="flex-1">Audit Log Transaksi</span>
                </a>
            </nav>
        </div>
        <?php endif; ?>
    </div>

    <div class="p-3.5 border-t border-slate-200 bg-gradient-to-t from-slate-50 to-white no-print">
        <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-xs mb-2.5">
            <div class="flex items-center gap-2.5">
                <div class="relative shrink-0">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-tr from-[#0e74bb] to-sky-400 text-white flex items-center justify-center font-extrabold text-xs shadow-xs uppercase">
                        <?= htmlspecialchars(substr($namaAdmin, 0, 2)) ?>
                    </div>
                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-500 border-2 border-white"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider leading-none mb-1">Sesi Aktif</p>
                    <p class="text-xs font-bold text-slate-900 truncate leading-tight"><?= htmlspecialchars($namaAdmin) ?></p>
                    <div class="flex items-center justify-between mt-1.5">
                        <span class="text-[9px] font-extrabold text-[#0e74bb] bg-sky-50 px-2 py-0.5 rounded uppercase border border-sky-100">
                            <?= htmlspecialchars($levelAdmin) ?>
                        </span>
                        <a href="pengaturan_akun.php" title="Pengaturan Akun" class="text-slate-400 hover:text-[#0e74bb] text-xs transition">
                            <i class="fas fa-gear"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <a href="logout.php" onclick="return confirm('Keluar dari Dasbor?')" class="flex items-center justify-center space-x-1.5 w-full py-2 bg-rose-50 border border-rose-200 hover:bg-rose-100 text-rose-700 rounded-lg text-xs font-bold transition shadow-xs">
            <i class="fas fa-arrow-right-from-bracket text-xs"></i>
            <span>Keluar</span>
        </a>
    </div>
</aside>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const iconOpen = document.getElementById('iconOpen');
        const iconClose = document.getElementById('iconClose');
        
        if (!sidebar || !overlay) return;
        
        sidebar.classList.toggle('-translate-x-full');
        
        if (sidebar.classList.contains('-translate-x-full')) {
            overlay.classList.replace('opacity-100', 'opacity-0');
            setTimeout(() => overlay.classList.add('hidden'), 300);
            if (iconClose) iconClose.classList.add('hidden');
            if (iconOpen) iconOpen.classList.remove('hidden');
            document.body.classList.remove('overflow-hidden');
        } else {
            overlay.classList.remove('hidden');
            setTimeout(() => overlay.classList.replace('opacity-0', 'opacity-100'), 10);
            if (iconOpen) iconOpen.classList.add('hidden');
            if (iconClose) iconClose.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
    }
</script>