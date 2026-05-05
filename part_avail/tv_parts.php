<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="60">
    <title>Part Availability | Maintenance Hub TV</title>
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

        .low-stock {
            background: rgba(239, 68, 68, 0.08);
            border-left: 4px solid #ef4444;
        }

        .ok-stock {
            background: transparent;
            border-left: 4px solid transparent;
        }

        .stock-bar-bg {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 999px;
            height: 6px;
        }

        .stock-bar-fill {
            border-radius: 999px;
            height: 6px;
            transition: width 0.5s;
        }
    </style>
</head>

<body class="flex flex-col">

    <?php
    include 'config.php';
    $parts = [];
    try {
        $parts = $pdo->query("SELECT * FROM expenses_part ORDER BY item_code ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }

    $total_parts = count($parts);
    $low_stock   = count(array_filter($parts, fn($p) => (int)$p['actual_stock'] < (int)$p['safety_stock']));
    $ok_stock    = $total_parts - $low_stock;
    ?>

    <!-- HEADER -->
    <header class="flex-shrink-0 px-8 py-4 border-b border-slate-700/50 flex items-center justify-between bg-slate-900/80">
        <div class="flex items-center gap-4">
            <img src="assets/yanmar.png" alt="Logo" class="h-10">
            <div>
                <div class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">PT. Yanmar Diesel Indonesia</div>
                <div class="text-xl font-black text-white tracking-tight">Part Availability</div>
            </div>
        </div>
        <div class="flex items-center gap-6">
            <div class="flex gap-4">
                <div class="text-center">
                    <div class="text-2xl font-black text-white"><?= $total_parts ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">Total Part</div>
                </div>
                <div class="w-px bg-slate-700"></div>
                <div class="text-center">
                    <div class="text-2xl font-black text-red-400"><?= $low_stock ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">Low Stock</div>
                </div>
                <div class="w-px bg-slate-700"></div>
                <div class="text-center">
                    <div class="text-2xl font-black text-emerald-400"><?= $ok_stock ?></div>
                    <div class="text-[10px] text-slate-500 uppercase font-semibold">Available</div>
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
        <div class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-white bg-slate-800 border-t border-x border-slate-700">
            <i class="fas fa-boxes-stacked mr-2 text-emerald-400"></i>Part Availability
        </div>
        <a href="tv_history.php" class="px-5 py-2 rounded-t-xl text-xs font-bold uppercase tracking-widest text-slate-400 hover:text-white hover:bg-slate-800 transition">
            <i class="fas fa-history mr-2"></i>History
        </a>
    </div>

    <!-- TABLE -->
    <div class="flex-1 overflow-hidden px-8 pb-4">
        <div class="h-full bg-slate-800/40 border border-slate-700/60 rounded-b-2xl rounded-tr-2xl overflow-hidden flex flex-col">
            <!-- Column Headers -->
            <div class="grid grid-cols-12 gap-0 bg-slate-700/40 border-b border-slate-700/60 px-6 py-3 flex-shrink-0">
                <div class="col-span-2 text-[10px] font-black uppercase tracking-widest text-slate-400">Kode Part</div>
                <div class="col-span-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Deskripsi</div>
                <div class="col-span-2 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Actual Stock</div>
                <div class="col-span-2 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Safety Stock</div>
                <div class="col-span-1 text-[10px] font-black uppercase tracking-widest text-slate-400">Effective</div>
                <div class="col-span-1 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Status</div>
            </div>
            <!-- Rows -->
            <div class="flex-1 overflow-y-auto">
                <?php foreach ($parts as $p):
                    $stock  = (int)$p['actual_stock'];
                    $safety = (int)$p['safety_stock'];
                    $isLow  = $stock < $safety;
                    $rowClass = $isLow ? 'low-stock' : 'ok-stock';
                    $maxBar   = max($stock, $safety * 2, 1);
                    $pct      = min(100, round($stock / $maxBar * 100));
                    $barColor = $isLow ? '#ef4444' : '#10b981';
                ?>
                    <div class="tv-row grid grid-cols-12 gap-0 px-6 py-3.5 border-b border-slate-700/30 <?= $rowClass ?>">
                        <div class="col-span-2 self-center">
                            <span class="font-mono text-slate-400 text-xs bg-slate-700/40 px-2 py-0.5 rounded"><?= htmlspecialchars($p['item_code']) ?></span>
                        </div>
                        <div class="col-span-4 pr-4 self-center">
                            <div class="font-semibold text-white text-sm"><?= htmlspecialchars($p['item_description'] ?? '-') ?></div>
                        </div>
                        <div class="col-span-2 text-center self-center">
                            <div class="text-xl font-black <?= $isLow ? 'text-red-400' : 'text-emerald-400' ?>"><?= $stock ?></div>
                            <div class="stock-bar-bg mt-1.5 mx-auto w-3/4">
                                <div class="stock-bar-fill" style="width:<?= $pct ?>%; background:<?= $barColor ?>;"></div>
                            </div>
                        </div>
                        <div class="col-span-2 text-center self-center">
                            <span class="text-slate-400 text-sm"><?= $safety ?></span>
                        </div>
                        <div class="col-span-1 self-center">
                            <?php $eff = (int)$p['effective_stock']; ?>
                            <span class="text-slate-400 text-xs font-bold <?= $eff < 0 ? 'text-red-400' : '' ?>"><?= ($eff >= 0 ? '+' : '') . $eff ?></span>
                        </div>
                        <div class="col-span-1 text-center self-center">
                            <?php if ($isLow): ?>
                                <span class="inline-flex items-center gap-1 bg-red-500/20 text-red-400 border border-red-500/30 px-2 py-1 rounded-full text-[10px] font-black uppercase">
                                    <i class="fas fa-exclamation-triangle text-[8px]"></i> Low
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2 py-1 rounded-full text-[10px] font-black uppercase">
                                    <i class="fas fa-check text-[8px]"></i> OK
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach;
                if (empty($parts)): ?>
                    <div class="flex items-center justify-center h-40 text-slate-600 text-sm">Belum ada data part.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <footer class="flex-shrink-0 px-8 py-2.5 flex items-center justify-between border-t border-slate-700/50 bg-slate-900/60">
        <span class="text-[10px] text-slate-600 uppercase tracking-widest">Auto refresh setiap 60 detik</span>
        <span class="text-[10px] text-slate-600 uppercase tracking-widest font-bold">Warehouse Real-Time · MT-YDN</span>
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