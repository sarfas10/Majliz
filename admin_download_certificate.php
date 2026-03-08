<?php
// admin_download_certificate.php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

// Auth: mahal (admin) must be logged in
if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    http_response_code(400);
    exit('Missing or invalid id.');
}

$request_id = (int)$_GET['id'];
$mahal_id   = (int)$_SESSION['user_id'];

require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) {
    die($db['error']);
}

/** @var mysqli $conn */
$conn = $db['conn'];

// Only allow download for certificates that belong to this mahal AND are completed
$sql = "
    SELECT cr.output_file, cr.output_blob
    FROM cert_requests cr
    INNER JOIN members m ON cr.member_id = m.id
      AND m.mahal_id = ?
    WHERE cr.id = ?
      AND cr.status = 'completed'
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $mahal_id, $request_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$row = $res->fetch_assoc();
if (!$row) {
    http_response_code(404);
    exit('Certificate not found or not completed.');
}

$filename = $row['output_file'] ?: ('certificate_' . $request_id . '.docx');
$data     = $row['output_blob'];

if (empty($data)) {
    http_response_code(404);
    exit('File not generated.');
}

// Stream to browser
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . (int)strlen($data));

echo $data;
exit;
