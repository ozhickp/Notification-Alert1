<?php

define('CHECKSHEET_ACCESS_KEY', '482703');

if (session_status() === PHP_SESSION_NONE) session_start();

// Logout / kunci ulang (dipanggil dari tombol "Kunci" di sidebar checksheet)
if (isset($_GET['logout'])) {
    unset($_SESSION['checksheet_unlocked'], $_SESSION['checksheet_unlocked_at']);
    header('Location: checksheet_gate.php');
    exit;
}

// Kalau sudah unlock, langsung lempar ke halaman tujuan
$redirect = $_GET['redirect'] ?? 'dashboard_checksheet.php';
if (!in_array($redirect, ['dashboard_checksheet.php', 'history_checksheet.php'], true)) {
    $redirect = 'dashboard_checksheet.php';
}
if (!empty($_SESSION['checksheet_unlocked'])) {
    header('Location: ' . $redirect);
    exit;
}

// ─── Rate limiting sederhana: 5x salah → lock 60 detik ─────────────────────
$maxAttempts   = 5;
$lockSeconds   = 60;
$attempts      = $_SESSION['checksheet_attempts']   ?? 0;
$lockedUntil   = $_SESSION['checksheet_locked_until'] ?? 0;
$now           = time();
$isLocked      = $lockedUntil > $now;
$error         = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    $inputKey = trim((string)($_POST['key'] ?? ''));

    if ($inputKey === '' || !ctype_digit($inputKey) || strlen($inputKey) > 6) {
        $error = 'Key harus berupa angka, maksimal 6 digit.';
    } elseif (hash_equals(CHECKSHEET_ACCESS_KEY, $inputKey)) {
        // Key benar
        $_SESSION['checksheet_unlocked']    = true;
        $_SESSION['checksheet_unlocked_at'] = $now;
        unset($_SESSION['checksheet_attempts'], $_SESSION['checksheet_locked_until']);
        header('Location: ' . $redirect);
        exit;
    } else {
        // Key salah
        $attempts++;
        $_SESSION['checksheet_attempts'] = $attempts;
        if ($attempts >= $maxAttempts) {
            $_SESSION['checksheet_locked_until'] = $now + $lockSeconds;
            $_SESSION['checksheet_attempts']     = 0;
            $isLocked = true;
        } else {
            $error = 'Key salah. Sisa percobaan: ' . ($maxAttempts - $attempts) . '.';
        }
    }
}

