
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101b35">
    <meta name="description" content="Attendly adalah platform manajemen absensi dan kehadiran karyawan modern dengan GPS, RFID, laporan, dan pengelolaan jadwal kerja.">
    <title>Attendly - Smart Attendance Management System</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #4268f5;
            --primary-dark: #3152dc;
            --dark: #101b35;
            --text: #26334d;
            --muted: #78849b;
            --light: #f6f8fc;
            --border: #e9edf5;
            --green: #19ad78;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 90px;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background: #fff;
            font-size: 14px;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
        }

        .container {
            max-width: 1160px;
        }

        /* NAVBAR */

        .navbar {
            background: rgba(255,255,255,.94);
            backdrop-filter: blur(15px);
            border-bottom: 1px solid rgba(230,235,245,.8);
            padding: 15px 0;
            transition: .3s;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--dark);
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -1px;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            background: var(--primary);
            color: white;
            font-size: 17px;
            box-shadow: 0 5px 15px rgba(66,104,245,.25);
        }

        .navbar-nav {
            gap: 7px;
        }

        .navbar-nav .nav-link {
            color: #65718a;
            font-size: 12px;
            font-weight: 600;
            padding: 10px 13px !important;
            transition: .2s;
        }

        .navbar-nav .nav-link:hover {
            color: var(--primary);
        }

        .btn {
            font-size: 12px;
            font-weight: 700;
            border-radius: 9px;
            padding: 12px 20px;
            transition: .25s;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            box-shadow: 0 7px 18px rgba(66,104,245,.2);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-outline-dark {
            border-color: #dce2ed;
            color: var(--dark);
        }

        .btn-outline-dark:hover {
            background: var(--dark);
            border-color: var(--dark);
        }

        /* HERO */

        .hero {
            position: relative;
            overflow: hidden;
            padding: 115px 0 105px;
            background:
                radial-gradient(circle at 80% 30%, rgba(88,120,255,.12), transparent 30%),
                radial-gradient(circle at 10% 80%, rgba(31,174,139,.07), transparent 25%),
                #fbfcff;
        }

        .hero::before {
            content: "";
            position: absolute;
            width: 450px;
            height: 450px;
            right: -220px;
            top: -170px;
            border: 1px solid rgba(66,104,245,.09);
            border-radius: 50%;
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 320px;
            height: 320px;
            right: -155px;
            top: -105px;
            border: 1px solid rgba(66,104,245,.09);
            border-radius: 50%;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 13px;
            background: #edf2ff;
            color: var(--primary);
            border: 1px solid #dce5ff;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .3px;
            margin-bottom: 24px;
        }

        .hero-badge i {
            font-size: 11px;
        }

        .hero h1 {
            color: var(--dark);
            font-size: clamp(38px, 5vw, 59px);
            line-height: 1.13;
            letter-spacing: -2.8px;
            font-weight: 900;
            margin-bottom: 22px;
        }

        .hero h1 span {
            color: var(--primary);
        }

        .hero-description {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.9;
            max-width: 490px;
            margin-bottom: 29px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .hero-actions .btn {
            padding: 14px 23px;
        }

        .hero-note {
            display: flex;
            align-items: center;
            gap: 17px;
            margin-top: 30px;
            color: var(--muted);
            font-size: 10px;
        }

        .hero-note span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .hero-note i {
            color: var(--green);
        }

        /* DASHBOARD PREVIEW */

        .dashboard-scene {
            position: relative;
            z-index: 2;
            padding: 15px 0 15px 25px;
        }

        .dashboard-window {
            position: relative;
            background: white;
            border: 1px solid #e9edf5;
            border-radius: 17px;
            padding: 17px;
            box-shadow: 0 30px 75px rgba(30,51,100,.14);
            transform: perspective(1000px) rotateY(-5deg) rotateX(2deg);
        }

        .window-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            padding-bottom: 13px;
            margin-bottom: 15px;
        }

        .window-dots {
            display: flex;
            gap: 5px;
        }

        .window-dots span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #dce2ed;
        }

        .window-title {
            font-size: 10px;
            font-weight: 800;
            color: var(--dark);
        }

        .window-profile {
            width: 23px;
            height: 23px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #edf2ff;
            color: var(--primary);
            font-size: 9px;
        }

        .preview-heading {
            font-size: 13px;
            font-weight: 800;
            color: var(--dark);
        }

        .preview-subheading {
            color: var(--muted);
            font-size: 8px;
            margin-top: 4px;
        }

        .preview-stat {
            padding: 12px 10px;
            border: 1px solid var(--border);
            border-radius: 9px;
            height: 100%;
        }

        .preview-stat-label {
            font-size: 8px;
            color: var(--muted);
        }

        .preview-stat-number {
            font-size: 20px;
            font-weight: 900;
            color: var(--dark);
            margin-top: 6px;
        }

        .preview-stat-icon {
            width: 24px;
            height: 24px;
            border-radius: 7px;
            display: grid;
            place-items: center;
            font-size: 10px;
            margin-bottom: 9px;
        }

        .preview-table {
            margin-top: 17px;
            border: 1px solid var(--border);
            border-radius: 9px;
            overflow: hidden;
        }

        .preview-table-head {
            background: #f8f9fc;
            padding: 10px;
            color: var(--muted);
            font-size: 8px;
            font-weight: 800;
        }

        .preview-table-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px;
            border-top: 1px solid #f0f2f6;
            font-size: 8px;
        }

        .preview-person {
            display: flex;
            align-items: center;
            gap: 7px;
            font-weight: 700;
            color: var(--dark);
        }

        .preview-avatar {
            width: 23px;
            height: 23px;
            display: grid;
            place-items: center;
            border-radius: 7px;
            background: #edf2ff;
            color: var(--primary);
            font-size: 8px;
        }

        .preview-status {
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 7px;
            font-weight: 800;
        }

        .preview-status.green {
            background: #e8f8ef;
            color: #159653;
        }

        .preview-status.yellow {
            background: #fff4df;
            color: #c58518;
        }

        .floating-card {
            position: absolute;
            z-index: 3;
            background: white;
            padding: 12px 15px;
            border: 1px solid #edf0f5;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(30,51,100,.13);
        }

        .floating-attendance {
            left: -8px;
            bottom: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .floating-icon {
            width: 35px;
            height: 35px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: #e8f8ef;
            color: var(--green);
        }

        .floating-title {
            font-size: 9px;
            font-weight: 800;
            color: var(--dark);
        }

        .floating-subtitle {
            font-size: 8px;
            color: var(--muted);
            margin-top: 3px;
        }

        .floating-gps {
            top: 25px;
            right: -12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* TRUST STRIP */

        .trust-strip {
            background: white;
            border-bottom: 1px solid var(--border);
            padding: 26px 0;
        }

        .trust-text {
            color: #9aa4b5;
            font-size: 10px;
            font-weight: 700;
            text-align: center;
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        .trust-item {
            color: #9aa4b5;
            font-size: 13px;
            font-weight: 800;
            text-align: center;
            letter-spacing: -.3px;
        }

        .trust-item i {
            margin-right: 5px;
        }

        /* SECTION GENERAL */

        .section {
            padding: 95px 0;
        }

        .section-light {
            background: var(--light);
        }

        .section-tag {
            display: inline-block;
            color: var(--primary);
            background: #edf2ff;
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .8px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .section-heading {
            color: var(--dark);
            font-size: clamp(27px, 3vw, 38px);
            font-weight: 900;
            letter-spacing: -1.6px;
            line-height: 1.25;
        }

        .section-description {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.9;
            max-width: 570px;
            margin: 13px auto 0;
        }

        /* FEATURES */

        .feature-card {
            height: 100%;
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 25px;
            transition: .3s;
        }

        .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 40px rgba(30,51,100,.08);
            border-color: #d9e2ff;
        }

        .feature-icon {
            width: 47px;
            height: 47px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            font-size: 17px;
            margin-bottom: 19px;
        }

        .feature-card h4 {
            color: var(--dark);
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .feature-card p {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.8;
            margin: 0;
        }

        .feature-blue { background: #edf2ff; color: #4268f5; }
        .feature-green { background: #e8f8ef; color: #19ad78; }
        .feature-orange { background: #fff4e5; color: #e99a29; }
        .feature-purple { background: #f2eaff; color: #9463d4; }
        .feature-red { background: #ffecec; color: #e35b65; }
        .feature-cyan { background: #e7f8fa; color: #159aa5; }

        /* HOW IT WORKS */

        .step-card {
            text-align: center;
            padding: 15px 20px;
        }

        .step-number {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            margin: 0 auto 18px;
            background: var(--primary);
            color: white;
            font-size: 17px;
            font-weight: 900;
            box-shadow: 0 8px 20px rgba(66,104,245,.22);
        }

        .step-card h4 {
            color: var(--dark);
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 9px;
        }

        .step-card p {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.8;
        }

        /* HIGHLIGHT */

        .highlight-box {
            background: var(--dark);
            color: white;
            border-radius: 20px;
            padding: 48px;
            position: relative;
            overflow: hidden;
        }

        .highlight-box::after {
            content: "";
            position: absolute;
            width: 300px;
            height: 300px;
            border: 1px solid rgba(255,255,255,.09);
            border-radius: 50%;
            right: -80px;
            top: -100px;
        }

        .highlight-box h3 {
            font-size: 30px;
            font-weight: 900;
            letter-spacing: -1px;
            line-height: 1.3;
        }

        .highlight-box p {
            color: #aebbd3;
            font-size: 12px;
            line-height: 1.9;
        }

        .highlight-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 25px;
        }

        .highlight-list span {
            color: #e1e8f6;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .highlight-list i {
            color: #60d6a8;
        }

        .highlight-visual {
            position: relative;
            z-index: 2;
            background: #1c2b4c;
            border: 1px solid #35466b;
            border-radius: 15px;
            padding: 20px;
        }

        .highlight-visual-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 13px 0;
            border-bottom: 1px solid rgba(255,255,255,.09);
        }

        .highlight-visual-item:last-child {
            border-bottom: 0;
        }

        .highlight-visual-label {
            color: #aebbd3;
            font-size: 10px;
        }

        .highlight-visual-value {
            color: white;
            font-size: 12px;
            font-weight: 800;
        }

        /* CTA */

        .cta-section {
            padding: 85px 0;
            background: linear-gradient(120deg, #3152dc, #5277ff);
            color: white;
            text-align: center;
        }

        .cta-section h2 {
            font-size: clamp(27px, 4vw, 40px);
            font-weight: 900;
            letter-spacing: -1.5px;
        }

        .cta-section p {
            color: #e1e8ff;
            font-size: 12px;
            line-height: 1.9;
            max-width: 550px;
            margin: 15px auto 25px;
        }

        .cta-section .btn-light {
            color: var(--primary);
            padding: 14px 24px;
        }

        .cta-section .btn-outline-light {
            padding: 14px 24px;
        }

        /* FOOTER */

        footer {
            background: #0c162c;
            color: white;
            padding: 65px 0 25px;
        }

        .footer-brand {
            color: white;
            font-size: 21px;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 17px;
        }

        .footer-description {
            color: #8998b3;
            font-size: 11px;
            line-height: 1.9;
            max-width: 280px;
        }

        .footer-title {
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .footer-links {
            display: flex;
            flex-direction: column;
            gap: 13px;
        }

        .footer-links a {
            color: #8998b3;
            font-size: 10px;
            transition: .2s;
        }

        .footer-links a:hover {
            color: white;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,.09);
            margin-top: 45px;
            padding-top: 22px;
            display: flex;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            color: #75849e;
            font-size: 10px;
        }

        .social-links {
            display: flex;
            gap: 9px;
        }

        .social-links a {
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            color: #aebbd3;
            background: #1a2946;
            transition: .2s;
        }

        .social-links a:hover {
            background: var(--primary);
            color: white;
        }

        /* ANIMATION */

        .reveal {
            opacity: 0;
            transform: translateY(25px);
            transition: opacity .7s ease, transform .7s ease;
        }

        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 991px) {
            .hero {
                padding: 85px 0 75px;
            }

            .hero h1 {
                font-size: 45px;
            }

            .dashboard-scene {
                max-width: 570px;
                margin: 50px auto 0;
                padding-left: 15px;
            }

            .navbar-collapse {
                background: white;
                padding: 15px;
                border-radius: 12px;
                margin-top: 12px;
                border: 1px solid var(--border);
            }

            .navbar-actions {
                padding-top: 12px;
            }

            .highlight-box {
                padding: 32px;
            }
        }

        @media (max-width: 575px) {
            .hero {
                padding: 70px 0 60px;
            }

            .hero h1 {
                font-size: 39px;
                letter-spacing: -1.8px;
            }

            .hero-description {
                font-size: 12px;
            }

            .hero-note {
                gap: 10px;
                flex-wrap: wrap;
            }

            .dashboard-window {
                transform: none;
                padding: 12px;
            }

            .floating-gps {
                right: -4px;
                top: -25px;
            }

            .floating-attendance {
                left: -5px;
                bottom: -30px;
            }

            .section {
                padding: 70px 0;
            }

            .highlight-box {
                padding: 25px;
            }

            .highlight-box h3 {
                font-size: 25px;
            }

            .highlight-list {
                grid-template-columns: 1fr;
            }

            .trust-item {
                font-size: 11px;
            }

            .footer-bottom {
                flex-direction: column;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body>

<!-- ================= NAVBAR ================= -->

<nav class="navbar navbar-expand-lg fixed-top">
    <div class="container">

        <a class="navbar-brand" href="#home">
            <span class="brand-icon">
                <i class="fa-solid fa-fingerprint"></i>
            </span>
            Attendly
        </a>

        <button class="navbar-toggler border-0 shadow-none" type="button"
                data-bs-toggle="collapse" data-bs-target="#mainNavbar"
                aria-controls="mainNavbar" aria-expanded="false" aria-label="Buka navigasi">
            <i class="fa-solid fa-bars fs-5"></i>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">

            <ul class="navbar-nav mx-auto">
                <li class="nav-item">
                    <a class="nav-link" href="#home">Beranda</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#fitur">Fitur</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#cara-kerja">Cara Kerja</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#keunggulan">Keunggulan</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#kontak">Kontak</a>
                </li>
            </ul>

            <div class="navbar-actions d-flex gap-2">
                <a href="login.php" class="btn btn-outline-dark">Masuk</a>
                <a href="#kontak" class="btn btn-primary">Hubungi Kami <i class="fa-solid fa-arrow-right ms-1"></i></a>
            </div>

        </div>
    </div>
</nav>

<!-- ================= HERO ================= -->

<section class="hero" id="home">
    <div class="container">
        <div class="row align-items-center">

            <div class="col-lg-6 hero-content">
                <div class="hero-badge">
                    <i class="fa-solid fa-sparkles"></i>
                    SMART ATTENDANCE MANAGEMENT SYSTEM
                </div>

                <h1>
                    Kelola Kehadiran Karyawan Jadi <span>Lebih Mudah.</span>
                </h1>

                <p class="hero-description">
                    Solusi manajemen absensi modern untuk membantu perusahaan
                    mengelola kehadiran, jadwal kerja, perizinan, dan laporan
                    karyawan dalam satu platform terintegrasi.
                </p>

                <div class="hero-actions">
                    <a href="#fitur" class="btn btn-primary">
                        Jelajahi Fitur <i class="fa-solid fa-arrow-right ms-2"></i>
                    </a>

                    <a href="#cara-kerja" class="btn btn-outline-dark">
                        <i class="fa-solid fa-circle-play me-2"></i> Pelajari Sistem
                    </a>
                </div>

                <div class="hero-note">
                    <span><i class="fa-solid fa-circle-check"></i> Akses berbasis web</span>
                    <span><i class="fa-solid fa-circle-check"></i> Multi metode absensi</span>
                    <span><i class="fa-solid fa-circle-check"></i> Laporan terpusat</span>
                </div>
            </div>

            <!-- PREVIEW DASHBOARD -->
            <div class="col-lg-6">
                <div class="dashboard-scene">

                    <div class="dashboard-window">

                        <div class="window-header">
                            <div class="window-dots">
                                <span></span><span></span><span></span>
                            </div>

                            <div class="window-title">ATTENDLY DASHBOARD</div>

                            <div class="window-profile">
                                <i class="fa-solid fa-user"></i>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="preview-heading">Dashboard Overview</div>
                                <div class="preview-subheading">Ringkasan aktivitas kehadiran</div>
                            </div>

                            <span class="badge text-bg-primary" style="font-size:8px;">
                                Live Preview
                            </span>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <div class="preview-stat">
                                    <div class="preview-stat-icon" style="background:#edf2ff;color:#4268f5;">
                                        <i class="fa-solid fa-users"></i>
                                    </div>
                                    <div class="preview-stat-label">Total Karyawan</div>
                                    <div class="preview-stat-number">248</div>
                                </div>
                            </div>

                            <div class="col-6">
                                <div class="preview-stat">
                                    <div class="preview-stat-icon" style="background:#e8f8ef;color:#19ad78;">
                                        <i class="fa-solid fa-user-check"></i>
                                    </div>
                                    <div class="preview-stat-label">Hadir Hari Ini</div>
                                    <div class="preview-stat-number">231</div>
                                </div>
                            </div>

                            <div class="col-6">
                                <div class="preview-stat">
                                    <div class="preview-stat-icon" style="background:#fff4e5;color:#e99a29;">
                                        <i class="fa-solid fa-clock"></i>
                                    </div>
                                    <div class="preview-stat-label">Terlambat</div>
                                    <div class="preview-stat-number">12</div>
                                </div>
                            </div>

                            <div class="col-6">
                                <div class="preview-stat">
                                    <div class="preview-stat-icon" style="background:#f2eaff;color:#9463d4;">
                                        <i class="fa-solid fa-envelope"></i>
                                    </div>
                                    <div class="preview-stat-label">Izin / Cuti</div>
                                    <div class="preview-stat-number">5</div>
                                </div>
                            </div>
                        </div>

                        <div class="preview-table">
                            <div class="preview-table-head d-flex justify-content-between">
                                <span>AKTIVITAS KARYAWAN</span>
                                <span>HARI INI</span>
                            </div>

                            <div class="preview-table-row">
                                <div class="preview-person">
                                    <div class="preview-avatar">AS</div>
                                    Andi Saputra
                                </div>
                                <span>07:55</span>
                                <span class="preview-status green">Hadir</span>
                            </div>

                            <div class="preview-table-row">
                                <div class="preview-person">
                                    <div class="preview-avatar">SR</div>
                                    Siti Rahmawati
                                </div>
                                <span>08:03</span>
                                <span class="preview-status yellow">Terlambat</span>
                            </div>

                            <div class="preview-table-row">
                                <div class="preview-person">
                                    <div class="preview-avatar">BS</div>
                                    Budi Santoso
                                </div>
                                <span>07:48</span>
                                <span class="preview-status green">Hadir</span>
                            </div>
                        </div>

                        <div class="text-end mt-2" style="font-size:7px;color:#9aa4b5;">
                            Ilustrasi dashboard Attendly
                        </div>

                    </div>

                    <div class="floating-card floating-attendance">
                        <div class="floating-icon">
                            <i class="fa-solid fa-user-check"></i>
                        </div>
                        <div>
                            <div class="floating-title">Absensi Berhasil</div>
                            <div class="floating-subtitle">Data tercatat pada sistem</div>
                        </div>
                    </div>

                    <div class="floating-card floating-gps">
                        <i class="fa-solid fa-location-dot" style="color:#19ad78;"></i>
                        <div>
                            <div class="floating-title">GPS Location</div>
                            <div class="floating-subtitle">Ilustrasi validasi lokasi</div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</section>

<!-- ================= TRUST STRIP ================= -->

<section class="trust-strip">
    <div class="container">
        <div class="trust-text mb-4">Satu platform untuk berbagai kebutuhan manajemen kehadiran</div>

        <div class="row g-3 align-items-center">
            <div class="col-6 col-md-3">
                <div class="trust-item"><i class="fa-solid fa-building"></i> Perusahaan</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="trust-item"><i class="fa-solid fa-store"></i> Retail & Bisnis</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="trust-item"><i class="fa-solid fa-hospital"></i> Institusi</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="trust-item"><i class="fa-solid fa-graduation-cap"></i> Pendidikan</div>
            </div>
        </div>
    </div>
</section>

<!-- ================= FITUR ================= -->

<section class="section" id="fitur">
    <div class="container">

        <div class="text-center mb-5 reveal">
            <span class="section-tag">Fitur Attendly</span>
            <h2 class="section-heading">Semua Kebutuhan Absensi<br>Dalam Satu Platform</h2>
            <p class="section-description">
                Kelola seluruh aktivitas kehadiran karyawan dengan sistem
                yang terstruktur, fleksibel, dan mudah digunakan.
            </p>
        </div>

        <div class="row g-3">

            <div class="col-md-6 col-lg-4 reveal">
                <div class="feature-card">
                    <div class="feature-icon feature-blue">
                        <i class="fa-solid fa-fingerprint"></i>
                    </div>
                    <h4>Absensi Digital</h4>
                    <p>Pencatatan check-in dan check-out secara digital untuk memudahkan pengelolaan kehadiran karyawan.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 reveal">
                <div class="feature-card">
                    <div class="feature-icon feature-green">
                        <i class="fa-solid fa-location-dot"></i>
                    </div>
                    <h4>Validasi GPS</h4>
                    <p>Atur lokasi kerja dan radius absensi untuk mendukung validasi lokasi karyawan.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 reveal">
                <div class="feature-card">
                    <div class="feature-icon feature-orange">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                    <h4>Integrasi RFID</h4>
                    <p>Dukung penggunaan kartu RFID sebagai salah satu metode identifikasi dan pencatatan kehadiran.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 reveal">
                <div class="feature-card">
                    <div class="feature-icon feature-purple">
                        <i class="fa-solid fa-calendar-days"></i>
                    </div>
                    <h4>Manajemen Jadwal</h4>
                    <p>Kelola shift, jam kerja, hari kerja, dan jadwal karyawan secara lebih terorganisir.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 reveal">
                <div class="feature-card">
                    <div class="feature-icon feature-red">
                        <i class="fa-solid fa-envelope-open-text"></i>
                    </div>
                    <h4>Izin & Cuti</h4>
                    <p>Kelola pengajuan izin, sakit, dan cuti melalui proses administrasi yang terpusat.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 reveal">
                <div class="feature-card">
                    <div class="feature-icon feature-cyan">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <h4>Laporan & Analitik</h4>
                    <p>Pantau ringkasan kehadiran, keterlambatan, dan rekapitulasi absensi melalui laporan.</p>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ================= CARA KERJA ================= -->

<section class="section section-light" id="cara-kerja">
    <div class="container">

        <div class="text-center mb-5 reveal">
            <span class="section-tag">Cara Kerja</span>
            <h2 class="section-heading">Sederhana, Cepat, dan Terorganisir</h2>
            <p class="section-description">
                Alur penggunaan Attendly dirancang agar proses pencatatan
                kehadiran lebih mudah bagi karyawan maupun administrator.
            </p>
        </div>

        <div class="row g-4">

            <div class="col-md-4 reveal">
                <div class="step-card">
                    <div class="step-number">01</div>
                    <h4>Data Karyawan</h4>
                    <p>Administrator mendaftarkan karyawan, departemen, jabatan, serta jadwal kerja.</p>
                </div>
            </div>

            <div class="col-md-4 reveal">
                <div class="step-card">
                    <div class="step-number">02</div>
                    <h4>Proses Absensi</h4>
                    <p>Karyawan melakukan absensi menggunakan metode yang disediakan perusahaan.</p>
                </div>
            </div>

            <div class="col-md-4 reveal">
                <div class="step-card">
                    <div class="step-number">03</div>
                    <h4>Laporan Kehadiran</h4>
                    <p>Administrator memantau aktivitas dan mengelola laporan kehadiran secara terpusat.</p>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ================= KEUNGGULAN ================= -->

<section class="section" id="keunggulan">
    <div class="container">

        <div class="highlight-box reveal">
            <div class="row align-items-center g-5">

                <div class="col-lg-6">
                    <span class="section-tag" style="background:#293d67;color:#a9bbff;">
                        Kenapa Attendly?
                    </span>

                    <h3>Kontrol Kehadiran Lebih Baik, Administrasi Lebih Efisien.</h3>

                    <p class="mt-3">
                        Attendly dirancang sebagai pusat pengelolaan kehadiran
                        yang membantu perusahaan menyederhanakan pekerjaan
                        administratif dan mendapatkan gambaran aktivitas karyawan.
                    </p>

                    <div class="highlight-list">
                        <span><i class="fa-solid fa-circle-check"></i> Pengelolaan terpusat</span>
                        <span><i class="fa-solid fa-circle-check"></i> Akses berbasis web</span>
                        <span><i class="fa-solid fa-circle-check"></i> Multi metode absensi</span>
                        <span><i class="fa-solid fa-circle-check"></i> Laporan terstruktur</span>
                        <span><i class="fa-solid fa-circle-check"></i> Pengaturan fleksibel</span>
                        <span><i class="fa-solid fa-circle-check"></i> Tampilan responsif</span>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="highlight-visual">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="text-white fw-bold" style="font-size:12px;">
                                <i class="fa-solid fa-chart-pie me-2" style="color:#7e9cff;"></i>
                                Attendance Overview
                            </div>
                            <span class="badge" style="background:#284a79;color:#c7d8ff;font-size:8px;">
                                Dashboard
                            </span>
                        </div>

                        <div class="highlight-visual-item">
                            <div class="highlight-visual-label">Manajemen Karyawan</div>
                            <div class="highlight-visual-value">Terpusat</div>
                        </div>

                        <div class="highlight-visual-item">
                            <div class="highlight-visual-label">Pencatatan Kehadiran</div>
                            <div class="highlight-visual-value">Digital</div>
                        </div>

                        <div class="highlight-visual-item">
                            <div class="highlight-visual-label">Pengelolaan Jadwal</div>
                            <div class="highlight-visual-value">Fleksibel</div>
                        </div>

                        <div class="highlight-visual-item">
                            <div class="highlight-visual-label">Laporan</div>
                            <div class="highlight-visual-value">Terstruktur</div>
                        </div>

                        <div class="highlight-visual-item">
                            <div class="highlight-visual-label">Integrasi</div>
                            <div class="highlight-visual-value">Dapat Dikembangkan</div>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</section>

<!-- ================= CTA ================= -->

<section class="cta-section" id="kontak">
    <div class="container reveal">

        <span class="badge rounded-pill mb-3 px-3 py-2"
              style="background:rgba(255,255,255,.15);font-size:9px;">
            START WITH ATTENDLY
        </span>

        <h2>Siap Mengelola Kehadiran<br>Dengan Cara yang Lebih Modern?</h2>

        <p>
            Mulai bangun sistem pengelolaan absensi yang lebih terstruktur
            untuk mendukung operasional perusahaan Anda.
        </p>

        <div class="d-flex justify-content-center flex-wrap gap-2">
            <a href="login.html" class="btn btn-light">
                Masuk ke Sistem <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>

            <a href="mailto:info@attendly.com" class="btn btn-outline-light">
                <i class="fa-solid fa-envelope me-2"></i> Hubungi Kami
            </a>
        </div>

    </div>
</section>

<!-- ================= FOOTER ================= -->

<footer>
    <div class="container">

        <div class="row g-4">

            <div class="col-lg-4 col-md-6">
                <div class="footer-brand">
                    <span class="brand-icon">
                        <i class="fa-solid fa-fingerprint"></i>
                    </span>
                    Attendly
                </div>

                <p class="footer-description">
                    Smart Attendance Management System.
                    Solusi digital untuk membantu perusahaan
                    mengelola kehadiran dan aktivitas karyawan.
                </p>

                <div class="social-links mt-3">
                    <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
                    <a href="#" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                </div>
            </div>

            <div class="col-lg-2 col-md-6">
                <div class="footer-title">Navigasi</div>
                <div class="footer-links">
                    <a href="#home">Beranda</a>
                    <a href="#fitur">Fitur</a>
                    <a href="#cara-kerja">Cara Kerja</a>
                    <a href="#keunggulan">Keunggulan</a>
                    <a href="#kontak">Kontak</a>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="footer-title">Fitur Sistem</div>
                <div class="footer-links">
                    <a href="#fitur">Absensi Digital</a>
                    <a href="#fitur">Validasi GPS</a>
                    <a href="#fitur">Integrasi RFID</a>
                    <a href="#fitur">Manajemen Jadwal</a>
                    <a href="#fitur">Laporan Kehadiran</a>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="footer-title">Informasi</div>
                <div class="footer-links">
                    <a href="mailto:info@attendly.com">info@attendly.com</a>
                    <a href="mailto:support@attendly.com">Support</a>
                    <a href="login.html">Login Administrator</a>
                </div>
            </div>

        </div>

        <div class="footer-bottom">
            <div>&copy; <span id="year"></span> Attendly. All rights reserved.</div>
            <div>Smart Attendance Management System</div>
        </div>

    </div>
</footer>

<!-- ================= JAVASCRIPT ================= -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Tahun otomatis
    document.getElementById('year').textContent = new Date().getFullYear();

    // Navbar scroll effect
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.navbar');

        if (window.scrollY > 30) {
            navbar.style.boxShadow = '0 5px 25px rgba(20,40,80,.07)';
        } else {
            navbar.style.boxShadow = 'none';
        }
    });

    // Animasi saat section terlihat
    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.12
    });

    document.querySelectorAll('.reveal').forEach(element => {
        revealObserver.observe(element);
    });

    // Tutup navbar mobile setelah klik menu
    document.querySelectorAll('.navbar-nav .nav-link').forEach(link => {
        link.addEventListener('click', () => {
            const navbar = document.getElementById('mainNavbar');
            const instance = bootstrap.Collapse.getInstance(navbar);

            if (instance) {
                instance.hide();
            }
        });
    });
</script>

</body>
</html>
