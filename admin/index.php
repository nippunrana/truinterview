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
          <div style="display: flex; align-items: center; gap: 12px;">
            <h1 class="header-title" id="view-title">Admin Console</h1>
            <div style="position: relative; display: inline-block; line-height: 1;">
              <button id="btn-copy-structure" class="btn-copy-action" style="display: none;" title="Copy table structure" onclick="toggleCopyMenu(event)">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                </svg>
              </button>
              <div id="copy-menu" class="copy-dropdown-menu">
                <button onclick="copyStructureAs('sql', event)">Copy as SQL (DDL)</button>
                <button onclick="copyStructureAs('markdown', event)">Copy as Markdown Table</button>
                <button onclick="copyStructureAs('csv', event)">Copy as CSV Column List</button>
              </div>
            </div>
          </div>
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
              <div style="display: flex; gap: 12px; align-items: center; flex: 1; max-width: 520px;">
                <div class="search-input-wrapper" style="max-width: none; flex: 1;">
                  <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                  <input type="text" id="search-term" class="form-input-admin" placeholder="Search rows..." oninput="handleSearch(this.value)">
                </div>
                <button class="btn-submit" id="btn-insert-row" onclick="openInsertModal()" style="height: 38px; padding: 0 16px; font-size: 0.85rem; border-radius: 8px; flex-shrink: 0; margin: 0;">
                  <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"></path>
                  </svg>
                  <span>Insert Row</span>
                </button>
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

  <!-- Insert new row modal -->
  <div class="cell-inspect-modal" id="insert-row-modal" onclick="closeInsertModal()">
    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 600px; height: auto; max-height: 85vh;">
      <div class="modal-header">
        <h4 class="modal-title" id="insert-modal-title">Insert New Row</h4>
        <button class="btn-close-modal" onclick="closeInsertModal()">&times;</button>
      </div>
      <form id="insert-row-form" onsubmit="handleInsertSubmit(event)">
        <div class="modal-body" style="gap: 16px; overflow-y: auto; max-height: calc(85vh - 140px);">
          <div id="insert-form-fields" style="display: flex; flex-direction: column; gap: 14px;">
            <!-- Fields loaded dynamically -->
          </div>
        </div>
        <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: 12px; background: rgba(0, 0, 0, 0.01);">
          <button type="button" class="btn-copy-action" style="padding: 8px 16px; font-size: 0.85rem; border-radius: var(--radius-inner);" onclick="closeInsertModal()">Cancel</button>
          <button type="submit" class="btn-submit" style="font-size: 0.85rem; padding: 8px 20px;">Insert Row</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Cell value details inspector modal -->
  <div class="cell-inspect-modal" id="cell-inspect-modal" onclick="closeInspector()">
    <div class="modal-content" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h4 class="modal-title" id="modal-field-title">Cell Inspector</h4>
        <div style="display: flex; align-items: center; gap: 12px;">
          <button id="btn-copy-inspect" class="btn-submit" style="margin: 0; padding: 6px 14px; font-size: 0.8rem; border-radius: 6px;" onclick="copyInspectedContent()">Copy Content</button>
          <button class="btn-close-modal" onclick="closeInspector()">&times;</button>
        </div>
      </div>
      <div class="modal-body">
        <pre class="code-block-inspect" id="modal-field-content"></pre>
      </div>
    </div>
  </div>

  <script>
    let activeTable = '';
    let activeTab = 'schema'; // schema or data
    let activeSchemaData = null;
    
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
      document.getElementById('btn-copy-structure').style.display = 'inline-flex';
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
      activeSchemaData = data;
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
      
      // Action Column Header
      const thActions = document.createElement('th');
      thActions.style.width = '60px';
      thActions.style.textAlign = 'center';
      thActions.textContent = 'Action';
      trHeader.appendChild(thActions);

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
        tbody.innerHTML = `<tr><td colspan="${data.columns.length + 1}" style="text-align: center; padding: 48px; color: var(--color-text-muted);">No records found matching filters.</td></tr>`;
      } else {
        data.rows.forEach(row => {
          const tr = document.createElement('tr');
          
           // Action column
          const tdActions = document.createElement('td');
          tdActions.style.textAlign = 'center';
          tdActions.style.padding = '8px 12px';
          tdActions.style.display = 'flex';
          tdActions.style.gap = '6px';
          tdActions.style.justifyContent = 'center';
          
          const btnCopy = document.createElement('button');
          btnCopy.className = 'btn-copy-action';
          btnCopy.title = 'Copy row data (JSON)';
          btnCopy.style.padding = '4px';
          btnCopy.style.borderRadius = '4px';
          btnCopy.innerHTML = `
            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
            </svg>
          `;
          btnCopy.onclick = (e) => copyRowData(row, e);
          
          const btnDelete = document.createElement('button');
          btnDelete.className = 'btn-copy-action btn-delete-action';
          btnDelete.title = 'Delete row';
          btnDelete.style.padding = '4px';
          btnDelete.style.borderRadius = '4px';
          btnDelete.style.color = 'var(--color-rose)';
          btnDelete.innerHTML = `
            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
          `;
          btnDelete.onclick = (e) => deleteRow(row, e);

          tdActions.appendChild(btnCopy);
          tdActions.appendChild(btnDelete);
          tr.appendChild(tdActions);

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

    let activeInspectValue = '';

    function syntaxHighlight(jsonStr) {
      jsonStr = jsonStr.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      return jsonStr.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
        let cls = 'json-number';
        if (/^"/.test(match)) {
          cls = (/:$/.test(match)) ? 'json-key' : 'json-string';
        } else if (/true|false/.test(match)) {
          cls = 'json-boolean';
        } else if (/null/.test(match)) {
          cls = 'json-null';
        }
        return '<span class="' + cls + '">' + match + '</span>';
      });
    }

    function inspectCell(field, val) {
      document.getElementById('modal-field-title').textContent = `Inspecting Cell: "${field}"`;
      const modalContentEl = document.getElementById('modal-field-content');
      
      let formatted = val;
      let isJson = false;
      
      try {
        if (typeof val === 'string') {
          const parsed = JSON.parse(val);
          formatted = JSON.stringify(parsed, null, 2);
          isJson = true;
        } else if (typeof val === 'object' && val !== null) {
          formatted = JSON.stringify(val, null, 2);
          isJson = true;
        }
      } catch (e) {
        formatted = val;
      }
      
      activeInspectValue = formatted;
      
      if (isJson) {
        modalContentEl.innerHTML = syntaxHighlight(formatted);
        document.getElementById('btn-copy-inspect').textContent = 'Copy JSON';
      } else {
        modalContentEl.textContent = formatted;
        document.getElementById('btn-copy-inspect').textContent = 'Copy Content';
      }
      
      document.getElementById('cell-inspect-modal').classList.add('active');
    }

    function closeInspector() {
      document.getElementById('cell-inspect-modal').classList.remove('active');
    }

    function copyInspectedContent() {
      const btn = document.getElementById('btn-copy-inspect');
      navigator.clipboard.writeText(activeInspectValue).then(() => {
        const originalText = btn.textContent;
        btn.textContent = 'Copied!';
        btn.style.background = 'var(--color-emerald)';
        setTimeout(() => {
          btn.textContent = originalText;
          btn.style.background = '';
        }, 1500);
      }).catch(err => {
        console.error('Copy failed:', err);
      });
    }

    function toggleCopyMenu(event) {
      event.stopPropagation();
      const menu = document.getElementById('copy-menu');
      if (menu) {
        menu.classList.toggle('active');
      }
    }

    function copyStructureAs(format, event) {
      if (!activeTable) return;
      
      // If we already have the schema data for the active table, use it
      if (activeSchemaData && activeSchemaData.table === activeTable) {
        performCopy(format, activeSchemaData, event);
      } else {
        // Fetch it on the fly
        const btn = event.currentTarget;
        const originalText = btn.textContent;
        btn.textContent = 'Loading...';
        btn.disabled = true;
        
        fetch(`api.php?action=get_table_schema&table=${encodeURIComponent(activeTable)}`)
          .then(res => res.json())
          .then(data => {
            btn.disabled = false;
            btn.textContent = originalText;
            if (!data.success) {
              alert('Error loading schema: ' + data.error);
              return;
            }
            activeSchemaData = data; // Cache it
            performCopy(format, data, event);
          })
          .catch(err => {
            btn.disabled = false;
            btn.textContent = originalText;
            alert('API communication error.');
          });
      }
    }

    function performCopy(format, data, event) {
      let content = '';
      if (format === 'sql') {
        content = generateSQL(data);
      } else if (format === 'markdown') {
        content = generateMarkdown(data);
      } else if (format === 'csv') {
        content = generateCSV(data);
      }
      
      navigator.clipboard.writeText(content).then(() => {
        const btn = event.currentTarget;
        const originalText = btn.textContent;
        btn.textContent = 'Copied!';
        btn.style.color = 'var(--color-emerald)';
        
        const mainBtn = document.getElementById('btn-copy-structure');
        if (mainBtn) {
          mainBtn.style.color = 'var(--color-emerald)';
          mainBtn.style.borderColor = 'var(--color-emerald)';
        }
        
        setTimeout(() => {
          btn.textContent = originalText;
          btn.style.color = '';
          if (mainBtn) {
            mainBtn.style.color = '';
            mainBtn.style.borderColor = '';
          }
          const menu = document.getElementById('copy-menu');
          if (menu) {
            menu.classList.remove('active');
          }
        }, 1500);
      }).catch(err => {
        console.error('Copy failed:', err);
        alert('Failed to copy to clipboard.');
      });
    }

    function generateSQL(data) {
      const tableName = data.table;
      let sql = `CREATE TABLE public."${tableName}" (\n`;
      
      const colLines = data.columns.map(col => {
        let line = `  "${col.column_name}" ${col.data_type}`;
        if (col.is_nullable === 'NO') {
          line += ' NOT NULL';
        }
        if (col.column_default !== null) {
          line += ` DEFAULT ${col.column_default}`;
        }
        return line;
      });

      // Find primary keys
      const pkCols = data.columns.filter(col => col.is_primary === 1).map(col => `"${col.column_name}"`);
      if (pkCols.length > 0) {
        colLines.push(`  CONSTRAINT "${tableName}_pkey" PRIMARY KEY (${pkCols.join(', ')})`);
      }

      // Add foreign keys constraints
      data.foreign_keys.forEach(fk => {
        const constraintName = `${tableName}_${fk.local_column}_fkey`;
        colLines.push(`  CONSTRAINT "${constraintName}" FOREIGN KEY ("${fk.local_column}") REFERENCES public."${fk.foreign_table}" ("${fk.foreign_column}")`);
      });

      sql += colLines.join(',\n') + '\n);';

      // Add indexes
      if (data.indexes && data.indexes.length > 0) {
        sql += '\n\n';
        const indexLines = data.indexes.map(idx => {
          let def = idx.indexdef;
          if (!def.endsWith(';')) def += ';';
          return def;
        });
        sql += indexLines.join('\n');
      }

      return sql;
    }

    function generateMarkdown(data) {
      const tableName = data.table;
      let md = `## Table: ${tableName}\n\n`;
      md += `| Column Name | Data Type | Nullable | Default Value | Keys / Constraints |\n`;
      md += `| :--- | :--- | :--- | :--- | :--- |\n`;
      
      data.columns.forEach(col => {
        let keys = [];
        if (col.is_primary) keys.push('PK');
        if (col.is_foreign) keys.push('FK');
        
        if (col.is_foreign) {
          const fk = data.foreign_keys.find(f => f.local_column === col.column_name);
          if (fk) {
            keys.push(`Ref: ${fk.foreign_table}(${fk.foreign_column})`);
          }
        }
        
        const nullableStr = col.is_nullable === 'YES' ? 'Yes' : 'No';
        const defaultStr = col.column_default !== null ? `\`${col.column_default}\`` : '*NULL*';
        const keysStr = keys.length > 0 ? keys.join(', ') : '-';
        
        md += `| **${col.column_name}** | \`${col.data_type}\` | ${nullableStr} | ${defaultStr} | ${keysStr} |\n`;
      });
      
      if (data.indexes && data.indexes.length > 0) {
        md += `\n### Indexes\n\n`;
        md += `| Index Name | Definition |\n`;
        md += `| :--- | :--- |\n`;
        data.indexes.forEach(idx => {
          md += `| \`${idx.indexname}\` | \`${idx.indexdef}\` |\n`;
        });
      }
      
      return md;
    }

    function generateCSV(data) {
      let csv = `column_name,data_type,is_nullable,column_default,is_primary,is_foreign\n`;
      data.columns.forEach(col => {
        const defaultVal = col.column_default !== null ? col.column_default.replace(/"/g, '""') : '';
        csv += `"${col.column_name}","${col.data_type}","${col.is_nullable}","${defaultVal}",${col.is_primary},${col.is_foreign}\n`;
      });
      return csv;
    }

    window.addEventListener('click', function(e) {
      const menu = document.getElementById('copy-menu');
      const btn = document.getElementById('btn-copy-structure');
      if (menu && menu.classList.contains('active') && !menu.contains(e.target) && !btn.contains(e.target)) {
        menu.classList.remove('active');
      }
    });

    function copyRowData(row, event) {
      event.stopPropagation();
      
      const content = JSON.stringify(row, null, 2);
      
      navigator.clipboard.writeText(content).then(() => {
        const btn = event.currentTarget;
        const originalHTML = btn.innerHTML;
        
        btn.innerHTML = `
          <svg style="width: 14px; height: 14px; color: var(--color-emerald);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
          </svg>
        `;
        btn.style.borderColor = 'var(--color-emerald)';
        btn.style.background = 'var(--color-emerald-glow)';
        
        setTimeout(() => {
          btn.innerHTML = originalHTML;
          btn.style.borderColor = '';
          btn.style.background = '';
        }, 1500);
      }).catch(err => {
        console.error('Row copy failed:', err);
        alert('Failed to copy row data.');
      });
    }

    function openInsertModal() {
      if (!activeTable) return;
      
      const fieldsContainer = document.getElementById('insert-form-fields');
      fieldsContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--color-text-muted);">Loading table schema...</div>';
      document.getElementById('insert-row-modal').classList.add('active');
      document.getElementById('insert-modal-title').textContent = `Insert New Row into "${activeTable}"`;

      fetch(`api.php?action=get_table_schema&table=${encodeURIComponent(activeTable)}`)
        .then(res => res.json())
        .then(data => {
          if (!data.success) {
            alert('Error loading schema: ' + data.error);
            closeInsertModal();
            return;
          }
          activeSchemaData = data;
          buildInsertForm(data.columns);
        })
        .catch(err => {
          alert('API communication error.');
          closeInsertModal();
        });
    }

    function closeInsertModal() {
      document.getElementById('insert-row-modal').classList.remove('active');
      document.getElementById('insert-row-form').reset();
    }

    function buildInsertForm(columns) {
      const container = document.getElementById('insert-form-fields');
      container.innerHTML = '';

      columns.forEach(col => {
        const fieldGroup = document.createElement('div');
        fieldGroup.className = 'form-field-group';

        const label = document.createElement('label');
        label.className = 'form-field-label';
        
        let labelText = col.column_name;
        let isRequired = false;

        if (col.column_default !== null || col.is_primary === 1) {
          labelText += ' (Optional / Auto-generated)';
        } else if (col.is_nullable === 'NO') {
          isRequired = true;
        } else {
          labelText += ' (Optional)';
        }

        label.innerHTML = `${escapeHtml(labelText)}${isRequired ? ' <span style="color: var(--color-rose);">*</span>' : ''}`;
        fieldGroup.appendChild(label);

        let control;
        const dataType = col.data_type.toLowerCase();

        if (dataType === 'boolean') {
          control = document.createElement('select');
          control.className = 'form-field-input';
          control.name = col.column_name;
          
          if (col.is_nullable === 'YES') {
            const optNull = document.createElement('option');
            optNull.value = '';
            optNull.textContent = 'NULL (Default)';
            control.appendChild(optNull);
          }
          
          const optTrue = document.createElement('option');
          optTrue.value = 'true';
          optTrue.textContent = 'True';
          control.appendChild(optTrue);

          const optFalse = document.createElement('option');
          optFalse.value = 'false';
          optFalse.textContent = 'False';
          control.appendChild(optFalse);

          if (col.column_default !== null) {
            if (col.column_default.includes('true')) {
              control.value = 'true';
            } else if (col.column_default.includes('false')) {
              control.value = 'false';
            }
          }
        } else if (dataType === 'json' || dataType === 'jsonb') {
          control = document.createElement('textarea');
          control.className = 'form-field-input';
          control.name = col.column_name;
          control.rows = 3;
          control.placeholder = '{}';
          if (isRequired) control.required = true;
          
          if (col.column_default !== null) {
            let def = col.column_default;
            if (def.includes('::')) {
              def = def.substring(0, def.indexOf('::'));
            }
            def = def.trim().replace(/^['"]|['"]$/g, '');
            control.value = def;
          }
        } else if (dataType === 'text' || (dataType.includes('char') && !dataType.includes('var') && col.character_maximum_length > 100)) {
          control = document.createElement('textarea');
          control.className = 'form-field-input';
          control.name = col.column_name;
          control.rows = 3;
          if (isRequired) control.required = true;
        } else {
          control = document.createElement('input');
          control.type = 'text';
          control.className = 'form-field-input';
          control.name = col.column_name;
          if (isRequired) control.required = true;

          if (col.column_default !== null) {
            let def = col.column_default;
            if (def.includes('::')) {
              def = def.substring(0, def.indexOf('::'));
            }
            def = def.trim().replace(/^['"]|['"]$/g, '');
            control.placeholder = `Default: ${def}`;
          } else if (col.is_primary === 1 && dataType === 'uuid') {
            control.placeholder = 'Auto-generated UUID';
          }
        }

        fieldGroup.appendChild(control);
        container.appendChild(fieldGroup);
      });
    }

    function handleInsertSubmit(event) {
      event.preventDefault();
      
      const form = event.target;
      const formData = new FormData(form);
      const payload = {
        table: activeTable
      };

      for (const [key, value] of formData.entries()) {
        payload[key] = value;
      }

      fetch('api.php?action=insert_row', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
        if (!data.success) {
          alert('Error inserting row: ' + data.error);
          return;
        }
        
        alert('Row inserted successfully!');
        closeInsertModal();
        
        currentPage = 1;
        loadActiveTableData();
      })
      .catch(err => {
        alert('API communication error.');
      });
    }

    function deleteRow(row, event) {
      event.stopPropagation();
      
      if (!confirm(`Are you sure you want to delete this row from "${activeTable}"?`)) {
        return;
      }
      
      if (!activeSchemaData || activeSchemaData.table !== activeTable) {
        alert("Schema data not loaded. Please refresh.");
        return;
      }
      
      const pkCols = activeSchemaData.columns.filter(col => col.is_primary === 1).map(col => col.column_name);
      if (pkCols.length === 0) {
        alert("Cannot delete: This table has no primary key column defined.");
        return;
      }
      
      const payload = {
        table: activeTable
      };
      
      pkCols.forEach(col => {
        payload[col] = row[col];
      });
      
      fetch('api.php?action=delete_row', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
        if (!data.success) {
          alert('Error deleting row: ' + data.error);
          return;
        }
        
        alert('Row deleted successfully!');
        loadActiveTableData();
      })
      .catch(err => {
        alert('API communication error.');
      });
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
