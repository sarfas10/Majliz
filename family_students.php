
<?php
// member_students.php - View family students

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';




// No-cache headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Auth gate
if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_connection.php';

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed: " . htmlspecialchars($db_result['error']));
}
$conn = $db_result['conn'];

$memberSess = $_SESSION['member'];
$is_sahakari = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'sahakari_head');

if (!$is_sahakari) {
    $household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
        ? (int)$memberSess['parent_member_id']
        : (int)$memberSess['member_id'];
} else {
    $household_member_id = (int)$memberSess['member_id'];
}

// Fetch member details
$member = null;
if ($is_sahakari) {
    $stmt = $conn->prepare("SELECT * FROM sahakari_members WHERE id = ? AND mahal_id = ?");
} else {
    $stmt = $conn->prepare("SELECT * FROM members WHERE id = ? AND mahal_id = ?");
}
$stmt->bind_param("ii", $household_member_id, $memberSess['mahal_id']);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    die("Member not found");
}

// Fetch family students with class and attendance info
$students = [];

$stmt = $conn->prepare("
    SELECT 
        s.id,
        s.student_name,
        s.admission_number,
        s.date_of_birth,
        s.gender,
        s.father_name,
        s.parent_phone,
        s.parent_email,
        s.address,
        s.year_of_joining,
        s.status,
        c.class_name,
        c.division,
        COUNT(DISTINCT sa.attendance_date) as total_days,
        SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
        CASE 
            WHEN COUNT(DISTINCT sa.attendance_date) > 0 
            THEN (SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT sa.attendance_date)) * 100 
            ELSE 0 
        END as attendance_percentage
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN student_attendance sa ON s.id = sa.student_id
    WHERE s.member_id = ? AND s.status = 'active'
    GROUP BY s.id
    ORDER BY c.class_name, c.division, s.student_name
");

