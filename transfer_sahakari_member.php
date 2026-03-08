<?php
// transfer_sahakari_member.php
require_once __DIR__ . '/session_bootstrap.php';

/* --- no-cache headers --- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

/* --- CSRF token --- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Include database connection
require_once 'db_connection.php';

// Check if this is an AJAX request for getting members
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_members') {
    getSahakariMembers();
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    processTransfer();
    exit();
}

// If not POST or AJAX, redirect to management page
header("Location: member-management.php");
exit();

function getSahakariMembers() {
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit();
    }

    $exclude_id = isset($_GET['exclude']) ? (int)$_GET['exclude'] : 0;

    try {
        $db_result = get_db_connection();
        if (isset($db_result['error'])) {
            throw new Exception($db_result['error']);
        }
        $conn = $db_result['conn'];

        // FIXED: Removed status filter to show ALL members
        $query = "SELECT id, head_name, member_number, status FROM sahakari_members WHERE mahal_id = ?";
        $params = [$_SESSION['user_id']];
        $types = "i";
        
        if ($exclude_id > 0) {
            $query .= " AND id != ?";
            $params[] = $exclude_id;
            $types .= "i";
        }
        
        $query .= " ORDER BY head_name ASC";
        
        $stmt = $conn->prepare($query);
        if ($exclude_id > 0) {
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param($types, $params[0]);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $members = $result->fetch_all(MYSQLI_ASSOC);
        
        $stmt->close();
        $conn->close();

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'members' => $members
        ]);

    } catch (Exception $e) {
        error_log("Error fetching sahakari members: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Failed to load members: ' . $e->getMessage()
        ]);
    }
}

function processTransfer() {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid CSRF token.";
        header("Location: sahakari_management.php");
        exit();
    }

    $source_member_id  = isset($_POST['source_member_id']) ? (int)$_POST['source_member_id'] : 0;
    $target_member_id  = isset($_POST['target_member_id']) ? (int)$_POST['target_member_id'] : 0;
    $head_relationship = isset($_POST['head_as_relationship']) ? trim($_POST['head_as_relationship']) : 'Relative';

    if ($source_member_id <= 0 || $target_member_id <= 0 || $source_member_id === $target_member_id) {
        $_SESSION['error'] = "Choose a valid target sahakari member.";
        header("Location: sahakari_details.php?id=" . $source_member_id);
        exit();
    }

    try {
        $db = get_db_connection();
        if (isset($db['error'])) {
            throw new Exception($db['error']);
        }
        /** @var mysqli $conn */
        $conn = $db['conn'];

        $conn->begin_transaction();

        $mahal_id = (int)$_SESSION['user_id'];

        // 1) Load + lock source sahakari head (must belong to current mahal)
        $stmt = $conn->prepare("SELECT id, head_name, phone, email, gender, dob, father_name, occupation, address, join_date FROM sahakari_members WHERE id=? AND mahal_id=? FOR UPDATE");
        $stmt->bind_param("ii", $source_member_id, $mahal_id);
        $stmt->execute();
        $src = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$src) throw new Exception("Source sahakari member not found.");

        // 2) Load + lock target sahakari head (same mahal)
        $stmt = $conn->prepare("SELECT id, head_name FROM sahakari_members WHERE id=? AND mahal_id=? FOR UPDATE");
        $stmt->bind_param("ii", $target_member_id, $mahal_id);
        $stmt->execute();
        $tgt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$tgt) throw new Exception("Target sahakari member not found.");

        // 3) Fetch + lock source family members
        $stmt = $conn->prepare("SELECT id, name, relationship, dob, gender, phone, email, status FROM sahakari_family_members WHERE member_id=? ORDER BY id ASC FOR UPDATE");
        $stmt->bind_param("i", $source_member_id);
        $stmt->execute();
        $src_family = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Map old family_member.id -> new family_member.id
        $id_map = [];

        // 4) Insert the source HEAD as a family member under target
        $stmt = $conn->prepare("
            INSERT INTO sahakari_family_members (member_id, name, relationship, dob, gender, phone, email, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
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
                INSERT INTO sahakari_family_members (member_id, name, relationship, dob, gender, phone, email, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            foreach ($src_family as $fm) {
                $stmtIns->bind_param(
                    "isssssss",
                    $target_member_id,
                    $fm['name'],
                    $fm['relationship'],
                    $fm['dob'],
                    $fm['gender'],
                    $fm['phone'],
                    $fm['email'],
                    $fm['status']
                );
                $stmtIns->execute();
                $id_map[(int)$fm['id']] = (int)$stmtIns->insert_id;
            }
            $stmtIns->close();
        }

        // 6) Re-link documents to match sahakari schema
        // 6a) Source HEAD docs become FAMILY docs under the new family_member row, and member_id becomes target head
        $stmt = $conn->prepare("
            UPDATE sahakari_member_documents
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
                    FROM sahakari_member_documents
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
                    $stmtUp = $conn->prepare("UPDATE sahakari_member_documents SET family_member_id=?, member_id=? WHERE id=?");
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
        $stmt = $conn->prepare("
            UPDATE transactions
            SET donor_member_id=NULL,
                description=CONCAT(COALESCE(description,''),' [unlinked: transferred from Sahakari M', LPAD(?,3,'0'), ' on ', ?, ']')
            WHERE donor_member_id=?
        ");
        $today = date('Y-m-d');
        $stmt->bind_param("isi", $source_member_id, $today, $source_member_id);
        $stmt->execute();
        $stmt->close();

        // 8) Remove original household (family members + head)
        // Delete original family members (cascades docs tied to those family_member_ids if any remain)
        $stmt = $conn->prepare("DELETE FROM sahakari_family_members WHERE member_id=?");
        $stmt->bind_param("i", $source_member_id);
        $stmt->execute();
        $stmt->close();

        // Delete the head (cascades any remaining member_documents if any)
        $stmt = $conn->prepare("DELETE FROM sahakari_members WHERE id=?");
        $stmt->bind_param("i", $source_member_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        $_SESSION['success'] = "Sahakari member '{$src['head_name']}' and their family have been successfully transferred to '{$tgt['head_name']}'.";
        header("Location: member-management.php");
        exit();

    } catch (Throwable $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        error_log("Sahakari transfer error: ".$e->getMessage());
        $_SESSION['error'] = "Transfer failed: ".$e->getMessage();
        header("Location: sahakari_details.php?id=".$source_member_id);
        exit();
    }
}
?>