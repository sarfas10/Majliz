<?php
// mahal_promo_api.php — API for mahal public promotional profile
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';

header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit();
}
$conn = $db_result['conn'];
$user_id = (int) $_SESSION['user_id'];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ─── Helpers ────────────────────────────────────────────────────────────────

function ensure_upload_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

function handle_image_upload(array $file, string $dir, string $prefix = ''): ?string
{
    ensure_upload_dir($dir);
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed)) {
        return null;
    }
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = $prefix . uniqid() . '_' . time() . '.' . $ext;
    $dest = rtrim($dir, '/') . '/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $filename;
    }
    return null;
}

function delete_file_if_exists(string $path): void
{
    if ($path && file_exists($path)) {
        unlink($path);
    }
}

// ─── Ensure tables exist ─────────────────────────────────────────────────────

$conn->query("CREATE TABLE IF NOT EXISTS mahal_public_profile (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    established_year VARCHAR(10),
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS mahal_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    caption VARCHAR(200),
    sort_order INT DEFAULT 0,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
)");
// Add is_primary column if it doesn't exist yet (for existing installs)
$conn->query("ALTER TABLE mahal_gallery ADD COLUMN IF NOT EXISTS is_primary TINYINT(1) NOT NULL DEFAULT 0");


$conn->query("CREATE TABLE IF NOT EXISTS mahal_committee (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    role VARCHAR(100),
    phone VARCHAR(20),
    image_path VARCHAR(255),
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
)");

// ─── Actions ─────────────────────────────────────────────────────────────────

