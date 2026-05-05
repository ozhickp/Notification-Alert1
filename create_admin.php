<?php
include_once("config.php");

$username = "admin1";
$email = "bhangga_pangestu@yanmar.com";
$password = password_hash("ydn1_6asix", PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (username, email_user, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $email, $password);
$stmt->execute();

echo "Admin Created Successfully!";
?>