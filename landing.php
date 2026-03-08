<?php
require_once __DIR__ . '/session_bootstrap.php';

/* --- if already logged in, never show landing --- */
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
if (!empty($_SESSION['member_login']) && $_SESSION['member_login'] === true) {
    header("Location: member_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <!-- BASIC META -->
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Masjid Management System — Majliz.in | Mosque & Community Administration</title>

  <!-- PRIMARY SEO -->
  <meta name="description" content="Majliz.in – A modern Masjid Management System for managing majlis, donations, zakat, events, finances, announcements, volunteers, and community records in one unified platform.">
  <meta name="keywords" content="majliz, masjid management system, mosque admin software, masjid donations, zakat tracking, islamic community management, majlis management, mosque events, mosque accounting">
  <meta name="author" content="Majliz.in">
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="https://majliz.in/">

  <!-- OPEN GRAPH (Social Sharing) -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="Majliz.in | Masjid Management System for Mosque & Majlis Administration">
  <meta property="og:description" content="Majliz.in provides a unified digital platform for masjid and majlis operations, including donations, zakat, events, announcements, finances, and member records.">
  <meta property="og:url" content="https://majliz.in/">
  <meta property="og:image" content="https://majliz.in/assets/og-image.png">
  <meta property="og:site_name" content="Majliz.in">

  <!-- TWITTER CARD -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Majliz.in | Masjid Management System">
  <meta name="twitter:description" content="A secure and structured masjid management platform for community engagement, donations, and administration.">
  <meta name="twitter:image" content="https://majliz.in/assets/og-image.png">

  <!-- SITE ICON -->
  <link rel="icon" href="https://majliz.in/favicon.ico" type="image/x-icon">

  <!-- THEME COLOR -->
  <meta name="theme-color" content="#0f5132">

  <!-- STRUCTURED DATA: SOFTWARE APPLICATION -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    "name": "Majliz.in Masjid Management System",
    "applicationCategory": "BusinessApplication",
    "operatingSystem": "Web",
    "description": "A web-based masjid and majlis management system designed to manage community records, donations, zakat, events, and finances.",
    "url": "https://majliz.in/",
    "offers": {
      "@type": "Offer",
      "price": "0",
      "priceCurrency": "INR"
    }
  }
  </script>

  <!-- STRUCTURED DATA: ORGANIZATION -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "Majliz.in",
    "url": "https://majliz.in/",
    "logo": "https://majliz.in/assets/logo.png"
  }
  </script>
