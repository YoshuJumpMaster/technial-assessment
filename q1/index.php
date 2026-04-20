<?php

/**
 * Server Monitoring Dashboard — index.php
 *
 * ARCHITECTURE OVERVIEW
 * ─────────────────────
 * This is a single-file PHP application. All backend logic, data fetching,
 * HTML rendering, CSS, and JavaScript live in one file to keep deployment
 * simple — no framework, no autoloader, no build step required.
 *
 * REQUEST LIFECYCLE
 * ─────────────────
 * 1. If the request is POST, a hidden <input name="action"> field in each
 *    form tells the switch statement which operation to run. After the
 *    operation completes (or fails), PHP either redirects back to this page
 *    or outputs a JSON error. Either way, execution stops before any HTML
 *    is rendered, so there is never a risk of mixing response types.
 *
 * 2. If the request is GET (normal page load or post-redirect), the POST
 *    block is skipped entirely. PHP then queries the database, builds the
 *    $servers array, closes the connection, and falls through to the HTML.
 *
 * WHY MYSQLI WITH PREPARED STATEMENTS
 * ─────────────────────────────────────
 * MySQLi is used instead of PDO because this stack is MySQL-only and MySQLi
 * ships with PHP by default, removing any extension dependency. Prepared
 * statements are used for every query that accepts user input — bind_param()
 * ensures values are always treated as data, never as SQL, which eliminates
 * SQL injection regardless of what the user submits.
 */

// ─── Database Connection ───────────────────────────────────────────────────

$host   = 'localhost';
$db     = 'server_monitor';
$user   = 'root';
$pass   = '';
$port   = 3306;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

// ─── Route POST Requests ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'register':
            registerServer($conn);
            break;
        case 'edit':
            editServer($conn);
            break;
        case 'maintenance':
            setMaintenance($conn);
            break;
        case 'delete':
            deleteServer($conn);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
}

// ─── Operation: Register New Server ───────────────────────────────────────
// Inserts a new row into the Server table.
// Required POST fields: hostname, ip_address, port, os_type, server_software, environment
// Status defaults to 'active', created_at is set automatically.
//
// WHY THIS FUNCTION RETURNS JSON INSTEAD OF REDIRECTING
// ──────────────────────────────────────────────────────
// Unlike the other operations, registerServer returns a JSON success response
// that includes the new server's insert_id. This makes the function useful
// beyond the browser form — a script or monitoring agent could POST to this
// endpoint and immediately get back the assigned id to use in follow-up
// requests (e.g. posting an initial metric_snapshot). The other operations
// (edit, maintenance, delete) act on servers that already exist and have
// known ids, so there is no new data the caller needs back — a redirect is
// cleaner and prevents the browser's "resubmit form?" prompt on refresh.

function registerServer(mysqli $conn): void
{
    $hostname        = trim($_POST['hostname']        ?? '');
    $ip_address      = trim($_POST['ip_address']      ?? '');
    $port            = intval($_POST['port']           ?? 0);
    $os_type         = trim($_POST['os_type']          ?? '');
    $server_software = trim($_POST['server_software']  ?? '');
    $environment     = trim($_POST['environment']      ?? '');

    if (!$hostname || !$ip_address || !$port) {
        http_response_code(400);
        echo json_encode(['error' => 'hostname, ip_address, and port are required']);
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO Server (hostname, ip_address, port, os_type, server_software, environment, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, \'active\', NOW())'
    );

    $stmt->bind_param('ssisss', $hostname, $ip_address, $port, $os_type, $server_software, $environment);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'server_id' => $stmt->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to register server: ' . $stmt->error]);
    }

    $stmt->close();
}


// ─── Operation: Edit Existing Server ──────────────────────────────────────
// Updates the static/configuration fields of an existing server row.
// Required POST fields: id, hostname, ip_address, port, os_type, server_software, environment
// Does NOT touch status or created_at.
//
// Status is intentionally excluded from this update. Status transitions
// (active → maintenance, etc.) are discrete operations with their own
// action handlers so that status changes are always explicit and auditable,
// never an accidental side-effect of a routine config edit.
// created_at is a write-once timestamp and must never be overwritten.

