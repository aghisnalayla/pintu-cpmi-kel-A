<?php
session_start();

if (!isset($_SESSION['cpmi_id'])) {
    header("Location: index.html");
    exit;
}

require_once __DIR__ . '/api/koneksi.php';
$cpmi_id = $_SESSION['cpmi_id'];

$jenisUjian = strtolower(trim($_GET['jenis'] ?? ''));
if ($jenisUjian !== 'tertulis' && $jenisUjian !== 'interview') {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'>
            <h3>Jenis ujian tidak valid.</h3>
            <a href='dashboard.php' style='color:#4f46e5; font-weight:bold;'>Kembali ke Dashboard</a>
         </div>");
}

$stmtVerif = $pdo->prepare("SELECT status FROM tb_status_verifikasi WHERE cpmi_id = ? LIMIT 1");
$stmtVerif->execute([$cpmi_id]);
$statusVerif = $stmtVerif->fetchColumn();

if (strtoupper(trim((string)$statusVerif)) !== 'MEMENUHI PERSYARATAN') {
    session_unset();
    session_destroy();
    header("Location: index.html");
    exit;
}

$stmtPeserta = $pdo->prepare("SELECT * FROM tb_pendaftaran WHERE cpmi_id = ? LIMIT 1");
$stmtPeserta->execute([$cpmi_id]);
$peserta = $stmtPeserta->fetch(PDO::FETCH_ASSOC);

if (!$peserta) {
    session_unset();
    session_destroy();
    header("Location: index.html");
    exit;
}

$jenisUjianDB = strtoupper($jenisUjian);
$stmtPU = $pdo->prepare("SELECT * FROM tb_peserta_ujian WHERE cpmi_id = ? AND jenis_ujian = ? LIMIT 1");
$stmtPU->execute([$cpmi_id, $jenisUjianDB]);
$pesertaUjian = $stmtPU->fetch(PDO::FETCH_ASSOC);

$aksesDiizinkan = false;
$statusPengerjaan = 'BELUM';

if ($pesertaUjian) {
    $aksesDiizinkan = true;
    $statusPengerjaan = strtoupper($pesertaUjian['status_pengerjaan'] ?? 'BELUM');
} else {
    if ($jenisUjian === 'tertulis' && (int)($peserta['akses_tertulis'] ?? 0) === 1) {
        $aksesDiizinkan = true;
        $statusPengerjaan = strtoupper($peserta['status_tertulis'] ?? 'BELUM');
    } elseif ($jenisUjian === 'interview' && (int)($peserta['akses_interview'] ?? 0) === 1) {
        $aksesDiizinkan = true;
        $statusPengerjaan = strtoupper($peserta['status_interview'] ?? 'BELUM');
    }
}

if (!$aksesDiizinkan) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'>
            <h3>Akses Ujian " . ucfirst($jenisUjian) . " belum dibuka untuk Anda.</h3>
            <a href='dashboard.php' style='color:#4f46e5; font-weight:bold;'>Kembali ke Dashboard</a>
         </div>");
}

if ($statusPengerjaan === 'SELESAI') {
    header("Location: selesai.php?jenis=" . urlencode($jenisUjian));
    exit;
}

$sessionKeyWaktuMulai = 'waktu_mulai_' . $jenisUjian . '_' . $cpmi_id;

