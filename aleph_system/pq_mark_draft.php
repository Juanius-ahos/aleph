<?php
$conn = new mysqli('localhost', 'aleph_aleph', 'Fetv9y8zz!', 'aleph_suite');
if ($conn->connect_error) { echo "DB connection failed: " . $conn->connect_error; exit; }
$conn->set_charset('utf8mb4');

$r = $conn->query("SELECT COUNT(*) as c FROM quotes WHERE status='accepted' AND deleted_at IS NULL");
$row = $r->fetch_assoc();
$count = (int)$row['c'];

$conn->query("UPDATE quotes SET status='draft' WHERE status='accepted' AND deleted_at IS NULL");

echo "Marked $count accepted quotes as draft.<br>";
echo "<a href='quotes.php'>Back to quotes</a><br><br>";
echo "<b style='color:red'>DELETE this file now!</b>";
$conn->close();
