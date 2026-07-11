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
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS custom_trugen_api_key VARCHAR(255)");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS trugen_key_scope VARCHAR(50) DEFAULT 'invite_only'");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS resume_path TEXT");
    $db->exec("ALTER TABLE users ALTER COLUMN resume_path TYPE TEXT");

    // Per-task AI models now live in config/models.php; custom AI keys are no longer supported
    $db->exec("ALTER TABLE users DROP COLUMN IF EXISTS custom_gemini_api_key");
    $db->exec("ALTER TABLE users DROP COLUMN IF EXISTS gemini_key_scope");
    $db->exec("ALTER TABLE users DROP COLUMN IF EXISTS model_chat_task");
    $db->exec("ALTER TABLE users DROP COLUMN IF EXISTS model_vision_task");
    $db->exec("ALTER TABLE users DROP COLUMN IF EXISTS model_eval_task");
    $db->exec("ALTER TABLE users DROP COLUMN IF EXISTS model_optimizer_task");


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

    // Create categories table
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        name VARCHAR(255) NOT NULL,
        description TEXT
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

    // Run DB migrations to remove templates and add job_role to links
    try {
        $db->exec("ALTER TABLE IF EXISTS interview_links DROP CONSTRAINT IF EXISTS interview_links_template_id_fkey CASCADE");
        $db->exec("ALTER TABLE IF EXISTS sessions DROP CONSTRAINT IF EXISTS sessions_template_id_fkey CASCADE");
        $db->exec("ALTER TABLE IF EXISTS interview_links DROP COLUMN IF EXISTS template_id CASCADE");
        $db->exec("ALTER TABLE IF EXISTS sessions DROP COLUMN IF EXISTS template_id CASCADE");
        $db->exec("DROP TABLE IF EXISTS interview_templates CASCADE");
    } catch (Exception $e) {
        // Fail silently
    }

    // Create interview_links table
    $db->exec("CREATE TABLE IF NOT EXISTS interview_links (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        company_id UUID REFERENCES companies(id) ON DELETE CASCADE,
        created_by UUID REFERENCES users(id) ON DELETE SET NULL,
        code VARCHAR(12) UNIQUE NOT NULL,
        candidate_email VARCHAR(255),
        candidate_name VARCHAR(150),
        max_attempts INTEGER DEFAULT 1,
        attempts_used INTEGER DEFAULT 0,
        expires_at TIMESTAMP WITH TIME ZONE,
        status VARCHAR(20) DEFAULT 'active',
        job_role VARCHAR(150) NOT NULL DEFAULT 'Software Engineer',
        job_description TEXT,
        is_public BOOLEAN NOT NULL DEFAULT FALSE,
        min_level INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("ALTER TABLE interview_links ADD COLUMN IF NOT EXISTS job_role VARCHAR(150) NOT NULL DEFAULT 'Software Engineer'");
    $db->exec("ALTER TABLE interview_links ADD COLUMN IF NOT EXISTS job_description TEXT");
    $db->exec("ALTER TABLE interview_links ADD COLUMN IF NOT EXISTS is_public BOOLEAN NOT NULL DEFAULT FALSE");
    $db->exec("ALTER TABLE interview_links ADD COLUMN IF NOT EXISTS min_level INTEGER NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE interview_links ADD COLUMN IF NOT EXISTS num_open_questions INTEGER");
    $db->exec("ALTER TABLE interview_links ADD COLUMN IF NOT EXISTS num_mcq_questions INTEGER");

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
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS session_type VARCHAR(20) DEFAULT 'practice'");
    $db->exec("ALTER TABLE sessions DROP COLUMN IF EXISTS model_chat_task");
    $db->exec("ALTER TABLE sessions DROP COLUMN IF EXISTS model_vision_task");
    $db->exec("ALTER TABLE sessions DROP COLUMN IF EXISTS model_eval_task");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS conduct_warnings INTEGER DEFAULT 0");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS closure_reason VARCHAR(50)");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS profile_id INTEGER REFERENCES candidate_profiles(id) ON DELETE SET NULL");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS level INTEGER DEFAULT 0");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS role_title_id VARCHAR(150)");
    $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS q_a JSONB");

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
                    session_type VARCHAR(20) DEFAULT 'practice',
                    conduct_warnings INTEGER DEFAULT 0,
                    closure_reason VARCHAR(50),
                    profile_id INTEGER REFERENCES candidate_profiles(id) ON DELETE SET NULL,
                    q_a JSONB
                )");
                
                // Copy data from sessions_old to sessions
                $db->exec("INSERT INTO sessions (id, candidate_name, email, current_status, level, role_title_id, mcq_preference, trugen_conversation_id, started_at, completed_at, final_score, user_id, interview_link_id, session_type, conduct_warnings, closure_reason, profile_id, q_a)
                    SELECT id, candidate_name, email, current_status, level, role_title_id, mcq_preference, trugen_conversation_id, started_at, completed_at, final_score, user_id, interview_link_id, session_type, conduct_warnings, closure_reason, profile_id, q_a
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

    // Create candidate_responses table
    $db->exec("CREATE TABLE IF NOT EXISTS candidate_responses (
        id SERIAL PRIMARY KEY,
        session_id UUID REFERENCES sessions(id) ON DELETE CASCADE,
        question_id INT,
        selected_option CHAR(1),
        is_correct BOOLEAN,
        submitted_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    )");

    // Run dynamic MCQ migrations & schema updates
    try {
        $db->exec("ALTER TABLE candidate_responses DROP CONSTRAINT IF EXISTS candidate_responses_question_id_fkey");
        $db->exec("DROP TABLE IF EXISTS mcq_questions CASCADE");
        $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS current_mcq_index INT");
        $db->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS current_open_question_index INT");
    } catch (Exception $e) {
        // Fail silently
    }

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


    $db->exec("ALTER TABLE candidate_profiles ADD COLUMN IF NOT EXISTS category_id UUID REFERENCES categories(uuid) ON DELETE SET NULL");
    $db->exec("ALTER TABLE candidate_profiles ADD COLUMN IF NOT EXISTS category_match_percentage INTEGER");

    // Drop cataegories table (remove typo fallback)
    $db->exec("DROP TABLE IF EXISTS cataegories");

    // Add category columns to interview_links
    $db->exec("ALTER TABLE interview_links ADD COLUMN IF NOT EXISTS category_id UUID REFERENCES categories(uuid) ON DELETE SET NULL");
    $db->exec("ALTER TABLE interview_links ADD COLUMN IF NOT EXISTS category_match_percentage INTEGER DEFAULT 0");

    // Retroactively backfill category_id and category_match_percentage for existing active interview links
    try {
        $stmt = $db->query("SELECT id, job_role, created_by FROM interview_links WHERE category_id IS NULL AND status = 'active'");
        $unfilled = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($unfilled)) {
            require_once __DIR__ . '/ai_service.php';
            $categories = getAllCategories();
            $updateStmt = $db->prepare("UPDATE interview_links SET category_id = :category_id, category_match_percentage = :match_percent WHERE id = :id");
            
            foreach ($unfilled as $row) {
                $aiResult = matchRoleToCategory($row['job_role'], $categories);
                if (!empty($aiResult['category_id']) && isset($aiResult['match_percentage'])) {
                    if ($aiResult['match_percentage'] >= 15) {
                        $updateStmt->execute([
                            'category_id' => $aiResult['category_id'],
                            'match_percent' => (int)$aiResult['match_percentage'],
                            'id' => $row['id']
                        ]);
                    }
                }
            }
        }
    } catch (Exception $ex) {
        // Fail silently
    }
    } catch (Exception $e) {
        // Fail silently
    }
}