if ($statusPengerjaan === 'BELUM') {
    $_SESSION[$sessionKeyWaktuMulai] = time();
    
    $pdo->prepare("
        INSERT INTO tb_peserta_ujian (cpmi_id, jenis_ujian, status_pengerjaan)
        VALUES (?, ?, 'SEDANG')
        ON DUPLICATE KEY UPDATE status_pengerjaan = 'SEDANG'
    ")->execute([$cpmi_id, $jenisUjianDB]);

    if ($jenisUjian === 'tertulis') {
        $pdo->prepare("UPDATE tb_pendaftaran SET status_tertulis = 'sedang', status_ujian = 'sedang' WHERE cpmi_id = ?")
            ->execute([$cpmi_id]);
    } else {
        $pdo->prepare("UPDATE tb_pendaftaran SET status_interview = 'sedang', status_ujian = 'sedang' WHERE cpmi_id = ?")
            ->execute([$cpmi_id]);
    }
}

$namaPengaturanKey = 'durasi_' . $jenisUjian;
$menitUjian = 60;
try {
    $stmtTimer = $pdo->prepare("SELECT nilai_pengaturan FROM tb_pengaturan WHERE nama_pengaturan = ?");
    $stmtTimer->execute([$namaPengaturanKey]);
    $resTimer = $stmtTimer->fetchColumn();
    if ($resTimer !== false) {
        $menitUjian = (int)$resTimer;
    }
} catch (Throwable $e) {}

$detikUjianTotal = $menitUjian * 60;

if (!isset($_SESSION[$sessionKeyWaktuMulai])) {
    $_SESSION[$sessionKeyWaktuMulai] = time();
}

$waktuBerjalan = time() - $_SESSION[$sessionKeyWaktuMulai];
$detikUjianSisa = $detikUjianTotal - $waktuBerjalan;

if ($detikUjianSisa <= 0) {
    header("Location: selesai.php?jenis=" . urlencode($jenisUjian));
    exit;
}

if ($jenisUjian === 'tertulis') {
    $stmtSoal = $pdo->query("SELECT * FROM tb_soal WHERE (LOWER(jenis_ujian) = 'tertulis' OR jenis_ujian IS NULL OR jenis_ujian = '') AND (status_aktif = 'Y' OR status_aktif IS NULL) ORDER BY id ASC");
} else {
    $stmtSoal = $pdo->query("SELECT * FROM tb_soal WHERE LOWER(jenis_ujian) = 'interview' AND (status_aktif = 'Y' OR status_aktif IS NULL) ORDER BY id ASC");
}
$daftarSoal = $stmtSoal->fetchAll(PDO::FETCH_ASSOC);

if (empty($daftarSoal)) {
    die("
    <!DOCTYPE html>
    <html lang='id'>
    <head><meta charset='UTF-8'><script src='https://cdn.tailwindcss.com'></script></head>
    <body class='bg-slate-100 flex items-center justify-center min-h-screen p-4'>
        <div class='bg-white p-8 rounded-3xl shadow-xl max-w-md w-full text-center'>
            <div class='w-14 h-14 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center mx-auto mb-4 font-bold text-2xl'>!</div>
            <h2 class='font-extrabold text-slate-800 text-lg mb-2'>Bank Soal Belum Tersedia</h2>
            <p class='text-slate-500 text-xs mb-6'>Belum ada soal aktif untuk <b>Ujian " . ucfirst($jenisUjian) . "</b>.</p>
            <a href='dashboard.php' class='block w-full py-3 bg-indigo-600 text-white font-bold rounded-xl text-xs'>Kembali ke Dashboard</a>
        </div>
    </body>
    </html>");
}

$jawabanTersimpan = [];
try {
    $stmtJwb = $pdo->prepare("SELECT soal_id, jawaban_pilihan FROM tb_jawaban_peserta WHERE cpmi_id = ?");
    $stmtJwb->execute([$cpmi_id]);
    while ($r = $stmtJwb->fetch(PDO::FETCH_ASSOC)) {
        $jawabanTersimpan[$r['soal_id']] = strtoupper(trim($r['jawaban_pilihan']));
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lembar Ujian <?php echo ucfirst($jenisUjian); ?> | BP3MI Jateng</title>
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col justify-between selection:bg-indigo-500 selection:text-white select-none">

    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
        <div class="max-w-6xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" 
                     alt="Logo" class="h-9 w-auto">
                <div>
                    <h1 class="font-extrabold text-slate-900 text-sm">Ujian <?php echo ucfirst($jenisUjian); ?></h1>
                    <span class="block text-[11px] text-slate-500"><?php echo htmlspecialchars($peserta['nama_lengkap']); ?> (<?php echo htmlspecialchars($cpmi_id); ?>)</span>
                </div>
            </div>

            <div class="flex items-center space-x-3">
                <div class="bg-slate-900 text-white px-3 py-1.5 rounded-xl shadow-xs font-mono text-xs font-bold flex items-center space-x-2">
                    <span class="text-slate-400">Sisa Waktu:</span>
                    <span id="tampilanTimer" class="text-amber-400 text-sm">--:--</span>
                </div>

                <span id="indikatorSimpan" class="text-[11px] font-semibold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200 hidden">
                    Tersimpan Otomatis
                </span>
                <a href="dashboard.php" onclick="return confirm('Kembali ke dashboard? Seluruh jawaban Anda tetap tersimpan.')" 
                   class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold px-3 py-1.5 rounded-xl text-xs transition hidden sm:inline-block">
                    Dashboard
                </a>
            </div>
        </div>
    </header>

    <form id="formUjian" method="POST" action="selesai.php?jenis=<?php echo urlencode($jenisUjian); ?>">
        <main class="max-w-6xl mx-auto px-4 py-6 w-full flex-grow">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                
                <div class="lg:col-span-3">
                    <?php foreach ($daftarSoal as $idx => $s): ?>
                    <?php 
                        $sid = $s['id'];
                        $terjawab = $jawabanTersimpan[$sid] ?? '';
                    ?>
                    <div id="kotakSoal_<?php echo $idx; ?>" class="kotak-pertanyaan bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xs <?php echo $idx === 0 ? '' : 'hidden'; ?>">
                        
                        <div class="flex justify-between items-center pb-4 border-b border-slate-100 mb-6">
                            <span class="font-extrabold text-indigo-600 text-sm uppercase tracking-wider">
                                Soal <?php echo $idx + 1; ?> dari <?php echo count($daftarSoal); ?>
                            </span>
                            <span class="text-[11px] uppercase font-bold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-md">
                                Modul <?php echo htmlspecialchars($jenisUjian); ?>
                            </span>
                        </div>

                        <p class="font-bold text-slate-900 text-base sm:text-lg mb-8 leading-relaxed">
                            <?php echo nl2br(htmlspecialchars($s['pertanyaan'])); ?>
                        </p>

                        <div class="space-y-3">
                            <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                            <?php $isiOpsi = $s['opsi_' . strtolower($opt)]; ?>
                            <label class="opsi-item flex items-center p-4 rounded-2xl border-2 cursor-pointer transition <?php echo $terjawab === $opt ? 'border-indigo-600 bg-indigo-50/50' : 'border-slate-100 hover:border-slate-200 bg-slate-50/50'; ?>">
                                <input type="radio" 
                                       name="jawaban[<?php echo $sid; ?>]" 
                                       value="<?php echo $opt; ?>" 
                                       <?php echo $terjawab === $opt ? 'checked' : ''; ?>
                                       onchange="simpanJawaban(<?php echo $sid; ?>, '<?php echo $opt; ?>', <?php echo $idx; ?>)"
                                       class="w-4 h-4 text-indigo-600 border-slate-300 focus:ring-indigo-500">
                                <span class="font-bold text-slate-700 ml-3 mr-2"><?php echo $opt; ?>.</span>
                                <span class="text-sm font-medium text-slate-800"><?php echo htmlspecialchars($isiOpsi); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex justify-between items-center pt-8 border-t border-slate-100 mt-8">
                            <button type="button" 
                                    onclick="pindahSoal(<?php echo $idx - 1; ?>)" 
                                    <?php echo $idx === 0 ? 'disabled' : ''; ?>
                                    class="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50 disabled:opacity-30 disabled:cursor-not-allowed transition">
                                &larr; Sebelumnya
                            </button>

                            <?php if ($idx < count($daftarSoal) - 1): ?>
                            <button type="button" 
                                    onclick="pindahSoal(<?php echo $idx + 1; ?>)" 
                                    class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md shadow-indigo-500/20 transition">
                                Selanjutnya &rarr;
                            </button>
                            <?php else: ?>
                            <button type="button" 
                                    onclick="konfirmasiSelesai()" 
                                    class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md shadow-emerald-500/20 transition">
                                Kumpulkan Ujian &radic;
                            </button>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="lg:col-span-1">
                    <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs sticky top-20">
                        <h3 class="font-extrabold text-slate-900 text-xs uppercase tracking-wider mb-4">Nomor Soal</h3>
                        
                        <div class="grid grid-cols-5 gap-2 max-h-[50vh] overflow-y-auto pr-1">
                            <?php foreach ($daftarSoal as $idx => $s): ?>
                            <?php 
                                $sid = $s['id'];
                                $sudahIsi = isset($jawabanTersimpan[$sid]) && $jawabanTersimpan[$sid] !== '';
                            ?>
                            <button type="button" 
                                    id="btnNav_<?php echo $idx; ?>"
                                    onclick="pindahSoal(<?php echo $idx; ?>)"
                                    class="h-10 rounded-xl font-bold text-xs transition border <?php echo $sudahIsi ? 'bg-emerald-50 border-emerald-300 text-emerald-700' : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'; ?>">
                                <?php echo $idx + 1; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="pt-6 border-t border-slate-100 mt-6 space-y-2">
                            <div class="flex items-center space-x-2 text-[11px] text-slate-500 font-medium">
                                <span class="w-3 h-3 rounded-md bg-emerald-100 border border-emerald-300"></span>
                                <span>Sudah Dijawab</span>
                            </div>
                            <div class="flex items-center space-x-2 text-[11px] text-slate-500 font-medium">
                                <span class="w-3 h-3 rounded-md bg-slate-50 border border-slate-200"></span>
                                <span>Belum Dijawab</span>
                            </div>
                        </div>

                        <div class="pt-5 mt-4">
                            <button type="button" onclick="konfirmasiSelesai()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-xs shadow-md shadow-emerald-500/20 transition">
                                Selesaikan Ujian
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </form>

    <footer class="text-center py-6 text-xs text-slate-400 bg-white border-t border-slate-200">
        &copy; <?php echo date('Y'); ?> BP3MI Jawa Tengah. All rights reserved.
    </footer>

    <script>
        const totalSoal = <?php echo count($daftarSoal); ?>;
        const cpmiId = "<?php echo $cpmi_id; ?>";
        const jenisUjian = "<?php echo $jenisUjian; ?>";

        let sisaWaktu = <?php echo $detikUjianSisa; ?>;
        let formSudahDisubmit = false;

        const hitungMundur = setInterval(function() {
            if (sisaWaktu <= 0) {
                clearInterval(hitungMundur);
                
                if (!formSudahDisubmit) {
                    formSudahDisubmit = true;
                    alert("Waktu Ujian " + jenisUjian.toUpperCase() + " telah habis! Sistem akan menutup sesi dan mengirimkan jawaban otomatis.");
                    document.getElementById('formUjian').submit();
                }
                return;
            }

            sisaWaktu--;

            let menit = Math.floor(sisaWaktu / 60);
            let detik = sisaWaktu % 60;

            document.getElementById('tampilanTimer').textContent = 
                String(menit).padStart(2, '0') + ":" + String(detik).padStart(2, '0');
        }, 1000);

        function pindahSoal(index) {
            if (index < 0 || index >= totalSoal) return;
            document.querySelectorAll('.kotak-pertanyaan').forEach(el => el.classList.add('hidden'));
            const target = document.getElementById('kotakSoal_' + index);
            if (target) {
                target.classList.remove('hidden');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        async function simpanJawaban(soalId, pilihan, indexSoal) {
            const btnNav = document.getElementById('btnNav_' + indexSoal);
            if (btnNav) {
                btnNav.className = "h-10 rounded-xl font-bold text-xs transition border bg-emerald-50 border-emerald-300 text-emerald-700";
            }

            const ind = document.getElementById('indikatorSimpan');
            if (ind) ind.classList.remove('hidden');

            try {
                await fetch('api/simpan_jawaban.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        cpmi_id: cpmiId,
                        soal_id: soalId,
                        jawaban_pilihan: pilihan
                    })
                });
                setTimeout(() => { if (ind) ind.classList.add('hidden'); }, 1200);
            } catch (e) {
                console.warn("Auto-save tertunda:", e);
            }
        }

        function konfirmasiSelesai() {
            if (confirm("Apakah Anda yakin ingin menyelesaikan Ujian " + jenisUjian.toUpperCase() + "? Seluruh jawaban Anda akan disimpan dan sesi modul ini ditutup.")) {
                formSudahDisubmit = true;
                document.getElementById('formUjian').submit();
            }
        }

        history.pushState(null, null, location.href);
        window.addEventListener('popstate', function () {
            history.pushState(null, null, location.href);
        });
    </script>
</body>
</html>