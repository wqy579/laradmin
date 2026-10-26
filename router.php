<?php

/**
 * php -S 截图环境路由脚本（机器 A batch-1 交付用）
 *
 * 前端构建产物在 public/admin/（base=/admin/，hash 路由），Laravel 入口 public/index.php。
 * 职责：
 *  - /admin/ 或 /admin/index.html → 伺服 SPA 入口（浏览器 hash 路由只请求一次 index.html）
 *  - /admin/assets/* 真实文件 → 交给内置服务器伺服静态
 *  - 其余 /admin/*（API：/admin/api、后端路由 /admin/ledger 等）→ 交给 Laravel
 *
 * 启动：APP_KEY=... DB_CONNECTION=sqlite DB_DATABASE=... CACHE_STORE=file \
 *       php -S 127.0.0.1:8001 /workspace/laradmin/router.php
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$public = __DIR__.'/public';

// SPA 入口：hash 路由下浏览器只请求 /admin/ 拿 index.html，页面跳转都在 # 后
if ($uri === '/' || $uri === '/admin' || $uri === '/admin/' || $uri === '/admin/index.html') {
    readfile($public.'/admin/index.html');

    return true;
}

// 前端静态资源：readfile 直接伺服（php -S 的 -t docroot 会污染 SCRIPT_NAME 导致
// Laravel 路由 404，故不交给内置服务器，改用 readfile + 显式 Content-Type）
if (str_starts_with($uri, '/admin/') && is_file($public.$uri)) {
    $ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    $types = [
        'js' => 'application/javascript', 'css' => 'text/css',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'json' => 'application/json', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
        'ico' => 'image/x-icon', 'html' => 'text/html',
    ];
    header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
    readfile($public.$uri);

    return true;
}

// 其余全部交给 Laravel（API 与后端路由）
require $public.'/index.php';

return true;