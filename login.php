<?php
session_start();

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    die('Berkas config.php tidak ditemukan.');
}

if (file_exists(__DIR__ . '/../api/helper_log.php')) {
    require_once __DIR__ . '/../api/helper_log.php';
}

if (isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) AND !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT id, nama, username, level, password, lembaga_pelatihan FROM tb_admin WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            $isPasswordValid = false;
            if ($admin) {
                if ($admin['password'] === md5($password) OR password_verify($password, $admin['password'])) {
                    $isPasswordValid = true;
                }
            }

            if ($isPasswordValid) {
                $_SESSION['admin_id']      = $admin['id'];
                $_SESSION['admin_nama']    = $admin['nama'];
                $_SESSION['admin_user']    = $admin['username'];
                $_SESSION['admin_level']   = $admin['level']; 
                $_SESSION['admin_lembaga'] = $admin['lembaga_pelatihan'];
                
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Berhasil masuk (login) ke sistem", 'AUTH');
                }

                header("Location: index.php");
                exit;
            } else {
                $error = 'Username atau kata sandi tidak ditemukan/salah.';
                if (function_exists('catatLog')) {
                    catatLog($pdo, "Gagal masuk (login) dengan username: {$username}", 'AUTH');
                }
            }
        } catch (Throwable $e) {
            $error = 'Terjadi kendala basis data: ' . $e->getMessage();
        }
    } else {
        $error = 'Harap lengkapi username dan kata sandi.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Masuk Administrator — BP3MI Jawa Tengah</title>
    
    <link rel="icon" type="image/png" href="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Nunito Sans', 'Plus Jakarta Sans', sans-serif; 
            background-color: #f4f6f9;
        }
        .btn-corporate {
            background-color: #0e74bb;
            transition: all 0.2s ease-in-out;
        }
        .btn-corporate:hover {
            background-color: #0b5e99;
        }
        .btn-corporate:active {
            background-color: #094e80;
        }
        .input-group-corporate:focus-within {
            border-color: #0e74bb;
            box-shadow: 0 0 0 3px rgba(14, 116, 187, 0.15);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between text-slate-800 antialiased selection:bg-[#0e74bb] selection:text-white">

    <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center">
            <div class="flex items-center space-x-3.5">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" 
                     alt="Logo BP2MI" class="h-10 w-auto object-contain">
                <div class="border-l border-slate-200 pl-3.5 leading-tight">
                    <p class="text-[11px] sm:text-xs font-extrabold text-slate-800 tracking-wider uppercase">PINTU | PUSAT INFORMASI PENDAFTARAN PENINGKATAN KAPASITAS CPMI</p>
                    <p class="text-[10px] sm:text-[11px] font-semibold text-slate-500 uppercase tracking-tight">PORTAL ADMINISTRATOR - BP3MI JAWA TENGAH</p>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 flex items-center justify-center p-4 sm:p-6 my-4 sm:my-8">
        <div class="w-full max-w-md">

            <div class="bg-white rounded-xl border border-slate-200 shadow-[0_4px_24px_-4px_rgba(0,0,0,0.06)] p-7 sm:p-9 relative overflow-hidden">
                
                <div class="text-center mb-6">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg/960px-Logo_Kementerian_Pelindungan_Pekerja_Migran_Indonesia_-_BP2MI_v2_%282024%29.svg.png" 
                         alt="Logo BP2MI" class="h-14 w-auto object-contain mx-auto mb-3.5">
                    
                    <span class="inline-block px-3 py-1 rounded text-[10px] font-extrabold uppercase tracking-widest bg-sky-50 text-[#0e74bb] border border-sky-100 mb-2.5">
                        Portal Masuk Administrator
                    </span>
                    <h1 class="text-base sm:text-lg font-extrabold text-slate-800 tracking-tight uppercase leading-snug">
                        Pusat Informasi Pendaftaran Peningkatan Kapasitas CPMI
                    </h1>
                    <p class="text-[11px] font-bold text-slate-500 mt-1 uppercase tracking-wider">
                        BP3MI Jawa Tengah
                    </p>
                </div>

                <div class="border-t border-slate-100 my-5"></div>

                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-xs font-semibold mb-5 flex items-start gap-2.5 leading-relaxed" role="alert">
                        <i class="fas fa-exclamation-circle text-red-500 text-sm mt-0.5 shrink-0"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" class="space-y-4 text-xs">
                    
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Username
                        </label>
                        <div class="input-group-corporate flex items-stretch rounded-lg border border-slate-300 bg-white overflow-hidden transition">
                            <span class="inline-flex items-center justify-center w-11 bg-slate-50 text-slate-400 border-r border-slate-200 shrink-0">
                                <i class="far fa-user text-sm"></i>
                            </span>
                            <input type="text" name="username" required autocomplete="username" placeholder="Masukkan username" 
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                   class="w-full h-11 px-3.5 text-xs font-semibold text-slate-800 placeholder-slate-400 focus:outline-none bg-transparent">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Password
                        </label>
                        <div class="input-group-corporate flex items-stretch rounded-lg border border-slate-300 bg-white overflow-hidden transition">
                            <span class="inline-flex items-center justify-center w-11 bg-slate-50 text-slate-400 border-r border-slate-200 shrink-0">
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input type="password" id="passwordInput" name="password" required autocomplete="current-password" placeholder="••••••••" 
                                   class="w-full h-11 px-3.5 text-xs font-semibold text-slate-800 placeholder-slate-400 focus:outline-none bg-transparent">
                            <button type="button" id="togglePasswordBtn" class="inline-flex items-center justify-center px-3.5 text-slate-400 hover:text-slate-600 transition focus:outline-none" title="Lihat/Sembunyikan Kata Sandi">
                                <i id="togglePasswordIcon" class="far fa-eye text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-corporate w-full h-11 text-white font-bold text-xs rounded-lg uppercase tracking-wider mt-3 shadow-sm hover:shadow transition flex items-center justify-center gap-2">
                        <i class="fas fa-sign-in-alt text-xs"></i>
                        <span>Masuk Dashboard Administrator</span>
                    </button>
                </form>

            </div>
            
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
        const toggleBtn = document.getElementById('togglePasswordBtn');
        const passwordInput = document.getElementById('passwordInput');
        const toggleIcon = document.getElementById('togglePasswordIcon');

        if (toggleBtn && passwordInput && toggleIcon) {
            toggleBtn.addEventListener('click', function () {
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                
                if (isPassword) {
                    toggleIcon.classList.remove('fa-eye');
                    toggleIcon.classList.add('fa-eye-slash');
                } else {
                    toggleIcon.classList.remove('fa-eye-slash');
                    toggleIcon.classList.add('fa-eye');
                }
            });
        }
    </script>
</body>
</html>