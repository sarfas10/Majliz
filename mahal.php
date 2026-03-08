<?php
// mahal.php — Public promotional page for a registered mahal
// URL: /mahal/SLUG (via .htaccess rewrite) or mahal.php?slug=SLUG

require_once __DIR__ . '/db_connection.php';

// ─── Get slug ────────────────────────────────────────────────────────────────
$slug = '';
// Support PATH_INFO: /mahal.php/VandanpathalJM
if (!empty($_SERVER['PATH_INFO'])) {
    $slug = trim($_SERVER['PATH_INFO'], '/');
}
// Support query string: mahal.php?slug=VandanpathalJM
if (empty($slug) && !empty($_GET['slug'])) {
    $slug = trim($_GET['slug']);
}
// Sanitize
$slug = preg_replace('/[^a-zA-Z0-9\-_]/', '', $slug);

// ─── DB lookup ───────────────────────────────────────────────────────────────
$mahal = null;
$profile = null;
$gallery = [];
$committee = [];

if (!empty($slug)) {
    $db_result = get_db_connection();
    if (!isset($db_result['error'])) {
        $conn = $db_result['conn'];

        // Ensure tables exist (graceful — won't fail if already there)
        $conn->query("CREATE TABLE IF NOT EXISTS mahal_public_profile (
            id INT AUTO_INCREMENT PRIMARY KEY, mahal_id INT NOT NULL UNIQUE,
            slug VARCHAR(100) NOT NULL UNIQUE, description TEXT,
            established_year VARCHAR(10), is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
        )");
        $conn->query("CREATE TABLE IF NOT EXISTS mahal_gallery (
            id INT AUTO_INCREMENT PRIMARY KEY, mahal_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL, caption VARCHAR(200), sort_order INT DEFAULT 0,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
        )");
        $conn->query("CREATE TABLE IF NOT EXISTS mahal_committee (
            id INT AUTO_INCREMENT PRIMARY KEY, mahal_id INT NOT NULL,
            name VARCHAR(150) NOT NULL, role VARCHAR(100), phone VARCHAR(20),
            image_path VARCHAR(255), sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
        )");

        // Fetch profile
        $stmt = $conn->prepare("
            SELECT p.*, r.name AS mahal_name, r.address, r.registration_no, r.phone AS mahal_phone
            FROM mahal_public_profile p
            JOIN register r ON r.id = p.mahal_id
            WHERE p.slug = ? AND p.is_published = 1
        ");
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $profile = $row;
            $mid = (int) $row['mahal_id'];

            // Gallery — order primary first
            $g = $conn->prepare("SELECT * FROM mahal_gallery WHERE mahal_id = ? ORDER BY is_primary DESC, sort_order, uploaded_at");
            $g->bind_param("i", $mid);
            $g->execute();
            $gr = $g->get_result();
            while ($r2 = $gr->fetch_assoc())
                $gallery[] = $r2;
            $g->close();

            // Committee
            $c = $conn->prepare("SELECT * FROM mahal_committee WHERE mahal_id = ? ORDER BY sort_order, id");
            $c->bind_param("i", $mid);
            $c->execute();
            $cr = $c->get_result();
            while ($r3 = $cr->fetch_assoc())
                $committee[] = $r3;
            $c->close();
        }

        $conn->close();
    }
}

$base_url = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/');
$gallery_base = $base_url . '/uploads/mahal_gallery/';
$committee_base = $base_url . '/uploads/mahal_committee/';

// Separate primary hero image from regular gallery
$hero_image = null;
$gallery_grid = [];
foreach ($gallery as $img) {
    if ($img['is_primary'] == 1 && $hero_image === null) {
        $hero_image = $img;
    } else {
        $gallery_grid[] = $img;
    }
}

