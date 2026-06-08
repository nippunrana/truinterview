<?php
// db.php - Database Manager and Session Logic

function loadEnv($path = __DIR__ . '/.env') {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Strip optional surrounding quotes from value
        if (preg_match('/^["\'](.*)["\']$/', $value, $matches)) {
            $value = $matches[1];
        }

        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// Load environment variables
loadEnv();

function getDB() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $requiredKeys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
    foreach ($requiredKeys as $key) {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new Exception("Environment configuration error: Missing required variable '$key' in .env file.");
        }
    }

    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT');
    $dbname = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    try {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

function initSchema() {
    $db = getDB();
    
    // Create users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        email VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        avatar_url VARCHAR(500),
        is_verified BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
        last_login_at TIMESTAMP WITH TIME ZONE
    )");

    // Add custom settings columns to users table
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS custom_trugen_agent_id VARCHAR(100)");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS custom_gemini_api_key VARCHAR(255)");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS model_chat_task VARCHAR(50) DEFAULT 'gemini-3.5-flash'");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS model_vision_task VARCHAR(50) DEFAULT 'gemini-3.5-flash'");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS model_eval_task VARCHAR(50) DEFAULT 'gemini-3.5-flash'");

    // Create companies table
    $db->exec("CREATE TABLE IF NOT EXISTS companies (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        name VARCHAR(200) NOT NULL,
        domain VARCHAR(200),
        logo_url VARCHAR(500),
        created_by UUID REFERENCES users(id) ON DELETE SET NULL,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");

    // Create company_members table
    $db->exec("CREATE TABLE IF NOT EXISTS company_members (
        id SERIAL PRIMARY KEY,
        company_id UUID REFERENCES companies(id) ON DELETE CASCADE,
        user_id UUID REFERENCES users(id) ON DELETE CASCADE,
        role VARCHAR(20) DEFAULT 'admin',
        joined_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");

    // Create interview_templates table
    $db->exec("CREATE TABLE IF NOT EXISTS interview_templates (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        company_id UUID REFERENCES companies(id) ON DELETE CASCADE,
        created_by UUID REFERENCES users(id) ON DELETE SET NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT,
        job_role VARCHAR(150),
        topics JSONB DEFAULT '[]',
        difficulty VARCHAR(20) DEFAULT 'medium',
        duration_minutes INTEGER DEFAULT 30,
        custom_system_prompt TEXT,
        mcq_enabled BOOLEAN DEFAULT TRUE,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");

    // Create interview_links table
    $db->exec("CREATE TABLE IF NOT EXISTS interview_links (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        template_id UUID REFERENCES interview_templates(id) ON DELETE CASCADE,
        company_id UUID REFERENCES companies(id) ON DELETE CASCADE,
        created_by UUID REFERENCES users(id) ON DELETE SET NULL,
        code VARCHAR(12) UNIQUE NOT NULL,
        candidate_email VARCHAR(255),
        candidate_name VARCHAR(150),
        max_attempts INTEGER DEFAULT 1,
        attempts_used INTEGER DEFAULT 0,
        expires_at TIMESTAMP WITH TIME ZONE,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");

    // Create sessions table
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        candidate_name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        current_status VARCHAR(20) DEFAULT 'STARTED',
        mcq_preference VARCHAR(15) DEFAULT 'PENDING',
        trugen_conversation_id VARCHAR(100),
        started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP WITH TIME ZONE,
        final_score JSONB
    )");

    // Add session columns if they don't exist
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS user_id UUID REFERENCES users(id) ON DELETE SET NULL");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS interview_link_id UUID REFERENCES interview_links(id) ON DELETE SET NULL");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS template_id UUID REFERENCES interview_templates(id) ON DELETE SET NULL");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS session_type VARCHAR(20) DEFAULT 'practice'");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS model_chat_task VARCHAR(50) DEFAULT 'gemini-3.5-flash'");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS model_vision_task VARCHAR(50) DEFAULT 'gemini-3.5-flash'");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS model_eval_task VARCHAR(50) DEFAULT 'gemini-3.5-flash'");

    // Create transcripts table
    $db->exec("CREATE TABLE IF NOT EXISTS transcripts (
        id SERIAL PRIMARY KEY,
        session_id UUID REFERENCES sessions(id) ON DELETE CASCADE,
        speaker VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        timestamp TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");

    // Create mcq_questions table
    $db->exec("CREATE TABLE IF NOT EXISTS mcq_questions (
        id SERIAL PRIMARY KEY,
        topic VARCHAR(50) NOT NULL,
        question TEXT NOT NULL,
        option_a TEXT NOT NULL,
        option_b TEXT NOT NULL,
        option_c TEXT NOT NULL,
        option_d TEXT NOT NULL,
        correct_option CHAR(1) NOT NULL
    )");

    // Create candidate_responses table
    $db->exec("CREATE TABLE IF NOT EXISTS candidate_responses (
        id SERIAL PRIMARY KEY,
        session_id UUID REFERENCES sessions(id) ON DELETE CASCADE,
        question_id INT REFERENCES mcq_questions(id),
        selected_option CHAR(1),
        is_correct BOOLEAN,
        submitted_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");
}

function seedQuestions() {
    $db = getDB();
    $count = $db->query("SELECT COUNT(*) FROM mcq_questions")->fetchColumn();
    if ($count == 0) {
        $questions = [
            [
                'topic' => 'JavaScript',
                'question' => 'What is the output of typeof null in JavaScript?',
                'option_a' => 'null',
                'option_b' => 'object',
                'option_c' => 'undefined',
                'option_d' => 'function',
                'correct_option' => 'B'
            ],
            [
                'topic' => 'CSS',
                'question' => 'Which CSS property is used to align grid items vertically inside their cell?',
                'option_a' => 'align-items',
                'option_b' => 'justify-items',
                'option_c' => 'align-content',
                'option_d' => 'grid-gap',
                'correct_option' => 'A'
            ],
            [
                'topic' => 'PHP',
                'question' => 'What does PDO stand for in PHP?',
                'option_a' => 'PHP Database Object',
                'option_b' => 'PHP Data Objects',
                'option_c' => 'Postgres Database Object',
                'option_d' => 'Programmable Data Objects',
                'correct_option' => 'B'
            ]
        ];

        $stmt = $db->prepare("INSERT INTO mcq_questions (topic, question, option_a, option_b, option_c, option_d, correct_option) VALUES (:topic, :question, :option_a, :option_b, :option_c, :option_d, :correct_option)");
        foreach ($questions as $q) {
            $stmt->execute($q);
        }
    }
}

function createSession($name, $email, $userId = null, $linkId = null, $templateId = null, $type = 'practice', $modelChat = 'gemini-3.5-flash', $modelVision = 'gemini-3.5-flash', $modelEval = 'gemini-3.5-flash') {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO sessions (candidate_name, email, user_id, interview_link_id, template_id, session_type, model_chat_task, model_vision_task, model_eval_task) VALUES (:name, :email, :user_id, :link_id, :template_id, :type, :model_chat, :model_vision, :model_eval) RETURNING id");
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'user_id' => $userId,
        'link_id' => $linkId,
        'template_id' => $templateId,
        'type' => $type,
        'model_chat' => $modelChat,
        'model_vision' => $modelVision,
        'model_eval' => $modelEval
    ]);
    return $stmt->fetchColumn();
}

