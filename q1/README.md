# Server Monitoring Dashboard

A single-file PHP dashboard for registering and managing servers, viewing their
latest health metrics, and controlling their operational status. Built as a
self-contained tool that can be dropped onto any PHP/MySQL host with no
framework, build step, or dependency installation required.

---

## Purpose and Context

The dashboard was built for small-to-medium infrastructure setups where a
lightweight, low-overhead monitoring interface is preferred over a full
observability platform. It covers the operational side of server management —
registering servers, editing their configuration, flagging them for maintenance,
and removing them — while displaying the most recent HTTP status code and
response time pulled from a separate metrics collection process that writes to
the `metric_snapshot` table.

---

## Database Schema

Three tables are required. Run the following DDL against your MySQL database
before starting the application.

### `Server`

| Column            | Type           | Notes                                          |
|-------------------|----------------|------------------------------------------------|
| `id`              | INT, PK, AI    | Auto-incremented primary key                   |
| `hostname`        | VARCHAR(255)   | Human-readable server name                     |
| `ip_address`      | VARCHAR(45)    | Supports IPv4 and IPv6                         |
| `port`            | SMALLINT       | Listening port (1–65535)                       |
| `os_type`         | VARCHAR(100)   | e.g. Ubuntu 22.04, Windows Server 2022         |
| `server_software` | VARCHAR(100)   | e.g. Nginx 1.24, Apache 2.4                    |
| `environment`     | VARCHAR(50)    | `production`, `staging`, or `development`      |
| `status`          | ENUM('active','inactive','maintenance') | Current operational status    |
| `created_at`      | DATETIME       | Set to `NOW()` on insert, never updated        |

### `metric_snapshot`

| Column             | Type        | Notes                                         |
|--------------------|-------------|-----------------------------------------------|
| `id`               | INT, PK, AI | Auto-incremented primary key                  |
| `server_id`        | INT, FK     | References `Server.id`                        |
| `http_status_code` | SMALLINT    | Last observed HTTP response code              |
| `response_time_ms` | INT         | Last observed response time in milliseconds   |
| `recorded_at`      | DATETIME    | Timestamp of the snapshot                     |

### `Alert`

| Column            | Type         | Notes                                          |
|-------------------|--------------|------------------------------------------------|
| `id`              | INT, PK, AI  | Auto-incremented primary key                   |
| `server_id`       | INT, FK      | References `Server.id`                         |
| `metric_name`     | VARCHAR(100) | Name of the metric that triggered the alert    |
| `threshold_value` | DECIMAL      | The threshold that was breached                |
| `severity`        | VARCHAR(50)  | e.g. `warning`, `critical`                     |
| `message`         | TEXT         | Human-readable alert description               |
| `status`          | VARCHAR(50)  | e.g. `open`, `resolved`                        |
| `triggered_at`    | DATETIME     | When the alert was first raised                |
| `resolved_at`     | DATETIME     | When the alert was resolved, nullable          |

---

## Running Locally

### Requirements

- PHP 8.1 or higher (uses `match` expressions and named argument syntax)
- MySQL 5.7 or higher / MariaDB 10.4 or higher
- A web server that processes PHP — Apache with `mod_php`, Nginx with PHP-FPM,
  or the PHP built-in server are all fine for local use

### Setup

1. Create the database and run the DDL for the three tables above.

2. Open `index.php` and update the connection variables near the top of the file:

   ```php
   $host = 'localhost';
   $db   = 'server_monitor'; // your database name
   $user = 'root';           // your MySQL username
   $pass = '';               // your MySQL password
   $port = 3306;             // your MySQL port
   ```

3. Place `index.php` in your web root (or any directory served by PHP) and
   navigate to it in a browser.

   To use the PHP built-in server from the project directory:

   ```bash
   php -S localhost:8080
   ```

   Then open `http://localhost:8080/index.php`.

---

## Architecture Decisions

### Single-file structure

All backend logic, data fetching, HTML, CSS, and JavaScript live in one file.
This was a deliberate choice for portability — the entire application is
deployable by copying a single file. There is no autoloader to configure, no
`composer install` to run, and no build pipeline to maintain. The trade-off
(discussed further below) is that the file grows large and mixes concerns, which
becomes harder to maintain as the application scales.

### POST routing through the `action` field

Every form includes a hidden `<input name="action">` field. When a POST request
arrives, a `switch` statement at the top of the file reads this field and
dispatches to the appropriate function. This pattern avoids the need for URL
routing or separate endpoint files while keeping each operation clearly named
and isolated in its own function. Any unknown action value returns a 400 error
rather than silently doing nothing.