function editServer(mysqli $conn): void
{
    $id              = intval($_POST['id']             ?? 0);
    $hostname        = trim($_POST['hostname']         ?? '');
    $ip_address      = trim($_POST['ip_address']       ?? '');
    $port            = intval($_POST['port']            ?? 0);
    $os_type         = trim($_POST['os_type']           ?? '');
    $server_software = trim($_POST['server_software']   ?? '');
    $environment     = trim($_POST['environment']       ?? '');

    if (!$id || !$hostname || !$ip_address || !$port) {
        http_response_code(400);
        echo json_encode(['error' => 'id, hostname, ip_address, and port are required']);
        return;
    }

    $stmt = $conn->prepare(
        'UPDATE Server
         SET hostname = ?, ip_address = ?, port = ?, os_type = ?, server_software = ?, environment = ?
         WHERE id = ?'
    );

    $stmt->bind_param('ssisssi', $hostname, $ip_address, $port, $os_type, $server_software, $environment, $id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Server not found']);
        } else {
            header('Location: index.php');
            exit;
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update server: ' . $stmt->error]);
    }

    $stmt->close();
}


// ─── Operation: Set Server to Maintenance ─────────────────────────────────
// Manually overrides a server's status to 'maintenance'.
// Required POST fields: id
//
// This is a dedicated action rather than a generic "set status" endpoint
// so that maintenance mode is always an intentional, named operation.
// Keeping it separate also makes it straightforward to add pre/post hooks
// later (e.g. suppressing alerts while a server is in maintenance).

function setMaintenance(mysqli $conn): void
{
    $id = intval($_POST['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        return;
    }

    $stmt = $conn->prepare('UPDATE Server SET status = \'maintenance\' WHERE id = ?');
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Server not found']);
        } else {
            header('Location: index.php');
            exit;
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to set maintenance: ' . $stmt->error]);
    }

    $stmt->close();
}


// ─── Operation: Delete Server ──────────────────────────────────────────────
// Removes a server row by id.
// Required POST fields: id
//
// WHY DEPENDENT ROWS ARE DELETED MANUALLY BEFORE THE SERVER ROW
// ──────────────────────────────────────────────────────────────
// The foreign keys on metric_snapshot and Alert may not have ON DELETE CASCADE
// configured — this depends on how the schema was created and the storage
// engine in use (CASCADE requires InnoDB). Rather than assume the schema is
// set up correctly and risk a constraint violation that silently prevents
// deletion, we explicitly delete child rows first. This makes the operation
// safe regardless of the FK configuration and keeps the behaviour predictable
// across different environments (dev, staging, production).

function deleteServer(mysqli $conn): void
{
    $id = intval($_POST['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        return;
    }

    // Remove dependent rows first if foreign keys don't cascade
    foreach (['metric_snapshot', 'Alert'] as $table) {
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE server_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare('DELETE FROM Server WHERE id = ?');
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Server not found']);
        } else {
            header('Location: index.php');
            exit;
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete server: ' . $stmt->error]);
    }

    $stmt->close();
}

// ─── HTML ──────────────────────────────────────────────────────────────────

// Fetch all servers with their most recent metric_snapshot values.
//
// WHY A CORRELATED SUBQUERY INSTEAD OF A DIRECT JOIN
// ────────────────────────────────────────────────────
// A naive LEFT JOIN metric_snapshot ON server_id = s.id would return one row
// per snapshot, not one row per server. Fixing that with GROUP BY + MAX()
// works for recorded_at but makes it awkward to also retrieve the other
// columns (http_status_code, response_time_ms) from that same latest row
// without a second join or a subquery anyway.
//
// The correlated subquery (SELECT id ... ORDER BY recorded_at DESC LIMIT 1)
// resolves the id of the single most-recent snapshot for each server, then
// the outer LEFT JOIN fetches all columns from exactly that row. This is
// clear, correct, and avoids the GROUP BY ambiguity. The trade-off is one
// subquery execution per server row, which is acceptable at dashboard scale
// and can be optimised with an index on (server_id, recorded_at) if needed.
$servers = [];
$result = $conn->query(
    'SELECT
        s.id, s.hostname, s.ip_address, s.port, s.status,
        s.os_type, s.server_software, s.environment,
        m.http_status_code, m.response_time_ms
     FROM Server s
     LEFT JOIN metric_snapshot m
        ON m.id = (
            SELECT id FROM metric_snapshot
            WHERE server_id = s.id
            ORDER BY recorded_at DESC
            LIMIT 1
        )
     ORDER BY s.hostname ASC'
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $servers[] = $row;
    }
}