$lockRemaining = $isLocked ? max(0, $lockedUntil - $now) : 0;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checksheet - Akses Terbatas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
        }

        .key-input {
            letter-spacing: .6em;
            font-variant-numeric: tabular-nums;
        }

        .key-input::placeholder {
            letter-spacing: normal;
        }

        .shake {
            animation: shake .35s;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            20%,
            60% {
                transform: translateX(-6px);
            }

            40%,
            80% {
                transform: translateX(6px);
            }
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-[#fef3ea] text-[#e36414] flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-lock text-xl"></i>
            </div>
            <h1 class="text-lg font-extrabold text-slate-900">Akses Checksheet</h1>
            <p class="text-xs text-slate-500 mt-1">Masukkan key 6 digit untuk melanjutkan</p>
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-slate-100 p-6">
            <?php if ($isLocked): ?>
                <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 mb-4 flex items-center gap-2">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>Terlalu banyak percobaan salah. Coba lagi dalam <span id="lockCountdown"><?= (int)$lockRemaining ?></span> detik.</span>
                </div>
            <?php elseif ($error): ?>
                <div id="errorBox" class="shake rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 mb-4 flex items-center gap-2">
                    <i class="fas fa-circle-xmark"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" <?= $isLocked ? 'onsubmit="return false;"' : '' ?>>
                <div class="relative">
                    <input
                        type="text"
                        id="keyInput"
                        class="key-input w-full text-center text-2xl font-extrabold bg-slate-50 border border-slate-200 rounded-xl px-4 py-4 pr-12 outline-none focus:ring-4 focus:ring-[#f5d9bd] focus:border-[#e36414] transition"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="******"
                        autocomplete="off"
                        <?= $isLocked ? 'disabled' : 'autofocus' ?>>
                    <input type="hidden" name="key" id="keyReal" value="">
                    <button
                        type="button"
                        id="toggleKeyBtn"
                        tabindex="-1"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition"
                        title="Tampilkan/sembunyikan key">
                        <i class="fas fa-eye" id="toggleKeyIcon"></i>
                    </button>
                </div>

                <button
                    type="submit"
                    id="submitBtn"
                    class="w-full mt-4 bg-[#e36414] hover:bg-[#c2530f] text-white font-bold text-sm py-3 rounded-xl transition disabled:opacity-50 disabled:cursor-not-allowed"
                    <?= $isLocked ? 'disabled' : '' ?>>
                    <i class="fas fa-unlock mr-1"></i> Buka Checksheet
                </button>
            </form>

            <a href="index.php" class="mt-4 flex items-center justify-center gap-1.5 text-xs font-semibold text-slate-400 hover:text-slate-600 transition">
                <i class="fas fa-arrow-left text-[10px]"></i> Kembali ke Menu Utama
            </a>
        </div>
    </div>

    <script>
        // Input key: hanya angka, maks 6 digit, ditampilkan sebagai "*"
        const keyInput = document.getElementById('keyInput');
        const keyReal = document.getElementById('keyReal');
        let realValue = '';
        let revealed = false;

        function renderDisplay() {
            keyInput.value = revealed ? realValue : '*'.repeat(realValue.length);
            keyReal.value = realValue;
        }

        if (keyInput) {
            keyInput.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace') {
                    e.preventDefault();
                    realValue = realValue.slice(0, -1);
                    renderDisplay();
                } else if (e.key === 'Delete') {
                    e.preventDefault();
                    realValue = '';
                    renderDisplay();
                } else if (/^[0-9]$/.test(e.key)) {
                    e.preventDefault();
                    if (realValue.length < 6) {
                        realValue += e.key;
                        renderDisplay();
                    }
                } else if (e.key.length === 1) {
                    // Blok karakter non-angka (huruf, simbol, dst)
                    e.preventDefault();
                }
                // Key kontrol seperti Tab, Enter, ArrowLeft/Right dibiarkan lewat
            });

            keyInput.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasted = (e.clipboardData || window.clipboardData).getData('text');
                const digits = pasted.replace(/\D/g, '').slice(0, 6 - realValue.length);
                realValue += digits;
                realValue = realValue.slice(0, 6);
                renderDisplay();
            });
        }

        // Validasi manual sebelum submit (pengganti required/pattern bawaan browser)
        const gateForm = keyInput ? keyInput.closest('form') : null;
        if (gateForm) {
            gateForm.addEventListener('submit', (e) => {
                if (realValue.length === 0) {
                    e.preventDefault();
                    keyInput.classList.add('shake');
                    setTimeout(() => keyInput.classList.remove('shake'), 350);
                    keyInput.focus();
                }
            });
        }

        // Toggle tampilkan/sembunyikan key (mata)
        const toggleBtn = document.getElementById('toggleKeyBtn');
        const toggleIcon = document.getElementById('toggleKeyIcon');
        if (toggleBtn && keyInput) {
            toggleBtn.addEventListener('click', () => {
                revealed = !revealed;
                toggleIcon.classList.toggle('fa-eye', !revealed);
                toggleIcon.classList.toggle('fa-eye-slash', revealed);
                renderDisplay();
                keyInput.focus();
            });
        }

        // Countdown lockout
        const lockEl = document.getElementById('lockCountdown');
        if (lockEl) {
            let remaining = parseInt(lockEl.textContent, 10) || 0;
            const timer = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(timer);
                    window.location.reload();
                } else {
                    lockEl.textContent = remaining;
                }
            }, 1000);
        }
    </script>

</body>

</html>