$page_title = $profile ? htmlspecialchars($profile['mahal_name']) . ' — Mahal Profile' : 'Mahal Not Found';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $page_title; ?>
    </title>
    <?php if ($profile): ?>
        <meta name="description" content="<?php echo htmlspecialchars(substr($profile['description'] ?? '', 0, 160)); ?>">
        <meta property="og:title" content="<?php echo htmlspecialchars($profile['mahal_name']); ?>">
        <meta property="og:description"
            content="<?php echo htmlspecialchars(substr($profile['description'] ?? '', 0, 160)); ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@400;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #0d1117;
            --bg2: #161b22;
            --bg3: #21262d;
            --border: rgba(255, 255, 255, 0.08);
            --text: #e6edf3;
            --text-muted: #8b949e;
            --accent: #22c55e;
            --accent2: #16a34a;
            --gold: #f59e0b;
            --radius: 16px;
            --radius-sm: 10px;
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.3);
            --glass: rgba(255, 255, 255, 0.04);
        }

        [data-theme="light"] {
            --bg: #f8fafc;
            --bg2: #ffffff;
            --bg3: #f1f5f9;
            --border: rgba(0, 0, 0, 0.1);
            --text: #0f172a;
            --text-muted: #64748b;
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.08);
            --glass: rgba(0, 0, 0, 0.02);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            transition: background 0.3s, color 0.3s;
        }

        /* ── NAV ─────────────────────────────────────────────────────────── */
        .nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(13, 17, 23, 0.35);
            /* Lighter dark mode bg */
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            /* Thinner border feel */
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        [data-theme="light"] .nav {
            background: rgba(255, 255, 255, 0.45);
            /* Lighter, clearer white bg */
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.03);
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 22px;
            text-decoration: none;
            color: var(--text);
            font-family: 'Playfair Display', serif;
            letter-spacing: 0.5px;
            transition: opacity 0.3s ease;
        }

        .nav-brand:hover {
            opacity: 0.9;
        }

        .nav-brand img {
            height: 48px;
            width: auto;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .theme-btn {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text);
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        [data-theme="light"] .theme-btn {
            background: rgba(0, 0, 0, 0.03);
            border-color: rgba(0, 0, 0, 0.08);
            color: var(--text);
        }

        .theme-btn:hover {
            transform: translateY(-2px) scale(1.05);
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.25);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
        }

        [data-theme="light"] .theme-btn:hover {
            background: rgba(0, 0, 0, 0.06);
            border-color: rgba(0, 0, 0, 0.12);
        }

        .login-btn {
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white;
            padding: 10px 24px;
            border-radius: 100px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: none;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.25);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4);
            filter: brightness(1.05);
        }
        }

        /* ── HERO ────────────────────────────────────────────────────────── */
        .hero {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 100px 32px 64px;
            position: relative;
            overflow: hidden;
        }

        .hero-bg {
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, #0d1117 0%, #1a2f1a 50%, #0d1117 100%);
            background-size: cover;
            background-position: center;
        }

        .hero-bg.has-image {
            background-blend-mode: multiply;
        }

        [data-theme="light"] .hero-bg {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 50%, #f8fafc 100%);
        }

        [data-theme="light"] .hero-bg.has-image {
            background-blend-mode: luminosity;
        }

        .hero-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            animation: float 8s ease-in-out infinite;
        }

        .hero-orb-1 {
            width: 600px;
            height: 600px;
            background: #22c55e;
            top: -200px;
            right: -200px;
        }

        .hero-orb-2 {
            width: 400px;
            height: 400px;
            background: #f59e0b;
            bottom: -100px;
            left: -100px;
            animation-delay: -4s;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0) scale(1);
            }

            50% {
                transform: translateY(-30px) scale(1.05);
            }
        }

        .hero-content {
            position: relative;
            z-index: 1;
            max-width: 800px;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: var(--accent);
            padding: 6px 16px;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 24px;
        }

        .hero-badge::before {
            content: '●';
            font-size: 9px;
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.3;
            }
        }

        .hero h1 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(40px, 6vw, 72px);
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 16px;
        }

        .hero-address {
            font-size: 16px;
            color: var(--text-muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .hero-reg {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .hero-stats {
            display: flex;
            gap: 32px;
            margin-top: 32px;
        }

        .stat-item {
            text-align: left;
        }

        .stat-num {
            font-size: 28px;
            font-weight: 800;
            color: var(--accent);
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ── SCROLL INDICATOR ─────────────────────────────────────────────── */
        .scroll-hint {
            position: absolute;
            bottom: 32px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 12px;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {

            0%,
            100% {
                transform: translateX(-50%) translateY(0);
            }

            50% {
                transform: translateX(-50%) translateY(8px);
            }
        }

        /* ── SECTIONS ─────────────────────────────────────────────────────── */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 32px;
        }

        section {
            padding: 80px 0;
        }

        .section-header {
            margin-bottom: 48px;
        }

        .section-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--accent);
            margin-bottom: 12px;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 36px;
            font-weight: 700;
        }

        .section-divider {
            width: 60px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), transparent);
            margin-top: 16px;
            border-radius: 2px;
        }

        /* ── ABOUT ────────────────────────────────────────────────────────── */
        .about-section {
            background: var(--bg);
        }

        .about-card {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 48px;
            box-shadow: var(--shadow-sm);
        }

        .about-text {
            font-size: 16px;
            line-height: 1.9;
            color: var(--text-muted);
            white-space: pre-wrap;
        }

        /* ── GALLERY ──────────────────────────────────────────────────────── */
        .gallery-section {
            background: var(--bg2);
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }

        .gallery-item {
            position: relative;
            border-radius: var(--radius-sm);
            overflow: hidden;
            aspect-ratio: 4/3;
            cursor: pointer;
            background: var(--bg3);
            border: 1px solid var(--border);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .gallery-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s;
        }

        .gallery-item:hover img {
            transform: scale(1.08);
        }

        .gallery-item .gallery-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.7) 0%, transparent 60%);
            opacity: 0;
            transition: opacity 0.3s;
            display: flex;
            align-items: flex-end;
            padding: 16px;
        }

        .gallery-item:hover .gallery-overlay {
            opacity: 1;
        }

        .gallery-caption {
            color: white;
            font-size: 13px;
            font-weight: 500;
        }

        .gallery-zoom-icon {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .gallery-item:hover .gallery-zoom-icon {
            opacity: 1;
        }

        .gallery-empty {
            text-align: center;
            padding: 64px;
            color: var(--text-muted);
        }

        .gallery-empty i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        /* ── COMMITTEE ────────────────────────────────────────────────────── */
        .committee-section {
            background: var(--bg);
        }

        .committee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 24px;
        }

        .member-card {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s, border-color 0.3s;
            position: relative;
        }

        .member-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent), var(--gold));
        }

        .member-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow);
            border-color: rgba(34, 197, 94, 0.3);
        }

        .member-photo {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            background: var(--bg3);
        }

        .member-photo-placeholder {
            width: 100%;
            aspect-ratio: 1;
            background: linear-gradient(135deg, var(--bg3), var(--bg2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .member-info {
            padding: 20px 16px;
        }

        .member-name {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .member-role {
            font-size: 12px;
            color: var(--accent);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .member-phone {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-muted);
            background: var(--bg3);
            padding: 6px 14px;
            border-radius: 100px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .member-phone:hover {
            background: var(--accent);
            color: white;
        }

        /* ── LIGHTBOX ─────────────────────────────────────────────────────── */
        .lightbox {
            position: fixed;
            inset: 0;
            z-index: 999;
            background: rgba(0, 0, 0, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }

        .lightbox.active {
            opacity: 1;
            pointer-events: all;
        }

        .lightbox img {
            max-width: 90vw;
            max-height: 85vh;
            object-fit: contain;
            border-radius: 8px;
        }

        .lightbox-close {
            position: absolute;
            top: 24px;
            right: 24px;
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: white;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .lightbox-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .lightbox-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: white;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            font-size: 22px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .lightbox-nav:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .lightbox-prev {
            left: 24px;
        }

        .lightbox-next {
            right: 24px;
        }

        .lightbox-caption {
            position: absolute;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }

        /* ── 404 ──────────────────────────────────────────────────────────── */
        .not-found {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 32px;
        }

        .not-found-icon {
            font-size: 80px;
            margin-bottom: 24px;
            opacity: 0.3;
        }

        .not-found h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .not-found p {
            color: var(--text-muted);
            font-size: 18px;
        }

        /* ── FOOTER ───────────────────────────────────────────────────────── */
        .footer {
            background: var(--bg2);
            border-top: 1px solid var(--border);
            padding: 32px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }

        .footer a {
            color: var(--accent);
            text-decoration: none;
        }

        /* ── RESPONSIVE ───────────────────────────────────────────────────── */
        @media (max-width: 768px) {
            .nav {
                padding: 14px 20px;
            }

            .hero {
                padding: 90px 20px 48px;
            }

            .hero-stats {
                flex-wrap: wrap;
                gap: 20px;
            }

            .container {
                padding: 0 20px;
            }

            section {
                padding: 60px 0;
            }

            .section-title {
                font-size: 28px;
            }

            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 10px;
            }

            .committee-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 16px;
            }

            .about-card {
                padding: 28px 20px;
            }
        }
    </style>
</head>

<body>

    <!-- NAVBAR -->
    <nav class="nav">
        <a href="<?php echo $base_url; ?>/" class="nav-brand">
            <img src="<?php echo $base_url; ?>/assets/logo.jpeg" alt="Majliz Logo">
            <span>Majliz</span>
        </a>
        <div style="display:flex; align-items:center; gap:16px;">
            <button class="theme-btn" onclick="toggleTheme()" id="themeBtn" title="Toggle light/dark mode">🌙</button>
            <a href="<?php echo $base_url; ?>/index.php" class="login-btn"><i class="fas fa-sign-in-alt"></i> Login</a>
        </div>
    </nav>

    <?php if (!$profile): ?>
        <!-- 404 PAGE -->
        <div class="not-found">
            <div class="not-found-icon">🕌</div>
            <h1>Mahal Not Found</h1>
            <p>No mahal found for "<strong>
                    <?php echo htmlspecialchars($slug ?: 'this URL'); ?>
                </strong>".
                <br>Please check the URL or contact the mahal administration.
            </p>
        </div>

    <?php else:
        $name = htmlspecialchars($profile['mahal_name']);
        $address = htmlspecialchars($profile['address'] ?? '');
        $reg_no = htmlspecialchars($profile['registration_no'] ?? '');
        $description = $profile['description'] ?? '';
        $est_year = $profile['established_year'] ?? '';
        ?>

        <!-- HERO -->
        <section class="hero">
            <div class="hero-bg<?php echo $hero_image ? ' has-image' : ''; ?>" <?php if ($hero_image): ?>
                    style="background-image: linear-gradient(to bottom, rgba(0,0,0,0.55) 0%, rgba(0,0,0,0.75) 100%), url('<?php echo $gallery_base . htmlspecialchars($hero_image['image_path']); ?>');"
                <?php endif; ?>></div>
            <?php if (!$hero_image): ?>
                <div class="hero-orb hero-orb-1"></div>
                <div class="hero-orb hero-orb-2"></div>
            <?php endif; ?>
            <div class="hero-content">

                <h1>
                    <?php echo $name; ?>
                </h1>
                <?php if ($address): ?>
                    <div class="hero-address"><i class="fas fa-map-marker-alt"></i>
                        <?php echo $address; ?>
                    </div>
                <?php endif; ?>
                <?php if ($reg_no): ?>
                    <div class="hero-reg"><i class="fas fa-id-card"></i> Reg. No:
                        <?php echo $reg_no; ?>
                    </div>
                <?php endif; ?>
                <div class="hero-stats">
                    <?php if ($est_year): ?>
                        <div class="stat-item">
                            <div class="stat-num">
                                <?php echo htmlspecialchars($est_year); ?>
                            </div>
                            <div class="stat-label">Established</div>
                        </div>
                    <?php endif; ?>
                    <?php if (count($gallery) > 0): ?>
                        <div class="stat-item">
                            <div class="stat-num">
                                <?php echo count($gallery); ?>
                            </div>
                            <div class="stat-label">Gallery Photos</div>
                        </div>
                    <?php endif; ?>
                    <?php if (count($committee) > 0): ?>
                        <div class="stat-item">
                            <div class="stat-num">
                                <?php echo count($committee); ?>
                            </div>
                            <div class="stat-label">Committee Members</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="scroll-hint" id="scrollHint">
                <span>Scroll to explore</span>
                <i class="fas fa-chevron-down"></i>
            </div>
        </section>

        <!-- ABOUT -->
        <?php if ($description): ?>
            <section class="about-section">
                <div class="container">
                    <div class="section-header">
                        <div class="section-label">About Us</div>
                        <div class="section-title">Who We Are</div>
                        <div class="section-divider"></div>
                    </div>
                    <div class="about-card">
                        <div class="about-text">
                            <?php echo htmlspecialchars($description); ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- GALLERY -->
        <section class="gallery-section" id="gallery">
            <div class="container">
                <div class="section-header">
                    <div class="section-label">Photo Gallery</div>
                    <div class="section-title">Moments &amp; Memories</div>
                    <div class="section-divider"></div>
                </div>
                <?php if (empty($gallery_grid)): ?>
                    <div class="gallery-empty">
                        <i class="fas fa-images"></i>
                        <p><?php echo $hero_image ? 'Only a hero image is set. Upload more photos to show in the gallery.' : 'No photos uploaded yet.'; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="gallery-grid" id="galleryGrid">
                        <?php foreach ($gallery_grid as $idx => $img): ?>
                            <div class="gallery-item" onclick="openLightbox(<?php echo $idx; ?>)">
                                <img src="<?php echo $gallery_base . htmlspecialchars($img['image_path']); ?>"
                                    alt="<?php echo htmlspecialchars($img['caption'] ?? 'Gallery image'); ?>" loading="lazy">
                                <div class="gallery-overlay">
                                    <div class="gallery-caption">
                                        <?php echo htmlspecialchars($img['caption'] ?? ''); ?>
                                    </div>
                                </div>
                                <div class="gallery-zoom-icon"><i class="fas fa-expand"></i></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- COMMITTEE -->
        <?php if (!empty($committee)): ?>
            <section class="committee-section" id="committee">
                <div class="container">
                    <div class="section-header">
                        <div class="section-label">Leadership</div>
                        <div class="section-title">Committee Members</div>
                        <div class="section-divider"></div>
                    </div>
                    <div class="committee-grid">
                        <?php foreach ($committee as $member): ?>
                            <div class="member-card">
                                <?php if ($member['image_path']): ?>
                                    <img src="<?php echo $committee_base . htmlspecialchars($member['image_path']); ?>"
                                        alt="<?php echo htmlspecialchars($member['name']); ?>" class="member-photo" loading="lazy">
                                <?php else: ?>
                                    <div class="member-photo-placeholder">
                                        <?php echo strtoupper(mb_substr($member['name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="member-info">
                                    <div class="member-name">
                                        <?php echo htmlspecialchars($member['name']); ?>
                                    </div>
                                    <?php if ($member['role']): ?>
                                        <div class="member-role">
                                            <?php echo htmlspecialchars($member['role']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($member['phone']): ?>
                                        <a href="tel:<?php echo htmlspecialchars($member['phone']); ?>" class="member-phone">
                                            <i class="fas fa-phone"></i>
                                            <?php echo htmlspecialchars($member['phone']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- LIGHTBOX -->
        <div class="lightbox" id="lightbox">
            <button class="lightbox-close" onclick="closeLightbox()">✕</button>
            <button class="lightbox-nav lightbox-prev" onclick="changeLightbox(-1)">‹</button>
            <img src="" id="lightboxImg" alt="Gallery image">
            <button class="lightbox-nav lightbox-next" onclick="changeLightbox(1)">›</button>
            <div class="lightbox-caption" id="lightboxCaption"></div>
        </div>

    <?php endif; ?>

    <!-- FOOTER -->
    <footer class="footer">
        <p>Powered by <a href="#">Majliz</a> &nbsp;·&nbsp; ©
            <?php echo date('Y'); ?>
        </p>
    </footer>

    <script>
        // ── Theme toggle ──────────────────────────────────────────────────────────
        const storedTheme = localStorage.getItem('mahal_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', storedTheme);
        updateThemeBtn(storedTheme);

        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('mahal_theme', next);
            updateThemeBtn(next);
        }
        function updateThemeBtn(theme) {
            const btn = document.getElementById('themeBtn');
            if (btn) btn.textContent = theme === 'dark' ? '☀️' : '🌙';
        }

        // ── Scroll hint hide ──────────────────────────────────────────────────────
        window.addEventListener('scroll', () => {
            const hint = document.getElementById('scrollHint');
            if (hint) hint.style.opacity = window.scrollY > 100 ? '0' : '1';
        });

        // ── Gallery lightbox ──────────────────────────────────────────────────────
        const galleryImages = <?php echo json_encode(array_values(array_map(function ($img) use ($gallery_base) {
            return ['src' => $gallery_base . $img['image_path'], 'caption' => $img['caption'] ?? ''];
        }, $gallery_grid))); ?>;
        let currentLightboxIdx = 0;

        function openLightbox(idx) {
            currentLightboxIdx = idx;
            setLightboxImage(idx);
            document.getElementById('lightbox').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('active');
            document.body.style.overflow = '';
        }
        function changeLightbox(dir) {
            currentLightboxIdx = (currentLightboxIdx + dir + galleryImages.length) % galleryImages.length;
            setLightboxImage(currentLightboxIdx);
        }
        function setLightboxImage(idx) {
            if (!galleryImages.length) return;
            document.getElementById('lightboxImg').src = galleryImages[idx].src;
            document.getElementById('lightboxCaption').textContent = galleryImages[idx].caption;
        }
        document.getElementById('lightbox')?.addEventListener('click', function (e) {
            if (e.target === this) closeLightbox();
        });
        document.addEventListener('keydown', (e) => {
            const lb = document.getElementById('lightbox');
            if (!lb?.classList.contains('active')) return;
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') changeLightbox(-1);
            if (e.key === 'ArrowRight') changeLightbox(1);
        });

        // ── Smooth scroll for nav links ───────────────────────────────────────────
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', e => {
                e.preventDefault();
                const el = document.querySelector(a.getAttribute('href'));
                if (el) el.scrollIntoView({ behavior: 'smooth' });
            });
        });
    </script>
</body>

</html>