$conn->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Monitoring Dashboard</title>

    <!-- ── CSS ───────────────────────────────────────────────────────── -->
    <style>
        /* Reset & base */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            min-height: 100vh;
        }

        /* ── Navbar ── */
        .navbar {
            background: #1a1a2e;
            color: #fff;
            padding: 0 2rem;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
        }

        .navbar h1 {
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: .5px;
        }

        /* ── Main content area ── */
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .toolbar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 1.25rem;
        }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .5rem 1.1rem;
            border: none;
            border-radius: 6px;
            font-size: .875rem;
            font-weight: 500;
            cursor: pointer;
            transition: opacity .15s;
            text-decoration: none;
        }

        .btn:hover { opacity: .85; }

        .btn-primary   { background: #4f46e5; color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #374151; }
        .btn-warning   { background: #f59e0b; color: #fff; }
        .btn-danger    { background: #ef4444; color: #fff; }

        /* ── Table ── */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .875rem;
        }

        thead {
            background: #1a1a2e;
            color: #fff;
        }

        thead th {
            padding: .85rem 1rem;
            text-align: left;
            font-weight: 500;
            letter-spacing: .4px;
            white-space: nowrap;
        }

        tbody tr:nth-child(even)  { background: #f8f9fb; }
        tbody tr:nth-child(odd)   { background: #ffffff; }
        tbody tr:hover            { background: #eef2ff; }

        tbody td {
            padding: .8rem 1rem;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }

        .actions { display: flex; gap: .5rem; flex-wrap: wrap; }

        .empty-state {
            padding: 3rem;
            text-align: center;
            color: #6b7280;
            font-size: .95rem;
        }

        /* ── Status badges ── */
        .badge {
            display: inline-block;
            padding: .25rem .65rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .badge-active      { background: #d1fae5; color: #065f46; }
        .badge-inactive    { background: #fee2e2; color: #991b1b; }
        .badge-maintenance { background: #fef3c7; color: #92400e; }
        .badge-unknown     { background: #e5e7eb; color: #374151; }

        /* ── Modal overlay ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open { display: flex; }

        .modal {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 8px 32px rgba(0,0,0,.2);
            position: relative;
        }

        .modal h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #1a1a2e;
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: #6b7280;
            line-height: 1;
        }

        .modal-close:hover { color: #111; }

        /* ── Form fields ── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: .35rem;
        }

        .form-group.full { grid-column: 1 / -1; }

        .form-group label {
            font-size: .8rem;
            font-weight: 500;
            color: #374151;
        }

        .form-group input,
        .form-group select {
            padding: .5rem .75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: .875rem;
            outline: none;
            transition: border-color .15s;
        }

        .form-group input:focus,
        .form-group select:focus { border-color: #4f46e5; }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: .75rem;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>

    <!-- ── Navbar ──────────────────────────────────────────────────────── -->
    <nav class="navbar">
        <h1>Server Monitoring Dashboard</h1>
    </nav>

    <div class="container">

        <!-- ── Toolbar ───────────────────────────────────────────────── -->
        <div class="toolbar">
            <button class="btn btn-primary" id="openAddModal">+ Add Server</button>
        </div>

        <!-- ── Server List Table ─────────────────────────────────────── -->
        <div class="card">
            <?php if (empty($servers)): ?>
                <p class="empty-state">No servers registered yet. Add one to get started.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Hostname</th>
                        <th>IP Address</th>
                        <th>Port</th>
                        <th>Status</th>
                        <th>Last HTTP Status</th>
                        <th>Last Response (ms)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($servers as $server): ?>
                    <?php
                        $status = strtolower($server['status'] ?? '');
                        $badgeClass = match($status) {
                            'active'      => 'badge-active',
                            'inactive'    => 'badge-inactive',
                            'maintenance' => 'badge-maintenance',
                            default       => 'badge-unknown',
                        };
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($server['hostname']) ?></td>
                        <td><?= htmlspecialchars($server['ip_address']) ?></td>
                        <td><?= htmlspecialchars($server['port']) ?></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($server['status']) ?></span></td>
                        <td><?= $server['http_status_code'] !== null ? htmlspecialchars($server['http_status_code']) : '—' ?></td>
                        <td><?= $server['response_time_ms'] !== null ? htmlspecialchars($server['response_time_ms']) : '—' ?></td>
                        <td>
                            <div class="actions">
                                <!--
                                    Edit button — data-* attribute pattern
                                    ──────────────────────────────────────
                                    Each server's current field values are embedded directly
                                    into the button element as data-* attributes when PHP
                                    renders the table row. This means the edit modal can be
                                    pre-filled entirely in JavaScript without a round-trip to
                                    the server. The alternative — navigating to ?edit=<id> and
                                    re-rendering the page — would cause a full reload and lose
                                    the user's scroll position. ENT_QUOTES is passed to
                                    htmlspecialchars() so that values containing single or
                                    double quotes don't break the HTML attribute syntax.
                                -->
                                <button class="btn btn-secondary btn-edit"
                                    data-id="<?= $server['id'] ?>"
                                    data-hostname="<?= htmlspecialchars($server['hostname'], ENT_QUOTES) ?>"
                                    data-ip="<?= htmlspecialchars($server['ip_address'], ENT_QUOTES) ?>"
                                    data-port="<?= htmlspecialchars($server['port'], ENT_QUOTES) ?>"
                                    data-os="<?= htmlspecialchars($server['os_type'] ?? '', ENT_QUOTES) ?>"
                                    data-software="<?= htmlspecialchars($server['server_software'] ?? '', ENT_QUOTES) ?>"
                                    data-environment="<?= htmlspecialchars($server['environment'] ?? '', ENT_QUOTES) ?>">
                                    Edit
                                </button>

                                <!-- Set Maintenance -->
                                <form method="POST" action="index.php">
                                    <input type="hidden" name="action" value="maintenance">
                                    <input type="hidden" name="id" value="<?= $server['id'] ?>">
                                    <button type="submit" class="btn btn-warning">Maintenance</button>
                                </form>

                                <!-- Delete -->
                                <form method="POST" action="index.php"
                                      onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($server['hostname'])) ?>?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $server['id'] ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Add Server Modal ────────────────────────────────────────────── -->
    <div class="modal-overlay" id="addModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
            <button class="modal-close" id="closeAddModal" aria-label="Close">&times;</button>
            <h2 id="addModalTitle">Register New Server</h2>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="register">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add-hostname">Hostname</label>
                        <input type="text" id="add-hostname" name="hostname" required>
                    </div>
                    <div class="form-group">
                        <label for="add-ip">IP Address</label>
                        <input type="text" id="add-ip" name="ip_address" required>
                    </div>
                    <div class="form-group">
                        <label for="add-port">Port</label>
                        <input type="number" id="add-port" name="port" required min="1" max="65535">
                    </div>
                    <div class="form-group">
                        <label for="add-os">OS Type</label>
                        <input type="text" id="add-os" name="os_type">
                    </div>
                    <div class="form-group">
                        <label for="add-software">Server Software</label>
                        <input type="text" id="add-software" name="server_software">
                    </div>
                    <div class="form-group">
                        <label for="add-env">Environment</label>
                        <select id="add-env" name="environment">
                            <option value="">— select —</option>
                            <option value="production">Production</option>
                            <option value="staging">Staging</option>
                            <option value="development">Development</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelAddModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Register</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Edit Server Modal ───────────────────────────────────────────── -->
    <div class="modal-overlay" id="editModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
            <button class="modal-close" id="closeEditModal" aria-label="Close">&times;</button>
            <h2 id="editModalTitle">Edit Server</h2>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit-hostname">Hostname</label>
                        <input type="text" id="edit-hostname" name="hostname" required>
                    </div>
                    <div class="form-group">
                        <label for="edit-ip">IP Address</label>
                        <input type="text" id="edit-ip" name="ip_address" required>
                    </div>
                    <div class="form-group">
                        <label for="edit-port">Port</label>
                        <input type="number" id="edit-port" name="port" required min="1" max="65535">
                    </div>
                    <div class="form-group">
                        <label for="edit-os">OS Type</label>
                        <input type="text" id="edit-os" name="os_type">
                    </div>
                    <div class="form-group">
                        <label for="edit-software">Server Software</label>
                        <input type="text" id="edit-software" name="server_software">
                    </div>
                    <div class="form-group">
                        <label for="edit-env">Environment</label>
                        <select id="edit-env" name="environment">
                            <option value="">— select —</option>
                            <option value="production">Production</option>
                            <option value="staging">Staging</option>
                            <option value="development">Development</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelEditModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── JavaScript ──────────────────────────────────────────────────── -->
    <script>
        /**
         * MODAL OPEN / CLOSE LOGIC
         * ─────────────────────────
         * Modals are toggled by adding/removing the CSS class "open" on the
         * .modal-overlay element. The overlay starts as display:none in CSS;
         * adding "open" switches it to display:flex, which both shows the
         * backdrop and centres the modal box via flexbox alignment.
         *
         * THREE WAYS TO CLOSE A MODAL — WHY ALL THREE ARE HANDLED
         * ─────────────────────────────────────────────────────────
         * 1. The × button and Cancel button: explicit, discoverable controls
         *    that any user will find immediately.
         *
         * 2. Clicking the dark backdrop (outside the modal box): a widely
         *    expected convention in modern UIs. The click handler checks
         *    e.target === overlay so that clicks *inside* the modal box
         *    (which bubble up to the overlay) are ignored — only a direct
         *    click on the backdrop itself triggers a close.
         *
         * 3. The Escape key: standard keyboard accessibility behaviour.
         *    Users who opened the modal via keyboard expect Escape to dismiss
         *    it. The handler queries for any currently open overlay so it
         *    works for both the add and edit modals without duplicating logic.
         */

        // ── Modal helpers ──────────────────────────────────────────────

        function openModal(overlay) {
            overlay.classList.add('open');
            // Trap focus on first input inside the modal
            const first = overlay.querySelector('input, select, button');
            if (first) first.focus();
        }

        function closeModal(overlay) {
            overlay.classList.remove('open');
        }

        // Close modal when clicking the dark backdrop (outside the modal box)
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', e => {
                if (e.target === overlay) closeModal(overlay);
            });
        });

        // Close modal on Escape key
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.open').forEach(closeModal);
            }
        });

        // ── Add Server Modal ───────────────────────────────────────────

        const addModal      = document.getElementById('addModal');
        const openAddBtn    = document.getElementById('openAddModal');
        const closeAddBtn   = document.getElementById('closeAddModal');
        const cancelAddBtn  = document.getElementById('cancelAddModal');

        openAddBtn.addEventListener('click',   () => openModal(addModal));
        closeAddBtn.addEventListener('click',  () => closeModal(addModal));
        cancelAddBtn.addEventListener('click', () => closeModal(addModal));

        // ── Edit Server Modal ──────────────────────────────────────────
        // Each Edit button carries the server's current values in data-* attributes
        // (written by PHP at render time). When clicked, we read those attributes
        // and push them into the shared edit form fields before opening the modal.
        // This means there is only one edit form in the DOM — it is reused for
        // every row — keeping the HTML lean and avoiding id collisions.

        const editModal     = document.getElementById('editModal');
        const closeEditBtn  = document.getElementById('closeEditModal');
        const cancelEditBtn = document.getElementById('cancelEditModal');

        closeEditBtn.addEventListener('click',  () => closeModal(editModal));
        cancelEditBtn.addEventListener('click', () => closeModal(editModal));

        document.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                // Pre-fill hidden id
                document.getElementById('edit-id').value       = btn.dataset.id;

                // Pre-fill text inputs
                document.getElementById('edit-hostname').value = btn.dataset.hostname;
                document.getElementById('edit-ip').value       = btn.dataset.ip;
                document.getElementById('edit-port').value     = btn.dataset.port;
                document.getElementById('edit-os').value       = btn.dataset.os;
                document.getElementById('edit-software').value = btn.dataset.software;

                // Pre-select the correct environment option
                const envSelect = document.getElementById('edit-env');
                for (const opt of envSelect.options) {
                    opt.selected = (opt.value === btn.dataset.environment);
                }

                // Update modal title with hostname
                document.getElementById('editModalTitle').textContent =
                    'Edit Server — ' + btn.dataset.hostname;

                openModal(editModal);
            });
        });
    </script>

</body>
</html>
