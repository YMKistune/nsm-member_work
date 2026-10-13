<?php
// Backward-compatible route: the system now has one primary Dashboard.
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: dashboard.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
