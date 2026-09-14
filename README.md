<div align="center">

# LarAdmin

基于 Laravel 11 + Swoole + Vue 3 + Element Plus 的进销存（ERP）后台管理系统

[![Laravel](https://img.shields.io/badge/Laravel-11.0-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net)
[![Vue](https://img.shields.io/badge/Vue-3.5-brightgreen.svg)](https://vuejs.org)
[![Element Plus](https://img.shields.io/badge/Element_Plus-2.14-blue.svg)](https://element-plus.org)
[![Swoole](https://img.shields.io/badge/Swoole-5.1-orange.svg)](https://www.swoole.com)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## ⚠️ 提交规范（必读）

本仓库采用 **「源码入库 + 云端自动构建」** 模式，请严格遵守：

1. **只提交源代码**，**禁止提交任何构建产物**：
   - `public/admin/`（前端构建输出，由 CI 在云端构建后随部署包下发）
   - `frontend/dist/`
   - `node_modules/`（依赖目录）
2. **代码 push 到 GitHub `main` 分支会自动触发云端构建与部署**，无需手动构建或上传产物（详见 [DEPLOY.md](DEPLOY.md)）。
3. **已配置 pre-commit / pre-push 钩子拦截**（`.githooks/`），克隆后请执行：
   ```bash
   git config core.hooksPath .githooks
   ```
   这样即使误 `git add -f` 构建产物，提交也会被自动拒绝。
4. **不要提交敏感信息**：`.env`（数据库密码、JWT 密钥）永远不要 `git add`。

> 注意：修改 `.github/workflows/` 下的文件需要带 workflow 权限的 Token 推送（或在 GitHub 网页上直接编辑），普通 Token 会被 GitHub 拒绝。

## 项目简介

LarAdmin 是一个进销存（ERP）后台管理系统：商品资料与分类、采购/销售订单、库存（出入库/调拨/退货/盘点监控）、客户与供应商、车辆与路线、员工与拜访记录、财务收支等业务模块，配套完整的 RBAC 权限体系与系统管理能力。

- **管理后台 SPA**：Vue 3 + Element Plus + VXE Table，源码位于 `frontend/`，构建产物部署到 `public/admin/`
- **运行时**：Laravel 11 + `hhxsv5/laravel-s`（Swoole 长生命周期服务）

## 核心特性

- **高性能运行时**：Swoole 常驻内存服务，worker 数量可经 `LARAVELS_WORKER_NUM` 调整（默认 4）
- **RBAC 权限**：用户、角色、权限、部门管理体系，菜单按权限渲染，JWT 认证（tymon/jwt-auth）
- **业务模块**：产品与分类（主/副分类联动计数）、采购单、销售单、库存出入库/调拨/退货、联开库存盘点与监控、成本价管理、客户/供应商、车辆/路线、员工/拜访、收款/付款/费用
- **数据导入导出**：基于 maatwebsite/excel 的批量导入导出
- **库存快照**：中间件级库存快照记录（stock.snapshot），支撑库存监控与盘点
- **现代管理界面**：Vue 3 Composition API + Element Plus + VXE Table，三栏分类导航
- **可拖拽仪表盘**：Widget 系统（StatCard、ChartLine、ChartBar、TodoList 等）
- **暗色模式**：全局 CSS 变量切换，Element Plus / VXE Table 深度适配
- **国际化**：vue-i18n，中/英双语支持
- **操作审计**：请求日志与操作记录

## 技术栈

### 后端

| 依赖 | 版本 | 用途 |
|------|------|------|
| PHP | ^8.2 | 运行时 |
| Laravel | ^11.0 | 框架核心 |
| hhxsv5/laravel-s | ^3.8 | Swoole 集成 |
| tymon/jwt-auth | ^2.3 | JWT 认证 |
| maatwebsite/excel | ^3.1 | Excel 导入导出 |

### 管理后台前端（`frontend/`）

| 依赖 | 版本 | 用途 |
|------|------|------|
| Vue | ^3.5 | 框架核心 |
| Vite | ^5.4 | 构建工具 |
| Element Plus | ^2.14 | UI 框架 |
| VXE Table | ^4.21 | 高性能表格 |
| Pinia | ^3.0 | 状态管理 |
| Vue Router | ^4.6 | 路由（hash 模式，base=/admin/） |
| Vue I18n | ^9.14 | 国际化 |
| @vueuse/core | ^14.3 | 组合式工具库 |
| grid-layout-plus | ^1.1 | 仪表盘拖拽布局 |

## 项目结构

**全模块化**：业务代码全部在 `modules/` 下，`app/` 只保留跨模块共享的内核。

```
laradmin/
├── modules/                          # 业务模块（每个模块自持路由 + 迁移 + 模型 + 服务）
│   ├── Auth/                         # 权限：用户 / 角色 / 权限 / 部门
│   ├── System/                       # 系统：配置 / 日志 / 字典 / 定时任务 / 附件 / 上传
│   └── Business/                     # 进销存：产品 / 客户 / 订单 / 库存 / 财务 / 拜访
│       └── （每个模块内部）
│       ├── Http/                     # Controllers / Requests / Middleware
│       ├── Models/  Services/        # 数据模型与业务服务层
│       ├── Providers/                # 模块 ServiceProvider（注册迁移、命令、事件）
│       ├── routes/admin.php          # 本模块管理端路由（由内核统一套信封加载）
│       └── database/migrations/      # 本模块迁移（Provider 里 loadMigrationsFrom）
├── app/                              # 共享内核（不放任何单一模块的业务逻辑）
│   ├── Http/Controllers/             # Controller 基类 / HomeController
│   ├── Http/Middleware/              # 鉴权 / 请求日志 / 限流
│   ├── Http/Requests/                # BaseFormRequest / LogRequest
│   ├── Exports/                      # GenericExport（Auth、System 共用）
│   ├── Traits/                       # ModelTrait / ResponseTrait
│   └── Support/                      # mbstring 兜底 shim
├── bootstrap/app.php                 # 模块路由发现 + 统一信封 + 中间件别名
├── routes/                           # web.php / console.php / admin.php（仅内核级路由）
├── database/migrations/              # 内核迁移（仅框架表，如 jobs）
├── frontend/                         # 管理后台 SPA（Vue 3 + Element Plus + Vite）
├── resources/
│   ├── mobile/                       # 移动端（UniApp，商品拜访/移动办公）
│   ├── web/                          # 前台 Web 应用
│   └── views/                        # Laravel Blade 视图
├── config/                           # laravels / jwt 等
├── deploy/                           # 服务器运维配置（如内部手册 nginx Basic Auth 片段）
├── public/admin/                     # 前端构建产物（CI 生成，不入库）
├── public/docs/                      # 文档页（deploy-guide.html 对外 / -internal.html 内部加口令）
├── .env.example                      # 环境变量模板（克隆后复制为 .env 再填值）
└── DEPLOY.md                         # 部署说明（GitHub Actions 云端构建）
```

### 模块约定

| 归属 | 放哪 |
| --- | --- |
| 路由声明 | `modules/<Module>/routes/{api,admin}.php`，只写 `Route::` 声明 |
| 信封（前缀 / 命名 / 横切中间件） | `bootstrap/app.php`，**不要在模块里复制信封**——信封是全局决策，改了要同时改三处 |
| 迁移 | `modules/<Module>/database/migrations/`，在模块 Provider 的 `boot()` 里 `loadMigrationsFrom` |
| 命令 / 事件 / 监听器 / Facade | 模块内同名目录，在模块 Provider 注册 |
| 跨模块共享的工具类 | `app/`（Traits、Support、Exports、基类 Request） |

`bootstrap/app.php` 用 `glob` 自动发现模块路由文件，新增模块放好 `routes/admin.php` 即被加载，
无需改内核。路由表由 `tests/Feature/RouteBaselineTest.php` 的快照逐条比对兜底。

## 部署机制（GitHub Actions）

**push 到 `main` 即自动部署**，完整流程与故障排查见 [DEPLOY.md](DEPLOY.md)。概要：

1. GitHub Actions 云端执行 `npm install && npm run build`（构建不占用服务器内存）
2. 打包源码 + 前端产物，经 SSH 上传服务器
3. 服务器同步源码、`composer install`、`php artisan migrate`、清缓存、替换 `public/admin/`、重启 laravels、健康检查

**禁止在服务器上手动执行 `npm run build`**：服务器仅 2G 内存，跑 Vite 会把整机拖死（历史教训，详见 DEPLOY.md）。

## 测试

项目此前**没有任何测试**（`phpunit.xml` 已配置，但 `tests/` 目录缺失），现已补上。

```bash
# 需要一个 MySQL 测试库（迁移含 MySQL 专有语法，SQLite 无法执行）
mysql -uroot -p -e "CREATE DATABASE laradmin_test"

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=laradmin_test php artisan test
```

- 测试在每次 push / PR 时由 GitHub Actions 自动运行（`.github/workflows/tests.yml`）
- 约定：**先写测试，再重构**，测试与被重构模块同目录对应（见 `tests/Feature/Business/`）
- 目前覆盖：库存核心服务（入库累加 / 出库扣减 / 库存不足 / 失败回滚 / 列表筛选 / 统计）

## 开源协议

本项目采用 [MIT](LICENSE) 协议开源。