$stmt->bind_param("i", $household_member_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Family Students - <?php echo htmlspecialchars($member['head_name'] ?? 'Member'); ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --primary:#2563eb; --primary-dark:#1d4ed8; --primary-light:#dbeafe;
        --secondary:#64748b; --success:#10b981; --success-light:#d1fae5;
        --warning:#f59e0b; --warning-light:#fef3c7; --danger:#ef4444;
        --danger-light:#fee2e2; --info:#06b6d4; --info-light:#cffafe;
        --light:#f8fafc; --dark:#1e293b; --border:#e2e8f0; --border-light:#f1f5f9;
        --text-primary:#1e293b; --text-secondary:#64748b; --text-light:#94a3b8;
        --shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06);
        --shadow-md:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06);
        --shadow-lg:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05);
        --shadow-xl:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);
        --radius:16px; --radius-sm:12px; --radius-lg:20px;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:linear-gradient(135deg,#f5f7fa 0%,#c3cfe2 100%);color:var(--text-primary);line-height:1.6;font-size:14px;min-height:100vh}
    
    /* Sidebar - Same as dashboard */
    .sidebar {
        width: 288px;
        background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 100%);
        border-right: 1px solid rgba(255, 255, 255, 0.1);
        color: white;
        position: fixed;
        inset: 0 auto 0 0;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        z-index: 1000;
        box-shadow: 8px 0 32px rgba(0, 0, 0, 0.15);
    }

    .sidebar.open { transform: translateX(0); }
    .sidebar-inner { padding: 24px; display: flex; flex-direction: column; min-height: 100%; }
    .sidebar-close {
        position: absolute; top: 20px; right: 20px;
        background: rgba(255, 255, 255, 0.15); border: none; color: white;
        border-radius: var(--radius-sm); padding: 8px; cursor: pointer;
        width: 36px; height: 36px; display: flex; align-items: center;
        justify-content: center; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        backdrop-filter: blur(10px);
    }
    .sidebar-close:hover { background: rgba(255, 255, 255, 0.25); transform: rotate(90deg); }

    .profile { padding: 24px 0; text-align: center; margin-bottom: 24px; position: relative; cursor: pointer; }
    .profile::after {
        content: ''; position: absolute; bottom: -12px; left: 20%; right: 20%;
        height: 2px; background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        border-radius: 2px;
    }
    .profile-avatar {
        width: 72px; height: 72px; border-radius: 50%;
        background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.1));
        backdrop-filter: blur(10px); display: flex; align-items: center;
        justify-content: center; margin: 0 auto 16px; font-size: 28px;
        color: white; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        border: 3px solid rgba(255, 255, 255, 0.2); overflow: hidden; position: relative;
    }
    .profile-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .profile .name { color: #fff; font-weight: 700; font-size: 18px; line-height: 1.2; margin-bottom: 4px; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .profile .role { color: rgba(255, 255, 255, 0.8); font-size: 13px; font-weight: 500; }

    .menu {
        padding: 16px 0 24px 0; display: flex; flex-direction: column;
        gap: 8px; flex: 1; position: relative;
    }
    .menu::after {
        content: ''; position: absolute; bottom: 0; left: 20%; right: 20%;
        height: 2px; background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        border-radius: 2px;
    }
    .menu-btn {
        appearance: none; background: transparent; border: none;
        color: rgba(255, 255, 255, 0.8); padding: 14px 16px;
        border-radius: var(--radius-sm); width: 100%; text-align: left;
        font-weight: 500; font-size: 14px; cursor: pointer;
        display: flex; align-items: center; gap: 12px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative; overflow: hidden;
    }
    .menu-btn:hover {
        background: rgba(255, 255, 255, 0.1); color: white; transform: translateX(4px);
    }
    .menu-btn.active {
        background: rgba(255, 255, 255, 0.15); color: white;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    }
    .menu-btn i { width: 20px; text-align: center; font-size: 16px; opacity: 0.9; }

    .sidebar-bottom { margin-top: auto; padding-top: 32px; position: relative; }
    .logout-btn {
        background: linear-gradient(135deg, rgba(239, 68, 68, 0.9), rgba(220, 38, 38, 0.9));
        border: none; color: white; padding: 14px 20px;
        border-radius: var(--radius-sm); font-weight: 600; cursor: pointer;
        width: 100%; display: flex; align-items: center; justify-content: center;
        gap: 8px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 16px rgba(239, 68, 68, 0.3);
        backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.1);
    }
    .logout-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
    }

    .sidebar-overlay {
        position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5);
        opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
        z-index: 999; backdrop-filter: blur(4px);
    }
    .sidebar-overlay.show { opacity: 1; pointer-events: auto; }

    .main-with-sidebar {
        margin-left: 0; min-height: 100vh;
        background: linear-gradient(135deg,#f5f7fa 0%,#c3cfe2 100%);
        display: flex; flex-direction: column; width: 100%;
    }

    .floating-menu-btn {
        background: #fff; border: 1px solid var(--border);
        color: var(--text-primary); padding: 12px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        border-radius: var(--radius-sm); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: var(--shadow); flex-shrink: 0; z-index: 2;
    }
    .floating-menu-btn:hover {
        background: var(--light); transform: translateY(-1px); box-shadow: var(--shadow-md);
    }

    body.no-scroll { overflow: hidden; }
    
    .header{
        background:#fff; border-bottom:1px solid var(--border);
        padding: 1.25rem 2rem; position:sticky; top:0; z-index:100;
        box-shadow:var(--shadow-md); display: flex; align-items: center; gap: 16px;
    }
    .header-content{
        display:flex; justify-content:space-between; align-items:center;
        width: 100%; margin:0 auto; flex: 1;
    }
    .breadcrumb{
        font-size:.875rem; color:var(--text-secondary);
        display:flex; align-items:center; gap:.5rem; flex-wrap:wrap
    }
    .breadcrumb a{
        color:var(--primary); text-decoration:none; transition:color .2s
    }
    .breadcrumb a:hover{ text-decoration:underline; color:var(--primary-dark) }
    
    .btn{
        padding:.7rem 1.2rem; border-radius:10px; border:1px solid var(--border);
        background:#fff; cursor:pointer; font-weight:600;
        display:inline-flex; gap:.5rem; align-items:center;
        text-decoration:none; color:var(--text-primary);
        transition:all .3s; font-size:.875rem
    }
    .btn:hover{ box-shadow:var(--shadow-md); transform:translateY(-2px) }
    .btn-primary{
        background:linear-gradient(135deg,var(--primary),var(--primary-dark));
        border-color:var(--primary); color:#fff; box-shadow:var(--shadow)
    }
    .btn-secondary{
        background:var(--light); border-color:var(--border); color:var(--text-secondary);
    }
    
    .main-container{
        padding: 2rem; width: 100%; max-width: 1400px; margin: 0 auto;
    }
    
    .page-header {
        background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 100%);
        color: #fff; padding: 2rem; border-radius: var(--radius-lg);
        margin-bottom: 2rem; box-shadow: var(--shadow-xl);
        position: relative; overflow: hidden;
    }
    .page-header::before{
        content:''; position:absolute; top:-50%; right:-10%;
        width:400px; height:400px;
        background:radial-gradient(circle,rgba(255,255,255,.2),transparent);
        border-radius:50%
    }
    .page-header-content { position: relative; z-index: 1; }
    .page-title { font-size: 1.75rem; font-weight: 800; margin-bottom: 0.5rem; }
    .page-subtitle { font-size: 0.95rem; opacity: 0.9; }

    .students-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 1.5rem;
    }

    .student-card {
        background: #fff;
        border: 2px solid var(--border-light);
        border-radius: var(--radius);
        padding: 1.5rem;
        box-shadow: var(--shadow);
        transition: all 0.3s;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    .student-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 5px;
        height: 100%;
        background: linear-gradient(180deg, var(--primary), var(--info));
    }
    .student-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: var(--primary-light);
    }

    .student-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1rem;
    }
    .student-name {
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--dark);
        margin-bottom: 0.25rem;
    }
    .student-class {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: var(--primary-light);
        color: var(--primary-dark);
        padding: 0.4rem 0.8rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.5px;
    }

    .student-details {
        display: grid;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }
    .detail-row {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        color: var(--text-secondary);
    }
    .detail-row i {
        color: var(--primary);
        width: 20px;
        text-align: center;
    }

    .attendance-bar {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-light);
    }
    .attendance-label {
        font-size: 0.75rem;
        color: var(--text-secondary);
        margin-bottom: 0.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .attendance-percent {
        font-weight: 700;
        font-size: 1rem;
    }
    .attendance-percent.good { color: var(--success); }
    .attendance-percent.low { color: var(--danger); }
    .progress-bar {
        height: 8px;
        background: var(--border-light);
        border-radius: 999px;
        overflow: hidden;
    }
    .progress-fill {
        height: 100%;
        border-radius: 999px;
        transition: width 0.3s ease;
    }

    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background: #fff;
        border-radius: var(--radius);
        border: 2px dashed var(--border);
    }
    .empty-state i {
        font-size: 4rem;
        color: var(--text-light);
        margin-bottom: 1rem;
    }
    .empty-state h3 {
        color: var(--text-secondary);
        margin-bottom: 0.5rem;
    }
    .empty-state p {
        color: var(--text-light);
        font-size: 0.95rem;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.3rem 0.7rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.4px;
    }
    .badge-primary {
        background: var(--primary-light);
        color: var(--primary-dark);
    }

    @media (min-width: 1024px) {
        .sidebar { transform: none; }
        .sidebar-overlay { display: none; }
        .main-with-sidebar { margin-left: 288px; width: calc(100% - 288px); }
        .floating-menu-btn { display: none !important; }
        .sidebar-close { display: none; }
    }

    @media (max-width: 768px) {
        .students-grid {
            grid-template-columns: 1fr;
        }
        .main-container { padding: 1rem; }
        .page-header { padding: 1.5rem; }
    }
