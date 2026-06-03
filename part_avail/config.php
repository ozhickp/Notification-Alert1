<?php

$host = 'db.yadin.com';
$dbname = 'db_notif_alert';
$username = 'ozick';
$password = 'Yadin.5678';

$conn = new mysqli("localhost", "root", "", "db_notif_alert");

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

function formatDate($date)
{
    return $date ? date('d M Y', strtotime($date)) : '-';
}

function calculateRemainingDays($changeDatePlan)
{
    if (!$changeDatePlan) return 0;

    $today = new DateTime('today');
    $plan  = new DateTime($changeDatePlan);

    $interval = $today->diff($plan);
    $days     = $interval->days;

    return $plan >= $today ? $days : -$days;
}

function calculateRemainingMonths($changeDatePlan)
{
    if (!$changeDatePlan) return 0;
    $today = new DateTime('today');
    $plan = new DateTime($changeDatePlan);
    $interval = $today->diff($plan);
    $months = ($interval->y * 12) + $interval->m;
    return $plan > $today ? $months : -$months;
}