function createSession($name, $email, $userId = null, $linkId = null, $templateId = null, $type = 'practice', $profileId = null, $qaJson = null, $targetLevel = null) {
    $db = getDB();
    $level = 0;
    $roleTitleId = null;
    if ($profileId) {
        $stmtProfile = $db->prepare("SELECT level, role_title_id FROM candidate_profiles WHERE id = :profile_id");
        $stmtProfile->execute(['profile_id' => $profileId]);
        $profile = $stmtProfile->fetch();
        if ($profile) {
            $level = isset($profile['level']) ? (int)$profile['level'] : 0;
            if ($targetLevel !== null) {
                $level = $targetLevel;
            }
            $roleTitleId = $profile['role_title_id'] ?? null;
        }
    }
    $stmt = $db->prepare("INSERT INTO sessions (candidate_name, email, user_id, interview_link_id, session_type, profile_id, level, role_title_id, q_a) VALUES (:name, :email, :user_id, :link_id, :type, :profile_id, :level, :role_title_id, :q_a) RETURNING id");
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'user_id' => $userId,
        'link_id' => $linkId,
        'type' => $type,
        'profile_id' => $profileId,
        'level' => $level,
        'role_title_id' => $roleTitleId,
        'q_a' => $qaJson
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

function getLatestActiveSession() {
    $db = getDB();
    // Only match unassociated sessions started within the last 4 hours to reduce cross-session confusion
    $stmt = $db->query("SELECT * FROM sessions WHERE current_status NOT IN ('COMPLETED') AND trugen_conversation_id IS NULL AND started_at >= NOW() - INTERVAL '4 hours' ORDER BY started_at DESC LIMIT 1");
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

function getCandidateResponses($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM candidate_responses WHERE session_id = :session_id ORDER BY id ASC");
    $stmt->execute(['session_id' => $sessionId]);
    $responses = $stmt->fetchAll();
    
    $session = getSession($sessionId);
    $qa = json_decode($session['q_a'] ?? '', true);
    
    $enriched = [];
    foreach ($responses as $r) {
        $idx = $r['question_id'];
        if ($qa && isset($qa[$idx])) {
            $q = $qa[$idx];
            $r['topic'] = $q['topic'] ?? $session['role_title_id'] ?? 'MCQ';
            $r['question'] = $q['question'];
            $r['option_a'] = $q['options']['A'] ?? '';
            $r['option_b'] = $q['options']['B'] ?? '';
            $r['option_c'] = $q['options']['C'] ?? '';
            $r['option_d'] = $q['options']['D'] ?? '';
            $r['correct_option'] = $q['answer'];
        }
        $enriched[] = $r;
    }
    return $enriched;
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

function getInterviewLink($id) {
    if (empty($id)) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM interview_links WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function createInterviewLink($companyId, $userId, $code, $candidateEmail, $maxAttempts, $expiresAt, $jobRole, $jobDescription = null, $isPublic = false, $minLevel = 0, $categoryId = null, $categoryMatchPercentage = 0, $numOpenQuestions = null, $numMcqQuestions = null) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO interview_links (company_id, created_by, code, candidate_email, max_attempts, expires_at, job_role, job_description, is_public, min_level, category_id, category_match_percentage, num_open_questions, num_mcq_questions) VALUES (:company_id, :created_by, :code, :candidate_email, :max_attempts, :expires_at, :job_role, :job_description, :is_public, :min_level, :category_id, :category_match_percentage, :num_open_questions, :num_mcq_questions) RETURNING id");
    $stmt->execute([
        'company_id' => $companyId,
        'created_by' => $userId,
        'code' => strtoupper(trim($code)),
        'candidate_email' => empty($candidateEmail) ? null : trim($candidateEmail),
        'max_attempts' => (int)$maxAttempts,
        'expires_at' => empty($expiresAt) ? null : $expiresAt,
        'job_role' => empty($jobRole) ? 'Software Engineer' : trim($jobRole),
        'job_description' => empty($jobDescription) ? null : trim($jobDescription),
        'is_public' => $isPublic ? 'true' : 'false',
        'min_level' => (int)$minLevel,
        'category_id' => $categoryId,
        'category_match_percentage' => (int)$categoryMatchPercentage,
        'num_open_questions' => $numOpenQuestions !== null && $numOpenQuestions !== '' ? (int)$numOpenQuestions : null,
        'num_mcq_questions' => $numMcqQuestions !== null && $numMcqQuestions !== '' ? (int)$numMcqQuestions : null
    ]);
    return $stmt->fetchColumn();
}

function listInterviewLinks($companyId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM interview_links WHERE company_id = :company_id AND status != 'deleted' ORDER BY created_at DESC");
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function listCandidateResults($companyId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT s.*, il.job_role as template_title, il.code as link_code FROM sessions s JOIN interview_links il ON s.interview_link_id = il.id WHERE il.company_id = :company_id ORDER BY s.started_at DESC");
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function listCandidateHistory($candidateId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT s.*, COALESCE(il.job_role, cp.role_title) as template_title FROM sessions s LEFT JOIN interview_links il ON s.interview_link_id = il.id LEFT JOIN candidate_profiles cp ON s.profile_id = cp.id WHERE s.user_id = :candidate_id ORDER BY s.started_at DESC");
    $stmt->execute(['candidate_id' => $candidateId]);
    return $stmt->fetchAll();
}

function deleteSession($sessionId, $userId) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM sessions WHERE id = :id AND user_id = :user_id");
    return $stmt->execute(['id' => $sessionId, 'user_id' => $userId]);
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
    $stmt = $db->prepare("SELECT COUNT(*) FROM interview_links WHERE company_id = :company_id AND status != 'deleted'");
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

function getSessionUserSettings($sessionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sessions WHERE id = :id");
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();
    if (!$session) {
        return null;
    }
    $targetUserId = null;
    if (!empty($session['interview_link_id'])) {
        $stmt = $db->prepare("SELECT created_by FROM interview_links WHERE id = :id");
        $stmt->execute(['id' => $session['interview_link_id']]);
        $targetUserId = $stmt->fetchColumn();
    } elseif (!empty($session['user_id'])) {
        $targetUserId = $session['user_id'];
    }
    if ($targetUserId) {
        $stmt = $db->prepare("SELECT custom_trugen_agent_id, custom_trugen_api_key, trugen_key_scope FROM users WHERE id = :id");
        $stmt->execute(['id' => $targetUserId]);
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
function getAllCategories() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM categories ORDER BY name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCandidateProfiles($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT cp.*, c.name as category_name, c.description as category_description FROM candidate_profiles cp LEFT JOIN categories c ON cp.category_id = c.uuid WHERE cp.user_id = :user_id ORDER BY cp.created_at ASC");
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

function getCandidateProfile($profileId, $userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT cp.*, c.name as category_name, c.description as category_description FROM candidate_profiles cp LEFT JOIN categories c ON cp.category_id = c.uuid WHERE cp.id = :id AND cp.user_id = :user_id");
    $stmt->execute(['id' => $profileId, 'user_id' => $userId]);
    return $stmt->fetch();
}

function createCandidateProfile($userId, $roleTitle, $categoryId = null, $matchPercentage = null) {
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

    $stmt = $db->prepare("INSERT INTO candidate_profiles (user_id, role_title, role_title_id, category_id, category_match_percentage) VALUES (:user_id, :role_title, :role_title_id, :category_id, :category_match_percentage) RETURNING id");
    $stmt->execute([
        'user_id' => $userId, 
        'role_title' => $roleTitle, 
        'role_title_id' => $roleTitleId,
        'category_id' => $categoryId,
        'category_match_percentage' => $matchPercentage
    ]);
    return $stmt->fetchColumn();
}

function findCandidateProfileBySlug($userId, $roleTitleId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM candidate_profiles WHERE user_id = :user_id AND role_title_id = :slug LIMIT 1");
    $stmt->execute(['user_id' => $userId, 'slug' => $roleTitleId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? $id : null;
}

function deleteCandidateProfile($profileId, $userId) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM candidate_profiles WHERE id = :id AND user_id = :user_id");
    return $stmt->execute(['id' => $profileId, 'user_id' => $userId]);
}

function updateCandidateProfileResume($profileId, $userId, $resumePath, $textVersion = null, $needsHumanReview = false, $optimizationChanges = null, $detectedRole = null, $originalPath = null, $userEnteredRole = null, $userEnteredDescription = null) {
    $db = getDB();
    
    // Fetch current resume_data JSON and category fields if they exist
    $stmt = $db->prepare("SELECT resume_data, category_id, category_match_percentage FROM candidate_profiles WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $profileId, 'user_id' => $userId]);
    $profileRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $existingJson = $profileRow['resume_data'] ?? '';
    $categoryId = $profileRow['category_id'] ?? null;
    $matchPercentage = $profileRow['category_match_percentage'] ?? null;
    
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
        
        // Re-evaluate category match using detected_role
        try {
            require_once __DIR__ . '/ai_service.php';
            $categories = getAllCategories();

            $aiResult = matchRoleToCategory($detectedRole, $categories);
            if (!empty($aiResult['category_id']) && isset($aiResult['match_percentage'])) {
                if ($aiResult['match_percentage'] >= 15) {
                    $categoryId = $aiResult['category_id'];
                    $matchPercentage = (int)$aiResult['match_percentage'];
                } else {
                    $categoryId = null;
                    $matchPercentage = 0;
                }
            } else {
                $categoryId = null;
                $matchPercentage = 0;
            }
        } catch (Exception $e) {
            // Fail silently, keep existing values
        }
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
            resume_data = :resume_data,
            category_id = :category_id,
            category_match_percentage = :category_match_percentage
            WHERE id = :id AND user_id = :user_id");
        return $stmt->execute([
            'path' => $resumePath,
            'text_version' => $textVersion,
            'needs_human_review' => $needsHumanReview ? 1 : 0,
            'resume_data' => $jsonVal,
            'category_id' => $categoryId,
            'category_match_percentage' => $matchPercentage,
            'id' => $profileId,
            'user_id' => $userId
        ]);
    } else {
        $stmt = $db->prepare("UPDATE candidate_profiles SET 
            optimized_resume_path = :path, 
            needs_human_review = :needs_human_review, 
            resume_data = :resume_data,
            category_id = :category_id,
            category_match_percentage = :category_match_percentage
            WHERE id = :id AND user_id = :user_id");
        return $stmt->execute([
            'path' => $resumePath,
            'needs_human_review' => $needsHumanReview ? 1 : 0,
            'resume_data' => $jsonVal,
            'category_id' => $categoryId,
            'category_match_percentage' => $matchPercentage,
            'id' => $profileId,
            'user_id' => $userId
        ]);
    }
}

// Only run the schema initializer when run explicitly from the command line: php db.php migrate
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'migrate') {
    try {
        initSchema();
        echo "Database schema initialized/migrated successfully.\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
