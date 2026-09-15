<?php

if (!function_exists('mergeTimeIntervals')) {
    function mergeTimeIntervals(array $intervals): array
    {
        if (empty($intervals)) return [];

        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);

        $merged = [$intervals[0]];
        foreach (array_slice($intervals, 1) as $iv) {
            $lastIdx = count($merged) - 1;
            if ($iv[0] <= $merged[$lastIdx][1]) {
                // Beririsan/bersentuhan → gabung, ambil ujung akhir terjauh
                $merged[$lastIdx][1] = max($merged[$lastIdx][1], $iv[1]);
            } else {
                $merged[] = $iv;
            }
        }
        return $merged;
    }
}

if (!function_exists('resolveDayTypeForDate')) {

    function resolveDayTypeForDate(int $ts): string
    {
        $dow = (int)date('N', $ts); // 1=Senin ... 7=Minggu
        if ($dow === 5) return 'friday';
        if ($dow >= 6) return 'holiday';
        return 'weekday';
    }
}

if (!function_exists('getShiftWindowsForDate')) {
    function getShiftWindowsForDate(PDO $pdo, string $dateYmd): array
    {
        static $cache = [];
        if (isset($cache[$dateYmd])) return $cache[$dateYmd];

        $ts       = strtotime($dateYmd . ' 00:00:00');
        $dayType  = resolveDayTypeForDate($ts);
        $nextTs   = strtotime('+1 day', $ts);
        $nextType = resolveDayTypeForDate($nextTs);

        $shifts     = fetchShiftSchedule($pdo, $dayType);
        $nextShifts = fetchShiftSchedule($pdo, $nextType);

        if (count($shifts) < 3 || count($nextShifts) < 3) {
            return $cache[$dateYmd] = [];
        }

        [$s1, $s2, $s3] = $shifts;
        $nextS1 = $nextShifts[0];

        $dateStr = date('Y-m-d', $ts);
        $nextDateStr = date('Y-m-d', $nextTs);

        $windows = [
            $s1['shift_name'] => [
                strtotime("$dateStr {$s1['start_time']}"),
                strtotime("$dateStr {$s2['start_time']}"),
            ],
            $s2['shift_name'] => [
                strtotime("$dateStr {$s2['start_time']}"),
                strtotime("$dateStr {$s3['start_time']}"),
            ],
            $s3['shift_name'] => [
                strtotime("$dateStr {$s3['start_time']}"),
                strtotime("$nextDateStr {$nextS1['start_time']}"),
            ],
        ];

        return $cache[$dateYmd] = $windows;
    }
}

