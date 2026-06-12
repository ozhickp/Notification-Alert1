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
            border-width: 2px;
            border-style: solid;
        }

        .menu-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.12);
        }

        /* Schedule – #5f0f40 */
        .card-schedule {
            border-color: #c97aad;
        }

        .card-schedule:hover {
            border-color: #5f0f40;
        }

        .card-schedule .card-icon {
            background: #f9eef5;
            color: #5f0f40;
        }

        .card-schedule:hover .card-icon {
            background: #5f0f40;
            color: #fff;
        }

        .card-schedule .card-link {
            color: #5f0f40;
        }

        /* Inventory – #9a031e */
        .card-inventory {
            border-color: #e08090;
        }

        .card-inventory:hover {
            border-color: #9a031e;
        }

        .card-inventory .card-icon {
            background: #fdf0f2;
            color: #9a031e;
        }

        .card-inventory:hover .card-icon {
            background: #9a031e;
            color: #fff;
        }

        .card-inventory .card-link {
            color: #9a031e;
        }

        /* E-Report – #fb8b24 */
        .card-report {
            border-color: #fdcf9a;
        }

        .card-report:hover {
            border-color: #fb8b24;
        }

        .card-report .card-icon {
            background: #fff4e8;
            color: #fb8b24;
        }

        .card-report:hover .card-icon {
            background: #fb8b24;
            color: #fff;
        }

        .card-report .card-link {
            color: #fb8b24;
        }

        /* Checksheet – #e36414 */
        .card-checksheet {
            border-color: #f5b889;
        }

        .card-checksheet:hover {
            border-color: #e36414;
        }

        .card-checksheet .card-icon {
            background: #fef3ea;
            color: #e36414;
        }

        .card-checksheet:hover .card-icon {
            background: #e36414;
            color: #fff;
        }

        .card-checksheet .card-link {
            color: #e36414;
        }

        /* Monitoring – #0f4c5c */
        .card-monitor {
            border-color: #7ab3bf;
        }

        .card-monitor:hover {
            border-color: #0f4c5c;
        }

        .card-monitor .card-icon {
            background: #e8f4f7;
            color: #0f4c5c;
        }

        .card-monitor:hover .card-icon {
            background: #0f4c5c;
            color: #fff;
        }

        .card-monitor .card-link {
            color: #0f4c5c;
        }

        .card-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            transition: all 0.3s;
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

        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">

            <!-- Schedule -->
            <a href="login_user.php" class="menu-card card-schedule group bg-white p-6 rounded-3xl flex flex-col items-start">
                <div class="card-icon card-icon group-hover:text-white">
                    <i class="fas fa-calendar-check text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">Schedule</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">Create part replacement schedule, monitor part changes, and notification alerts</p>
                <div class="mt-auto flex items-center text-xs font-bold card-link">
                    Open Dashboard <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <!-- Inventory -->
            <a href="login_user.php?redirect=dashboard_part.php" class="menu-card card-inventory group bg-white p-6 rounded-3xl flex flex-col items-start">
                <div class="card-icon group-hover:text-white">
                    <i class="fas fa-boxes-stacked text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">Inventory</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">Check real-time sparepart stock and warehouse availability.</p>
                <div class="mt-auto flex items-center text-xs font-bold card-link">
                    Check Inventory <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <!-- E-Report -->
            <a href="login_user.php?redirect=dashboard_report.php" class="menu-card card-report group bg-white p-6 rounded-3xl flex flex-col items-start">
                <div class="card-icon group-hover:text-white">
                    <i class="fas fa-chart-bar text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">E-Report</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">Generate and view maintenance reports and summaries.</p>
                <div class="mt-auto flex items-center text-xs font-bold card-link">
                    Open Report <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <!-- Checksheet -->
            <a href="dashboard_checksheet.php" class="menu-card card-checksheet group bg-white p-6 rounded-3xl flex flex-col items-start">
                <div class="card-icon group-hover:text-white">
                    <i class="fas fa-clipboard-list text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">Checksheet</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">Manage and record daily checksheet activities for machine inspection.</p>
                <div class="mt-auto flex items-center text-xs font-bold card-link">
                    Open Checksheet <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <!-- Monitoring -->
            <a href="monitor.php" class="menu-card card-monitor group bg-white p-6 rounded-3xl flex flex-col items-start">
                <div class="card-icon group-hover:text-white">
                    <i class="fas fa-display text-lg"></i>
                </div>
                <h2 class="text-base font-bold text-slate-800 mb-1">Monitoring</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-4">View-only dashboard for schedule, part availability, and maintenance history data.</p>
                <div class="mt-auto flex items-center text-xs font-bold card-link">
                    Open Monitor <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

        </div>

        <div class="mt-10 pt-6 border-t border-slate-200 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full animate-pulse" style="background:#5f0f40;"></span>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">Version 1.3</span>
            </div>
            <div class="flex items-center gap-1 text-xs text-slate-400 font-medium italic">
                <i class="far fa-copyright text-[10px]"></i>
                <span>MT-YDN</span>
            </div>
        </div>
    </div>

</body>

</html>