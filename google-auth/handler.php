<?php
// google-auth/handler.php - Backend handler for Google Sign-In and Role Selection
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'verify';

try {
    $clientId = getenv('GOOGLE_CLIENT_ID');
    if (empty($clientId)) {
        throw new Exception("Google Client ID is not configured on the server.");
    }

    if ($action === 'verify') {
        $credential = $input['credential'] ?? '';
        if (empty($credential)) {
            throw new Exception("Google credential token is missing.");
        }

        // Verify with Google's tokeninfo API using cURL
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception("Google token verification failed: " . ($curlError ?: "HTTP Code $httpCode"));
        }

        $payload = json_decode($response, true);
        
        // Validate client audience
        if (($payload['aud'] ?? '') !== $clientId) {
            throw new Exception("Token audience mismatch. Ensure GOOGLE_CLIENT_ID is correct.");
        }
        
        // Validate issuer
        $iss = $payload['iss'] ?? '';
        if ($iss !== 'https://accounts.google.com' && $iss !== 'accounts.google.com') {
            throw new Exception("Invalid token issuer: $iss");
        }

        if (empty($payload['email'])) {
            throw new Exception("Google account did not provide an email address.");
        }

        $email = trim(strtolower($payload['email']));
        $fullName = trim($payload['name'] ?? 'Google User');

        // Check if user exists in the database
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // User exists, log them in immediately
            // Update last login
            $stmt = $db->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute(['id' => $user['id']]);

            $_SESSION['user'] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'full_name' => $user['full_name']
            ];

            $redirect = $user['role'] === 'candidate' ? 'candidate-v2/index.php' : 'recruiter/index.php';
            echo json_encode([
                'success' => true,
                'is_new' => false,
                'redirect' => $redirect
            ]);
            exit();
        } else {
            // New user, store temp info in session and prompt for role on client side
            $_SESSION['pending_google_user'] = [
                'email' => $email,
                'full_name' => $fullName
            ];
            echo json_encode([
                'success' => true,
                'is_new' => true
            ]);
            exit();
        }

    } elseif ($action === 'complete_registration') {
        if (empty($_SESSION['pending_google_user'])) {
            throw new Exception("No pending Google registration found. Please try logging in again.");
        }

        $role = trim(strtolower($input['role'] ?? ''));
        $companyName = trim($input['company_name'] ?? '');

        if (!in_array($role, ['candidate', 'recruiter'])) {
            throw new Exception("Invalid role selected.");
        }

        $pending = $_SESSION['pending_google_user'];
        $email = $pending['email'];
        $fullName = $pending['full_name'];

        $db = getDB();

        // Check again to avoid race conditions
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            throw new Exception("Account already created for this email.");
        }

        // Create random secure password hash since they use Google Login
        $randomPassword = bin2hex(random_bytes(32));
        $passwordHash = password_hash($randomPassword, PASSWORD_BCRYPT);

        // Begin transaction
        $db->beginTransaction();

        try {
            // Insert user
            $stmt = $db->prepare("INSERT INTO users (email, password_hash, role, full_name, has_password) VALUES (:email, :password_hash, :role, :full_name, FALSE) RETURNING id");
            $stmt->execute([
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => $role,
                'full_name' => $fullName
            ]);
            $userId = $stmt->fetchColumn();

            // Handle Recruiter Company Setup
            if ($role === 'recruiter') {
                if (empty($companyName)) {
                    $companyName = $fullName . "'s Company";
                }

                // Insert company
                $stmt = $db->prepare("INSERT INTO companies (name, created_by) VALUES (:name, :created_by) RETURNING id");
                $stmt->execute(['name' => $companyName, 'created_by' => $userId]);
                $companyId = $stmt->fetchColumn();

                // Insert company member mapping
                $stmt = $db->prepare("INSERT INTO company_members (company_id, user_id, role) VALUES (:company_id, :user_id, 'admin')");
                $stmt->execute(['company_id' => $companyId, 'user_id' => $userId]);
            }

            $db->commit();
        } catch (Exception $ex) {
            $db->rollBack();
            throw $ex;
        }

        // Log the newly registered user in
        $_SESSION['user'] = [
            'id' => $userId,
            'email' => $email,
            'role' => $role,
            'full_name' => $fullName
        ];

        // Clear pending user session
        unset($_SESSION['pending_google_user']);

        $redirect = $role === 'candidate' ? 'candidate-v2/index.php' : 'recruiter/index.php';
        echo json_encode([
            'success' => true,
            'redirect' => $redirect
        ]);
        exit();

    } else {
        throw new Exception("Invalid action requested.");
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit();
}
