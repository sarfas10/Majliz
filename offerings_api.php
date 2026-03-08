<?php
// offerings_api.php - JSON API for member/non-member offerings
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$mahal_id = (int) $_SESSION['user_id'];

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}
$conn = $db_result['conn'];

// ── Ensure table exists ─────────────────────────────────────────────────────
$conn->query("
CREATE TABLE IF NOT EXISTS member_offerings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    mahal_id        INT NOT NULL,
    offering_type   VARCHAR(100) NOT NULL DEFAULT 'Other',
    offering_value  VARCHAR(255) NOT NULL,
    description     TEXT,
    offered_by      VARCHAR(255),
    member_id       INT DEFAULT NULL,
    offering_date   DATE NOT NULL,
    status          ENUM('pending','fulfilled','cancelled') DEFAULT 'pending',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mo_mahal   (mahal_id),
    INDEX idx_mo_member  (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── LIST ────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $filter_member_id = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;

    $sql = "
        SELECT o.*,
               m.head_name AS member_name
        FROM   member_offerings o
        LEFT JOIN members m ON m.id = o.member_id AND m.mahal_id = o.mahal_id
        WHERE  o.mahal_id = ?
    ";
    $params = [$mahal_id];
    $types = 'i';

    if ($filter_member_id > 0) {
        $sql .= " AND o.member_id = ?";
        $params[] = $filter_member_id;
        $types .= 'i';
    }

    $sql .= " ORDER BY o.offering_date DESC, o.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $rows]);
    $conn->close();
    exit();
}

// ── GET MEMBERS (for dropdown) ───────────────────────────────────────────────
if ($action === 'get_members') {
    $q = isset($_GET['q']) ? '%' . $conn->real_escape_string(trim($_GET['q'])) . '%' : '%';
    $sql = "SELECT id, head_name FROM members WHERE mahal_id = ? AND head_name LIKE ? ORDER BY head_name ASC LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $mahal_id, $q);
    $stmt->execute();
    $result = $stmt->get_result();
    $members = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'data' => $members]);
    $conn->close();
    exit();
}

// ── All mutating actions require POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    $conn->close();
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    $data = $_POST;
}

// ── CREATE ───────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $offering_type = trim($data['offering_type'] ?? 'Other');
    $offering_value = trim($data['offering_value'] ?? '');
    $description = trim($data['description'] ?? '');
    $offered_by = trim($data['offered_by'] ?? '');
    $member_id = isset($data['member_id']) && $data['member_id'] !== '' ? (int) $data['member_id'] : null;
    $offering_date = trim($data['offering_date'] ?? date('Y-m-d'));
    $status = in_array($data['status'] ?? '', ['pending', 'fulfilled', 'cancelled']) ? $data['status'] : 'pending';
    $notes = trim($data['notes'] ?? '');

    if ($offering_value === '') {
        echo json_encode(['success' => false, 'message' => 'Offering value is required']);
        $conn->close();
        exit();
    }

    if ($member_id !== null) {
        // Member offering: bind member_id as integer
        $stmt = $conn->prepare("
            INSERT INTO member_offerings (mahal_id, offering_type, offering_value, description, offered_by, member_id, offering_date, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'issssisss',
            $mahal_id,
            $offering_type,
            $offering_value,
            $description,
            $offered_by,
            $member_id,
            $offering_date,
            $status,
            $notes
        );
    } else {
        // Non-member offering: member_id is NULL — omit from INSERT so default applies
        $stmt = $conn->prepare("
            INSERT INTO member_offerings (mahal_id, offering_type, offering_value, description, offered_by, offering_date, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'isssssss',
            $mahal_id,
            $offering_type,
            $offering_value,
            $description,
            $offered_by,
            $offering_date,
            $status,
            $notes
        );
    }

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        echo json_encode(['success' => true, 'id' => $new_id]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    $conn->close();
    exit();
}

// ── UPDATE ───────────────────────────────────────────────────────────────────
if ($action === 'update') {
    $id = (int) ($data['id'] ?? 0);
    $offering_type = trim($data['offering_type'] ?? '');
    $offering_value = trim($data['offering_value'] ?? '');
    $description = trim($data['description'] ?? '');
    $offered_by = trim($data['offered_by'] ?? '');
    $member_id = isset($data['member_id']) && $data['member_id'] !== '' ? (int) $data['member_id'] : null;
    $offering_date = trim($data['offering_date'] ?? '');
    $status = in_array($data['status'] ?? '', ['pending', 'fulfilled', 'cancelled']) ? $data['status'] : 'pending';
    $notes = trim($data['notes'] ?? '');

    if ($id <= 0 || $offering_value === '') {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        $conn->close();
        exit();
    }

    if ($member_id !== null) {
        // Member offering: set member_id as integer
        $stmt = $conn->prepare("
            UPDATE member_offerings
            SET offering_type=?, offering_value=?, description=?, offered_by=?,
                member_id=?, offering_date=?, status=?, notes=?
            WHERE id=? AND mahal_id=?
        ");
        $stmt->bind_param(
            'sssssissii',
            $offering_type,
            $offering_value,
            $description,
            $offered_by,
            $member_id,
            $offering_date,
            $status,
            $notes,
            $id,
            $mahal_id
        );
    } else {
        // Non-member offering: explicitly set member_id = NULL
        $stmt = $conn->prepare("
            UPDATE member_offerings
            SET offering_type=?, offering_value=?, description=?, offered_by=?,
                member_id=NULL, offering_date=?, status=?, notes=?
            WHERE id=? AND mahal_id=?
        ");
        $stmt->bind_param(
            'sssssssii',
            $offering_type,
            $offering_value,
            $description,
            $offered_by,
            $offering_date,
            $status,
            $notes,
            $id,
            $mahal_id
        );
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    $stmt->close();
    $conn->close();
    exit();
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        $conn->close();
        exit();
    }
    $stmt = $conn->prepare("DELETE FROM member_offerings WHERE id=? AND mahal_id=?");
    $stmt->bind_param('ii', $id, $mahal_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Not found or access denied']);
    }
    $stmt->close();
    $conn->close();
    exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
$conn->close();
