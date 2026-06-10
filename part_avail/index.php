<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Hub - Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
        }

        .menu-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .menu-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-5xl">
        <div class="text-center mb-10">
            <img src="assets/yanmar.png" alt="Company Logo" class="h-20 w-auto mx-auto block">
            <h1 class="text-s font-extrabold text-slate-900 tracking-tight">PT. Yanmar Diesel Indonesia</h1>
            <h2 class="text-2xl font-bold text-slate-700 tracking-tight">Maintenance Hub</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">

            <a href="login_user.php" class="menu-card group bg-white p-6 rounded-3xl border-2 border-blue-200 hover:border-blue-500 flex flex-col items-start">
                <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-blue-600 group-hover:text-white transition-all">
                    <i class="fas fa-calendar-check text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">Schedule</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">Create part replacement schedule, monitor part changes, and notification alerts</p>
                <div class="mt-auto flex items-center text-xs font-bold text-blue-600">
                    Open Dashboard <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <a href="dashboard_part.php" class="menu-card group bg-white p-6 rounded-3xl border-2 border-emerald-200 hover:border-emerald-500 flex flex-col items-start">
                <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-emerald-600 group-hover:text-white transition-all">
                    <i class="fas fa-boxes-stacked text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">Inventory</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">Check real-time sparepart stock and warehouse availability.</p>
                <div class="mt-auto flex items-center text-xs font-bold text-emerald-600">
                    Check Inventory <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <a href="dashboard_checksheet.php" class="menu-card group bg-white p-6 rounded-3xl border-2 border-rose-200 hover:border-rose-500 flex flex-col items-start">
                <div class="w-12 h-12 bg-rose-50 text-rose-600 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-rose-600 group-hover:text-white transition-all">
                    <i class="fas fa-clipboard-list text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">Checksheet</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">Manage and record daily checksheet activities for machine inspection.</p>
                <div class="mt-auto flex items-center text-xs font-bold text-rose-600">
                    Open Checksheet <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <a href="monitor.php" class="menu-card group bg-white p-6 rounded-3xl border-2 border-violet-200 hover:border-violet-500 flex flex-col items-start">
                <div class="w-12 h-12 bg-violet-50 text-violet-600 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-violet-600 group-hover:text-white transition-all">
                    <i class="fas fa-display text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">Monitoring</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">View-only dashboard for schedule, part availability, and maintenance history data.</p>
                <div class="mt-auto flex items-center text-xs font-bold text-violet-600">
                    Open Monitor <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

        </div>

        <div class="mt-10 pt-6 border-t border-slate-200 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">Version 1.2</span>
            </div>
            <div class="flex items-center gap-1 text-xs text-slate-400 font-medium italic">
                <i class="far fa-copyright text-[10px]"></i>
                <span>MT-YDN</span>
            </div>
        </div>
    </div>

</body>

</html>