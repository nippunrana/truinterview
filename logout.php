<?php
// logout.php - User Logout Page
require_once __DIR__ . '/auth.php';
logoutUser();
header('Location: login.php');
exit();
