<?php
// admin/index.php - Database Structure & Data Viewer Admin Panel
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce admin permission
requireAuth(['admin']);

$user = getCurrentUser();
$db = getDB();

// Fetch public tables on load for zero-delay sidebar rendering
$tablesSql = "SELECT 
                t.table_name,
                (SELECT count(*) FROM information_schema.columns c WHERE c.table_name = t.table_name AND c.table_schema = 'public') as column_count,
                coalesce(c.reltuples::bigint, 0) as row_count_estimate
              FROM information_schema.tables t
              LEFT JOIN pg_class c ON c.relname = t.table_name
              WHERE t.table_schema = 'public'
              ORDER BY t.table_name";

$tables = $db->query($tablesSql)->fetchAll(PDO::FETCH_ASSOC);

// Calculate database stats for the landing dashboard
$totalTables = count($tables);
$totalColumns = array_sum(array_column($tables, 'column_count'));
$totalRowsEstimate = array_sum(array_column($tables, 'row_count_estimate'));

// Form initials for avatar
$initials = "AD";
if (!empty($user['full_name'])) {
    $parts = explode(" ", $user['full_name']);
    $initials = strtoupper(($parts[0][0] ?? '') . ($parts[1][0] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/admin.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">

  <div class="admin-layout">
    
    <!-- Collapsible Sidebar -->
    <aside class="admin-sidebar">
      <div class="sidebar-header">
        <a href="../index.php" class="brand-title-admin">
          <svg style="width: 26px; height: 26px; color: #06b6d4;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
          </svg>
          <span>Tru<span>Admin</span></span>
        </a>
      </div>

      <div class="sidebar-scrollable">
        <div>
          <div class="sidebar-section-title">Database Tables</div>
          <nav class="table-list">
            <?php foreach ($tables as $t): ?>
              <button class="table-item-btn" onclick="selectTable('<?php echo htmlspecialchars($t['table_name']); ?>')" id="btn-tbl-<?php echo htmlspecialchars($t['table_name']); ?>">
                <span><?php echo htmlspecialchars($t['table_name']); ?></span>
                <span class="table-row-count-badge"><?php echo number_format($t['row_count_estimate']); ?></span>
              </button>
            <?php endforeach; ?>
          </nav>
        </div>
      </div>

      <div class="sidebar-footer">
        <div class="footer-user">
          <div>
            <div class="footer-user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
            <div class="db-status-bar" style="margin-top: 2px;">
              <span class="status-indicator"></span>
              <span>PostgreSQL Connected</span>
            </div>
          </div>
          <a href="../logout.php" class="btn-sidebar-logout">Log Out</a>
        </div>
      </div>
    </aside>

    <!-- Main View Panel -->
    <main class="admin-main">
      
      <!-- Top Dynamic Header -->
      <header class="main-header">
        <div class="header-meta">
          <h1 class="header-title" id="view-title">Admin Console</h1>
          <p class="header-subtitle" id="view-subtitle">Select a table from the sidebar to inspect its structure and browse records.</p>
        </div>
        
        <!-- Tab Options (Initially hidden, shown when a table is active) -->
        <div class="tab-bar" id="tab-controls" style="display: none;">
          <button class="tab-trigger active" onclick="switchTab('schema')" id="tab-btn-schema">Table Structure</button>
          <button class="tab-trigger" onclick="switchTab('data')" id="tab-btn-data">Browse Data</button>
        </div>
      </header>

      <!-- Content Viewport -->
      <div class="main-viewport" id="main-viewport">
        
        <!-- Welcome Screen (Default empty state) -->
        <div id="welcome-view" class="grid-container">
          <div class="card-panel" style="background: linear-gradient(135deg, rgba(241, 245, 249, 0.8) 0%, rgba(226, 232, 240, 0.8) 100%); text-align: center; padding: 48px;">
            <svg class="empty-view-icon" style="color: var(--color-cyan);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z"></path>
            </svg>
            <h2 class="empty-view-title" style="font-size: 1.6rem;">Welcome to the TruInterview Admin Panel</h2>
            <p class="empty-view-desc" style="margin: 8px auto 24px auto;">You are securely authenticated as an administrator. Select any relation from the left schema sidebar to review indices, schema properties, or search values.</p>
            
            <!-- Connection Stats -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; max-width: 600px; margin: 0 auto; text-align: left;">
              <div style="background: rgba(0, 0, 0, 0.02); border: 1px solid var(--color-border); padding: 16px; border-radius: 10px;">
                <div style="font-size: 0.72rem; color: var(--color-text-muted); text-transform: uppercase; font-weight: 700;">Relation Count</div>
                <div style="font-size: 1.5rem; font-weight: 700; margin-top: 4px; color: var(--color-cyan);"><?php echo $totalTables; ?></div>
              </div>
              <div style="background: rgba(0, 0, 0, 0.02); border: 1px solid var(--color-border); padding: 16px; border-radius: 10px;">
                <div style="font-size: 0.72rem; color: var(--color-text-muted); text-transform: uppercase; font-weight: 700;">Total Columns</div>
                <div style="font-size: 1.5rem; font-weight: 700; margin-top: 4px; color: var(--color-cyan);"><?php echo $totalColumns; ?></div>
              </div>
              <div style="background: rgba(0, 0, 0, 0.02); border: 1px solid var(--color-border); padding: 16px; border-radius: 10px;">
                <div style="font-size: 0.72rem; color: var(--color-text-muted); text-transform: uppercase; font-weight: 700;">Estimated Rows</div>
                <div style="font-size: 1.5rem; font-weight: 700; margin-top: 4px; color: var(--color-cyan);"><?php echo number_format($totalRowsEstimate); ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Schema Inspector View -->
        <div id="schema-view" style="display: none;" class="grid-container">
          <div class="card-panel">
            <h3 class="card-title">Columns Schema</h3>
            <div class="table-viewport" style="margin-top: 16px;">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Column Name</th>
                    <th>Data Type</th>
                    <th>Nullable</th>
                    <th>Default Value</th>
                    <th>Keys / Constraints</th>
                  </tr>
                </thead>
                <tbody id="schema-columns-tbody">
                  <!-- Loaded dynamically -->
                </tbody>
              </table>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;" id="indexes-and-constraints-row">
            <div class="card-panel">
              <h3 class="card-title">Indexes</h3>
              <div class="table-viewport" style="margin-top: 16px;">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Definition</th>
                    </tr>
                  </thead>
                  <tbody id="schema-indexes-tbody">
                    <!-- Loaded dynamically -->
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card-panel">
              <h3 class="card-title">Foreign Key Relations</h3>
              <div class="table-viewport" style="margin-top: 16px;">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Local Column</th>
                      <th>References</th>
                    </tr>
                  </thead>
                  <tbody id="schema-fks-tbody">
                    <!-- Loaded dynamically -->
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Data Browser View -->
        <div id="data-view" style="display: none;" class="grid-container">
          <div class="card-panel">
            
            <!-- Controls bar -->
            <div class="control-bar">
              <div class="search-input-wrapper">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input type="text" id="search-term" class="form-input-admin" placeholder="Search rows..." oninput="handleSearch(this.value)">
              </div>
              
              <div class="pagination-controls">
                <span>Show:</span>
                <select id="limit-select" class="select-admin" onchange="handleLimitChange(this.value)">
                  <option value="10">10 rows</option>
                  <option value="20" selected>20 rows</option>
                  <option value="50">50 rows</option>
                  <option value="100">100 rows</option>
                </select>
                <span id="pagination-info">Showing 0-0 of 0</span>
                <div style="display: flex; gap: 4px;">
                  <button class="pagination-btn" id="btn-prev-page" onclick="changePage(-1)" disabled>&larr;</button>
                  <button class="pagination-btn" id="btn-next-page" onclick="changePage(1)" disabled>&rarr;</button>
                </div>
              </div>
            </div>

            <!-- Dynamic Table content -->
            <div class="table-viewport" style="max-height: 60vh;">
              <table class="data-table" id="data-browser-table">
                <thead id="data-headers-thead">
                  <!-- Loaded dynamically -->
                </thead>
                <tbody id="data-rows-tbody">
                  <!-- Loaded dynamically -->
                </tbody>
              </table>
            </div>

          </div>
        </div>

        <!-- Loading State Skeleton -->
        <div id="loading-view" style="display: none;" class="card-panel">
          <div class="skeleton-row"><div class="skeleton-cell" style="width: 40%"></div></div>
          <div class="skeleton-row"><div class="skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton-cell"></div></div>
        </div>

      </div>
    </main>
  </div>

  <!-- Cell value details inspector modal -->
  <div class="cell-inspect-modal" id="cell-inspect-modal" onclick="closeInspector()">
    <div class="modal-content" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h4 class="modal-title" id="modal-field-title">Cell Inspector</h4>
        <button class="btn-close-modal" onclick="closeInspector()">&times;</button>
      </div>
      <div class="modal-body">
        <pre class="code-block-inspect" id="modal-field-content"></pre>
      </div>
    </div>
  </div>

  <script>
    let activeTable = '';
    let activeTab = 'schema'; // schema or data
    
    // Data view state
    let searchString = '';
    let currentPage = 1;
    let currentLimit = 20;
    let sortColumn = '';
    let sortOrder = 'ASC';

    function selectTable(tableName) {
      activeTable = tableName;
      
      // Update sidebar UI selection
      document.querySelectorAll('.table-item-btn').forEach(btn => btn.classList.remove('active'));
      const activeBtn = document.getElementById('btn-tbl-' + tableName);
      if (activeBtn) activeBtn.classList.add('active');

      // Update headers
      document.getElementById('view-title').textContent = tableName;
      document.getElementById('view-subtitle').textContent = `Table schema and records browser for "${tableName}".`;
      document.getElementById('tab-controls').style.display = 'flex';

      // Hide welcome
      document.getElementById('welcome-view').style.display = 'none';

      // Load data
      loadActiveTableData();
    }

    function switchTab(tabId) {
      activeTab = tabId;
      document.getElementById('tab-btn-schema').classList.toggle('active', tabId === 'schema');
      document.getElementById('tab-btn-data').classList.toggle('active', tabId === 'data');
      
      loadActiveTableData();
    }

    function showLoading(show) {
      document.getElementById('loading-view').style.display = show ? 'block' : 'none';
      if (show) {
        document.getElementById('schema-view').style.display = 'none';
        document.getElementById('data-view').style.display = 'none';
      }
    }

    function loadActiveTableData() {
      if (!activeTable) return;
      
      showLoading(true);

      if (activeTab === 'schema') {
        fetch(`api.php?action=get_table_schema&table=${encodeURIComponent(activeTable)}`)
          .then(res => res.json())
          .then(data => {
            showLoading(false);
            if (!data.success) {
              alert('Error loading schema: ' + data.error);
              return;
            }
            renderSchema(data);
          })
          .catch(err => {
            showLoading(false);
            alert('API communication error.');
          });
      } else {
        fetch(`api.php?action=get_table_data&table=${encodeURIComponent(activeTable)}&page=${currentPage}&limit=${currentLimit}&sort_by=${encodeURIComponent(sortColumn)}&sort_order=${sortOrder}&search=${encodeURIComponent(searchString)}`)
          .then(res => res.json())
          .then(data => {
            showLoading(false);
            if (!data.success) {
              alert('Error loading data: ' + data.error);
              return;
            }
            renderDataBrowser(data);
          })
          .catch(err => {
            showLoading(false);
            alert('API communication error.');
          });
      }
    }

    function renderSchema(data) {
      document.getElementById('schema-view').style.display = 'block';
      
      // Render columns
      const tbodyCols = document.getElementById('schema-columns-tbody');
      tbodyCols.innerHTML = '';
      data.columns.forEach(col => {
        const tr = document.createElement('tr');
        
        let keys = '';
        if (col.is_primary) keys += '<span class="pk-badge">PK</span> ';
        if (col.is_foreign) keys += '<span class="fk-badge">FK</span>';

        tr.innerHTML = `
          <td class="font-mono-cell" style="font-weight: 600; color: var(--color-text-primary);">${escapeHtml(col.column_name)}</td>
          <td class="font-mono-cell" style="color: #2563eb;">${escapeHtml(col.data_type)}</td>
          <td>${col.is_nullable === 'YES' ? '<span class="nullable-badge">YES</span>' : '<span style="color: #f43f5e; font-size: 0.75rem; font-weight: 600;">NO</span>'}</td>
          <td class="font-mono-cell" style="font-size:0.78rem;">${col.column_default !== null ? escapeHtml(col.column_default) : '<span style="color: var(--color-text-muted); font-style: italic;">NULL</span>'}</td>
          <td>${keys}</td>
        `;
        tbodyCols.appendChild(tr);
      });

      // Render Indexes
      const tbodyIdx = document.getElementById('schema-indexes-tbody');
      tbodyIdx.innerHTML = '';
      if (data.indexes.length === 0) {
        tbodyIdx.innerHTML = '<tr><td colspan="2" style="text-align: center; color: var(--color-text-muted);">No indexes configured</td></tr>';
      } else {
        data.indexes.forEach(idx => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td class="font-mono-cell" style="font-weight: 600;">${escapeHtml(idx.indexname)}</td>
            <td class="font-mono-cell" style="font-size:0.75rem; color: var(--color-text-secondary);">${escapeHtml(idx.indexdef)}</td>
          `;
          tbodyIdx.appendChild(tr);
        });
      }

      // Render Foreign Keys
      const tbodyFk = document.getElementById('schema-fks-tbody');
      tbodyFk.innerHTML = '';
      if (data.foreign_keys.length === 0) {
        tbodyFk.innerHTML = '<tr><td colspan="2" style="text-align: center; color: var(--color-text-muted);">No foreign keys defined</td></tr>';
      } else {
        data.foreign_keys.forEach(fk => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td class="font-mono-cell" style="font-weight: 600;">${escapeHtml(fk.local_column)}</td>
            <td class="font-mono-cell" style="font-size: 0.8rem; color: #7c3aed;">
              &rarr; ${escapeHtml(fk.foreign_table)}(${escapeHtml(fk.foreign_column)})
            </td>
          `;
          tbodyFk.appendChild(tr);
        });
      }
    }

    function renderDataBrowser(data) {
      document.getElementById('data-view').style.display = 'block';

      // Headings
      const thead = document.getElementById('data-headers-thead');
      thead.innerHTML = '';
      const trHeader = document.createElement('tr');
      data.columns.forEach(col => {
        const th = document.createElement('th');
        th.className = 'sortable';
        
        let indicator = '';
        if (sortColumn === col) {
          indicator = sortOrder === 'ASC' ? ' &uarr;' : ' &darr;';
        }
        
        th.innerHTML = `${escapeHtml(col)}${indicator}`;
        th.onclick = () => handleSort(col);
        trHeader.appendChild(th);
      });
      thead.appendChild(trHeader);

      // Rows
      const tbody = document.getElementById('data-rows-tbody');
      tbody.innerHTML = '';
      if (data.rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${data.columns.length}" style="text-align: center; padding: 48px; color: var(--color-text-muted);">No records found matching filters.</td></tr>`;
      } else {
        data.rows.forEach(row => {
          const tr = document.createElement('tr');
          data.columns.forEach(col => {
            const td = document.createElement('td');
            td.className = 'font-mono-cell';
            
            const cellValue = row[col];
            let displayVal = '';

            if (cellValue === null) {
              displayVal = '<span style="color: var(--color-text-muted); font-style: italic;">NULL</span>';
            } else if (typeof cellValue === 'object') {
              const strJson = JSON.stringify(cellValue);
              displayVal = `<span style="color: #db2777; cursor: pointer;" onclick="inspectCell('${escapeJsonString(col)}', '${escapeJsonString(strJson)}')">${escapeHtml(strJson.substring(0, 40))}...</span>`;
            } else if (cellValue.length > 50) {
              displayVal = `<span style="color: #2563eb; cursor: pointer;" onclick="inspectCell('${escapeJsonString(col)}', '${escapeJsonString(cellValue)}')">${escapeHtml(cellValue.substring(0, 45))}...</span>`;
            } else {
              displayVal = escapeHtml(String(cellValue));
            }
            
            td.innerHTML = displayVal;
            tr.appendChild(td);
          });
          tbody.appendChild(tr);
        });
      }

      // Pagination details
      const startRecord = data.total_rows === 0 ? 0 : (currentPage - 1) * currentLimit + 1;
      const endRecord = Math.min(data.total_rows, currentPage * currentLimit);
      document.getElementById('pagination-info').textContent = `Showing ${startRecord}-${endRecord} of ${data.total_rows}`;
      
      document.getElementById('btn-prev-page').disabled = currentPage <= 1;
      document.getElementById('btn-next-page').disabled = currentPage >= data.pages_count;
    }

    function handleSort(col) {
      if (sortColumn === col) {
        sortOrder = sortOrder === 'ASC' ? 'DESC' : 'ASC';
      } else {
        sortColumn = col;
        sortOrder = 'ASC';
      }
      currentPage = 1;
      loadActiveTableData();
    }

    let searchTimeout;
    function handleSearch(val) {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        searchString = val;
        currentPage = 1;
        loadActiveTableData();
      }, 300);
    }

    function handleLimitChange(limit) {
      currentLimit = parseInt(limit);
      currentPage = 1;
      loadActiveTableData();
    }

    function changePage(direction) {
      currentPage += direction;
      loadActiveTableData();
    }

    function inspectCell(field, val) {
      document.getElementById('modal-field-title').textContent = `Inspecting Cell: "${field}"`;
      const modalContentEl = document.getElementById('modal-field-content');
      
      try {
        // Attempt to pretty format JSON
        const parsed = JSON.parse(val);
        modalContentEl.textContent = JSON.stringify(parsed, null, 2);
      } catch (e) {
        modalContentEl.textContent = val;
      }
      
      document.getElementById('cell-inspect-modal').classList.add('active');
    }

    function closeInspector() {
      document.getElementById('cell-inspect-modal').classList.remove('active');
    }

    function escapeHtml(str) {
      return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function escapeJsonString(str) {
      return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, '\\n').replace(/\r/g, '\\r');
    }
  </script>
</body>
</html>
