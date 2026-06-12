<?php
// admin/api.php - Secure JSON API for Admin DB Structure & Data Viewer
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce admin routing guard
requireAuth(['admin']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    // 1. Helper to fetch allowed tables to prevent SQL injection
    $stmt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    $allowedTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($action === 'get_tables') {
        // Fetch tables with row estimates and column counts
        $sql = "SELECT 
                  t.table_name,
                  (SELECT count(*) FROM information_schema.columns c WHERE c.table_name = t.table_name AND c.table_schema = 'public') as column_count,
                  coalesce(c.reltuples::bigint, 0) as row_count_estimate
                FROM information_schema.tables t
                LEFT JOIN pg_class c ON c.relname = t.table_name
                WHERE t.table_schema = 'public'
                ORDER BY t.table_name";
        
        $tablesData = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        // Return JSON
        echo json_encode([
            'success' => true,
            'tables' => $tablesData
        ]);
        exit();
    }

    if ($action === 'get_table_schema') {
        $table = $_GET['table'] ?? '';
        if (!in_array($table, $allowedTables)) {
            throw new Exception("Invalid or unauthorized table specified.");
        }

        // Fetch columns
        $colSql = "SELECT 
                     c.column_name,
                     c.data_type,
                     c.is_nullable,
                     c.column_default,
                     CASE WHEN (
                       SELECT count(*) 
                       FROM information_schema.table_constraints tc
                       JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                       WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = c.table_name AND kcu.column_name = c.column_name AND tc.table_schema = 'public'
                     ) > 0 THEN 1 ELSE 0 END as is_primary,
                     CASE WHEN (
                       SELECT count(*) 
                       FROM information_schema.table_constraints tc
                       JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                       WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = c.table_name AND kcu.column_name = c.column_name AND tc.table_schema = 'public'
                     ) > 0 THEN 1 ELSE 0 END as is_foreign
                   FROM information_schema.columns c
                   WHERE c.table_name = :table AND c.table_schema = 'public'
                   ORDER BY c.ordinal_position";
        
        $colStmt = $db->prepare($colSql);
        $colStmt->execute(['table' => $table]);
        $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Indexes
        $idxSql = "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = :table AND schemaname = 'public'";
        $idxStmt = $db->prepare($idxSql);
        $idxStmt->execute(['table' => $table]);
        $indexes = $idxStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Foreign Key Constraints Details
        $fkSql = "SELECT
                    kcu.column_name AS local_column,
                    ccu.table_name AS foreign_table,
                    ccu.column_name AS foreign_column
                  FROM information_schema.table_constraints AS tc
                  JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                  JOIN information_schema.constraint_column_usage AS ccu
                    ON ccu.constraint_name = tc.constraint_name
                    AND ccu.table_schema = tc.table_schema
                  WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = :table AND tc.table_schema = 'public'";
        
        $fkStmt = $db->prepare($fkSql);
        $fkStmt->execute(['table' => $table]);
        $foreignKeys = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'table' => $table,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys
        ]);
        exit();
    }

    if ($action === 'get_table_data') {
        $table = $_GET['table'] ?? '';
        if (!in_array($table, $allowedTables)) {
            throw new Exception("Invalid or unauthorized table specified.");
        }

        // Pagination parameters
        $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $limit;

        // Sorting parameters
        $sortBy = $_GET['sort_by'] ?? '';
        $sortOrder = strtoupper($_GET['sort_order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        // Fetch table columns to validate sort column and generate search filters
        $colStmt = $db->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = :table AND table_schema = 'public'");
        $colStmt->execute(['table' => $table]);
        $columnsInfo = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_column($columnsInfo, 'column_name');

        if (!empty($sortBy) && !in_array($sortBy, $columnNames)) {
            throw new Exception("Invalid sort column specified.");
        }

        // Search parameters
        $search = $_GET['search'] ?? '';
        $whereClause = "";
        $bindParams = [];

        if ($search !== '') {
            $conditions = [];
            foreach ($columnNames as $colName) {
                // Cast to text to perform case-insensitive search
                $conditions[] = "CAST(\"$colName\" AS TEXT) ILIKE :search_term";
            }
            if (!empty($conditions)) {
                $whereClause = "WHERE " . implode(" OR ", $conditions);
                $bindParams['search_term'] = '%' . $search . '%';
            }
        }

        // Fetch total rows count matching conditions
        $countSql = "SELECT COUNT(*) FROM \"$table\" $whereClause";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($bindParams);
        $totalRows = (int)$countStmt->fetchColumn();

        // Build main data query
        $orderClause = "";
        if (!empty($sortBy)) {
            $orderClause = "ORDER BY \"$sortBy\" $sortOrder";
        }
        
        $dataSql = "SELECT * FROM \"$table\" $whereClause $orderClause LIMIT :limit OFFSET :offset";
        $dataStmt = $db->prepare($dataSql);
        
        // Bind parameters safely
        foreach ($bindParams as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'table' => $table,
            'total_rows' => $totalRows,
            'page' => $page,
            'limit' => $limit,
            'pages_count' => ceil($totalRows / $limit),
            'columns' => $columnNames,
            'rows' => $rows
        ]);
        exit();
    }

    if ($action === 'insert_row') {
        // Read input (JSON or POST form)
        $inputData = [];
        $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $inputData = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            $inputData = $_POST;
        }

        $table = $inputData['table'] ?? '';
        if (!in_array($table, $allowedTables)) {
            throw new Exception("Invalid or unauthorized table specified.");
        }

        // Fetch table columns to validate inputs
        $colStmt = $db->prepare("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = :table AND table_schema = 'public'");
        $colStmt->execute(['table' => $table]);
        $columnsInfo = $colStmt->fetchAll(PDO::FETCH_ASSOC);

        $insertCols = [];
        $insertVals = [];
        $bindParams = [];

        foreach ($columnsInfo as $col) {
            $colName = $col['column_name'];
            $dataType = $col['data_type'];
            $isNullable = $col['is_nullable'];
            $hasDefault = ($col['column_default'] !== null);

            if (array_key_exists($colName, $inputData)) {
                $val = $inputData[$colName];

                // If value is empty string, determine if we should insert NULL or skip
                if ($val === '') {
                    if ($isNullable === 'YES') {
                        $insertCols[] = "\"$colName\"";
                        $insertVals[] = ":" . $colName;
                        $bindParams[$colName] = null;
                    } elseif ($hasDefault) {
                        // Skip column to let database use default value
                        continue;
                    } else {
                        // Not nullable, no default, but empty string
                        if (in_array($dataType, ['character varying', 'varchar', 'text', 'character', 'char'])) {
                            $insertCols[] = "\"$colName\"";
                            $insertVals[] = ":" . $colName;
                            $bindParams[$colName] = '';
                        } else {
                            $insertCols[] = "\"$colName\"";
                            $insertVals[] = ":" . $colName;
                            $bindParams[$colName] = null;
                        }
                    }
                } else {
                    $insertCols[] = "\"$colName\"";
                    $insertVals[] = ":" . $colName;

                    // Parse JSON/JSONB
                    if (in_array($dataType, ['json', 'jsonb'])) {
                        if (is_array($val)) {
                            $bindParams[$colName] = json_encode($val);
                        } else {
                            $decoded = json_decode($val, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                throw new Exception("Invalid JSON format for column '$colName'.");
                            }
                            $bindParams[$colName] = $val;
                        }
                    } elseif ($dataType === 'boolean') {
                        $bindParams[$colName] = filter_var($val, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                    } else {
                        $bindParams[$colName] = $val;
                    }
                }
            }
        }

        if (empty($insertCols)) {
            throw new Exception("No values provided for insertion.");
        }

        $sql = "INSERT INTO \"$table\" (" . implode(", ", $insertCols) . ") VALUES (" . implode(", ", $insertVals) . ")";
        $stmt = $db->prepare($sql);
        $stmt->execute($bindParams);

        echo json_encode([
            'success' => true,
            'message' => "Row inserted successfully."
        ]);
        exit();
    }

    if ($action === 'delete_row') {
        // Read input (JSON or POST form)
        $inputData = [];
        $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $inputData = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            $inputData = $_POST;
        }

        $table = $inputData['table'] ?? '';
        if (!in_array($table, $allowedTables)) {
            throw new Exception("Invalid or unauthorized table specified.");
        }

        // Fetch primary key columns
        $pkSql = "SELECT kcu.column_name 
                  FROM information_schema.table_constraints tc 
                  JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                  WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = :table AND tc.table_schema = 'public'";
        
        $pkStmt = $db->prepare($pkSql);
        $pkStmt->execute(['table' => $table]);
        $pkColumns = $pkStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($pkColumns)) {
            throw new Exception("Cannot delete row: Table has no primary key configured.");
        }

        $whereConditions = [];
        $bindParams = [];
        foreach ($pkColumns as $pkCol) {
            if (!isset($inputData[$pkCol])) {
                throw new Exception("Missing primary key value for column '$pkCol'.");
            }
            $whereConditions[] = "\"$pkCol\" = :$pkCol";
            $bindParams[$pkCol] = $inputData[$pkCol];
        }

        $sql = "DELETE FROM \"$table\" WHERE " . implode(" AND ", $whereConditions);
        $stmt = $db->prepare($sql);
        $stmt->execute($bindParams);

        echo json_encode([
            'success' => true,
            'message' => "Row deleted successfully."
        ]);
        exit();
    }

    throw new Exception("Invalid API action specified.");

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit();
}
