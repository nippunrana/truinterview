<?php
// register.php - User Registration Page
require_once __DIR__ . '/auth.php';

// If user is already logged in, redirect to their dashboard
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user['role'] === 'candidate') {
        header('Location: candidate/index.php');
    } else {
        header('Location: recruiter/index.php');
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $fullName = $_POST['full_name'] ?? '';
    $role = $_POST['role'] ?? 'candidate';
    $companyName = $_POST['company_name'] ?? '';

    try {
        if (empty($email) || empty($password) || empty($fullName)) {
            throw new Exception("All fields are required.");
        }
        if ($password !== $confirmPassword) {
            throw new Exception("Passwords do not match.");
        }
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters long.");
        }

        // Register user in users table
        $userRow = registerUser($email, $password, $fullName, $role);

        // If recruiter, create company profile
        if ($role === 'recruiter') {
            if (empty($companyName)) {
                $companyName = $fullName . "'s Company";
            }
            $db = getDB();
            
            // Insert company
            $stmt = $db->prepare("INSERT INTO companies (name, created_by) VALUES (:name, :created_by) RETURNING id");
            $stmt->execute(['name' => $companyName, 'created_by' => $userRow['id']]);
            $companyId = $stmt->fetchColumn();

            // Insert member mapping
            $stmt = $db->prepare("INSERT INTO company_members (company_id, user_id, role) VALUES (:company_id, :user_id, 'admin')");
            $stmt->execute(['company_id' => $companyId, 'user_id' => $userRow['id']]);
        }

        // Automatically log the user in
        loginUser($email, $password);

        // Redirect appropriately
        if ($role === 'candidate') {
            header('Location: candidate/index.php');
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
  <title>Register - TruInterview</title>
  <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-body">

  <div class="auth-container">
    
    <!-- Hero Block (Left 50%) -->
    <div class="auth-hero-side">
      <div class="auth-hero-content">
        <div id="hero-text-candidate" class="hero-text-block">
          <h1 class="hero-text-title">Supercharge your interview prep with real-time AI.</h1>
          <p class="hero-text-desc">Practice realistic coding and conversational rounds, track your scores, and land your dream job with confidence.</p>
        </div>
        <div id="hero-text-recruiter" class="hero-text-block" style="display: none;">
          <h1 class="hero-text-title">Identify top technical talent in minutes, not hours.</h1>
          <p class="hero-text-desc">Create custom assessment templates, generate candidate-specific invites, and review depth feedback screens powered by Gemini.</p>
        </div>
      </div>
    </div>

    <!-- Form Block (Right 50%) -->
    <div class="auth-form-side">
      <div class="auth-form-wrapper">
        
        <div class="auth-header">
          <a href="index.php" class="auth-brand">
            <svg style="width: 32px; height: 32px; color: #06b6d4;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <span class="auth-brand-logo">TruInterview</span>
          </a>
          <h2 class="auth-title">Create your account</h2>
          <p class="auth-subtitle">Get started with AI tech assessments</p>
        </div>

        <?php if (!empty($error)): ?>
          <div class="auth-error">
            <?php echo htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
          
          <!-- Role selector toggle -->
          <div class="role-selector-container">
            <button type="button" id="role-candidate-btn" class="role-btn active" onclick="setRole('candidate')">Candidate</button>
            <button type="button" id="role-recruiter-btn" class="role-btn" onclick="setRole('recruiter')">Recruiter / Employer</button>
          </div>
          
          <!-- Hidden input for role -->
          <input type="hidden" name="role" id="role-input" value="candidate">

          <div class="form-group">
            <label for="full_name">Full Name</label>
            <input type="text" name="full_name" id="full_name" class="form-input" placeholder="e.g. John Doe" required autocomplete="name">
          </div>

          <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" name="email" id="email" class="form-input" placeholder="e.g. john@example.com" required autocomplete="email">
          </div>

          <!-- Extra recruiter field (initially hidden) -->
          <div class="form-group" id="company-group" style="display: none;">
            <label for="company_name">Company Name</label>
            <input type="text" name="company_name" id="company_name" class="form-input" placeholder="e.g. Acme Corp">
          </div>

          <div class="form-group">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" class="form-input" placeholder="Min. 6 characters" required autocomplete="new-password">
          </div>

          <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Re-enter password" required autocomplete="new-password">
          </div>

          <button type="submit" class="btn-auth-submit">Create Account</button>
        </form>

        <div class="auth-footer">
          Already have an account? <a href="login.php" class="auth-link">Log In</a>
        </div>

      </div>
    </div>

  </div>

  <script>
    function setRole(role) {
      document.getElementById('role-input').value = role;
      
      const candidateBtn = document.getElementById('role-candidate-btn');
      const recruiterBtn = document.getElementById('role-recruiter-btn');
      const companyGroup = document.getElementById('company-group');
      const companyInput = document.getElementById('company_name');
      const textCandidate = document.getElementById('hero-text-candidate');
      const textRecruiter = document.getElementById('hero-text-recruiter');
      
      if (role === 'candidate') {
        candidateBtn.classList.add('active');
        recruiterBtn.classList.remove('active');
        companyGroup.style.display = 'none';
        companyInput.removeAttribute('required');
        
        textCandidate.style.display = 'block';
        textRecruiter.style.display = 'none';
      } else {
        candidateBtn.classList.remove('active');
        recruiterBtn.classList.add('active');
        companyGroup.style.display = 'flex';
        companyInput.setAttribute('required', 'required');
        
        textCandidate.style.display = 'none';
        textRecruiter.style.display = 'block';
      }
    }
  </script>
</body>
</html>
