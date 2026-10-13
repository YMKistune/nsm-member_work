<?php
// Legacy manual-entry route retired in the Excel-first workflow. No database rows are deleted.
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: member_ap.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