### Prepared statements

All queries that accept user input use MySQLi prepared statements with
`bind_param()`. This ensures user-supplied values are always treated as data
by the MySQL driver, never interpolated into the query string, which eliminates
SQL injection regardless of the input content. MySQLi was chosen over PDO
because this application targets MySQL exclusively and MySQLi ships with PHP
by default, removing any extension dependency.

### Correlated subquery for the latest metric snapshot

The server list query uses a correlated subquery to retrieve the most recent
`metric_snapshot` row for each server:

```sql
LEFT JOIN metric_snapshot m
    ON m.id = (
        SELECT id FROM metric_snapshot
        WHERE server_id = s.id
        ORDER BY recorded_at DESC
        LIMIT 1
    )
```

A direct `LEFT JOIN` without this would return one row per snapshot rather than
one row per server. The common alternative — `GROUP BY server_id` with
`MAX(recorded_at)` — resolves the timestamp correctly but makes it awkward to
also retrieve the other columns (`http_status_code`, `response_time_ms`) from
that same row without a second join. The correlated subquery resolves the exact
row id of the latest snapshot, then the outer join fetches all its columns
cleanly. The trade-off is one subquery execution per server row; this is
acceptable at dashboard scale and can be addressed by adding a composite index
on `(server_id, recorded_at)` if the table grows large.

### Manual deletion of dependent rows in `deleteServer`

Before deleting a server row, `deleteServer` explicitly deletes the server's
rows from `metric_snapshot` and `Alert`:

```php
foreach (['metric_snapshot', 'Alert'] as $table) {
    $stmt = $conn->prepare("DELETE FROM `$table` WHERE server_id = ?");
    ...
}
```

This is done because `ON DELETE CASCADE` on the foreign keys is not guaranteed
— it depends on how the schema was created and whether the InnoDB storage engine
is in use. Relying on cascade behaviour that may not be present would cause a
foreign key constraint violation and silently prevent the server from being
deleted. Explicit deletion makes the operation safe and predictable across all
environments without requiring a specific schema configuration.

---

## Notable Adaptations During Development

### `$conn->close()` placement fix

In the initial backend-only version, `$conn->close()` was called immediately
after the POST routing block — before any HTML was rendered. When the HTML block
was added and needed to query the database to build the server list, the
connection was already closed, which would have caused a fatal error at runtime.
The fix was to move `$conn->close()` to after the data fetch query, so the
connection stays open for the full duration of the PHP execution and is only
closed once all database work is complete.

### Edit form refactor from query parameters to `data-*` attributes

The first version of the edit form was driven by a `?edit=<id>` query parameter.
Clicking Edit navigated to `index.php?edit=5`, PHP detected the parameter,
fetched the server row, and rendered a pre-filled form inline on the page. This
worked but caused a full page reload on every edit click, losing the user's
scroll position, and required PHP to re-query the database just to populate a
form with data already present in the rendered table.

The refactored approach embeds each server's current field values directly into
the Edit button as `data-*` attributes when PHP renders the table row. JavaScript
reads those attributes on click and populates a single shared edit modal without
any server round-trip. This removed the `?edit=` query parameter handling
entirely from the PHP layer, simplified the HTML (one edit form instead of a
conditionally rendered inline form), and made the interaction faster and
scroll-position-safe.

---

## Single-file Approach: Trade-offs and Production Considerations

The single-file structure is well suited to the scope this project was built for.
For a production environment handling real infrastructure, the same functionality
would typically be structured differently:

- **Separation of concerns** — database connection, business logic, and
  presentation would live in separate files or classes. A `Database` class would
  manage the connection, individual controller or service classes would own each
  operation, and templates or a front-end framework would handle rendering.

- **Routing** — a router (even a minimal one) would map URLs and HTTP methods to
  handlers, replacing the `action` field switch. This makes the API surface
  explicit and easier to document and test.

- **Security hardening** — a production deployment would add CSRF token
  validation on all state-changing POST requests, authentication and
  authorisation before any operation is reachable, rate limiting, and HTTPS
  enforcement.

- **Error handling** — rather than returning raw MySQLi error strings in JSON
  responses, a production application would log errors server-side and return
  generic messages to the client to avoid leaking internal details.

- **Frontend** — the inline CSS and JavaScript would be extracted into separate
  asset files, processed through a build pipeline, and served with appropriate
  cache headers.

The single-file approach here is a conscious trade-off: it sacrifices
scalability and separation of concerns in exchange for simplicity, portability,
and zero setup overhead — which is the right trade-off for the context this
dashboard was built for.
