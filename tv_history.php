<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="60">
    <title>History | Maintenance Hub TV</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: white;
            min-height: 100vh;
        }

        .pulse-dot {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: 0.4
            }
        }

        .tv-row:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        ::-webkit-scrollbar {
            width: 4px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }

        .timeline-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 4px;
        }
    </style>
</head>

<body class="flex flex-col">

    <?php
    include 'config.php';
    $histories = [];
    try {
        $histories = $pdo->query("
            SELECT
                'predictive'   AS type,
                h.machine_name,
                h.maintenance_point AS activity,
                h.department,
                h.line,
                h.note          AS notes,
                h.reported_by,
                h.reported_at   AS created_at,
                u.username      AS technician
            FROM history_maintenance h
            LEFT JOIN users u ON u.id = h.reported_by

            UNION ALL

            SELECT
                'preventive'   AS type,
                h.machine_name,
                h.maintenance_point AS activity,
                h.department,
                h.line,
                h.note          AS notes,
                h.reported_by,
                h.reported_at   AS created_at,
                u.username      AS technician
            FROM history_preventive h
            LEFT JOIN users u ON u.id = h.reported_by

            ORDER BY created_at DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }

    $total = count($histories);
    $today_count = count(array_filter($histories, fn($h) => date('Y-m-d', strtotime($h['created_at'] ?? '')) === date('Y-m-d')));
    $this_month = count(array_filter($histories, fn($h) => date('Y-m', strtotime($h['created_at'] ?? '')) === date('Y-m')));
    ?>

    <!-- HEADER -->
    <header class="flex-shrink-0 px-8 py-4 border-b border-slate-700/50 flex items-center justify-between bg-slate-900/80">
        <div class="flex items-center gap-4">
            <img src="assets/yanmar.png" alt="Logo" class="h-10">
            <div>
                <div class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">PT. Yanmar Diesel Indonesia</div>
                <div class="text-xl font-black text-white tracking-tight">Maintenance History</div>
            </div>
        </div>
        <div class="flex items-center gap-6">
            <div class="flex gap-4">
                <div class="text-center">
                    <div class="text-2xl font-black text-amber-400"><?= $today_count ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">Hari Ini</div>
                </div>
                <div class="w-px bg-slate-700"></div>
                <div class="text-center">
                    <div class="text-2xl font-black text-blue-400"><?= $this_month ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">Bulan Ini</div>
                </div>
                <div class="w-px bg-slate-700"></div>
                <div class="text-center">
                    <div class="text-2xl font-black text-slate-300"><?= $total ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">Total Log</div>
                </div>
            </div>
            <div class="text-right">
                <div id="clock" class="text-2xl font-black font-mono tracking-widest text-white"></div>
                <div id="date-label" class="text-[10px] text-slate-500 uppercase tracking-wide"></div>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 bg-emerald-400 rounded-full pulse-dot"></span>
                <span class="text-[10px] text-emerald-400 uppercase font-bold tracking-widest">Live</span>
            </div>
        </div>
    </header>

    <!-- NAV TABS -->
    <div class="flex-shrink-0 flex gap-1 px-8 pt-4 pb-0">
        <a href="index.php" class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-slate-400 hover:text-white hover:bg-slate-800 transition">
            <i class="fas fa-home mr-2"></i>Portal
        </a>
        <a href="tv_schedule.php" class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-slate-400 hover:text-white hover:bg-slate-800 transition">
            <i class="fas fa-calendar-check mr-2"></i>Schedule
        </a>
        <a href="tv_parts.php" class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-slate-400 hover:text-white hover:bg-slate-800 transition">
            <i class="fas fa-boxes-stacked mr-2"></i>Part Availability
        </a>
        <div class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-white bg-slate-800 border-t border-x border-slate-700">
            <i class="fas fa-history mr-2 text-amber-400"></i>History
        </div>
    </div>

    <!-- TABLE -->
    <div class="flex-1 overflow-hidden px-8 pb-4">
        <div class="h-full bg-slate-800/40 border border-slate-700/60 rounded-b-2xl rounded-tr-2xl overflow-hidden flex flex-col">
            <!-- Column Headers -->
            <div class="grid grid-cols-12 gap-0 bg-slate-700/40 border-b border-slate-700/60 px-6 py-3 flex-shrink-0">
                <div class="col-span-1 text-[10px] font-black uppercase tracking-widest text-slate-400">Waktu</div>
                <div class="col-span-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Mesin</div>
                <div class="col-span-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Aktivitas</div>
                <div class="col-span-2 text-[10px] font-black uppercase tracking-widest text-slate-400">Teknisi</div>
                <div class="col-span-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Keterangan</div>
            </div>
            <!-- Rows -->
            <div class="flex-1 overflow-y-auto">
                <?php
                $prev_date = '';
                foreach ($histories as $h):
                    $dt = $h['created_at'] ?? '';
                    $date_part = $dt ? date('Y-m-d', strtotime($dt)) : '';
                    $time_part = $dt ? date('H:i', strtotime($dt)) : '-';
                    $is_today = $date_part === date('Y-m-d');
                    $is_month = $date_part && date('Y-m', strtotime($dt)) === date('Y-m');
                    $dotColor = $is_today ? '#f59e0b' : ($is_month ? '#60a5fa' : '#475569');
                ?>

                    <?php if ($date_part !== $prev_date && $date_part): $prev_date = $date_part; ?>
                        <div class="px-6 py-2 bg-slate-900/60 border-b border-slate-700/30 flex items-center gap-3">
                            <div class="timeline-dot" style="background:<?= $dotColor ?>;"></div>
                            <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                <?php
                                $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                                $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                                $ts = strtotime($date_part);
                                echo $days[date('w', $ts)] . ', ' . date('j', $ts) . ' ' . $months[date('n', $ts) - 1] . ' ' . date('Y', $ts);
                                if ($is_today) echo ' — <span style="color:#f59e0b;">HARI INI</span>';
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="tv-row grid grid-cols-12 gap-0 px-6 py-3 border-b border-slate-700/20">
                        <div class="col-span-1 self-center">
                            <span class="text-slate-500 text-xs font-mono"><?= $time_part ?></span>
                        </div>
                        <div class="col-span-3 pr-4 self-center">
                            <div class="font-semibold text-white text-sm"><?= htmlspecialchars($h['machine_name'] ?? '-') ?></div>
                            <div class="text-slate-500 text-[10px] mt-0.5"><?= htmlspecialchars($h['department'] ?? '') ?><?= ($h['line'] ?? '') ? ' · ' . htmlspecialchars($h['line']) : '' ?></div>
                        </div>
                        <div class="col-span-3 pr-4 self-center">
                            <span class="inline-flex items-center gap-1.5 text-xs flex-wrap">
                                <?php if ($h['type'] === 'predictive'): ?>
                                    <span class="bg-blue-500/20 text-blue-400 border border-blue-500/30 px-1.5 py-0.5 rounded text-[9px] font-black uppercase">Predictive</span>
                                <?php else: ?>
                                    <span class="bg-violet-500/20 text-violet-400 border border-violet-500/30 px-1.5 py-0.5 rounded text-[9px] font-black uppercase">Preventive</span>
                                <?php endif; ?>
                                <span class="text-slate-300"><?= htmlspecialchars($h['activity'] ?? '-') ?></span>
                            </span>
                        </div>
                        <div class="col-span-2 self-center">
                            <span class="text-slate-400 text-xs"><?= htmlspecialchars($h['technician'] ?? ('User #' . ($h['reported_by'] ?? '-'))) ?></span>
                        </div>
                        <div class="col-span-3 self-center">
                            <span class="text-slate-500 text-xs leading-relaxed"><?= htmlspecialchars($h['notes'] ?? '-') ?></span>
                        </div>
                    </div>
                <?php endforeach;
                if (empty($histories)): ?>
                    <div class="flex items-center justify-center h-40 text-slate-600 text-sm">Belum ada history maintenance.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <footer class="flex-shrink-0 px-8 py-2.5 flex items-center justify-between border-t border-slate-700/50 bg-slate-900/60">
        <span class="text-[10px] text-slate-600 uppercase tracking-widest">Auto refresh setiap 60 detik · Menampilkan 200 log terakhir</span>
        <span class="text-[10px] text-slate-600 uppercase tracking-widest font-bold">MT-YDN Maintenance Hub</span>
    </footer>

    <script>
        function updateClock() {
            const now = new Date();
            const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            document.getElementById('clock').textContent = now.toTimeString().slice(0, 8);
            document.getElementById('date-label').textContent = `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
        }
        updateClock();
        setInterval(updateClock, 1000);
    </script>
</body>

</html>