<div align="center">

# LarAdmin

基于 Laravel 13 + Swoole + Vue 3 + Element Plus 的高性能后台管理系统 / CMS

[![Laravel](https://img.shields.io/badge/Laravel-13.0-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net)
[![Vue](https://img.shields.io/badge/Vue-3.5-brightgreen.svg)](https://vuejs.org)
[![Element Plus](https://img.shields.io/badge/Element_Plus-2.14-blue.svg)](https://element-plus.org)
[![Swoole](https://img.shields.io/badge/Swoole-5.1-orange.svg)](https://www.swoole.com)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## ⚠️ 提交规范（必读）

本仓库采用 **「源码入库 + 服务器自动构建」** 模式，请严格遵守：

1. **只提交源代码**，**禁止提交任何构建产物**：
   - `public/admin/`（前端构建输出）
   - `*/dist/`（打包产物，如 `resources/admin/dist/`）
   - `node_modules/`（依赖目录）
2. **服务器会自动构建**：代码 push 到 `main` 分支后，Gitee Webhook 会自动执行 `npm run build` 并部署，**无需手动构建或上传产物**。
3. **已配置 pre-commit 钩子拦截**（`.githooks/pre-commit`），克隆后请执行：
   ```bash
   git config core.hooksPath .githooks
   ```
   这样即使误 `git add -f` 构建产物，提交也会被自动拒绝。
4. **为什么禁止**：构建产物与源码不一致会导致仓库臃肿、历史混乱、协作冲突，且上传的产物会在服务器部署时被重新构建覆盖。

---

## 项目简介

LarAdmin 是一个基于 Laravel 13 + Swoole + Vue 3 的高性能后台管理系统，采用模块化架构设计，提供完整的认证授权、系统管理、内容管理及业务扩展能力。项目集成 `hhxsv5/laravel-s` 实现 Swoole 长生命周期服务，大幅提升系统并发性能与响应速度。

包含三个前端应用：

- **管理后台 SPA**：Vue 3 + Element Plus + VXE Table，位于 `resources/admin/`
- **公开网站**：Nuxt 4 SSR，位于 `resources/web/`
- **移动端应用**：UniApp + Vue 3，支持 App + 多端小程序，位于 `resources/mobile/`

## 核心特性

- **高性能运行时**：基于 Swoole 协程，支持高并发、低延迟
- **模块化架构**：采用 `nwidart/laravel-modules` 实现业务模块化管理
- **RBAC 权限**：完整的用户、角色、权限、部门管理体系，权限 Redis 缓存级联失效
- **动态内容建模**：CMS 模块支持动态定义内容类型与字段，自动建表、自动注册菜单
- **多存储驱动**：内置 Local / S3 / MinIO / OSS / RustFS 五种存储驱动
- **现代管理界面**：Vue 3 Composition API + Element Plus + VXE Table
- **三种布局模式**：basic（经典侧边栏）、dual（双栏导航）、top（水平导航）自由切换
- **可拖拽仪表盘**：Widget 系统，支持 StatCard、ChartLine、ChartBar、TodoList、RecentActivity
- **暗色模式**：全局 CSS 变量切换，Element Plus / VXE Table 深度适配
- **国际化**：vue-i18n，中 / 英双语支持
- **实时通信**：内置 WebSocket，支持 JWT 鉴权、频道订阅、广播与系统通知
- **数据导入导出**：基于 Laravel Excel 的批量数据导入导出能力
- **操作审计**：完整的操作日志记录与审计功能
- **可视化任务调度**：定时任务的图形化管理与监控

## 技术栈

### 后端

| 依赖 | 版本 | 用途 |
|------|------|------|
| PHP | ^8.2 | 运行时（Docker 使用 8.3） |
| Laravel | ^13.0 | 框架核心 |
| hhxsv5/laravel-s | ^3.8 | Swoole 集成 |
| tymon/jwt-auth | ^2.2 | JWT 认证 |
| nwidart/laravel-modules | ^12.0 | 模块化开发 |
| intervention/image | ^3.11 | 图片处理 |
| maatwebsite/excel | ^3.1 | Excel 导入导出 |

### 管理后台前端（`resources/admin/`）

| 依赖 | 版本 | 用途 |
|------|------|------|
| Vue | ^3.5 | 框架核心 |
| Vite | ^8.0 | 构建工具 |
| Element Plus | ^2.14 | UI 框架 |
| VXE Table | ^4.19 | 高性能表格 |
| Pinia | ^3.0 | 状态管理 |
| Vue Router | ^4.6 | 路由 |
| Vue I18n | ^9.14 | 国际化 |
| @vueuse/core | ^14.3 | 组合式工具库 |
| Axios | ^1.16 | HTTP 请求 |
| grid-layout-plus | ^1.1 | 仪表盘拖拽布局 |

### 公开网站前端（`resources/web/`）

| 依赖 | 版本 | 用途 |
|------|------|------|
| Nuxt | ^4.4 | SSR 框架 |
| @nuxt/image | ^2.0 | 图片优化 |

### 移动端（`resources/mobile/`）

| 依赖 | 版本 | 用途 |
|------|------|------|
| @dcloudio/uni-ui | ^1.5 | UniApp UI 组件库 |
| luch-request | ^3.1 | 网络请求 |
| mp-html | ^2.5 | 富文本解析 |

## 项目结构

```
laradmin/
├── app/                              # 基础模块（Auth 认证 / System 系统）
│   ├── Http/Controllers/             # 控制器（Admin 后台 / Api 公开接口）
│   ├── Models/                       # 数据模型
│   ├── Services/                     # 业务服务层（含 Storage 存储驱动）
│   ├── Exports/ Imports/             # Excel 导入导出
│   ├── Middleware/                   # 中间件（鉴权 / 日志 / 限流）
│   └── Traits/                       # 公共 Trait（Response / Model）
├── modules/                          # 业务模块目录（nwidart/laravel-modules）
│   └── Cms/                          # CMS 内容管理模块
├── resources/
│   ├── admin/                        # 管理后台 SPA（Vue 3 + Element Plus）
│   ├── web/                          # 公开网站（Nuxt 4 SSR）
│   └── mobile/                       # 移动端（UniApp + Vue 3）
├── routes/                           # 路由（admin.php / api.php / web.php）
├── config/                           # 配置（laravels / jwt / modules 等）
├── database/                         # 迁移与填充
└── storage/                          # 模块激活状态等运行时文件
```

## 开源协议

本项目采用 [MIT](LICENSE) 协议开源。
# Deploy Trigger Fri Sep  4 12:00:01 UTC 2026
# Deploy 1788523297

# Build v9 - force deploy with fix

## 部署机制（2026-09-12 起）

- **不停服部署**：webhook 部署时保持 laravels/nginx/mysql/php-fpm 全部运行，不再停止服务（避免 LaravelS 优雅停止超时导致部署卡死）。
- **平滑重载**：构建完成后执行 `php8.4 bin/laravels reload`，worker 重启加载新 PHP 代码，master 不重启，服务零中断。
- **内存实测**：2G 内存 + 2G swap 环境下，服务全运行状态构建约 90 秒，swap 峰值约 1.86G（接近上限但未 OOM），构建结束后内存自动回收，无卡死。
- **reload 容错**：构建结束先等待 15 秒内存回收再 reload；失败自动重试一次；仍失败则强杀 master 后 `systemctl start`（不再使用 `systemctl restart`，避免 LaravelS 优雅停止超时卡死）。
- **部署可靠性观察**：部署过程中 laravels 偶尔会被外部 `bin/laravels restart` 进程拉走（触发源排查中，已排除 git hooks/fswatch/宝塔插件/定时任务），已通过秒级监控 + 手动恢复流程兜底；`laravels-check` 巡检脚本已同步改为强杀方案。
- 前端构建产物由服务器部署时自动生成，禁止提交 `public/admin`、`dist` 等产物。