switch ($action) {

    // ── GET all promo data ────────────────────────────────────────────────────
    case 'get':
        $profile = null;
        $stmt = $conn->prepare("SELECT * FROM mahal_public_profile WHERE mahal_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $profile = $r;

        $gallery = [];
        $stmt2 = $conn->prepare("SELECT * FROM mahal_gallery WHERE mahal_id = ? ORDER BY sort_order, uploaded_at");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        $g = $stmt2->get_result();
        while ($row = $g->fetch_assoc())
            $gallery[] = $row;
        $stmt2->close();

        $committee = [];
        $stmt3 = $conn->prepare("SELECT * FROM mahal_committee WHERE mahal_id = ? ORDER BY sort_order, id");
        $stmt3->bind_param("i", $user_id);
        $stmt3->execute();
        $c = $stmt3->get_result();
        while ($row = $c->fetch_assoc())
            $committee[] = $row;
        $stmt3->close();

        echo json_encode([
            'success' => true,
            'profile' => $profile,
            'gallery' => $gallery,
            'committee' => $committee
        ]);
        break;

    // ── Save profile (slug + description) ────────────────────────────────────
    case 'save_profile':
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $established = trim($_POST['established_year'] ?? '');
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        if (empty($slug)) {
            echo json_encode(['success' => false, 'message' => 'Slug is required']);
            break;
        }
        // Sanitize slug: only alphanumeric, hyphens, underscores
        $slug = preg_replace('/[^a-zA-Z0-9\-_]/', '', $slug);
        if (empty($slug)) {
            echo json_encode(['success' => false, 'message' => 'Slug contains invalid characters']);
            break;
        }

        // Check slug uniqueness (excluding current mahal)
        $chk = $conn->prepare("SELECT mahal_id FROM mahal_public_profile WHERE slug = ? AND mahal_id != ?");
        $chk->bind_param("si", $slug, $user_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $chk->close();
            echo json_encode(['success' => false, 'message' => 'This slug is already taken by another mahal']);
            break;
        }
        $chk->close();

        // Upsert
        $stmt = $conn->prepare("
            INSERT INTO mahal_public_profile (mahal_id, slug, description, established_year, is_published)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE slug = VALUES(slug), description = VALUES(description),
                established_year = VALUES(established_year), is_published = VALUES(is_published)
        ");
        $stmt->bind_param("isssi", $user_id, $slug, $description, $established, $is_published);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'slug' => $slug]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $conn->error]);
        }
        $stmt->close();
        break;

    // ── Upload gallery image ──────────────────────────────────────────────────
    case 'upload_gallery':
        if (empty($_FILES['image'])) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            break;
        }
        $dir = __DIR__ . '/uploads/mahal_gallery/';
        $filename = handle_image_upload($_FILES['image'], $dir, 'g' . $user_id . '_');
        if (!$filename) {
            echo json_encode(['success' => false, 'message' => 'Invalid file (JPEG/PNG/WebP, max 5MB)']);
            break;
        }
        $caption = trim($_POST['caption'] ?? '');
        $sort = (int) ($_POST['sort_order'] ?? 0);
        $stmt = $conn->prepare("INSERT INTO mahal_gallery (mahal_id, image_path, caption, sort_order) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("issi", $user_id, $filename, $caption, $sort);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $conn->insert_id, 'filename' => $filename]);
        } else {
            // Cleanup uploaded file on DB error
            delete_file_if_exists($dir . $filename);
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
        }
        $stmt->close();
        break;

    // ── Set primary gallery image ─────────────────────────────────────────────
    case 'set_primary':
        $id = (int) ($_POST['id'] ?? 0);
        // Verify ownership
        $chk = $conn->prepare("SELECT id FROM mahal_gallery WHERE id = ? AND mahal_id = ?");
        $chk->bind_param("ii", $id, $user_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            echo json_encode(['success' => false, 'message' => 'Not found']);
            break;
        }
        $chk->close();
        // Clear existing primary for this mahal
        $clear = $conn->prepare("UPDATE mahal_gallery SET is_primary = 0 WHERE mahal_id = ?");
        $clear->bind_param("i", $user_id);
        $clear->execute();
        $clear->close();
        // Set new primary
        $set = $conn->prepare("UPDATE mahal_gallery SET is_primary = 1 WHERE id = ? AND mahal_id = ?");
        $set->bind_param("ii", $id, $user_id);
        $set->execute();
        $set->close();
        echo json_encode(['success' => true]);
        break;

    // ── Delete gallery image ──────────────────────────────────────────────────
    case 'delete_gallery':
        $id = (int) ($_POST['id'] ?? 0);
        // Verify ownership
        $stmt = $conn->prepare("SELECT image_path FROM mahal_gallery WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Not found']);
            break;
        }
        delete_file_if_exists(__DIR__ . '/uploads/mahal_gallery/' . $row['image_path']);
        $del = $conn->prepare("DELETE FROM mahal_gallery WHERE id = ? AND mahal_id = ?");
        $del->bind_param("ii", $id, $user_id);
        $del->execute();
        $del->close();
        echo json_encode(['success' => true]);
        break;

    // ── Save committee member ─────────────────────────────────────────────────
    case 'save_committee':
        $member_id = (int) ($_POST['member_id'] ?? 0); // 0 = new
        $name = trim($_POST['name'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $sort = (int) ($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Name is required']);
            break;
        }

        $image_path = null;

        // Handle image upload if provided
        if (!empty($_FILES['image']['tmp_name'])) {
            $dir = __DIR__ . '/uploads/mahal_committee/';
            $image_path = handle_image_upload($_FILES['image'], $dir, 'c' . $user_id . '_');
        }

        if ($member_id > 0) {
            // UPDATE existing — first verify ownership
            $own = $conn->prepare("SELECT id, image_path FROM mahal_committee WHERE id = ? AND mahal_id = ?");
            $own->bind_param("ii", $member_id, $user_id);
            $own->execute();
            $existing = $own->get_result()->fetch_assoc();
            $own->close();
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Not found']);
                break;
            }

            if ($image_path) {
                // Delete old image
                delete_file_if_exists(__DIR__ . '/uploads/mahal_committee/' . $existing['image_path']);
                $stmt = $conn->prepare("UPDATE mahal_committee SET name=?, role=?, phone=?, image_path=?, sort_order=? WHERE id=? AND mahal_id=?");
                $stmt->bind_param("sssssii", $name, $role, $phone, $image_path, $sort, $member_id, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE mahal_committee SET name=?, role=?, phone=?, sort_order=? WHERE id=? AND mahal_id=?");
                $stmt->bind_param("sssiii", $name, $role, $phone, $sort, $member_id, $user_id);
            }
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'id' => $member_id]);
        } else {
            // INSERT new
            $stmt = $conn->prepare("INSERT INTO mahal_committee (mahal_id, name, role, phone, image_path, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssi", $user_id, $name, $role, $phone, $image_path, $sort);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'id' => $conn->insert_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
            }
            $stmt->close();
        }
        break;

    // ── Delete committee member ───────────────────────────────────────────────
    case 'delete_committee':
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT image_path FROM mahal_committee WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Not found']);
            break;
        }
        if ($row['image_path']) {
            delete_file_if_exists(__DIR__ . '/uploads/mahal_committee/' . $row['image_path']);
        }
        $del = $conn->prepare("DELETE FROM mahal_committee WHERE id = ? AND mahal_id = ?");
        $del->bind_param("ii", $id, $user_id);
        $del->execute();
        $del->close();
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        break;
}

$conn->close();
?>