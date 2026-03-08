<?php
// transfer_family.php
require_once __DIR__ . '/session_bootstrap.php';

/* no-cache */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* auth */
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

/* CSRF */
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_POST['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    http_response_code(400);
    echo "Invalid request.";
    exit();
}

$source_member_id  = isset($_POST['source_member_id']) ? (int)$_POST['source_member_id'] : 0;
$target_member_id  = isset($_POST['target_member_id']) ? (int)$_POST['target_member_id'] : 0;
$head_relationship = isset($_POST['head_as_relationship']) ? trim($_POST['head_as_relationship']) : 'Relative';

if ($source_member_id <= 0 || $target_member_id <= 0 || $source_member_id === $target_member_id) {
    http_response_code(400);
    echo "Choose a valid target family head.";
    exit();
}

require_once 'db_connection.php';

/**
 * Ensures tables/columns match the addmember.php structure (members, family_members, member_documents).
 * This mirrors the structure expectations used in addmember.php.
 */
function ensureAddMemberSchema(mysqli $conn) {
    // members table: ensure exists and has member_number and unique (mahal_id, member_number)
    $res = $conn->query("SHOW TABLES LIKE 'members'");
    if ($res && $res->num_rows == 0) {
        $sql = "CREATE TABLE members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            head_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            dob DATE DEFAULT NULL,
            gender VARCHAR(20) DEFAULT NULL,
            occupation VARCHAR(255) DEFAULT NULL,
            address TEXT NOT NULL,
            mahal_id INT NOT NULL,
            member_number INT UNSIGNED DEFAULT NULL,
            join_date DATE NOT NULL,
            total_family_members INT DEFAULT 1,
            monthly_donation_due VARCHAR(20) DEFAULT 'pending',
            total_due DECIMAL(10,2) DEFAULT 0.00,
            monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mahal_id (mahal_id),
            INDEX idx_email (email),
            UNIQUE KEY uniq_mahal_member_number (mahal_id, member_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$conn->query($sql)) {
            throw new Exception("Create members failed: ".$conn->error);
        }
        $conn->query("ALTER TABLE members ADD CONSTRAINT fk_members_mahal 
                      FOREIGN KEY (mahal_id) REFERENCES register(id)
                      ON DELETE CASCADE ON UPDATE CASCADE");
    } else {
        // ensure columns/indexes
        $col = $conn->query("SHOW COLUMNS FROM members LIKE 'member_number'");
        if ($col->num_rows == 0) {
            if (!$conn->query("ALTER TABLE members ADD COLUMN member_number INT UNSIGNED DEFAULT NULL AFTER mahal_id")) {
                throw new Exception("Add member_number failed: ".$conn->error);
            }
        }
        $idx = $conn->query("SHOW INDEX FROM members WHERE Key_name='uniq_mahal_member_number'");
        if ($idx->num_rows == 0) {
            $conn->query("ALTER TABLE members ADD UNIQUE KEY uniq_mahal_member_number (mahal_id, member_number)");
        }
        $col = $conn->query("SHOW COLUMNS FROM members LIKE 'monthly_fee'");
        if ($col->num_rows == 0) {
            $conn->query("ALTER TABLE members ADD COLUMN monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_due");
        }
        $col = $conn->query("SHOW COLUMNS FROM members LIKE 'monthly_donation_due'");
        if ($col->num_rows == 0) {
            $conn->query("ALTER TABLE members ADD COLUMN monthly_donation_due VARCHAR(20) DEFAULT 'pending' AFTER total_family_members");
        }
        $col = $conn->query("SHOW COLUMNS FROM members LIKE 'total_due'");
        if ($col->num_rows == 0) {
            $conn->query("ALTER TABLE members ADD COLUMN total_due DECIMAL(10,2) DEFAULT 0.00 AFTER monthly_donation_due");
        }
    }

    // family_members table
    $res = $conn->query("SHOW TABLES LIKE 'family_members'");
    if ($res && $res->num_rows == 0) {
        $sql = "CREATE TABLE family_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            relationship VARCHAR(50) NOT NULL,
            dob DATE DEFAULT NULL,
            gender VARCHAR(20) DEFAULT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_member_id (member_id),
            CONSTRAINT fk_family_member FOREIGN KEY (member_id) REFERENCES members(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$conn->query($sql)) {
            throw new Exception("Create family_members failed: ".$conn->error);
        }
    }

    // member_documents table
    $res = $conn->query("SHOW TABLES LIKE 'member_documents'");
    if ($res && $res->num_rows == 0) {
        $sql = "CREATE TABLE member_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            family_member_id INT DEFAULT NULL,
            owner_type ENUM('head','family') NOT NULL,
            doc_type VARCHAR(50) NOT NULL,
            doc_number VARCHAR(100) NOT NULL,
            name_on_doc VARCHAR(255) DEFAULT NULL,
            issued_by VARCHAR(255) DEFAULT NULL,
            issued_on DATE DEFAULT NULL,
            expiry_on DATE DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            file_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_member_id (member_id),
            INDEX idx_family_member_id (family_member_id),
            INDEX idx_doc_type (doc_type),
            INDEX idx_doc_number (doc_number),
            CONSTRAINT fk_docs_member FOREIGN KEY (member_id) REFERENCES members(id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_docs_family FOREIGN KEY (family_member_id) REFERENCES family_members(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$conn->query($sql)) {
            throw new Exception("Create member_documents failed: ".$conn->error);
        }
    }
}

try {
    $db = get_db_connection();
    if (isset($db['error'])) {
        throw new Exception($db['error']);
    }
    /** @var mysqli $conn */
    $conn = $db['conn'];

    // Ensure schema matches addmember.php
    ensureAddMemberSchema($conn);

    $conn->begin_transaction();

    $mahal_id = (int)$_SESSION['user_id'];

    // 1) Load + lock source head (must belong to current mahal)
    $stmt = $conn->prepare("SELECT id, head_name, phone, email, gender, dob FROM members WHERE id=? AND mahal_id=? FOR UPDATE");
    $stmt->bind_param("ii", $source_member_id, $mahal_id);
    $stmt->execute();
    $src = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$src) throw new Exception("Source member not found.");

    // 2) Load + lock target head (same mahal)
    $stmt = $conn->prepare("SELECT id, head_name FROM members WHERE id=? AND mahal_id=? FOR UPDATE");
    $stmt->bind_param("ii", $target_member_id, $mahal_id);
    $stmt->execute();
    $tgt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tgt) throw new Exception("Target member not found.");

    // 3) Fetch + lock source family members
    $stmt = $conn->prepare("SELECT id, name, relationship, dob, gender, phone, email FROM family_members WHERE member_id=? ORDER BY id ASC FOR UPDATE");
    $stmt->bind_param("i", $source_member_id);
    $stmt->execute();
    $src_family = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Map old family_member.id -> new family_member.id
    $id_map = [];

    // 4) Insert the source HEAD as a family member under target
    $stmt = $conn->prepare("
        INSERT INTO family_members (member_id, name, relationship, dob, gender, phone, email, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->bind_param(
        "issssss",
        $target_member_id,
        $src['head_name'],
        $head_relationship,
        $src['dob'],
        $src['gender'],
        $src['phone'],
        $src['email']
    );
    $stmt->execute();
    $new_head_fmid = (int)$stmt->insert_id;
    $stmt->close();

    // 5) Clone all source family members under target
    if (!empty($src_family)) {
        $stmtIns = $conn->prepare("
            INSERT INTO family_members (member_id, name, relationship, dob, gender, phone, email, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        foreach ($src_family as $fm) {
            $stmtIns->bind_param(
                "issssss",
                $target_member_id,
                $fm['name'],
                $fm['relationship'],
                $fm['dob'],
                $fm['gender'],
                $fm['phone'],
                $fm['email']
            );
            $stmtIns->execute();
            $id_map[(int)$fm['id']] = (int)$stmtIns->insert_id;
        }
        $stmtIns->close();
    }

    // 6) Re-link documents to match addmember.php schema usage
    // 6a) Source HEAD docs become FAMILY docs under the new family_member row, and member_id becomes target head
    $stmt = $conn->prepare("
        UPDATE member_documents
        SET owner_type='family', family_member_id=?, member_id=?
        WHERE member_id=? AND owner_type='head' AND family_member_id IS NULL
    ");
    $stmt->bind_param("iii", $new_head_fmid, $target_member_id, $source_member_id);
    $stmt->execute();
    $stmt->close();

    // 6b) Source FAMILY docs move to the cloned family_member ids; normalize member_id to target head
    if (!empty($id_map)) {
        $oldIds = array_keys($id_map);
        // chunk IN clause if very large
        $chunks = array_chunk($oldIds, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('i', count($chunk));

            $q = "
                SELECT id, family_member_id
                FROM member_documents
                WHERE member_id=? AND owner_type='family' AND family_member_id IN ($placeholders)
                FOR UPDATE
            ";
            $stmt = $conn->prepare($q);
            // bind: first member_id, then each family_member_id
            $bindTypes = 'i' . $types;
            $bindValues = array_merge([$source_member_id], $chunk);
            $stmt->bind_param($bindTypes, ...$bindValues);
            $stmt->execute();
            $res = $stmt->get_result();
            $docs = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (!empty($docs)) {
                $stmtUp = $conn->prepare("UPDATE member_documents SET family_member_id=?, member_id=? WHERE id=?");
                foreach ($docs as $d) {
                    $old_fmid = (int)$d['family_member_id'];
                    $new_fmid = $id_map[$old_fmid] ?? null;
                    if ($new_fmid) {
                        $stmtUp->bind_param("iii", $new_fmid, $target_member_id, $d['id']);
                        $stmtUp->execute();
                    }
                }
                $stmtUp->close();
            }
        }
    }

    // 7) Unlink past transactions from the old head (keep history + audit note)
    // (Structure-agnostic; leaves rows but removes donor reference to a deleted member.)
    $stmt = $conn->prepare("
        UPDATE transactions
        SET donor_member_id=NULL,
            description=CONCAT(COALESCE(description,''),' [unlinked: transferred from M', LPAD(?,3,'0'), ' on ', ?, ']')
        WHERE donor_member_id=?
    ");
    $today = date('Y-m-d');
    $stmt->bind_param("isi", $source_member_id, $today, $source_member_id);
    $stmt->execute();
    $stmt->close();

    // 8) Remove original household (family members + head)
    // Delete original family members (cascades docs tied to those family_member_ids if any remain)
    $stmt = $conn->prepare("DELETE FROM family_members WHERE member_id=?");
    $stmt->bind_param("i", $source_member_id);
    $stmt->execute();
    $stmt->close();

    // Delete the head (cascades any remaining member_documents if any)
    $stmt = $conn->prepare("DELETE FROM members WHERE id=?");
    $stmt->bind_param("i", $source_member_id);
    $stmt->execute();
    $stmt->close();

    // 9) Recompute and update target's total_family_members to actual count
    $stmt = $conn->prepare("UPDATE members SET total_family_members = (SELECT COUNT(*) FROM family_members WHERE member_id=?) WHERE id=?");
    $stmt->bind_param("ii", $target_member_id, $target_member_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    header("Location: user_details.php?id=".$target_member_id."&msg=transfer_ok");
    exit();

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    error_log("Transfer error: ".$e->getMessage());
    http_response_code(500);
    echo "Transfer failed: ".$e->getMessage();
    exit();
}
