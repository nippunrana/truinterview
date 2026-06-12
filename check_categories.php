<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as count FROM categories");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Verification: " . $row['count'] . " categories found in the database.";
} catch (Exception $e) {
    echo "Verification Error: " . $e->getMessage();
}

@unlink(__FILE__);
