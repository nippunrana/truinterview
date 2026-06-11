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
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS model_chat_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS model_vision_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS model_eval_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS model_optimizer_task VARCHAR(50) DEFAULT 'gemini-3.5-flash'");
    $db->exec("ALTER TABLE users ALTER COLUMN model_chat_task SET DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE users ALTER COLUMN model_vision_task SET DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE users ALTER COLUMN model_eval_task SET DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS resume_path TEXT");
    $db->exec("ALTER TABLE users ALTER COLUMN resume_path TYPE TEXT");


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
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS model_chat_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS model_vision_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS model_eval_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE sessions ALTER COLUMN model_chat_task SET DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE sessions ALTER COLUMN model_vision_task SET DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE sessions ALTER COLUMN model_eval_task SET DEFAULT 'gemini-3.1-flash-lite'");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS conduct_warnings INTEGER DEFAULT 0");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS closure_reason VARCHAR(50)");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS profile_id INTEGER REFERENCES candidate_profiles(id) ON DELETE SET NULL");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS level INTEGER DEFAULT 0");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS role_title_id VARCHAR(150)");

    // Reorder columns in sessions if level/role_title_id are not next to current_status
    try {
        $stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'sessions' ORDER BY ordinal_position");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($cols)) {
            $currentStatusIdx = array_search('current_status', $cols);
            $levelIdx = array_search('level', $cols);
            $roleTitleIdIdx = array_search('role_title_id', $cols);
            
            if ($currentStatusIdx !== false && $levelIdx !== false && $roleTitleIdIdx !== false &&
                ($levelIdx !== $currentStatusIdx + 1 || $roleTitleIdIdx !== $levelIdx + 1)) {
                
                // Drop constraints pointing to sessions
                $db->exec("ALTER TABLE transcripts DROP CONSTRAINT IF EXISTS transcripts_session_id_fkey");
                $db->exec("ALTER TABLE candidate_responses DROP CONSTRAINT IF EXISTS candidate_responses_session_id_fkey");
                $db->exec("ALTER TABLE proctor_alerts DROP CONSTRAINT IF EXISTS proctor_alerts_session_id_fkey");
                
                // Rename sessions to sessions_old
                $db->exec("ALTER TABLE sessions RENAME TO sessions_old");
                
                // Create sessions table in correct order
                $db->exec("CREATE TABLE sessions (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    candidate_name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    current_status VARCHAR(20) DEFAULT 'STARTED',
                    level INTEGER DEFAULT 0,
                    role_title_id VARCHAR(150),
                    mcq_preference VARCHAR(15) DEFAULT 'PENDING',
                    trugen_conversation_id VARCHAR(100),
                    started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    completed_at TIMESTAMP WITH TIME ZONE,
                    final_score JSONB,
                    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
                    interview_link_id UUID REFERENCES interview_links(id) ON DELETE SET NULL,
                    template_id UUID REFERENCES interview_templates(id) ON DELETE SET NULL,
                    session_type VARCHAR(20) DEFAULT 'practice',
                    model_chat_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite',
                    model_vision_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite',
                    model_eval_task VARCHAR(50) DEFAULT 'gemini-3.1-flash-lite',
                    conduct_warnings INTEGER DEFAULT 0,
                    closure_reason VARCHAR(50),
                    profile_id INTEGER REFERENCES candidate_profiles(id) ON DELETE SET NULL
                )");
                
                // Copy data from sessions_old to sessions
                $db->exec("INSERT INTO sessions (id, candidate_name, email, current_status, level, role_title_id, mcq_preference, trugen_conversation_id, started_at, completed_at, final_score, user_id, interview_link_id, template_id, session_type, model_chat_task, model_vision_task, model_eval_task, conduct_warnings, closure_reason, profile_id)
                    SELECT id, candidate_name, email, current_status, level, role_title_id, mcq_preference, trugen_conversation_id, started_at, completed_at, final_score, user_id, interview_link_id, template_id, session_type, model_chat_task, model_vision_task, model_eval_task, conduct_warnings, closure_reason, profile_id
                    FROM sessions_old");
                
                // Re-add constraints pointing to sessions
                $db->exec("ALTER TABLE transcripts ADD CONSTRAINT transcripts_session_id_fkey FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE");
                $db->exec("ALTER TABLE candidate_responses ADD CONSTRAINT candidate_responses_session_id_fkey FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE");
                $db->exec("ALTER TABLE proctor_alerts ADD CONSTRAINT proctor_alerts_session_id_fkey FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE");
                
                // Drop old table
                $db->exec("DROP TABLE sessions_old");
            }
        }
    } catch (Exception $e) {
        // Fail silently
    }

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

    // Create proctor_alerts table
    $db->exec("CREATE TABLE IF NOT EXISTS proctor_alerts (
        id SERIAL PRIMARY KEY,
        session_id UUID REFERENCES sessions(id) ON DELETE CASCADE,
        alert_type VARCHAR(50) NOT NULL,
        severity VARCHAR(20) DEFAULT 'warning',
        client_details JSONB,
        snapshot_path VARCHAR(500),
        ai_verdict TEXT,
        ai_confirmed BOOLEAN,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");

    // Create candidate_profiles table for V2 role-based profiles
    $db->exec("CREATE TABLE IF NOT EXISTS candidate_profiles (
        id SERIAL PRIMARY KEY,
        user_id UUID REFERENCES users(id) ON DELETE CASCADE,
        role_title VARCHAR(150) NOT NULL,
        role_title_id VARCHAR(150),
        optimized_resume_path TEXT,
        text_version TEXT,
        needs_human_review BOOLEAN DEFAULT FALSE,
        resume_data JSONB,
        level INTEGER DEFAULT 0,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Ensure column exists for existing tables
    $db->exec("ALTER TABLE candidate_profiles ADD COLUMN IF NOT EXISTS role_title_id VARCHAR(150)");
    $db->exec("ALTER TABLE candidate_profiles ADD COLUMN IF NOT EXISTS text_version TEXT");
    $db->exec("ALTER TABLE candidate_profiles ADD COLUMN IF NOT EXISTS needs_human_review BOOLEAN DEFAULT FALSE");
    $db->exec("ALTER TABLE candidate_profiles ADD COLUMN IF NOT EXISTS resume_data JSONB");
    $db->exec("ALTER TABLE candidate_profiles ADD COLUMN IF NOT EXISTS level INTEGER DEFAULT 0");

    // Reorder columns in candidate_profiles if role_title_id is not next to role_title, or level is not next to role_title_id
    try {
        $stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'candidate_profiles' ORDER BY ordinal_position");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($cols)) {
            $roleTitleIdx = array_search('role_title', $cols);
            $roleTitleIdIdx = array_search('role_title_id', $cols);
            $levelIdx = array_search('level', $cols);
            
            $needsReorder = false;
            if ($roleTitleIdx !== false && $roleTitleIdIdx !== false && $roleTitleIdIdx !== $roleTitleIdx + 1) {
                $needsReorder = true;
            }
            if ($roleTitleIdIdx !== false && $levelIdx !== false && $levelIdx !== $roleTitleIdIdx + 1) {
                $needsReorder = true;
            }
            
            if ($needsReorder) {
                // Disassociate the sequence from the old column
                $db->exec("ALTER SEQUENCE IF EXISTS candidate_profiles_id_seq OWNED BY NONE");
                
                // Drop constraint on sessions table pointing to candidate_profiles
                $db->exec("ALTER TABLE sessions DROP CONSTRAINT IF EXISTS sessions_profile_id_fkey");
                
                // Rename candidate_profiles to candidate_profiles_old
                $db->exec("ALTER TABLE candidate_profiles RENAME TO candidate_profiles_old");
                
                // Create the table candidate_profiles with correct column order using the existing sequence
                $db->exec("CREATE TABLE candidate_profiles (
                    id INTEGER DEFAULT nextval('candidate_profiles_id_seq') PRIMARY KEY,
                    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
                    role_title VARCHAR(150) NOT NULL,
                    role_title_id VARCHAR(150),
                    level INTEGER DEFAULT 0,
                    optimized_resume_path TEXT,
                    text_version TEXT,
                    needs_human_review BOOLEAN DEFAULT FALSE,
                    resume_data JSONB,
                    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
                )");
                
                // Associate the sequence with the new table's id column
                $db->exec("ALTER SEQUENCE IF EXISTS candidate_profiles_id_seq OWNED BY candidate_profiles.id");
                
                // Copy data from candidate_profiles_old to candidate_profiles
                $db->exec("INSERT INTO candidate_profiles (id, user_id, role_title, role_title_id, level, optimized_resume_path, text_version, needs_human_review, resume_data, created_at)
                    SELECT id, user_id, role_title, role_title_id, level, optimized_resume_path, text_version, needs_human_review, resume_data, created_at
                    FROM candidate_profiles_old");
                
                // Restore/sync the sequence value
                $db->exec("SELECT setval('candidate_profiles_id_seq', COALESCE((SELECT MAX(id) FROM candidate_profiles), 1), true)");
                
                // Re-add foreign key constraint to sessions
                $db->exec("ALTER TABLE sessions ADD CONSTRAINT sessions_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES candidate_profiles(id) ON DELETE SET NULL");
                
                // Drop the old table
                $db->exec("DROP TABLE candidate_profiles_old");
            }
        }
    } catch (Exception $e) {
        // Fail silently
    }

    // Backfill role_title_id for existing candidate profiles
    try {
        $stmt = $db->query("SELECT id, role_title FROM candidate_profiles WHERE role_title_id IS NULL OR role_title_id = ''");
        $unfilled = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($unfilled)) {
            $updateStmt = $db->prepare("UPDATE candidate_profiles SET role_title_id = :role_title_id WHERE id = :id");
            foreach ($unfilled as $row) {
                $rtId = preg_replace('/\s+/', '-', $row['role_title']);
                $rtId = preg_replace('/[^a-zA-Z0-9\-]/', '', $rtId);
                $rtId = preg_replace('/-+/', '-', $rtId);
                $rtId = trim($rtId, '-');
                $updateStmt->execute(['role_title_id' => $rtId, 'id' => $row['id']]);
            }
        }
    } catch (Exception $e) {
        // Fail silently
    }
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

function createSession($name, $email, $userId = null, $linkId = null, $templateId = null, $type = 'practice', $modelChat = 'gemini-3.1-flash-lite', $modelVision = 'gemini-3.1-flash-lite', $modelEval = 'gemini-3.1-flash-lite', $profileId = null) {
    $db = getDB();
    $level = 0;
    $roleTitleId = null;
    if ($profileId) {
        $stmtProfile = $db->prepare("SELECT level, role_title_id FROM candidate_profiles WHERE id = :profile_id");
        $stmtProfile->execute(['profile_id' => $profileId]);
        $profile = $stmtProfile->fetch();
        if ($profile) {
            $level = isset($profile['level']) ? (int)$profile['level'] : 0;
            $roleTitleId = $profile['role_title_id'] ?? null;
        }
    }
    $stmt = $db->prepare("INSERT INTO sessions (candidate_name, email, user_id, interview_link_id, template_id, session_type, model_chat_task, model_vision_task, model_eval_task, profile_id, level, role_title_id) VALUES (:name, :email, :user_id, :link_id, :template_id, :type, :model_chat, :model_vision, :model_eval, :profile_id, :level, :role_title_id) RETURNING id");
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'user_id' => $userId,
        'link_id' => $linkId,
        'template_id' => $templateId,
        'type' => $type,
        'model_chat' => $modelChat,
        'model_vision' => $modelVision,
        'model_eval' => $modelEval,
        'profile_id' => $profileId,
        'level' => $level,
        'role_title_id' => $roleTitleId
    ]);
    return $stmt->fetchColumn();
}

