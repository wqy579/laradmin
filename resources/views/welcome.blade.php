<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="LarAdmin - 基于 Laravel + Laravel-S + Swoole + Vue3 的高性能后台管理系统">
    <title>LarAdmin - 高性能后台管理系统</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --dark-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --accent-color: #667eea;
            --text-primary: #333;
            --text-secondary: #666;
            --light-bg: #f8f9fa;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'PingFang SC', 'Microsoft YaHei', sans-serif;
            line-height: 1.6;
            color: var(--text-primary);
            background: var(--primary-gradient);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header */
        header {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 15px 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: all 0.3s ease;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        header.scrolled {
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 0;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
        }

        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff 0%, #f0f0f0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .logo span {
            font-size: 32px;
        }

        header.scrolled .logo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-links {
            display: flex;
            gap: 40px;
            list-style: none;
        }

        .nav-links a {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 500;
            font-size: 16px;
            transition: all 0.3s ease;
            position: relative;
            padding: 5px 0;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s ease;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        header.scrolled .nav-links a {
            color: var(--text-primary);
        }

        .nav-links a:hover {
            transform: translateY(-2px);
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
            padding: 5px;
        }

        .mobile-menu-toggle span {
            width: 25px;
            height: 3px;
            background: white;
            border-radius: 3px;
            transition: all 0.3s ease;
        }

        header.scrolled .mobile-menu-toggle span {
            background: var(--accent-color);
        }

        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 120px 0 80px;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(102, 126, 234, 0.1) 0%, transparent 70%);
            animation: float 20s ease-in-out infinite;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(118, 75, 162, 0.1) 0%, transparent 70%);
            animation: float 15s ease-in-out infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(30px, 30px) rotate(180deg); }
        }

        .hero-content {
            text-align: center;
            color: white;
            position: relative;
            z-index: 1;
        }

        .hero-badge {
            display: inline-block;
            padding: 8px 24px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeInDown 0.8s ease-out;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero h1 {
            font-size: 64px;
            font-weight: 800;
            margin-bottom: 20px;
            line-height: 1.2;
            text-shadow: 0 4px 30px rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.8s ease-out 0.2s both;
        }

        .hero h1 .gradient-text {
            background: linear-gradient(135deg, #fff 0%, #f0f0f0 50%, #e0e0e0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero p {
            font-size: 20px;
            margin-bottom: 40px;
            opacity: 0.95;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
            animation: fadeInUp 0.8s ease-out 0.4s both;
            font-weight: 300;
        }

        .hero-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeInUp 0.8s ease-out 0.6s both;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 16px 40px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 50px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            min-width: 180px;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary {
            background: white;
            color: var(--accent-color);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
            transform: translateY(-3px);
        }

        .stats-section {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            margin-top: 80px;
            animation: fadeInUp 0.8s ease-out 0.8s both;
        }

        .stat-item {
            text-align: center;
            padding: 30px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .stat-number {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #fff 0%, #f0f0f0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Features Section */
        .features {
            padding: 120px 0;
            background: white;
            position: relative;
        }

        .section-header {
            text-align: center;
            margin-bottom: 80px;
        }

        .section-tag {
            display: inline-block;
            padding: 8px 20px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            color: var(--accent-color);
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .section-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
        }

        .feature-card {
            padding: 40px;
            background: white;
            border-radius: 20px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid transparent;
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
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            border-color: rgba(102, 126, 234, 0.2);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-icon-wrapper {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            margin-left: auto;
            margin-right: auto;
            position: relative;
            z-index: 1;
        }

        .feature-icon {
            font-size: 32px;
            color: white;
        }

        .feature-card h3 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--text-primary);
            position: relative;
            z-index: 1;
            text-align: center;
        }

        .feature-card p {
            color: var(--text-secondary);
            line-height: 1.7;
            position: relative;
            z-index: 1;
        }

        /* Tech Stack Section */
        .tech-stack {
            padding: 120px 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            position: relative;
            overflow: hidden;
        }

        .tech-stack::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 20% 50%, rgba(102, 126, 234, 0.1) 0%, transparent 50%);
        }

        .tech-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            position: relative;
            z-index: 1;
        }

        .tech-item {
            text-align: center;
            padding: 35px;
            background: white;
            border-radius: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        }

        .tech-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .tech-item:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
        }

        .tech-item:hover::before {
            transform: scaleX(1);
        }

        .tech-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            transition: transform 0.3s ease;
        }

        .tech-item:hover .tech-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .tech-item h4 {
            color: var(--text-primary);
            margin-top: 15px;
            font-size: 18px;
            font-weight: 600;
        }

        .tech-version {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 400;
            line-height: 1.5;
            margin-top: 8px;
        }

        /* Architecture Section */
        .architecture {
            padding: 120px 0;
            background: white;
        }

        .architecture-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: start;
        }

        .architecture-text h3 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .architecture-text p {
            margin-bottom: 20px;
            color: var(--text-secondary);
            font-size: 16px;
            line-height: 1.8;
        }

        .architecture-list {
            list-style: none;
            margin-top: 30px;
        }

        .architecture-list li {
            padding: 15px 0;
            padding-left: 40px;
            position: relative;
            color: var(--text-primary);
            font-size: 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .architecture-list li:last-child {
            border-bottom: none;
        }

        .architecture-list li::before {
            content: '✓';
            position: absolute;
            left: 0;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            top: 50%;
            transform: translateY(-50%);
        }

        .code-block {
            background: #1e1e1e;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }

        .code-header {
            background: #2d2d2d;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #3d3d3d;
        }

        .code-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .code-dot.red { background: #ff5f56; }
        .code-dot.yellow { background: #ffbd2e; }
        .code-dot.green { background: #27c93f; }

        .code-title {
            color: #999;
            font-size: 14px;
            margin-left: 10px;
        }

        .code-content {
            padding: 25px;
            overflow-x: auto;
        }

        .code-content pre {
            margin: 0;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            color: #d4d4d4;
        }

        .code-content code {
            color: inherit;
        }

        .code-content .comment { color: #6a9955; }
        .code-content .command { color: #4ec9b0; }
        .code-content .string { color: #ce9178; }

        /* CTA Section */
        .cta-section {
            padding: 100px 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            text-align: center;
            color: white;
        }

        .cta-content h2 {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .cta-content p {
            font-size: 18px;
            margin-bottom: 40px;
            opacity: 0.95;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .cta-content .btn {
            background: white;
            color: var(--accent-color);
            padding: 18px 50px;
            font-size: 18px;
        }

        /* Footer */
        footer {
            padding: 60px 0 30px;
            background: #1a1a2e;
            color: rgba(255, 255, 255, 0.8);
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-section h4 {
            color: white;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .footer-section p {
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 15px;
            line-height: 1.8;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 12px;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: white;
        }

        .footer-bottom {
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fadeInUp {
            animation: fadeInUp 0.8s ease-out;
        }

        /* Scroll indicator */
        .scroll-indicator {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            color: white;
            font-size: 24px;
            animation: bounce 2s infinite;
            cursor: pointer;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateX(-50%) translateY(0); }
            40% { transform: translateX(-50%) translateY(-20px); }
            60% { transform: translateX(-50%) translateY(-10px); }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .hero h1 {
                font-size: 52px;
            }

            .features-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 15px;
            }

            .logo {
                font-size: 22px;
            }

            .logo span {
                font-size: 26px;
            }

            .nav-links {
                display: none;
                position: fixed;
                top: 70px;
                left: 0;
                width: 100%;
                background: white;
                flex-direction: column;
                padding: 30px;
                gap: 20px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            }

            .nav-links.active {
                display: flex;
            }

            .nav-links a {
                color: var(--text-primary);
            }

            .mobile-menu-toggle {
                display: flex;
            }

            .hero {
                padding: 100px 0 60px;
            }

            .hero h1 {
                font-size: 40px;
            }

            .hero p {
                font-size: 16px;
            }

            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }

            .btn {
                width: 100%;
                max-width: 300px;
            }

            .stats-section {
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                margin-top: 50px;
            }

            .stat-item {
                padding: 20px;
            }

            .stat-number {
                font-size: 32px;
            }

            .features, .tech-stack, .architecture {
                padding: 80px 0;
            }

            .section-title {
                font-size: 32px;
            }

            .section-subtitle {
                font-size: 16px;
            }

            .features-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .feature-card {
                padding: 30px;
            }

            .architecture-content {
                grid-template-columns: 1fr;
                gap: 40px;
            }

            .code-content {
                padding: 15px;
            }

            .code-content pre {
                font-size: 12px;
            }

            .cta-content h2 {
                font-size: 32px;
            }

            .cta-content p {
                font-size: 16px;
            }

            .cta-content .btn {
                padding: 15px 40px;
                font-size: 16px;
            }
        }

        @media (max-width: 480px) {
            .hero h1 {
                font-size: 32px;
            }

            .hero p {
                font-size: 14px;
            }

            .section-title {
                font-size: 28px;
            }

            .tech-grid {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 28px;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }
        }

        /* Smooth scroll behavior */
        html {
            scroll-behavior: smooth;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }
    </style>
</head>
<body>
    <header id="header">
        <div class="container">
            <nav>
                <a href="#" class="logo">
                    <span>🚀</span> LarAdmin
                </a>
                <ul class="nav-links" id="navLinks">
                    <li><a href="#features">核心特性</a></li>
                    <li><a href="#tech">技术栈</a></li>
                    <li><a href="#architecture">系统架构</a></li>
                    <li><a href="/admin">后台管理</a></li>
                </ul>
                <div class="mobile-menu-toggle" id="mobileMenuToggle">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </nav>
        </div>
    </header>

    <section class="hero">
        <div class="container hero-content">
            <div class="hero-badge">✨ 全新升级 · 高性能架构</div>
            <h1>新一代<br><span class="gradient-text">高性能后台管理系统</span></h1>
            <p>基于 Laravel 12 + Swoole + Vue 3 构建的进销存（ERP）后台管理系统，覆盖商品资料、采购销售、库存盘点、客户供应商、车辆路线与财务收支，为企业级应用提供坚实的技术支撑</p>
            <div class="hero-buttons">
                <a href="/admin" class="btn btn-primary">立即开始</a>
                <a href="#features" class="btn btn-secondary">了解更多</a>
            </div>
            <div class="stats-section">
                <div class="stat-item">
                    <div class="stat-number">3</div>
                    <div class="stat-label">业务域（Auth / System / Business）</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">32</div>
                    <div class="stat-label">数据库迁移（可重放）</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">71</div>
                    <div class="stat-label">自动化测试（420 断言）</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">4</div>
                    <div class="stat-label">CI 门禁（防泄漏 / 静态 / 测试 / 构建）</div>
                </div>
            </div>
        </div>
        <div class="scroll-indicator" onclick="document.getElementById('features').scrollIntoView()">
            ⬇
        </div>
    </section>

    <section class="features" id="features">
        <div class="container">
            <div class="section-header">
                <div class="section-tag">核心优势</div>
                <h2 class="section-title">为什么选择 LarAdmin</h2>
                <p class="section-subtitle">融合最新技术栈，为您提供开箱即用的高性能解决方案</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <span class="feature-icon">⚡</span>
                    </div>
                    <h3>常驻内存服务</h3>
                    <p>基于 Swoole 的长生命周期运行时，跳过每次请求的框架引导开销；worker 数量经 LARAVELS_WORKER_NUM 配置（默认 4）</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <span class="feature-icon">🧩</span>
                    </div>
                    <h3>模块化架构</h3>
                    <p>按 Auth / System / Business 三域划分命名空间（app/ 下独立目录），认证、系统与业务代码物理隔离，易于扩展和维护</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <span class="feature-icon">🔐</span>
                    </div>
                    <h3>RBAC 权限控制</h3>
                    <p>基于角色的访问控制系统，支持用户、角色、权限、部门管理，精细化控制每个操作权限</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <span class="feature-icon">🎨</span>
                    </div>
                    <h3>现代化前端</h3>
                    <p>基于 Vue 3 + Element Plus + VXE Table + Vite 构建的现代化管理界面，支持暗色模式与中英双语</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <span class="feature-icon">📊</span>
                    </div>
                    <h3>数据管理</h3>
                    <p>支持数据导入导出、在线编辑、批量操作等功能，让数据管理变得简单高效</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <span class="feature-icon">🌐</span>
                    </div>
                    <h3>实时通信</h3>
                    <p>基于 Laravel-S 的 WebSocket 服务推送站内通知，通知未读状态可实时同步（modules/System/Services/WebSocket）</p>
                </div>
            </div>
        </div>
    </section>

    <section class="tech-stack" id="tech">
        <div class="container">
            <div class="section-header">
                <div class="section-tag">技术栈</div>
                <h2 class="section-title">强大的技术支撑</h2>
                <p class="section-subtitle">采用业界领先的技术栈，确保系统的稳定性、可扩展性和高性能</p>
            </div>
            <div class="tech-grid">
                <div class="tech-item">
                    <div class="tech-icon">🐘</div>
                    <h4>PHP 8.2+</h4>
                    <p class="tech-version">生产运行时 8.5</p>
                </div>
                <div class="tech-item">
                    <div class="tech-icon">🔷</div>
                    <h4>Laravel 12</h4>
                    <p class="tech-version">12.69</p>
                </div>
                <div class="tech-item">
                    <div class="tech-icon">🚀</div>
                    <h4>Swoole</h4>
                    <p class="tech-version">常驻内存服务</p>
                </div>
                <div class="tech-item">
                    <div class="tech-icon">⚡</div>
                    <h4>Laravel-S 3.8</h4>
                    <p class="tech-version">hhxsv5/laravel-s</p>
                </div>
                <div class="tech-item">
                    <div class="tech-icon">💚</div>
                    <h4>Vue 3.5</h4>
                    <p class="tech-version">Composition API</p>
                </div>
                <div class="tech-item">
                    <div class="tech-icon">🎨</div>
                    <h4>Element Plus</h4>
                    <p class="tech-version">2.14 + VXE Table</p>
                </div>
                <div class="tech-item">
                    <div class="tech-icon">💾</div>
                    <h4>MySQL 8.x</h4>
                    <p class="tech-version">MariaDB 10.x 亦可</p>
                </div>
                <div class="tech-item">
                    <div class="tech-icon">🟢</div>
                    <h4>Node 20</h4>
                    <p class="tech-version">前端云端构建</p>
                </div>
            </div>
        </div>
    </section>

    <section class="architecture" id="architecture">
        <div class="container">
            <div class="section-header">
                <div class="section-tag">系统架构</div>
                <h2 class="section-title">清晰的架构设计</h2>
                <p class="section-subtitle">采用分层架构设计，确保代码的可维护性和可扩展性</p>
            </div>
            <div class="architecture-content">
                <div class="architecture-text">
                    <h3>模块化分层架构</h3>
                    <p>项目采用清晰的分层架构，将业务逻辑合理划分，便于团队协作和代码维护：</p>
                    <ul class="architecture-list">
                        <li>三域划分：modules/Auth（认证）、modules/System（系统与权限）、modules/Business（进销存业务）各自独立命名空间</li>
                        <li>Controller 层：负责参数校验与响应封装，保持薄控制器</li>
                        <li>Service 层：核心业务逻辑（如 StockService 统一收敛库存出入库规则）</li>
                        <li>Model 层：定义数据模型和数据库交互</li>
                        <li>Exports / Imports：基于 maatwebsite/excel 的批量导入导出</li>
                        <li>Middleware：鉴权、日志、限流、库存快照（stock.snapshot）</li>
                        <li>统一的 API 响应格式，便于前端处理</li>
                        <li>JWT 认证（tymon/jwt-auth）+ RBAC 权限体系</li>
                    </ul>
                </div>
                <div class="code-block">
                    <div class="code-header">
                        <div class="code-dot red"></div>
                        <div class="code-dot yellow"></div>
                        <div class="code-dot green"></div>
                        <span class="code-title">快速开始</span>
                    </div>
                    <div class="code-content">
                        <pre><code><span class="comment"># 环境：PHP 8.2+（生产运行时 8.5）+ MySQL 8.x / MariaDB 10.x</span>
<span class="command">composer install</span>
<span class="command">cp .env.example .env</span>
<span class="command">php artisan key:generate</span>
<span class="command">php artisan jwt:secret</span>

<span class="comment"># 数据库迁移（32 个；需 MySQL，SQLite 无法执行迁移链）</span>
<span class="command">php artisan migrate</span>
<span class="command">php artisan db:seed</span>

<span class="comment"># 前端：frontend/ 下构建（Node 20），产物输出到 public/admin</span>
<span class="command">cd frontend && npm ci && npm run build</span>

<span class="comment"># 本地开发（PHP 内置服务器，无需 swoole）</span>
<span class="command">php artisan serve</span>

<span class="comment"># 生产（Swoole 常驻服务，需 swoole 扩展）</span>
<span class="command">php bin/laravels start</span>

<span class="comment"># 访问后台：http://localhost:8000/admin</span></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container cta-content">
            <h2>准备好开始了吗？</h2>
            <p>立即体验 LarAdmin 的强大功能，开启高效开发之旅</p>
            <a href="/admin" class="btn">立即使用</a>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>关于 LarAdmin</h4>
                    <p>LarAdmin 是一个基于 Laravel + Swoole + Vue3 的高性能后台管理系统，旨在为开发者提供开箱即用的解决方案，提高开发效率。</p>
                </div>
                <div class="footer-section">
                    <h4>快速链接</h4>
                    <ul class="footer-links">
                        <li><a href="#features">核心特性</a></li>
                        <li><a href="#tech">技术栈</a></li>
                        <li><a href="#architecture">系统架构</a></li>
                        <li><a href="/admin">后台管理</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>相关资源</h4>
                    <ul class="footer-links">
                        <li><a href="https://laravel.com/docs" target="_blank">Laravel 文档</a></li>
                        <li><a href="https://www.swoole.com/" target="_blank">Swoole 文档</a></li>
                        <li><a href="https://github.com/hhxsv5/laravel-s" target="_blank">Laravel-S 文档</a></li>
                        <li><a href="https://vuejs.org/" target="_blank">Vue 3 文档</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 LarAdmin. Built with ❤️ using Laravel 12 & Swoole & Vue 3</p>
            </div>
        </div>
    </footer>

    <script>
        // Header scroll effect
        const header = document.getElementById('header');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Mobile menu toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const navLinks = document.getElementById('navLinks');

        mobileMenuToggle.addEventListener('click', () => {
            navLinks.classList.toggle('active');
        });

        // Close mobile menu when clicking a link
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                navLinks.classList.remove('active');
            });
        });

        // Intersection Observer for scroll animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe elements for animation
        document.querySelectorAll('.feature-card, .tech-item, .architecture-content > div').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(el);
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>
