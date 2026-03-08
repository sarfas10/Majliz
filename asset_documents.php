<?php
// asset_documents.php
require_once __DIR__ . '/session_bootstrap.php';

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Include the centralized database connection
require_once 'db_connection.php';

// Get database connection
$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed: " . $db_result['error']);
}
$conn = $db_result['conn'];

// Get logged-in user details
$user_id = $_SESSION['user_id'];

// Get asset ID from request
$asset_id = isset($_GET['asset_id']) ? intval($_GET['asset_id']) : 0;
if (isset($_POST['asset_id'])) {
    $asset_id = intval($_POST['asset_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_document' && $asset_id > 0) {
        $mahal_id = $user_id;
        $document_type = $_POST['document_type'] ?? '';
        $document_name = $_POST['document_name'] ?? '';
        $description = $_POST['description'] ?? '';
        $expiry_date = $_POST['expiry_date'] ?? null;
        
        // Handle file upload
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['document_file'];
            
            // Create uploads directory if it doesn't exist
            $upload_dir = __DIR__ . '/uploads/asset_documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $unique_filename = 'asset_' . $asset_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $unique_filename;
            
            // Allowed file types
            $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'txt'];
            $max_file_size = 10 * 1024 * 1024; // 10MB
            
            if (in_array(strtolower($file_extension), $allowed_types)) {
                if ($file['size'] <= $max_file_size) {
                    if (move_uploaded_file($file['tmp_name'], $file_path)) {
                        // Save to database
                        $stmt = $conn->prepare("INSERT INTO asset_documents (mahal_id, asset_id, document_type, document_name, file_name, file_path, file_size, expiry_date, description, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        
                        $file_name = $file['name'];
                        $relative_path = 'uploads/asset_documents/' . $unique_filename;
                        $file_size = $file['size'];
                        
                        $stmt->bind_param(
                            "iisssssisi",
                            $mahal_id,
                            $asset_id,
                            $document_type,
                            $document_name,
                            $file_name,
                            $relative_path,
                            $file_size,
                            $expiry_date,
                            $description,
                            $user_id
                        );
                        
                        if ($stmt->execute()) {
                            echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                        }
                        $stmt->close();
                    } else {
                        echo json_encode(['success' => false, 'message' => 'File upload failed']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 10MB']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, TXT']);
            }
        } else {
            $error_msg = 'No file uploaded';
            if ($_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
                $error_msg = 'Upload error: ' . $_FILES['document_file']['error'];
            }
            echo json_encode(['success' => false, 'message' => $error_msg]);
        }
        exit();
    }
    
    if ($action === 'delete_document') {
        $document_id = intval($_POST['document_id'] ?? 0);
        $mahal_id = $user_id;
        
        // First get the file path
        $stmt = $conn->prepare("SELECT file_path FROM asset_documents WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("ii", $document_id, $mahal_id);
        $stmt->execute();
        $stmt->bind_result($file_path);
        $stmt->fetch();
        $stmt->close();
        
        // Delete from database
        $stmt = $conn->prepare("DELETE FROM asset_documents WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("ii", $document_id, $mahal_id);
        
        if ($stmt->execute()) {
            // Delete physical file
            if ($file_path && file_exists(__DIR__ . '/' . $file_path)) {
                unlink(__DIR__ . '/' . $file_path);
            }
            echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete document']);
        }
        $stmt->close();
        exit();
    }
}

// Get asset documents (for AJAX requests)
if ($asset_id > 0 && isset($_GET['action']) && $_GET['action'] === 'get_documents') {
    $mahal_id = $user_id;
    $stmt = $conn->prepare("SELECT * FROM asset_documents WHERE asset_id = ? AND mahal_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("ii", $asset_id, $mahal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $documents = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode(['success' => true, 'documents' => $documents]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>