if (!function_exists('fetchShiftSchedule')) {
    function fetchShiftSchedule(PDO $pdo, string $dayType): array
    {
        static $cache = [];
        if (isset($cache[$dayType])) return $cache[$dayType];
        try {
            $stmt = $pdo->prepare("
                SELECT shift_name, start_time, end_time
                FROM shift_schedules
                WHERE day_type = ?
                ORDER BY start_time ASC
            ");
            $stmt->execute([$dayType]);
            return $cache[$dayType] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Tabel belum ada / belum di-setup — biarkan pemanggil fallback
            // ke pemotongan libur sehari-penuh saja (tanpa scoping shift).
            return $cache[$dayType] = [];
        }
    }
}

if (!function_exists('getHolidayRowsInRange')) {
    function getHolidayRowsInRange(PDO $pdo, string $startDate, string $endDate, ?string $department, ?string $line): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT holiday_date, department, line, shifts
                FROM holiday_settings
                WHERE holiday_date BETWEEN ? AND ?
                  AND (department IS NULL OR department = ?)
                  AND (line IS NULL OR line = ?)
            ");
            $stmt->execute([$startDate, $endDate, $department, $line]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('subtractHolidayMinutes')) {
    function subtractHolidayMinutes(PDO $pdo, int $startTs, int $endTs, ?string $department, ?string $line): float
    {
        if ($endTs <= $startTs) return 0;

        $startDate = date('Y-m-d', $startTs);
        $endDate   = date('Y-m-d', $endTs);

        $holidayRows = getHolidayRowsInRange($pdo, $startDate, $endDate, $department, $line);
        if (empty($holidayRows)) return 0;

        // Susun map tanggal → 'ALL' (libur sehari penuh) atau daftar nama shift.
        $holidayMap = []; // ['2026-09-15' => 'ALL' | ['Shift 1', 'Shift 2']]
        foreach ($holidayRows as $row) {
            $d = $row['holiday_date'];
            if ($row['shifts'] === null || trim($row['shifts']) === '') {
                $holidayMap[$d] = 'ALL';
                continue;
            }
            if (($holidayMap[$d] ?? null) === 'ALL') continue; // sudah full day, tidak perlu ditambah
            $shiftNames = array_map('trim', explode(',', $row['shifts']));
            $holidayMap[$d] = array_unique(array_merge($holidayMap[$d] ?? [], $shiftNames));
        }

        $subtracted = 0.0;
        $cursorTs = strtotime($startDate . ' 00:00:00');
        $endBoundTs = strtotime($endDate . ' 00:00:00');

        while ($cursorTs <= $endBoundTs) {
            $curDateStr = date('Y-m-d', $cursorTs);
            $dayStart   = $cursorTs;
            $dayEnd     = strtotime('+1 day', $cursorTs);

            if (isset($holidayMap[$curDateStr])) {
                if ($holidayMap[$curDateStr] === 'ALL') {
                    // Sehari penuh libur → potong overlap [start,end] dengan [dayStart,dayEnd)
                    $overlapStart = max($startTs, $dayStart);
                    $overlapEnd   = min($endTs, $dayEnd);
                    if ($overlapEnd > $overlapStart) {
                        $subtracted += ($overlapEnd - $overlapStart) / 60;
                    }
                } else {
                    // Libur cuma shift tertentu → butuh jendela waktu tiap shift
                    $windows = getShiftWindowsForDate($pdo, $curDateStr);
                    foreach ($holidayMap[$curDateStr] as $shiftName) {
                        if (!isset($windows[$shiftName])) continue; // shift_schedules belum lengkap
                        [$wStart, $wEnd] = $windows[$shiftName];
                        $overlapStart = max($startTs, $wStart);
                        $overlapEnd   = min($endTs, $wEnd);
                        if ($overlapEnd > $overlapStart) {
                            $subtracted += ($overlapEnd - $overlapStart) / 60;
                        }
                    }
                }
            }

            $cursorTs = $dayEnd;
        }

        return $subtracted;
    }
}

if (!function_exists('calculateAdjustedDowntimeMinutes')) {
    /**
     * Fungsi utama: hitung total downtime (menit) dari kumpulan baris
     * e_reports, dengan union interval per mesin + pemotongan waktu libur.
     *
     * @param array<array{department:?string,line:?string,op:?string,machine_name:?string,repair_start:?string,repair_finish:?string}> $reportRows
     */
    function calculateAdjustedDowntimeMinutes(PDO $pdo, array $reportRows): int
    {
        // 1) Kelompokkan per mesin (department + line + op + machine_name —
        //    sama seperti identitas mesin di tabel machine_list). Baris yang
        //    repair_start/repair_finish-nya kosong (masih "belum selesai")
        //    dikecualikan dulu di sini secara alami.
        $groups = [];
        foreach ($reportRows as $r) {
            if (empty($r['repair_start']) || empty($r['repair_finish'])) continue;

            $startTs = strtotime($r['repair_start']);
            $endTs   = strtotime($r['repair_finish']);
            if (!$startTs || !$endTs || $endTs <= $startTs) continue;

            $key = implode('|', [
                $r['department'] ?? '',
                $r['line'] ?? '',
                $r['op'] ?? '',
                $r['machine_name'] ?? '',
            ]);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'department' => $r['department'] ?? null,
                    'line'       => $r['line'] ?? null,
                    'intervals'  => [],
                ];
            }
            $groups[$key]['intervals'][] = [$startTs, $endTs];
        }

        // 2) Union interval per mesin, lalu potong waktu libur per hasil union.
        $totalMinutes = 0.0;
        foreach ($groups as $g) {
            $merged = mergeTimeIntervals($g['intervals']);
            foreach ($merged as [$s, $e]) {
                $grossMinutes   = ($e - $s) / 60;
                $holidayMinutes = subtractHolidayMinutes($pdo, $s, $e, $g['department'], $g['line']);
                $totalMinutes  += max(0, $grossMinutes - $holidayMinutes);
            }
        }

        return (int) round($totalMinutes);
    }
}