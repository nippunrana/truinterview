<?php
// login.php - User Login Page
require_once __DIR__ . '/auth.php';

// If user is already logged in, redirect to their dashboard
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user['role'] === 'candidate') {
        header('Location: candidate-v2/index.php');
    } elseif ($user['role'] === 'admin') {
        header('Location: admin/index.php');
    } else {
        header('Location: recruiter/index.php');
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        if (empty($email) || empty($password)) {
            throw new Exception("Email and password are required.");
        }

        // Authenticate user
        $user = loginUser($email, $password);

        // Redirect based on role
        if ($user['role'] === 'candidate') {
            header('Location: candidate-v2/index.php');
        } elseif ($user['role'] === 'admin') {
            header('Location: admin/index.php');
        } else {
            header('Location: recruiter/index.php');
        }
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - TruInterview</title>
  <link rel="stylesheet" href="assets/css/auth.css">
  <link rel="stylesheet" href="google-auth/style.css">
</head>
<body class="auth-body">

  <div class="auth-container">
    
    <!-- Hero Block (Left 50%) -->
    <div class="auth-hero-side login-bg">
      <div class="auth-hero-content">
        <div class="hero-text-block">
          <h1 class="hero-text-title">Empowering technical interviews with real-time AI.</h1>
          <p class="hero-text-desc">Whether you are a recruiter assessing top talent or a candidate preparing for your next role, TruInterview provides realistic rounds and deep feedback powered by Gemini.</p>
        </div>
      </div>
    </div>

    <!-- Form Block (Right 50%) -->
    <div class="auth-form-side">
      <!-- Back to Home Button -->
      <a href="index.php" class="auth-back-btn">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
        </svg>
        <span>Back to Home</span>
      </a>

      <div class="auth-form-wrapper">
        
        <div class="auth-header">
          <a href="index.php" class="auth-brand">
            <svg style="width: 32px; height: 32px; color: #06b6d4;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <span class="auth-brand-logo">TruInterview</span>
          </a>
          <h2 class="auth-title">Welcome back</h2>
          <p class="auth-subtitle">Log in to manage technical assessments</p>
        </div>

        <?php if (!empty($error)): ?>
          <div class="auth-error">
            <?php echo htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <!-- Google Sign-In Option -->
        <div id="google-signin-container" class="google-btn-container"></div>
        <div class="auth-divider">or continue with email</div>

        <form method="POST" action="login.php">
          <div class="form-group">
            <label for="email">Email Address or Username</label>
            <input type="text" name="email" id="email" class="form-input" placeholder="e.g. john@example.com or admin_username" required autocomplete="username">
          </div>

          <div class="form-group">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" class="form-input" placeholder="Enter password" required autocomplete="current-password">
          </div>

          <button type="submit" class="btn-auth-submit">Log In</button>
        </form>

        <div class="auth-footer">
          Don't have an account? <a href="register.php" class="auth-link">Sign Up</a>
        </div>

      </div>
    </div>

  </div>

  <script>
    window.GOOGLE_CLIENT_ID = "<?php echo getenv('GOOGLE_CLIENT_ID'); ?>";
  </script>
  <script src="https://accounts.google.com/gsi/client?onload=onGoogleLibraryLoad" async defer></script>
  <script src="google-auth/client.js" defer></script>
</body>
</html>
