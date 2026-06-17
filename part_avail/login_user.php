<?php
session_start();
require_once __DIR__ . '/config.php';

// Whitelist halaman yang boleh dijadikan redirect tujuan
$allowed_redirects = ['dashboard_user.php', 'dashboard_part.php', 'history_maintenance.php', 'dashboard_report.php'];
$redirect = trim($_GET['redirect'] ?? $_POST['redirect'] ?? '');
if (!in_array($redirect, $allowed_redirects)) {
    $redirect = 'dashboard_user.php';
}

if (isset($_SESSION['user_id'], $_SESSION['role']) && $_SESSION['role'] === 'user') {
    header('Location: ' . $redirect);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $error = 'Username/email dan password wajib diisi.';
    } else {
        // Cari user berdasarkan username ATAU email, role = 'user'
        $stmt = $pdo->prepare("
            SELECT id, username, email_user, password, role, is_active
            FROM users
            WHERE (username = ? OR email_user = ?)
              AND role = 'user'
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Akun tidak ditemukan atau bukan akun pengguna.';
        } elseif (!$user['is_active']) {
            $error = 'Akun Anda tidak aktif. Hubungi administrator.';
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Password salah.';
        } else {
            // Login berhasil
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            header('Location: ' . $redirect);
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
    <title>Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * {
            font-family: 'DM Sans', sans-serif;
        }

        h1,
        h2,
        .brand {
            font-family: 'Sora', sans-serif;
        }

        body {
            min-height: 100vh;
            background: #f1f5f9;
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

        .btn-outline {
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #94a3b8;
            transition: border-color 0.2s, color 0.2s, background 0.2s;
        }

        .btn-outline:hover {
            border-color: rgba(255, 255, 255, 0.25);
            color: #e2e8f0;
            background: rgba(255, 255, 255, 0.04);
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

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #334155;
            font-size: 0.75rem;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.07);
        }
    </style>
</head>

<body class="dot-grid flex items-center justify-center min-h-screen px-4 py-12">
    <a href="index.php" class="fixed top-6 left-6 flex items-center gap-2 text-slate-500 hover:text-blue-600 transition-colors font-semibold text-sm group">
        <div class="w-8 h-8 rounded-full bg-white shadow-sm border border-slate-200 flex items-center justify-center group-hover:border-blue-200 group-hover:bg-blue-50 transition-all">
            <i class="fas fa-arrow-left"></i>
        </div>
        Back to Hub
    </a>

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
            <h1 class="brand text-2xl font-bold F tracking-tight">Maintenance System</h1>
        </div>

        <!-- Card -->
        <div class="glass-card rounded-2xl p-8 fade-up-2">
            <h2 class="brand text-xl font-semibold text-black mb-1">Selamat Datang</h2>
            <p class="text-slate-500 text-sm mb-6">Masuk ke akun pengguna Anda</p>

            <!-- Alert Error -->
            <?php if ($error): ?>
                <div class="flex items-start gap-3 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl px-4 py-3 mb-5 text-sm">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5" novalidate>

                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

                <!-- Username / Email -->
                <div>
                    <label class="label-text block mb-1.5">Username atau Email</label>
                    <input type="text" name="identifier" required
                        value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                        placeholder="contoh: john_doe atau john@contoh.com"
                        class="input-field w-full rounded-xl px-4 py-3 text-sm text-black" />
                </div>

                <!-- Password -->
                <div>
                    <label class="label-text block mb-1.5">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" required
                            placeholder="Masukkan password"
                            class="input-field w-full rounded-xl px-4 py-3 pr-11 text-sm text-black" />
                        <button type="button" onclick="togglePass()"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition">
                            <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary w-full text-white font-semibold py-3 rounded-xl text-sm tracking-wide">
                    Masuk
                </button>
            </form>

            <!-- Divider -->
            <div class="divider my-6">atau</div>

            <!-- Admin Login Button -->
            <a href="login_admin.php"
                class="btn-outline flex items-center justify-center gap-2 w-full py-3 rounded-xl text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Login sebagai Admin
            </a>
        </div>

        <!-- Footer links -->
        <p class="text-center text-slate-500 text-sm mt-6 fade-up-3">
            Belum punya akun?
            <a href="register_user.php" class="text-sky-400 hover:text-sky-300 font-medium transition">Daftar di sini</a>
        </p>

    </div>

    <script>
        function togglePass() {
            const input = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            const isPass = input.type === 'password';
            input.type = isPass ? 'text' : 'password';
            icon.innerHTML = isPass ?
                `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>` :
                `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;
        }
    </script>
</body>

</html>