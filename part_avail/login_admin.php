<?php
// ── login_admin.php ── Login untuk Admin ──
session_start();
require_once __DIR__ . '/config.php';

// Redirect jika sudah login sebagai admin
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'superadmin') {
    header('Location: dashboard_admin.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $error = 'Username/email dan password wajib diisi.';
    } else {
        // Cari user dengan role = 'superadmin'
        $stmt = $pdo->prepare("
            SELECT id, username, email_user, password, role, is_active
            FROM users
            WHERE (username = ? OR email_user = ?)
              AND role = 'superadmin'
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Akun admin tidak ditemukan.';
        } elseif (!$user['is_active']) {
            $error = 'Akun admin tidak aktif.';
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Password salah.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            header('Location: dashboard_admin.php');
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
    <title>Login Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet" />
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
            background: #0c0a09;
            background-image:
                radial-gradient(ellipse 80% 60% at 20% 0%, rgba(234, 88, 12, 0.10) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 100%, rgba(220, 38, 38, 0.08) 0%, transparent 60%);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.07);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        .input-field {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #f1f5f9;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-field:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.12);
        }

        .input-field::placeholder {
            color: #44403c;
        }

        .btn-admin {
            background: linear-gradient(135deg, #ea580c, #dc2626);
            transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 20px rgba(234, 88, 12, 0.30);
        }

        .btn-admin:hover {
            opacity: 0.92;
            transform: translateY(-1px);
            box-shadow: 0 8px 28px rgba(234, 88, 12, 0.40);
        }

        .btn-admin:active {
            transform: translateY(0);
        }

        .btn-back {
            border: 1px solid rgba(255, 255, 255, 0.10);
            color: #78716c;
            transition: border-color 0.2s, color 0.2s, background 0.2s;
        }

        .btn-back:hover {
            border-color: rgba(255, 255, 255, 0.20);
            color: #d6d3d1;
            background: rgba(255, 255, 255, 0.04);
        }

        .label-text {
            color: #78716c;
            font-size: 0.78rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-weight: 500;
        }

        .dot-grid {
            background-image: radial-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px);
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

        /* Badge admin */
        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(234, 88, 12, 0.12);
            border: 1px solid rgba(234, 88, 12, 0.25);
            color: #fb923c;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 999px;
        }
    </style>
</head>

<body class="dot-grid flex items-center justify-center min-h-screen px-4 py-12">

    <div class="w-full max-w-md">

        <!-- Brand -->
        <div class="text-center mb-8 fade-up">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl mb-4"
                style="background: linear-gradient(135deg,#ea580c,#dc2626); box-shadow:0 8px 24px rgba(234,88,12,0.35)">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h1 class="brand text-2xl font-bold text-white tracking-tight">Maintenance System</h1>
            <div class="admin-badge mt-2 mx-auto">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                Admin Portal
            </div>
        </div>

        <!-- Card -->
        <div class="glass-card rounded-2xl p-8 fade-up-2">
            <h2 class="brand text-xl font-semibold text-white mb-1">Admin Login</h2>
            <p class="text-stone-500 text-sm mb-6">Akses terbatas untuk administrator sistem</p>

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

                <div>
                    <label class="label-text block mb-1.5">Username atau Email</label>
                    <input type="text" name="identifier" required
                        value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                        placeholder="Username atau email admin"
                        class="input-field w-full rounded-xl px-4 py-3 text-sm" />
                </div>

                <div>
                    <label class="label-text block mb-1.5">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" required
                            placeholder="Password admin"
                            class="input-field w-full rounded-xl px-4 py-3 pr-11 text-sm" />
                        <button type="button" onclick="togglePass()"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-stone-600 hover:text-stone-400 transition">
                            <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Warning notice -->
                <div class="flex items-start gap-2 text-xs text-stone-600 bg-stone-900/60 border border-stone-800 rounded-xl px-4 py-3">
                    <svg class="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-orange-700" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    Halaman ini hanya untuk administrator. Aktivitas login dicatat.
                </div>

                <button type="submit" class="btn-admin w-full text-white font-semibold py-3 rounded-xl text-sm tracking-wide">
                    Masuk sebagai Admin
                </button>
            </form>
        </div>

        <!-- Back to user login -->
        <div class="text-center mt-6 fade-up-3">
            <a href="login_user.php"
                class="btn-back inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Kembali ke Login Pengguna
            </a>
        </div>

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