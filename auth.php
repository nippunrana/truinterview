<?php
// auth.php - Authentication Helper Functions
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Hash password using bcrypt
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Register a new user and return user info
 */
function registerUser($email, $password, $fullName, $role) {
    $db = getDB();
    $email = trim(strtolower($email));
    $role = strtolower($role);

    if (!in_array($role, ['candidate', 'recruiter'])) {
        throw new Exception("Invalid role specified.");
    }

    // Check if user already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        throw new Exception("An account with this email already exists.");
    }

    $passwordHash = hashPassword($password);

    $stmt = $db->prepare("INSERT INTO users (email, password_hash, role, full_name) VALUES (:email, :password_hash, :role, :full_name) RETURNING id, email, role, full_name");
    $stmt->execute([
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => $role,
        'full_name' => $fullName
    ]);
    
    return $stmt->fetch();
}

/**
 * Authenticate and log in user
 */
function loginUser($email, $password) {
    $db = getDB();
    $email = trim(strtolower($email));

    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !verifyPassword($password, $user['password_hash'])) {
        throw new Exception("Invalid email or password.");
    }

    // Update last login
    $stmt = $db->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->execute(['id' => $user['id']]);

    // Set session data
    $_SESSION['user'] = [
        'id' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'full_name' => $user['full_name']
    ];

    return $_SESSION['user'];
}

/**
 * Log out user
 */
function logoutUser() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['user']);
    session_destroy();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/**
 * Get current logged-in user
 */
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

/**
 * Route guard: require user to be logged in (and optionally have specific roles)
 */
function requireAuth($allowedRoles = []) {
    if (!isLoggedIn()) {
        header('Location: /truinterview/login.php');
        exit();
    }

    $user = getCurrentUser();
    if (!empty($allowedRoles) && !in_array($user['role'], $allowedRoles)) {
        // Forbidden or redirect to correct role dashboard
        if ($user['role'] === 'candidate') {
            header('Location: /truinterview/candidate/index.php');
        } else {
            header('Location: /truinterview/recruiter/index.php');
        }
        exit();
    }
}
