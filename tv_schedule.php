<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="60">
    <title>Schedule | Maintenance Hub TV</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: white;
            min-height: 100vh;
        }

        .ticker-wrap {
            overflow: hidden;
            white-space: nowrap;
        }

        .ticker-inner {
            display: inline-block;
            animation: ticker 40s linear infinite;
        }

        @keyframes ticker {
            0% {
                transform: translateX(0);
            }

            100% {
                transform: translateX(-50%);
            }
        }

        .overdue {
            background: rgba(239, 68, 68, 0.15);
            border-left: 4px solid #ef4444;
        }

        .near-due {
            background: rgba(234, 179, 8, 0.12);
            border-left: 4px solid #eab308;
        }

        .ok {
            background: rgba(16, 185, 129, 0.08);
            border-left: 4px solid #10b981;
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

        .tv-row {
            transition: background 0.2s;
        }

        .tv-row:hover {
            background: rgba(255, 255, 255, 0.04);
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
    </style>
</head>

<body class="flex flex-col">

    <?php
    include 'config.php';
    $schedules = $pdo->query("
    SELECT s.*, DATEDIFF(change_date_plan, CURDATE()) AS remaining_day
    FROM schedules s ORDER BY change_date_plan ASC
")->fetchAll(PDO::FETCH_ASSOC);

    $overdue_count = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] <= 0));
    $near_count = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 0 && (int)$r['remaining_day'] <= 7));
    $ok_count = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 7));
    $total = count($schedules);
    ?>

    <!-- HEADER -->
    <header class="flex-shrink-0 px-8 py-4 border-b border-slate-700/50 flex items-center justify-between bg-slate-900/80">
        <div class="flex items-center gap-4">
            <img src="assets/yanmar.png" alt="Logo" class="h-10">
            <div>
                <div class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">PT. Yanmar Diesel Indonesia</div>
                <div class="text-xl font-black text-white tracking-tight">Maintenance Schedule</div>
            </div>
        </div>
        <div class="flex items-center gap-6">
            <!-- Stats mini -->
            <div class="flex gap-4">
                <div class="text-center">
                    <div class="text-2xl font-black text-red-400"><?= $overdue_count ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">Overdue</div>
                </div>
                <div class="w-px bg-slate-700"></div>
                <div class="text-center">
                    <div class="text-2xl font-black text-yellow-400"><?= $near_count ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">≤ 7 Hari</div>
                </div>
                <div class="w-px bg-slate-700"></div>
                <div class="text-center">
                    <div class="text-2xl font-black text-emerald-400"><?= $ok_count ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">On Track</div>
                </div>
            </div>
            <!-- Clock -->
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
        <div class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-white bg-slate-800 border-t border-x border-slate-700">
            <i class="fas fa-calendar-check mr-2 text-blue-400"></i>Schedule
        </div>
        <a href="tv_parts.php" class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-slate-400 hover:text-white hover:bg-slate-800 transition">
            <i class="fas fa-boxes-stacked mr-2"></i>Part Availability
        </a>
        <a href="tv_history.php" class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-slate-400 hover:text-white hover:bg-slate-800 transition">
            <i class="fas fa-history mr-2"></i>History
        </a>
    </div>

    <!-- TABLE -->
    <div class="flex-1 overflow-hidden px-8 pb-4">
        <div class="h-full bg-slate-800/40 border border-slate-700/60 rounded-b-2xl rounded-tr-2xl overflow-hidden flex flex-col">
            <!-- Column Headers -->
            <div class="grid grid-cols-12 gap-0 bg-slate-700/40 border-b border-slate-700/60 px-6 py-3 flex-shrink-0">
                <div class="col-span-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Mesin / Unit</div>
                <div class="col-span-1 text-[10px] font-black uppercase tracking-widest text-slate-400">Dept</div>
                <div class="col-span-1 text-[10px] font-black uppercase tracking-widest text-slate-400">Line</div>
                <div class="col-span-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Maintenance Point</div>
                <div class="col-span-1 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Interval</div>
                <div class="col-span-1 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Plan Date</div>
                <div class="col-span-2 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Sisa Hari</div>
            </div>
            <!-- Rows -->
            <div class="flex-1 overflow-y-auto">
                <?php foreach ($schedules as $row):
                    $d = (int)$row['remaining_day'];
                    $rowClass = $d <= 0 ? 'overdue' : ($d <= 7 ? 'near-due' : 'ok');
                    $badgeClass = $d <= 0 ? 'bg-red-500/20 text-red-400 border border-red-500/30' : ($d <= 7 ? 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30');
                    $icon = $d <= 0 ? 'fa-circle-exclamation text-red-400' : ($d <= 7 ? 'fa-triangle-exclamation text-yellow-400' : 'fa-circle-check text-emerald-400');
                ?>
                    <div class="tv-row grid grid-cols-12 gap-0 px-6 py-3.5 border-b border-slate-700/30 <?= $rowClass ?>">
                        <div class="col-span-3 pr-4">
                            <div class="font-bold text-white text-sm leading-tight"><?= htmlspecialchars($row['machine_name']) ?></div>
                            <?php if ($row['name_unit']): ?>
                                <div class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($row['name_unit']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-span-1 text-slate-400 text-xs self-center"><?= htmlspecialchars($row['department']) ?></div>
                        <div class="col-span-1 text-slate-400 text-xs self-center"><?= htmlspecialchars($row['line']) ?></div>
                        <div class="col-span-3 pr-4 self-center">
                            <div class="text-slate-200 text-xs leading-snug"><?= htmlspecialchars($row['maintenance_point']) ?></div>
                        </div>
                        <div class="col-span-1 text-center self-center">
                            <span class="text-slate-400 text-xs font-semibold"><?= htmlspecialchars($row['interval_month']) ?> bln</span>
                        </div>
                        <div class="col-span-1 text-center self-center">
                            <span class="text-slate-300 text-xs font-mono"><?= formatDate($row['change_date_plan']) ?></span>
                        </div>
                        <div class="col-span-2 text-center self-center">
                            <div class="flex items-center justify-center gap-2">
                                <i class="fas <?= $icon ?> text-xs"></i>
                                <span class="<?= $badgeClass ?> px-3 py-1 rounded-full text-xs font-black">
                                    <?= $d <= 0 ? abs($d) . ' hari lewat' : $d . ' hari lagi' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- TICKER -->
    <?php
    $overdue_items = array_filter($schedules, fn($r) => (int)$r['remaining_day'] <= 0);
    if (!empty($overdue_items)):
        $ticker_text = '';
        foreach ($overdue_items as $r) {
            $ticker_text .= ' ⚠️ OVERDUE: ' . htmlspecialchars($r['machine_name']) . ' — ' . htmlspecialchars($r['maintenance_point']) . ' (' . abs((int)$r['remaining_day']) . ' hari terlambat) ·····';
        }
        $ticker_text = str_repeat($ticker_text, 2);
    ?>
        <div class="flex-shrink-0 bg-red-900/40 border-t border-red-800/50 py-2 px-0 ticker-wrap">
            <div class="ticker-inner text-xs font-bold text-red-300 tracking-wide">
                <?= $ticker_text ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- FOOTER -->
    <footer class="flex-shrink-0 px-8 py-2.5 flex items-center justify-between border-t border-slate-700/50 bg-slate-900/60">
        <span class="text-[10px] text-slate-600 uppercase tracking-widest">Auto refresh setiap 60 detik</span>
        <span class="text-[10px] text-slate-600 uppercase tracking-widest font-bold"><?= $total ?> Total Schedule · MT-YDN</span>
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