function getSession($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sessions WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function updateSessionConversation($id, $convId) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE sessions SET trugen_conversation_id = :convId, current_status = 'IN_PROGRESS' WHERE id = :id");
    return $stmt->execute(['id' => $id, 'convId' => $convId]);
}

function updateSessionPreference($id, $pref) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE sessions SET mcq_preference = :pref WHERE id = :id");
    return $stmt->execute(['id' => $id, 'pref' => $pref]);
}

function logTranscript($sessionId, $speaker, $message) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO transcripts (session_id, speaker, message) VALUES (:session_id, :speaker, :message)");
    return $stmt->execute(['session_id' => $sessionId, 'speaker' => $speaker, 'message' => $message]);
}

function getTranscripts($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT speaker, message, timestamp FROM transcripts WHERE session_id = :session_id ORDER BY id ASC");
    $stmt->execute(['session_id' => $sessionId]);
    return $stmt->fetchAll();
}

function getSessionByConversationId($convId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sessions WHERE trugen_conversation_id = :convId");
    $stmt->execute(['convId' => $convId]);
    return $stmt->fetch();
}

function getLatestStartedSession() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM sessions WHERE current_status = 'STARTED' AND trugen_conversation_id IS NULL ORDER BY started_at DESC LIMIT 1");
    return $stmt->fetch();
}

function getNextUnansweredQuestion($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM mcq_questions WHERE id NOT IN (SELECT question_id FROM candidate_responses WHERE session_id = :session_id) ORDER BY id ASC LIMIT 1");
    $stmt->execute(['session_id' => $sessionId]);
    return $stmt->fetch();
}

function saveCandidateResponse($sessionId, $questionId, $selectedOption, $isCorrect) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO candidate_responses (session_id, question_id, selected_option, is_correct) VALUES (:session_id, :question_id, :selected_option, :is_correct)");
    return $stmt->execute([
        'session_id' => $sessionId,
        'question_id' => $questionId,
        'selected_option' => $selectedOption,
        'is_correct' => $isCorrect ? 1 : 0
    ]);
}