</style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

    <div id="app">
        <aside class="sidebar" id="sidebar" aria-hidden="true">
            <button class="sidebar-close" id="sidebarClose" type="button">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-inner">
                <div class="profile" onclick="window.location.href='member_dashboard.php'">
                    <div class="profile-avatar">
                        <img src="/ma/logo.jpeg" alt="Logo">
                    </div>
                    <div class="name"><?php echo htmlspecialchars($member['head_name'] ?? 'Member'); ?></div>
                    <div class="role">Member Dashboard</div>
                </div>

                <nav class="menu">
                    <button class="menu-btn" type="button" onclick="window.location.href='member_dashboard.php'">
                        <i class="fas fa-house-user"></i>
                        <span>Dashboard</span>
                    </button>

            


                    <button class="menu-btn" type="button" onclick="window.location.href='add_family_member_self.php'">
                        <i class="fas fa-user-plus"></i>
                        <span>Add Family Member</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='member_cert_requests.php'">
                        <i class="fas fa-list"></i>
                        <span>Certificate Requests</span>
                    </button>
                </nav>

                <div class="sidebar-bottom">
                    <form action="logout.php" method="post" style="margin:0">
                        <button type="submit" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <main class="main-with-sidebar" id="main">
            <section class="header">
                <button class="floating-menu-btn" id="menuToggle" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="header-content">
                    <div class="breadcrumb">
                        <a href="member_dashboard.php"><i class="fas fa-house-user"></i> Dashboard</a>
                        <span>/</span>
                        <span style="font-weight: bold; font-size: 22px; color: black;">My Students</span>
                    </div>
                </div>
            </section>

            <div class="main-container">
                <div class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">
                            <i class="fas fa-user-graduate"></i> Family Students
                        </h1>
                        <p class="page-subtitle">
                            View academic information and performance of family members enrolled as students
                        </p>
                    </div>
                </div>

                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-graduation-cap"></i>
                        <h3>No Students Found</h3>
                        <p>No family members are currently enrolled as students.</p>
                    </div>
                <?php else: ?>
                    <div class="students-grid">
                        <?php foreach ($students as $student): ?>
                            <div class="student-card" onclick="window.location.href='member_student_detail.php?student_id=<?= $student['id'] ?>'">
                                <div class="student-header">
                                    <div>
                                        <div class="student-name">
                                            <?= htmlspecialchars($student['student_name']) ?>
                                        </div>
                                        <?php if (!empty($student['admission_number'])): ?>
                                            <span class="badge badge-primary">
                                                <?= htmlspecialchars($student['admission_number']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($student['class_name']): ?>
                                        <span class="student-class">
                                            <i class="fas fa-chalkboard"></i>
                                            <?= htmlspecialchars($student['class_name']) ?>-<?= htmlspecialchars($student['division']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="student-details">
                                    <?php if ($student['gender']): ?>
                                        <div class="detail-row">
                                            <i class="fas fa-venus-mars"></i>
                                            <span><?= htmlspecialchars($student['gender']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($student['date_of_birth']): ?>
                                        <div class="detail-row">
                                            <i class="fas fa-birthday-cake"></i>
                                            <span><?= date('M j, Y', strtotime($student['date_of_birth'])) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($student['father_name']): ?>
                                        <div class="detail-row">
                                            <i class="fas fa-user"></i>
                                            <span><?= htmlspecialchars($student['father_name']) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($student['parent_phone']): ?>
                                        <div class="detail-row">
                                            <i class="fas fa-phone"></i>
                                            <span><?= htmlspecialchars($student['parent_phone']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($student['total_days'] > 0): ?>
                                    <div class="attendance-bar">
                                        <div class="attendance-label">
                                            <span>Attendance</span>
                                            <span class="attendance-percent <?= $student['attendance_percentage'] < 75 ? 'low' : 'good' ?>">
                                                <?= number_format($student['attendance_percentage'], 1) ?>%
                                            </span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" 
                                                 style="width: <?= min(100, $student['attendance_percentage']) ?>%; 
                                                        background: <?= $student['attendance_percentage'] < 75 ? 'var(--danger)' : 'var(--success)' ?>;">
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggle = document.getElementById('menuToggle');
        const closeBtn = document.getElementById('sidebarClose');

        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('show');
            overlay.hidden = false;
            document.body.classList.add('no-scroll');
            toggle.setAttribute('aria-expanded', 'true');
            sidebar.setAttribute('aria-hidden', 'false');
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            document.body.classList.remove('no-scroll');
            toggle.setAttribute('aria-expanded', 'false');
            sidebar.setAttribute('aria-hidden', 'true');
            setTimeout(() => { overlay.hidden = true; }, 200);
        }

        toggle.addEventListener('click', () => {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });

        closeBtn.addEventListener('click', closeSidebar);
        overlay.addEventListener('click', closeSidebar);
        
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
        });

        if (window.matchMedia('(min-width: 1024px)').matches) {
            sidebar.classList.add('open');
            sidebar.setAttribute('aria-hidden', 'false');
            toggle.setAttribute('aria-expanded', 'true');
        }
    </script>
</body>
</html>