function getSession($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sessions WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function getSessionConductWarnings($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT conduct_warnings FROM sessions WHERE id = :id");
    $stmt->execute(['id' => $sessionId]);
    return (int)$stmt->fetchColumn();
}

function incrementConductWarning($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE sessions SET conduct_warnings = conduct_warnings + 1 WHERE id = :id RETURNING conduct_warnings");
    $stmt->execute(['id' => $sessionId]);
    return (int)$stmt->fetchColumn();
}

function closeSessionForMisconduct($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE sessions SET current_status = 'TERMINATING', closure_reason = 'misconduct' WHERE id = :id");
    return $stmt->execute(['id' => $sessionId]);
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
    if (is_array($message)) {
        $message = implode(" ", $message);
    }
    $message = trim((string)$message);
    if ($message === '') {
        return false;
    }
    $db = getDB();
    
    // De-duplicate consecutive identical messages for the same speaker in this session
    $stmt = $db->prepare("SELECT speaker, message FROM transcripts WHERE session_id = :session_id ORDER BY id DESC LIMIT 1");
    $stmt->execute(['session_id' => $sessionId]);
    $last = $stmt->fetch();
    if ($last && $last['speaker'] === $speaker && trim($last['message']) === $message) {
        return true;
    }
    
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

function getInterviewTemplate($id) {
    if (empty($id)) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM interview_templates WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
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

function saveProctorAlert($sessionId, $type, $severity, $clientDetails, $snapshotPath, $aiVerdict, $aiConfirmed) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO proctor_alerts (session_id, alert_type, severity, client_details, snapshot_path, ai_verdict, ai_confirmed) VALUES (:session_id, :alert_type, :severity, :client_details, :snapshot_path, :ai_verdict, :ai_confirmed) RETURNING id");
    $stmt->execute([
        'session_id' => $sessionId,
        'alert_type' => $type,
        'severity' => $severity,
        'client_details' => is_array($clientDetails) ? json_encode($clientDetails) : $clientDetails,
        'snapshot_path' => $snapshotPath,
        'ai_verdict' => $aiVerdict,
        'ai_confirmed' => $aiConfirmed ? 'true' : 'false'
    ]);
    return $stmt->fetchColumn();
}

function getProctorAlerts($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM proctor_alerts WHERE session_id = :session_id ORDER BY created_at ASC");
    $stmt->execute(['session_id' => $sessionId]);
    $alerts = $stmt->fetchAll();
    foreach ($alerts as &$a) {
        if (!empty($a['client_details'])) {
            $a['client_details'] = json_decode($a['client_details'], true);
        }
    }
    return $alerts;
}

function getProctorAlertCount($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM proctor_alerts WHERE session_id = :session_id AND ai_confirmed = TRUE");
    $stmt->execute(['session_id' => $sessionId]);
    return (int)$stmt->fetchColumn();
}

function getProctorSummary($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT alert_type, COUNT(*) as count FROM proctor_alerts WHERE session_id = :session_id GROUP BY alert_type");
    $stmt->execute(['session_id' => $sessionId]);
    return $stmt->fetchAll();
}

function getCandidateResumes($rawPath) {
    if (empty($rawPath)) {
        return [];
    }
    $decoded = json_decode($rawPath, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        usort($decoded, function($a, $b) {
            return ($b['date'] ?? 0) - ($a['date'] ?? 0);
        });
        return $decoded;
    }
    return [
        [
            'path' => $rawPath,
            'date' => time()
        ]
    ];
}

// V2 Candidate Dashboard Helpers
function getCandidateProfiles($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM candidate_profiles WHERE user_id = :user_id ORDER BY created_at ASC");
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

function getCandidateProfile($profileId, $userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM candidate_profiles WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $profileId, 'user_id' => $userId]);
    return $stmt->fetch();
}

function createCandidateProfile($userId, $roleTitle) {
    $db = getDB();
    // Check limit
    $stmt = $db->prepare("SELECT COUNT(*) FROM candidate_profiles WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    if ($stmt->fetchColumn() >= 3) {
        return false; // Max 3 profiles
    }
    
    // Generate role_title_id from role_title
    $roleTitleId = preg_replace('/\s+/', '-', $roleTitle);
    $roleTitleId = preg_replace('/[^a-zA-Z0-9\-]/', '', $roleTitleId);
    $roleTitleId = preg_replace('/-+/', '-', $roleTitleId);
    $roleTitleId = trim($roleTitleId, '-');

    $stmt = $db->prepare("INSERT INTO candidate_profiles (user_id, role_title, role_title_id) VALUES (:user_id, :role_title, :role_title_id) RETURNING id");
    $stmt->execute(['user_id' => $userId, 'role_title' => $roleTitle, 'role_title_id' => $roleTitleId]);
    return $stmt->fetchColumn();
}

function deleteCandidateProfile($profileId, $userId) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM candidate_profiles WHERE id = :id AND user_id = :user_id");
    return $stmt->execute(['id' => $profileId, 'user_id' => $userId]);
}

