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

function createSession($name, $email) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO sessions (candidate_name, email) VALUES (:name, :email) RETURNING id");
    $stmt->execute(['name' => $name, 'email' => $email]);
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

// Auto-init and seed tables on load
try {
    initSchema();
    seedQuestions();
} catch (Exception $e) {
    // Fail silently in imports, let endpoints report errors if they happen
}
