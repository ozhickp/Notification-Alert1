<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send_reminder.php';

$startTime = microtime(true);

try {
    $updateStmt = $pdo->prepare("
        UPDATE schedules
        SET remaining_day = DATEDIFF(change_date_plan, CURDATE())
        WHERE change_date_plan IS NOT NULL
    ");
    $updateStmt->execute();
    $updatedRows = $updateStmt->rowCount();
} catch (\Exception $e) {
    exit(1);
}

processThirtyDayReminders($pdo);
processSevenDayReminders($pdo);
processOverdueReminders($pdo);

$elapsed = round(microtime(true) - $startTime, 5);