function updateCandidateProfileResume($profileId, $userId, $resumePath, $textVersion = null, $needsHumanReview = false, $optimizationChanges = null, $detectedRole = null, $originalPath = null, $userEnteredRole = null, $userEnteredDescription = null) {
    $db = getDB();
    
    // Fetch current resume_data JSON if exists to preserve other keys
    $stmt = $db->prepare("SELECT resume_data FROM candidate_profiles WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $profileId, 'user_id' => $userId]);
    $existingJson = $stmt->fetchColumn();
    $resumeData = !empty($existingJson) ? json_decode($existingJson, true) : [];
    if (!is_array($resumeData)) {
        $resumeData = [];
    }
    
    $resumeData['optimized_resume_path'] = $resumePath;
    if ($textVersion !== null) {
        $resumeData['text_version'] = $textVersion;
    }
    $resumeData['needs_human_review'] = $needsHumanReview ? true : false;
    if ($optimizationChanges !== null) {
        $resumeData['optimization_changes'] = $optimizationChanges;
    }
    if ($detectedRole !== null) {
        $resumeData['detected_role'] = $detectedRole;
    }
    if ($originalPath !== null) {
        $resumeData['original_path'] = $originalPath;
    }
    if ($userEnteredRole !== null) {
        $resumeData['user_entered_role'] = $userEnteredRole;
    }
    if ($userEnteredDescription !== null) {
        $resumeData['user_entered_description'] = $userEnteredDescription;
    }

    // Order keys logically: text_version, detected_role, original_path, user_entered_role, user_entered_description, needs_human_review, optimization_changes, optimized_resume_path
    $ordered = [];
    $logicalOrder = [
        'text_version',
        'detected_role',
        'original_path',
        'user_entered_role',
        'user_entered_description',
        'needs_human_review',
        'optimization_changes',
        'optimized_resume_path'
    ];
    foreach ($logicalOrder as $key) {
        if (array_key_exists($key, $resumeData)) {
            $ordered[$key] = $resumeData[$key];
        }
    }
    // Append any extra keys
    foreach ($resumeData as $key => $val) {
        if (!array_key_exists($key, $ordered)) {
            $ordered[$key] = $val;
        }
    }
    $resumeData = $ordered;
    
    $jsonVal = json_encode($resumeData);
    
    if ($textVersion !== null) {
        $stmt = $db->prepare("UPDATE candidate_profiles SET 
            optimized_resume_path = :path, 
            text_version = :text_version, 
            needs_human_review = :needs_human_review, 
            resume_data = :resume_data 
            WHERE id = :id AND user_id = :user_id");
        return $stmt->execute([
            'path' => $resumePath,
            'text_version' => $textVersion,
            'needs_human_review' => $needsHumanReview ? 1 : 0,
            'resume_data' => $jsonVal,
            'id' => $profileId,
            'user_id' => $userId
        ]);
    } else {
        $stmt = $db->prepare("UPDATE candidate_profiles SET 
            optimized_resume_path = :path, 
            needs_human_review = :needs_human_review, 
            resume_data = :resume_data 
            WHERE id = :id AND user_id = :user_id");
        return $stmt->execute([
            'path' => $resumePath,
            'needs_human_review' => $needsHumanReview ? 1 : 0,
            'resume_data' => $jsonVal,
            'id' => $profileId,
            'user_id' => $userId
        ]);
    }
}

// Auto-init and seed tables on load
try {
    initSchema();
    seedQuestions();
} catch (Exception $e) {
    // Fail silently in imports, let endpoints report errors if they happen
}