</head>
<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Majiliz - Mosque Management Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary: #0EA5E9;
            --primary-dark: #0284C7;
            --primary-light: #38BDF8;
            --accent: #10b981;
            --dark: #0f172a;
            --gray-900: #1e293b;
            --gray-700: #334155;
            --gray-500: #64748b;
            --gray-300: #cbd5e1;
            --gray-100: #f1f5f9;
            --gray-50: #f8fafc;
            --white: #ffffff;
            --gold: #F59E0B;
            --teal: #0d9488;
            --gradient-1: linear-gradient(135deg, #0EA5E9 0%, #3B82F6 100%);
            --gradient-2: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-3: linear-gradient(135deg, #F59E0B 0%, #EAB308 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: var(--gray-900);
            line-height: 1.6;
            background-color: var(--white);
            overflow-x: hidden;
        }

        .container {
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Header */
        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 16px 0;
            z-index: 1000;
            background-color: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(203, 213, 225, 0.3);
            transition: all 0.3s ease;
        }

        header.scrolled {
            padding: 12px 0;
            background-color: rgba(255, 255, 255, 0.95);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            text-decoration: none;
        }

        .logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        nav ul {
            display: flex;
            list-style: none;
            gap: 40px;
            align-items: center;
        }

        nav a {
            font-weight: 500;
            font-size: 15px;
            color: var(--gray-700);
            transition: color 0.3s ease;
            text-decoration: none;
        }

        nav a:hover {
            color: var(--primary);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 24px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 15px;
            text-decoration: none;
            gap: 8px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .mobile-toggle {
            display: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--primary);
        }

        /* Hero Section */
        .hero {
            padding: 140px 0 100px;
            background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 50%, #f8fafc 100%);
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 800px;
            height: 800px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.08) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.06) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .hero-content {
            max-width: 600px;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(59, 130, 246, 0.1);
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 24px;
        }

        .hero h1 {
            font-size: 52px;
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 24px;
            color: var(--dark);
            letter-spacing: -0.02em;
            text-align: left;
        }

        .hero h1 .gradient-text {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero p {
            font-size: 18px;
            color: var(--gray-700);
            margin-bottom: 32px;
            line-height: 1.7;
            text-align: left;
        }

        .btn-group {
            display: flex;
            gap: 16px;
            margin-bottom: 32px;
        }

        .hero-visual {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Quran Quote Container - White Background with Gold Icons */
        .quran-quote {
            position: relative;
            margin-bottom: 0;
        }

        .quote-container {
            background: var(--white);
            border-radius: 24px;
            padding: 48px;
            box-shadow: 0 20px 60px rgba(212, 175, 55, 0.12);
            border: 1px solid rgba(212, 175, 55, 0.15);
            position: relative;
            overflow: hidden;
        }

        .quote-container::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.08) 0%, transparent 70%);
            border-radius: 50%;
        }

        .quote-container::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.05) 0%, transparent 70%);
            border-radius: 50%;
        }

        .quote-icon {
            width: 56px;
            height: 56px;
            background: var(--gold);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
            box-shadow: 0 8px 20px rgba(212, 175, 55, 0.3);
        }

        .arabic-text {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 16px;
            line-height: 1.8;
            direction: rtl;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .quote-translation {
            font-size: 18px;
            color: var(--gray-700);
            font-style: italic;
            margin-bottom: 12px;
            line-height: 1.6;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .quote-reference {
            font-size: 14px;
            color: var(--gray-600);
            font-weight: 600;
            text-align: center;
            position: relative;
            z-index: 1;
            letter-spacing: 1px;
        }

        .quran-decoration {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
            position: relative;
            z-index: 1;
        }

        .decoration-item {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gold) 0%, #fbbf24 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
        }

        .mini-badges {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            justify-content: center;
        }

        .mini-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(3, 105, 161, 0.08);
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            color: #1BA1A1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(3, 105, 161, 0.2);
        }

        .mini-badge i {
            color: #1BA1A1;
        }

        .quote-icon {
            display: none;
        }

        /* Trust Section below Quran Container */
        .trust-section {
            background: linear-gradient(135deg, var(--accent) 0%, #059669 100%);
            color: white;
            border-radius: 20px;
            padding: 32px 40px;
            display: flex;
            align-items: center;
            gap: 24px;
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3);
            margin-top: 20px;
        }

        .trust-section-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }

        .trust-section-content h4 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .trust-section-content p {
            font-size: 15px;
            opacity: 0.9;
            line-height: 1.6;
        }

        /* Features Section */
        .features {
            padding: 100px 0;
            background: white;
        }

        .section-header {
            max-width: 700px;
            margin: 0 auto 64px;
            text-align: center;
        }

        .section-badge {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
            text-transform: uppercase;
        }

        .section-title {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 16px;
            color: var(--dark);
            letter-spacing: -0.02em;
        }

        .section-subtitle {
            font-size: 18px;
            color: var(--gray-700);
            line-height: 1.7;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 32px;
        }

        .feature-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            border: 1px solid var(--gray-300);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            border-color: var(--primary-light);
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            color: white;
            font-size: 28px;
            position: relative;
            z-index: 1;
        }

        .feature-card h3 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--dark);
            position: relative;
            z-index: 1;
        }

        .feature-card p {
            color: var(--gray-700);
            line-height: 1.7;
            font-size: 15px;
            position: relative;
            z-index: 1;
        }

        /* Modules Section */
        .modules {
            padding: 100px 0;
            background: var(--gray-50);
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-top: 48px;
        }

        .module-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            border: 1px solid var(--gray-300);
            transition: all 0.3s ease;
        }

        .module-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.06);
            border-color: var(--primary);
        }

        .module-card h4 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .module-card h4 i {
            color: var(--primary);
            font-size: 20px;
        }

        .module-card p {
            color: var(--gray-700);
            font-size: 14px;
            line-height: 1.6;
        }

        /* Benefits Section */
        .benefits {
            padding: 100px 0;
            background: white;
        }

        .benefits-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: center;
            margin-top: 64px;
        }

        .benefits-list {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .benefit-item {
            display: flex;
            gap: 16px;
        }

        .benefit-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--accent) 0%, #059669 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            flex-shrink: 0;
        }

        .benefit-text h4 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--dark);
        }

        .benefit-text p {
            font-size: 15px;
            color: var(--gray-700);
            line-height: 1.6;
        }

        .benefits-visual {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 24px;
            padding: 48px;
            position: relative;
            overflow: hidden;
        }

        .benefits-visual::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .visual-content {
            position: relative;
            z-index: 1;
            color: white;
            text-align: center;
        }

        .visual-content i {
            font-size: 80px;
            margin-bottom: 24px;
            opacity: 0.9;
        }

        .visual-content h3 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .visual-content p {
            font-size: 16px;
            opacity: 0.9;
            line-height: 1.7;
        }

        /* Contact Section */
        .contact-section {
            padding: 100px 0;
            background: var(--gray-50);
        }

        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: start;
        }

        .contact-info h2 {
            font-size: 36px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 16px;
            letter-spacing: -0.02em;
        }

        .contact-info>p {
            font-size: 18px;
            color: var(--gray-700);
            margin-bottom: 40px;
            line-height: 1.7;
        }

        .contact-items {
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        .contact-item {
            display: flex;
            gap: 20px;
            align-items: start;
        }

        .contact-item-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
            flex-shrink: 0;
        }

        .contact-item h4 {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 6px;
        }

        .contact-item a {
            color: var(--primary);
            text-decoration: none;
            font-size: 15px;
            transition: color 0.3s ease;
        }

        .contact-item a:hover {
            color: var(--primary-dark);
        }

        .contact-item p {
            color: var(--gray-700);
            font-size: 15px;
            line-height: 1.6;
        }

        .contact-form-wrapper {
            background: white;
            border-radius: 24px;
            padding: 48px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--gray-300);
        }

        .form-input {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            margin-bottom: 16px;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-input {
            resize: vertical;
            min-height: 120px;
        }

        .cta-section {
            padding: 100px 0;
            background: linear-gradient(135deg, var(--dark) 0%, var(--gray-900) 100%);
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.5;
        }

        .cta-content {
            max-width: 700px;
            margin: 0 auto;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .cta-content h2 {
            font-size: 42px;
            font-weight: 800;
            color: white;
            margin-bottom: 20px;
            letter-spacing: -0.02em;
        }

        .cta-content p {
            font-size: 18px;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 40px;
            line-height: 1.7;
        }

        .btn-large {
            padding: 16px 40px;
            font-size: 18px;
            background: white;
            color: var(--primary);
        }

        .btn-large:hover {
            background: var(--gray-50);
        }

        /* Enhanced Footer Styles */
        footer {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            padding: 80px 0 24px;
            position: relative;
            overflow: hidden;
        }

        footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.02'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.3;
        }

        .footer-main {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 60px;
            margin-bottom: 60px;
            position: relative;
            z-index: 1;
        }

        .footer-brand-section {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 8px;
        }

        .footer-logo .logo-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: transparent;
        }

        .footer-logo .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .logo-text h3 {
            font-size: 28px;
            font-weight: 800;
            margin: 0;
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-text .tagline {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 4px;
        }

        .footer-description {
            color: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            line-height: 1.7;
            margin: 16px 0;
        }

        .footer-newsletter {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 24px;
            margin-top: 16px;
        }

        .footer-newsletter h4 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            color: white;
        }

        .newsletter-form {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .newsletter-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            color: white;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        .newsletter-input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .newsletter-btn {
            width: 46px;
            height: 46px;
            background: linear-gradient(135deg, var(--accent) 0%, #059669 100%);
            border: none;
            border-radius: 10px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .newsletter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .newsletter-note {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
            line-height: 1.5;
        }

        .footer-links-section {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
        }

        .footer-links-column h4 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 20px;
            color: white;
            position: relative;
            padding-bottom: 10px;
        }

        .footer-links-column h4::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 2px;
        }

        .footer-links-column ul {
            list-style: none;
        }

        .footer-links-column li {
            margin-bottom: 12px;
        }

        .footer-links-column a {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-links-column a i {
            font-size: 10px;
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .footer-links-column a:hover {
            color: white;
            transform: translateX(4px);
        }

        .footer-links-column a:hover i {
            opacity: 1;
            color: var(--primary-light);
        }

        .footer-cta-column .cta-card {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.1) 0%, rgba(16, 185, 129, 0.1) 100%);
            border-radius: 20px;
            padding: 32px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }

        .cta-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at top right, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
        }

        .cta-icon {
            width: 56px;
            height: 56px;
            background: white;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .cta-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
        }

        .cta-card h4 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 12px;
            color: white;
        }

        .cta-card p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .btn-footer {
            background: white;
            color: var(--dark);
            font-weight: 600;
            width: 100%;
            justify-content: center;
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 16px;
            position: relative;
            z-index: 10 !important;
            pointer-events: auto !important;
            text-decoration: none;
        }

        .btn-footer:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 255, 255, 0.15);
        }

        .trust-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.05);
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            justify-content: center;
        }

        .trust-badge i {
            color: var(--accent);
        }

        .footer-bottom {
            padding-top: 40px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            z-index: 1;
        }

        .footer-bottom-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .copyright p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
        }

        .footer-social {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .social-label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
        }

        .social-icons {
            display: flex;
            gap: 12px;
        }

        .social-icon {
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 14px;
        }

        .social-icon:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .footer-credits {
            text-align: center;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .footer-credits p {
            color: rgba(255, 255, 255, 0.5);
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        /* Floating animation for decoration items */
        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .hero-grid {
                grid-template-columns: 1fr;
                gap: 48px;
                text-align: center;
            }

            .hero-content {
                max-width: 700px;
                margin: 0 auto;
            }

            .hero h1 {
                font-size: 42px;
                text-align: center;
            }

            .hero p {
                text-align: center;
            }

            .btn-group {
                justify-content: center;
            }

            .footer-main {
                grid-template-columns: 1fr;
                gap: 40px;
            }

            .footer-links-section {
                grid-template-columns: repeat(2, 1fr);
                gap: 40px;
            }
        }

        @media (max-width: 768px) {
            nav {
                position: fixed;
                top: 73px;
                left: 0;
                width: 100%;
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(12px);
                padding: 24px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
                transform: translateY(-150%);
                transition: transform 0.3s ease;
                opacity: 0;
                visibility: hidden;
            }

            nav.active {
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
            }

            nav ul {
                flex-direction: column;
                gap: 20px;
            }

            .mobile-toggle {
                display: block;
            }

            .hero {
                padding: 120px 0 80px;
            }

            .hero h1 {
                font-size: 36px;
            }

            .hero p {
                font-size: 18px;
            }

            .btn-group {
                flex-direction: column;
            }

            .hero-stats {
                gap: 32px;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .modules-grid {
                grid-template-columns: 1fr;
            }

            .footer-links-section {
                grid-template-columns: 1fr;
                gap: 32px;
            }

            .footer-bottom-content {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }

            .footer-social {
                flex-direction: column;
                gap: 12px;
            }

            .footer-newsletter {
                padding: 20px;
            }

            .cta-content h2 {
                font-size: 32px;
            }
        }

        @media (max-width: 480px) {
            .hero h1 {
                font-size: 32px;
            }

            .section-title {
                font-size: 28px;
            }

            .stat-number {
                font-size: 28px;
            }

            .feature-card {
                padding: 32px 24px;
            }

            .trust-section {
                padding: 24px;
                flex-direction: column;
                text-align: center;
            }

            .trust-section-icon {
                margin-bottom: 16px;
            }

            footer {
                padding: 60px 0 20px;
            }

            .cta-card {
                padding: 24px;
            }
        }



        /* Add these CSS fixes for the footer button */
        .cta-card {
            position: relative;
            z-index: 5;
        }

        .cta-card::before {
            pointer-events: none;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header id="header">
        <div class="container header-container">
            <a href="#" class="logo">
                <div class="logo-icon">
                    <img src="logo.jpeg" alt="Majiliz Logo">
                </div>
                <span>Majiliz</span>
            </a>

            <div class="mobile-toggle" id="mobile-toggle">
                <i class="fas fa-bars"></i>
            </div>

            <nav id="nav-menu">
                <ul>
                    <li><a href="#modules">Modules</a></li>
                    <li><a href="index.php" class="btn">Get Started</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-grid">
                <div class="hero-content">
                    <div class="hero-badge">
                        <i class="fas fa-sparkles"></i>
                        <span>Built for Modern Mosques</span>
                    </div>

                    <h1>Manage Your Mosque with <span class="gradient-text">Clarity & Ease</span></h1>
                    <p>A comprehensive platform that simplifies mosque operations—finance, events, donations, inventory,
                        and community engagement. No spreadsheets, no hassle.</p>

                    <div class="btn-group">
                        <a href="index.php" class="btn">
                            <i class="fas fa-rocket"></i>
                            Get Started
                        </a>
                    </div>
                </div>

                <div class="hero-visual">
                    <!-- Quran Quote Container -->
                    <div class="quran-quote">
                        <div class="quote-container">
                            <div class="quote-icon">
                                <i class="fas fa-quote-right"></i>
                            </div>
                            <p class="arabic-text">إِنَّمَا يَعْمُرُ مَسَاجِدَ اللَّهِ</p>
                            <p class="quote-translation">"The mosques of Allah are only to be maintained by those who
                                believe"</p>
                            <p class="quote-reference">— Quran 9:18</p>

                            <div class="quran-decoration">
                                <div class="decoration-item">
                                    <i class="fas fa-star"></i>
                                </div>
                                <div class="decoration-item">
                                    <i class="fas fa-moon"></i>
                                </div>
                                <div class="decoration-item">
                                    <i class="fas fa-book-open"></i>
                                </div>
                            </div>

                            <div class="mini-badges">
                                <div class="mini-badge">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Secure & Reliable</span>
                                </div>
                                <div class="mini-badge">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Easy to Use</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Modules Section -->
    <section class="modules" id="modules">
        <div class="container">
            <div class="section-header">
                <span class="section-badge">Powerful Features</span>
                <h2 class="section-title">Built for Scale & Security</h2>
                <p class="section-subtitle">Enterprise-grade features that grow with your community.</p>
            </div>

            <div class="modules-grid">
                <div class="module-card">
                    <h4><i class="fas fa-money-bill-wave"></i> Finance Management</h4>
                    <p>Complete financial oversight with transparent accounting. Track income, expenses, donations, and
                        generate detailed financial reports.</p>
                </div>

                <div class="module-card">
                    <h4><i class="fas fa-building"></i> Asset Management</h4>
                    <p>Track and manage all mosque assets, maintenance schedules, and inventory. Keep your resources
                        organized and well-maintained.</p>
                </div>

                <div class="module-card">
                    <h4><i class="fas fa-users"></i> Member Management</h4>
                    <p>Manage community members, registrations, profiles, and communication. Build strong connections
                        with your congregation.</p>
                </div>

                <div class="module-card">
                    <h4><i class="fas fa-user-tie"></i> Staff Management</h4>
                    <p>Schedule imams, teachers, and administrative staff. Manage payroll, leave, and performance
                        tracking.</p>
                </div>

                <div class="module-card">
                    <h4><i class="fas fa-graduation-cap"></i> Academics</h4>
                    <p>Manage Quran classes, Islamic studies, student enrollment, attendance, and academic progress
                        tracking.</p>
                </div>

                <div class="module-card">
                    <h4><i class="fas fa-certificate"></i> Certificate Management</h4>
                    <p>Generate, track, and issue certificates for marriage, death birth etc with digital verification.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-main">
                <div class="footer-brand-section">
                    <div class="footer-logo">
                        <div class="logo-icon">
                            <img src="logo.jpeg" alt="Majiliz Logo">
                        </div>
                        <div class="logo-text">
                            <h3>Majiliz</h3>
                            <p class="tagline">Modern Mosque Management</p>
                        </div>
                    </div>
                    <p class="footer-description">Streamlining mosque operations with secure, modern technology. Helping
                        communities thrive with efficient management tools designed for spiritual spaces.</p>
                </div>

                <div class="footer-cta-column">
                    <div class="cta-card">
                        <div class="cta-icon">
                            <img src="logo.jpeg" alt="Majiliz Logo">
                        </div>
                        <h4>Ready to Streamline?</h4>
                        <p>Create your mosque management portal in minutes. Start with core modules, add more as you
                            grow.</p>
                        <a href="index.php" class="btn btn-footer"
                            onclick="window.location.href='index.php'; return false;">
                            <i class="fas fa-rocket"></i>
                            Register Your Mosque
                        </a>
                        <div class="trust-badge">
                            <i class="fas fa-shield-alt"></i>
                            <span>Secure & GDPR Compliant</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="footer-credits">
                <p>&copy; 2025 Majiliz. All rights reserved. Serving communities worldwide</p>
            </div>
        </div>
    </footer>

    <script>
        // Mobile Navigation Toggle
        const mobileToggle = document.getElementById('mobile-toggle');
        const navMenu = document.getElementById('nav-menu');

        mobileToggle.addEventListener('click', () => {
            navMenu.classList.toggle('active');
            mobileToggle.innerHTML = navMenu.classList.contains('active')
                ? '<i class="fas fa-times"></i>'
                : '<i class="fas fa-bars"></i>';
        });

        // Close mobile menu when clicking a link
        document.querySelectorAll('#nav-menu a').forEach(link => {
            link.addEventListener('click', () => {
                navMenu.classList.remove('active');
                mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
            });
        });

        // Header scroll effect
        const header = document.getElementById('header');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 100) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();

                const targetId = this.getAttribute('href');
                if (targetId === '#') return;

                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    window.scrollTo({
                        top: targetElement.offsetTop - 80,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Add floating animation to decoration items
        document.addEventListener('DOMContentLoaded', function () {
            const decorationItems = document.querySelectorAll('.decoration-item');
            decorationItems.forEach((item, index) => {
                item.style.animation = `float 3s ease-in-out ${index * 0.5}s infinite`;
            });

            // Fix for footer button click
            const footerBtn = document.querySelector('.btn-footer');
            if (footerBtn) {
                footerBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.location.href = 'index.php';
                });
            }
        });
    </script>
</body>

</html>