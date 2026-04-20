# AI Usage Note

## Solution Overview

The solution here applied is structured over a single `index.php` file containing all layers of the application, following a specific order, php backend logic at the top, data fetch queries in the middle, and plain HTML, CSS and javascript at the bottom. PHP is ther server sided language handling all data operations, and mysqli the built in php extension used to connect and communicate with the MySQL database. I chose to use this single file formart given the context of this assessment, since no build step, no framework, and no routing configuration were required in this implementation.

---

## GUI and Interaction Design

The gui for this application was conceptualized as a dashboard with a top navigation bar, a list of servers as the main content shown as table containing each server's hostname, ip_address, port, status and most recent http_status_code and response_time_ms pulled from the metric_snapshot table. Each row on the table also has edit, maintenance and delete action buttons, this accounts for the writing operations request of the assignment. Adding and editing operations here are handled through pop ups so that the user never needs to leave the dashboard page.

---

## Backend Design Decisions

Every pop up form submission includes a hidden field identifying which operation to perform, the backend in turn reads this field and routes the request to the correct function without needing separate pages for each operation type(register, edit, maintenance or delete). In turn, each function handles its database interaction in a way that prevents user input from being interpreted as part of the query, which is good practice since its standard protection against sql injections. After an operation is completed, the page redirects back to itself, effectively being updated, this also prevents the browser from accidentally resubmitting the same form if the user manually refreshes the page. The register operation is the exception to this behavior since after processing its required to return the ID of the newly created server.

The server list query is written specifically to return exactly one row per server, pulling only the most recent monitoring snapshot for each one, this prevents scenarios such as when a server with multiple snapshots in history would appear multiple times in the table, being redundant and not useful for monitoring. Delete operations also clean up all monitoring and alert records linked to a server before removing it, rather than just relying on the database to do the work automatically, this is better since automatic database compensation is not guaranteed depending on how the database was configured, and since here there's no say on how the database would have been configured, implementing this failsafe is beneficial.

---

## Notable Adaptations Made by Kiro

At last, there were two notable adaptions Kiro made by its own and are worth mentioning.

The first one would be in prompt 3, Kiro caught that the database connection was being closed before the server list query ran, which if left untreated would lead the table to load empty with no visible error. Kiro fixed this by moving the database connection close after the query.

In prompt 4, Kiro improved the edit form so that clicking on the edit button on any row would instantly open a pre-filled pop up using data already present on the page, instead of needing to reload the page to fetch that server's data from the database again. Both adaption were reviewed and accepted.

---

## Production Considerations

It's also worth noting that the single file format approach I chose was motivated by simplicity's sake given the context of this assessment, however, in a production environment, php logic css and javascript would need to be separated into distinct files, each concerning its own layer of the application, and the solution would also need to include other aspects such as authentication, protection and error handling.

---

## Repository

Link to github repository: https://github.com/YoshuJumpMaster/technial-assessment/tree/main/q1

---

## Prompts and Explanations

### Prompt 1

```
Create a single index.php file for a server monitoring dashboard. The backend should use PHP with MySQLi to connect to a MySQL database. It should handle four operations via POST requests: registering a new server (insert into Server table), editing an existing server's static fields (hostname, ip_address, port, os_type, server_software, environment), manually setting a server's status to maintenance, and deleting a server. Each operation should be triggered by a hidden action field in the form submission. The database has three tables: Server (id, hostname, ip_address, port, os_type, server_software, environment, status, created_at), metric_snapshot (id, server_id, http_status_code, response_time_ms, recorded_at) and Alert (id, server_id, metric_name, threshold_value, severity, message, status, triggered_at, resolved_at). Write only the PHP backend logic for now, no HTML or CSS yet. Add comments separating each operation clearly.
```

Explanation: This prompt was scoping the backend only before actually building any interface around it. Each operation in the backend is isolated in its own function via the hidden action field, keeping all logic in one place without requiring a router. Accepted, but with adjustment in the following prompt.

---

### Prompt 2

```
In the current index.php, all four operations return JSON responses after success. Since this is a single file PHP application that renders HTML, replace these responses with a redirect back to index.php. Keep the JSON error responses for failure cases since those are still useful for debugging. Also keep the JSON success response only for registerServer since it returns the new server's insert_id which may be useful later.
```

Explanation: This was a necessary adjustment before adding HTML, all success responses were returning raw json which the browser would display instead of the dashboard. Kiro applied the required edits and left registerserver() unchanged as requested. Accepted without modifications.

---

### Prompt 3

```
Now add the HTML structure to the same index.php file. The page should have a server list table displaying all servers with columns for hostname, ip_address, port, status, last http_status_code and last response_time_ms, the last two being pulled from the most recent metric_snapshot for each server ensuring only one snapshot row per server is returned. Each row should have edit, set maintenance and delete action buttons. Above the table there should be an Add Server button that opens a form to register a new server. The edit action should populate a form with the server's current values. Keep the PHP backend block at the top of the file and the HTML below it, separated by a clear comment.
```

Explanation: In this prompt I prompted kiro to design the html structure, detailing the concept I had envisioned and the constraints. The correlated subquery was also specified explicitly to avoid a direct join returning multiple rows per server, as this would break the dashboard table. Kiro also caught the connection closing before the query ran and fixed it in this step. Accepted without modifications.

---

### Prompt 4

```
Now add CSS styles and JavaScript to the same index.php file. The CSS should style the page as a clean, minimal dashboard: a top navigation bar with the page title, a styled HTML table with alternating row colors, status badges with colors reflecting the server status (green for active, red for inactive, yellow for maintenance), and a pop up for the add and edit forms. The JavaScript should handle opening and closing the pop up. Add the CSS inside a style tag in the head and the JavaScript inside a script tag before the closing body tag, both clearly commented.
```

Explanation: In this prompt I prompted kiro to design the CSS for the page, giving it description of what I had conceptualized, a minimal dashboard, and the pop up design. For the styling, I also decided to go with status badges with semantic colors, as they would be the most readable indicator of a server's health at a glance. In this step Kiro also refactored the edit form to use data that was already in the page instead of reloading without being prompted to do so. Kiro also made pop-ups closeable via backdrop click and escape key without being prompted. Accepted without modifications.

---

## Assumption Summary

For this solution, I assumed that the database I had proposed and its three tables already existed and that they were already accessible with the database credentials configured at the top of index.php. No schema creation or migration logic was included. The delete operation also assumes that a automatic deletion of linked records by the database may not be configured and handles dependent row cleanup manually. There's also an assumption concerning the monitoring data displayed in the table, this implementation assumes the data in metric_snapshot to be written by separate processes, as proposed in q1c, meaning the dashboard depends on that process to have data to display in the monitoring columns.
