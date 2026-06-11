<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

$db = getDB();

// 1. Check candidate_profiles columns
$stmt1 = $db->query("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_name = 'candidate_profiles' ORDER BY ordinal_position");
$profilesCols = $stmt1->fetchAll(PDO::FETCH_ASSOC);

// 2. Check sessions columns
$stmt2 = $db->query("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_name = 'sessions' ORDER BY ordinal_position");
$sessionsCols = $stmt2->fetchAll(PDO::FETCH_ASSOC);

@unlink(__FILE__);

echo json_encode([
    'candidate_profiles_columns' => $profilesCols,
    'sessions_columns' => $sessionsCols
], JSON_PRETTY_PRINT);
