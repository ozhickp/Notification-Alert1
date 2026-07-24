<?php
// ── register.php ──
session_start();
require_once __DIR__ . '/config.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $role     = trim($_POST['role'] ?? '');

    // Whitelist role yang boleh dipilih lewat form publik ini
    $allowedRoles = ['admin_maintenance', 'admin_conrod', 'technician'];

    // Validasi
    if (empty($username) || empty($email) || empty($password) || empty($role)) {
        $error = 'Semua field wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Role tidak valid.';
    } else {
        // Cek duplikat username / email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email_user = ? LIMIT 1");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = 'Username atau email sudah digunakan.';
        } else {
            // Simpan user baru dengan role sesuai pilihan (admin_maintenance / admin_conrod / technician)
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email_user, password, role, is_active, created_at)
                VALUES (?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$username, $email, $hashed, $role]);
            header('Location: login_user.php?registered=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Daftar Akun — Maintenance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet" />
    <style>
        * {
            font-family: 'DM Sans', sans-serif;
        }

        h1,
        h2,
        h3,
        .brand {
            font-family: 'Sora', sans-serif;
        }

        body {
            min-height: 100vh;
            background: #f8fafc;
            background-image:
                radial-gradient(ellipse 80% 60% at 20% 0%, rgba(14, 165, 233, 0.12) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 100%, rgba(99, 102, 241, 0.10) 0%, transparent 60%);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }

        .input-field {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.10);
            color: #f1f5f9;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-field:focus {
            outline: none;
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.12);
        }

        .input-field::placeholder {
            color: #475569;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0ea5e9, #6366f1);
            transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 20px rgba(14, 165, 233, 0.30);
        }

        .btn-primary:hover {
            opacity: 0.92;
            transform: translateY(-1px);
            box-shadow: 0 8px 28px rgba(14, 165, 233, 0.40);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .label-text {
            color: #94a3b8;
            font-size: 0.78rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-weight: 500;
        }

        .dot-grid {
            background-image: radial-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 28px 28px;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-up {
            animation: fadeUp 0.5s ease both;
        }

        .fade-up-2 {
            animation: fadeUp 0.5s 0.1s ease both;
        }

        .fade-up-3 {
            animation: fadeUp 0.5s 0.2s ease both;
        }

        .strength-bar {
            height: 3px;
            border-radius: 2px;
            transition: width 0.3s, background 0.3s;
        }

        input[type="password"]::-ms-reveal {
            display: none;
        }
    </style>
</head>

<body class="dot-grid flex items-center justify-center min-h-screen px-4 py-12">

    <div class="w-full max-w-md">

        <!-- Brand -->
        <div class="text-center mb-8 fade-up">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl mb-4"
                style="background: linear-gradient(135deg,#0ea5e9,#6366f1); box-shadow:0 8px 24px rgba(14,165,233,0.35)">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </div>
            <h1 class="brand text-2xl font-bold text-black tracking-tight">Maintenance</h1>
            <p class="text-slate-500 text-sm mt-1">Machine Management System</p>
        </div>

        <!-- Card -->
        <div class="glass-card rounded-2xl p-8 fade-up-2">
            <h2 class="brand text-xl font-semibold text-black mb-1">Buat Akun Baru</h2>
            <p class="text-slate-500 text-sm mb-6">Isi data di bawah untuk mendaftar sebagai pengguna</p>

            <!-- Alert Error -->
            <?php if ($error): ?>
                <div class="flex items-start gap-3 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl px-4 py-3 mb-5 text-sm fade-up">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Alert Success -->
            <?php if ($success): ?>
                <div class="flex items-start gap-3 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-xl px-4 py-3 mb-5 text-sm fade-up">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <?= htmlspecialchars($success) ?>
                    <a href="login_user.php" class="font-semibold underline ml-1 text-emerald-300">Login sekarang →</a>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5" novalidate>

                <!-- Username -->
                <div>
                    <label class="label-text block mb-1.5">Nama Pengguna</label>
                    <input type="text" name="username" required
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        placeholder="contoh: john_doe"
                        class="input-field w-full rounded-xl px-4 py-3 text-sm text-slate-900" />
                </div>

                <!-- Email -->
                <div>
                    <label class="label-text block mb-1.5">Alamat Email</label>
                    <input type="email" name="email" required
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        placeholder="john@contoh.com"
                        class="input-field w-full rounded-xl px-4 py-3 text-sm text-slate-900" />
                </div>

                <!-- Role -->
                <div>
                    <label class="label-text block mb-1.5">Role</label>
                    <select name="role" required
                        class="input-field w-full rounded-xl px-4 py-3 text-sm text-slate-900">
                        <option value="" disabled <?= empty($_POST['role']) ? 'selected' : '' ?>>— Pilih Role —</option>
                        <option value="admin_maintenance" <?= (($_POST['role'] ?? '') === 'admin_maintenance') ? 'selected' : '' ?>>Admin Maintenance</option>
                        <option value="technician" <?= (($_POST['role'] ?? '') === 'technician') ? 'selected' : '' ?>>Technician</option>
                        <option value="admin_conrod" <?= (($_POST['role'] ?? '') === 'admin_conrod') ? 'selected' : '' ?>>Admin Conrod</option>
                    </select>
                </div>

                <!-- Password -->
                <div>
                    <label class="label-text block mb-1.5">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" required
                            placeholder="Minimal 6 karakter"
                            oninput="checkStrength(this.value)"
                            class="input-field w-full rounded-xl px-4 py-3 pr-11 text-sm text-slate-900" />
                        <button type="button" onclick="togglePass('password','eyeIcon1')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition">
                            <svg id="eyeIcon1" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                    <!-- Strength bar -->
                    <div class="mt-2 flex gap-1">
                        <div id="s1" class="strength-bar flex-1 bg-slate-700"></div>
                        <div id="s2" class="strength-bar flex-1 bg-slate-700"></div>
                        <div id="s3" class="strength-bar flex-1 bg-slate-700"></div>
                        <div id="s4" class="strength-bar flex-1 bg-slate-700"></div>
                    </div>
                    <p id="strengthLabel" class="text-xs text-slate-600 mt-1"></p>
                </div>

                <!-- Confirm Password -->
                <div>
                    <label class="label-text block mb-1.5">Konfirmasi Password</label>
                    <div class="relative">
                        <input type="password" name="confirm_password" id="confirm_password" required
                            placeholder="Ulangi password"
                            class="input-field w-full rounded-xl px-4 py-3 pr-11 text-sm text-slate-900" />
                        <button type="button" onclick="togglePass('confirm_password','eyeIcon2')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition">
                            <svg id="eyeIcon2" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary w-full text-white font-semibold py-3 rounded-xl text-sm tracking-wide">
                    Buat Akun
                </button>
            </form>

            <!-- Login link (langsung di bawah tombol Buat Akun) -->
            <p class="text-center text-slate-500 text-sm mt-3 fade-up-3">
                Sudah punya akun?
                <a href="login_user.php" class="text-sky-400 hover:text-sky-300 font-medium transition">Login di sini</a>
            </p>
        </div>

    </div>

    <script>
        function togglePass(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            const isPass = input.type === 'password';
            input.type = isPass ? 'text' : 'password';
            icon.innerHTML = isPass ?
                `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>` :
                `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;
        }

        function checkStrength(val) {
            let score = 0;
            if (val.length >= 6) score++;
            if (val.length >= 10) score++;
            if (/[A-Z]/.test(val) && /[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            const colors = ['#ef4444', '#f97316', '#eab308', '#22c55e'];
            const labels = ['Lemah', 'Cukup', 'Kuat', 'Sangat Kuat'];
            const textColors = ['text-red-400', 'text-orange-400', 'text-yellow-400', 'text-emerald-400'];

            for (let i = 1; i <= 4; i++) {
                const bar = document.getElementById('s' + i);
                bar.style.background = i <= score ? colors[score - 1] : '#334155';
            }

            const label = document.getElementById('strengthLabel');
            label.className = 'text-xs mt-1 ' + (score > 0 ? textColors[score - 1] : 'text-slate-600');
            label.textContent = score > 0 ? 'Kekuatan: ' + labels[score - 1] : '';
        }
    </script>
</body>

</html>