function getMCQQuestionById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM mcq_questions WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function getCandidateResponses($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT cr.*, mq.topic, mq.question, mq.option_a, mq.option_b, mq.option_c, mq.option_d, mq.correct_option FROM candidate_responses cr JOIN mcq_questions mq ON cr.question_id = mq.id WHERE cr.session_id = :session_id ORDER BY cr.id ASC");
    $stmt->execute(['session_id' => $sessionId]);
    return $stmt->fetchAll();
}

function getInterviewLinkByCode($code) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM interview_links WHERE UPPER(code) = UPPER(:code) AND status = 'active'");
    $stmt->execute(['code' => trim($code)]);
    return $stmt->fetch();
}

function incrementLinkAttempts($id) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE interview_links SET attempts_used = attempts_used + 1 WHERE id = :id");
    return $stmt->execute(['id' => $id]);
}

function getRecruiterCompany($recruiterId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT c.* FROM companies c JOIN company_members cm ON c.id = cm.company_id WHERE cm.user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $recruiterId]);
    return $stmt->fetch();
}

function createInterviewTemplate($companyId, $userId, $title, $description, $jobRole, $topics, $difficulty, $duration, $customPrompt, $mcqEnabled) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO interview_templates (company_id, created_by, title, description, job_role, topics, difficulty, duration_minutes, custom_system_prompt, mcq_enabled) VALUES (:company_id, :created_by, :title, :description, :job_role, :topics, :difficulty, :duration, :custom_prompt, :mcq_enabled) RETURNING id");
    $stmt->execute([
        'company_id' => $companyId,
        'created_by' => $userId,
        'title' => $title,
        'description' => $description,
        'job_role' => $jobRole,
        'topics' => json_encode($topics),
        'difficulty' => $difficulty,
        'duration' => (int)$duration,
        'custom_prompt' => $customPrompt,
        'mcq_enabled' => $mcqEnabled ? 1 : 0
    ]);
    return $stmt->fetchColumn();
}

function listInterviewTemplates($companyId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM interview_templates WHERE company_id = :company_id AND is_active = TRUE ORDER BY created_at DESC");
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function createInterviewLink($templateId, $companyId, $userId, $code, $candidateEmail, $candidateName, $maxAttempts, $expiresAt) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO interview_links (template_id, company_id, created_by, code, candidate_email, candidate_name, max_attempts, expires_at) VALUES (:template_id, :company_id, :created_by, :code, :candidate_email, :candidate_name, :max_attempts, :expires_at) RETURNING id");
    $stmt->execute([
        'template_id' => $templateId,
        'company_id' => $companyId,
        'created_by' => $userId,
        'code' => strtoupper(trim($code)),
        'candidate_email' => empty($candidateEmail) ? null : trim($candidateEmail),
        'candidate_name' => empty($candidateName) ? null : trim($candidateName),
        'max_attempts' => (int)$maxAttempts,
        'expires_at' => empty($expiresAt) ? null : $expiresAt
    ]);
    return $stmt->fetchColumn();
}

