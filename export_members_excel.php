<?php
// export_members_excel.php
// Real XLSX exporter (compat-friendly)

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

require_once 'db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) {
    http_response_code(500);
    echo "DB connection error: " . htmlspecialchars($db['error']);
    exit;
}
$conn = $db['conn'];

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo "Missing Composer autoload.";
    exit;
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$mahal_id = (int)($_SESSION['user_id'] ?? 0);

$search = trim((string)($_GET['search'] ?? ''));
$donation_status = $_GET['donation_status'] ?? 'all';
$member_status = $_GET['member_status'] ?? 'all';
$heads_only = isset($_GET['heads_only']) && $_GET['heads_only'] === '1';
$sahakari_only = isset($_GET['sahakari_only']) && $_GET['sahakari_only'] === '1';

function safe_prepare_and_execute(mysqli $conn, string $sql, array $bindTypes = [], array $bindValues = []) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) throw new RuntimeException($conn->error);

    if (!empty($bindTypes)) {
        $types = implode('', $bindTypes);
        $refs = [ &$types ];
        foreach ($bindValues as $i => $v) $refs[] = &$bindValues[$i];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

try {
    $membersTable = $sahakari_only ? 'sahakari_members' : 'members';
    $familyTable  = $sahakari_only ? 'sahakari_family_members' : 'family_members';

    // HEAD filtering
    $headWhere = ["m.mahal_id = ?"];
    $headTypes = ["i"];
    $headParams = [$mahal_id];

    if ($donation_status !== 'all') {
        $headWhere[] = "m.monthly_donation_due = ?";
        $headTypes[] = "s";
        $headParams[] = $donation_status;
    }
    if ($member_status !== 'all') {
        $headWhere[] = "m.status = ?";
        $headTypes[] = "s";
        $headParams[] = $member_status;
    }
    if ($search !== '') {
        $headWhere[] = "(m.head_name LIKE ? OR m.email LIKE ? OR m.phone LIKE ? OR m.member_number LIKE ?)";
        $like = "%{$search}%";
        $headTypes = array_merge($headTypes, ["s","s","s","s"]);
        $headParams = array_merge($headParams, [$like,$like,$like,$like]);
    }

    $orderExpr = "(m.member_number IS NULL), CAST(m.member_number AS UNSIGNED), m.id";

    $sqlHeads = "SELECT m.*
                 FROM {$membersTable} m
                 WHERE " . implode(' AND ', $headWhere) . "
                 ORDER BY {$orderExpr} ASC";

    $heads = safe_prepare_and_execute($conn, $sqlHeads, $headTypes, $headParams);

    $rows = [];
    foreach ($heads as $h) {
        $h['__type'] = 'head';
        $rows[] = $h;
    }

    // FAMILY rows (only if NOT heads-only)
    if (!$heads_only) {
        $familyWhere = ["m.mahal_id = ?"];
        $familyTypes = ["i"];
        $familyParams = [$mahal_id];

        if ($member_status !== 'all') {
            $familyWhere[] = "fm.status = ?";
            $familyTypes[] = "s";
            $familyParams[] = $member_status;
        }

        if ($search !== '') {
            $familyWhere[] = "(fm.name LIKE ? OR fm.email LIKE ? OR fm.phone LIKE ? OR m.member_number LIKE ?)";
            $like = "%{$search}%";
            $familyTypes = array_merge($familyTypes, ["s","s","s","s"]);
            $familyParams = array_merge($familyParams, [$like,$like,$like,$like]);
        }

        $sqlF = "SELECT fm.*, m.member_number AS parent_member_number, m.head_name AS parent_name
                 FROM {$familyTable} fm
                 INNER JOIN {$membersTable} m ON m.id = fm.member_id
                 WHERE " . implode(' AND ', $familyWhere) . "
                 ORDER BY fm.created_at DESC";

        $family_rows = safe_prepare_and_execute($conn, $sqlF, $familyTypes, $familyParams);
        foreach ($family_rows as $f) {
            $f['__type'] = 'family';
            $rows[] = $f;
        }
    }

    // ------------------------------------------
    // EXCEL creation (WITHOUT DB ID column)
    // ------------------------------------------
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Members');

    // HEADER WITHOUT DB ID
    $header = [
        "Type","Member No","Name","Parent","Relationship","Email","Phone","Address",
        "Donation Status","Living Status","Total Due","Family Size","Added On"
    ];

    // write header
    $rowNum = 1;
    foreach ($header as $idx => $h) {
        $coord = Coordinate::stringFromColumnIndex($idx+1) . $rowNum;
        $sheet->setCellValue($coord, $h);
        $sheet->getStyle($coord)->getFont()->setBold(true);
    }

    // write rows
    $r = 2;
    foreach ($rows as $row) {
        $isHead = $row['__type'] === 'head';

        $vals = [
            $isHead ? "Head" : "Family",
            $isHead ? ($row['member_number'] ?? '') : ($row['parent_member_number'] ?? ''),
            $row['head_name'] ?? $row['name'] ?? '',
            $row['parent_name'] ?? '',
            $isHead ? "Head" : ($row['relationship'] ?? ''),
            $row['email'] ?? '',
            $row['phone'] ?? '',
            $row['address'] ?? '',
            $row['monthly_donation_due'] ?? '',
            $row['status'] ?? '',
            $row['total_due'] ?? '',
            $row['total_family_members'] ?? '',
            $row['created_at'] ?? ''
        ];

        foreach ($vals as $ci => $val) {
            $coord = Coordinate::stringFromColumnIndex($ci+1) . $r;
            $sheet->setCellValue($coord, $val);
        }
        $r++;
    }

    // autosize A–M (13 columns)
    foreach (range('A','M') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $mahal = preg_replace('/[^a-zA-Z0-9_-]/','_', $_SESSION['mahal_name'] ?? 'Mahal');
    $filename = $mahal . "_Members_" . date("Y-m-d_His") . ".xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save("php://output");
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo "<h2>Export failed</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}