function listInterviewLinks($companyId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT il.*, it.title as template_title FROM interview_links il JOIN interview_templates it ON il.template_id = it.id WHERE il.company_id = :company_id ORDER BY il.created_at DESC");
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function listCandidateResults($companyId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT s.*, it.title as template_title, il.code as link_code FROM sessions s JOIN interview_links il ON s.interview_link_id = il.id JOIN interview_templates it ON s.template_id = it.id WHERE il.company_id = :company_id ORDER BY s.started_at DESC");
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function listCandidateHistory($candidateId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT s.*, it.title as template_title FROM sessions s LEFT JOIN interview_templates it ON s.template_id = it.id WHERE s.user_id = :candidate_id ORDER BY s.started_at DESC");
    $stmt->execute(['candidate_id' => $candidateId]);
    return $stmt->fetchAll();
}

function getCandidateStats($candidateId) {
    $db = getDB();
    
    // Total sessions
    $stmt = $db->prepare("SELECT COUNT(*) FROM sessions WHERE user_id = :candidate_id");
    $stmt->execute(['candidate_id' => $candidateId]);
    $totalSessions = (int)$stmt->fetchColumn();

    // Average score (logic/problem solving/communication overall rating)
    $stmt = $db->prepare("SELECT final_score FROM sessions WHERE user_id = :candidate_id AND current_status = 'COMPLETED' AND final_score IS NOT NULL");
    $stmt->execute(['candidate_id' => $candidateId]);
    $scores = $stmt->fetchAll();
    
    $avgScore = 0.0;
    $completedCount = count($scores);
    if ($completedCount > 0) {
        $totalVal = 0.0;
        foreach ($scores as $s) {
            $data = json_decode($s['final_score'], true);
            $comm = (float)($data['communication_score'] ?? 0);
            $prob = (float)($data['problem_solving_score'] ?? 0);
            $qual = (float)($data['code_quality_score'] ?? 0);
            $totalVal += ($comm + $prob + $qual) / 3.0;
        }
        $avgScore = round($totalVal / $completedCount, 1);
    }

    return [
        'total_sessions' => $totalSessions,
        'completed_sessions' => $completedCount,
        'average_score' => $avgScore
    ];
}

function getRecruiterStats($companyId) {
    $db = getDB();

    // Total links
    $stmt = $db->prepare("SELECT COUNT(*) FROM interview_links WHERE company_id = :company_id");
    $stmt->execute(['company_id' => $companyId]);
    $totalLinks = (int)$stmt->fetchColumn();

    // Total sessions
    $stmt = $db->prepare("SELECT COUNT(*) FROM sessions s JOIN interview_links il ON s.interview_link_id = il.id WHERE il.company_id = :company_id");
    $stmt->execute(['company_id' => $companyId]);
    $totalSessions = (int)$stmt->fetchColumn();

    // Completed sessions
    $stmt = $db->prepare("SELECT COUNT(*) FROM sessions s JOIN interview_links il ON s.interview_link_id = il.id WHERE il.company_id = :company_id AND s.current_status = 'COMPLETED'");
    $stmt->execute(['company_id' => $companyId]);
    $completedSessions = (int)$stmt->fetchColumn();

    // Avg Score
    $stmt = $db->prepare("SELECT s.final_score FROM sessions s JOIN interview_links il ON s.interview_link_id = il.id WHERE il.company_id = :company_id AND s.current_status = 'COMPLETED' AND s.final_score IS NOT NULL");
    $stmt->execute(['company_id' => $companyId]);
    $scores = $stmt->fetchAll();

    $avgScore = 0.0;
    if (count($scores) > 0) {
        $totalVal = 0.0;
        foreach ($scores as $s) {
            $data = json_decode($s['final_score'], true);
            $comm = (float)($data['communication_score'] ?? 0);
            $prob = (float)($data['problem_solving_score'] ?? 0);
            $qual = (float)($data['code_quality_score'] ?? 0);
            $totalVal += ($comm + $prob + $qual) / 3.0;
        }
        $avgScore = round($totalVal / count($scores), 1);
    }

    return [
        'total_links' => $totalLinks,
        'total_sessions' => $totalSessions,
        'completed_sessions' => $completedSessions,
        'average_score' => $avgScore
    ];
}

function getSessionApiKey($session) {
    if (is_string($session)) {
        $session = getSession($session);
    }
    if (!$session) {
        return null;
    }
    $db = getDB();
    if (!empty($session['interview_link_id'])) {
        $stmt = $db->prepare("SELECT u.custom_gemini_api_key FROM users u JOIN interview_links il ON u.id = il.created_by WHERE il.id = :id");
        $stmt->execute(['id' => $session['interview_link_id']]);
        $key = $stmt->fetchColumn();
        if (!empty($key)) {
            return $key;
        }
    } elseif (!empty($session['user_id'])) {
        $stmt = $db->prepare("SELECT custom_gemini_api_key FROM users WHERE id = :id");
        $stmt->execute(['id' => $session['user_id']]);
        $key = $stmt->fetchColumn();
        if (!empty($key)) {
            return $key;
        }
    }
    return null;
}

function getSessionUserSettings($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sessions WHERE id = :id");
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();
    if (!$session) {
        return null;
    }
    $recruiterId = null;
    if (!empty($session['interview_link_id'])) {
        $stmt = $db->prepare("SELECT created_by FROM interview_links WHERE id = :id");
        $stmt->execute(['id' => $session['interview_link_id']]);
        $recruiterId = $stmt->fetchColumn();
    }
    if ($recruiterId) {
        $stmt = $db->prepare("SELECT custom_trugen_agent_id, custom_gemini_api_key, model_chat_task, model_vision_task, model_eval_task FROM users WHERE id = :id");
        $stmt->execute(['id' => $recruiterId]);
        return $stmt->fetch();
    }
    return null;
}

// Auto-init and seed tables on load
try {
    initSchema();
    seedQuestions();
} catch (Exception $e) {
    // Fail silently in imports, let endpoints report errors if they happen
}
