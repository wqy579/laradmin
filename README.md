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

## 环境要求

> 版本均来自 `composer.json` / `composer.lock` / `.github/workflows/` 与实际依赖，非估算。

| 组件 | 版本要求 | 说明 |
|------|----------|------|
| **PHP** | 代码最低 **8.2**；生产运行时 **8.5** | 见下方「PHP 版本说明」 |
| **Laravel** | 11.x（当前锁版 11.56.1） | `composer.lock` 实际锁定版本 |
| **MySQL** | 8.x（MariaDB 10.x 亦可） | 生产运行时；应用代码不能跑 SQLite，见「SQLite 的适用边界」 |
| **Redis** | 可选（6.x+） | `.env.example` 默认启用，但代码未直接调用，见下 |
| **Node.js** | 20（CI 矩阵 20 + 22） | 仅云端构建前端用，服务器不构建 |
| **Swoole** | ≥ 4.8 | `composer.json` 的 platform 下限 |

### PHP 版本说明

- **代码最低 8.2**：`composer.json` 声明 `"php": "^8.2"`，CI（`.github/workflows/tests.yml`）按 `['8.2', '8.5']` 双矩阵跑静态检查与 PHPUnit。本地 PHP 8.2.33 实测可正常启动并全量通过 71 个测试。
- **生产运行时统一 8.5**：服务器系统 CLI 为 `php8.5`，部署脚本（`deploy.yml`）与 laravels worker 全部走 `php8.5`，避免双运行时并存带来的排查成本（见提交 `a97422e`）。
- **`composer.json` 的 `config.platform` 是刻意为之**：`"php": "8.2.0"`、`"ext-swoole": "4.8.0"`、`"ext-pcntl": "8.2.0"`。前两个让 composer 按 8.2.0 解析依赖（`phpoffice/phpspreadsheet` 1.30.6 声明 `php <8.5.0`，据此才能在 php8.5 上安装）；后两个是让 `composer install` 在**未装** swoole/pcntl 的机器上也能通过，扩展本身仍需另行安装。
- ⚠️ 注意：因上述 platform 锁定，worker 实际以 php8.5 运行 `phpoffice/phpspreadsheet` 1.30.6，处于其声明支持范围之外。当前实测正常（Excel 导入导出通过测试），升级该依赖前请留意。

### PHP 扩展

**必须**（缺失即无法运行）：

| 扩展 | 依赖来源 |
|------|----------|
| `swoole` | `hhxsv5/laravel-s`，应用内 22 处 `Swoole\` API |
| `pdo_mysql`（含 `mysqlnd`） | 数据库驱动 |
| `pcntl` | swoole 进程 / worker 管理 |
| `sockets` | swoole 网络层 |
| `mbstring` | 19 个依赖声明需要，Laravel `Str` 依赖 |
| `json` `openssl` `ctype` `filter` `hash` `iconv` `session` `tokenizer` | Laravel / Composer 基础 |
| `dom` `xml` `xmlwriter` `xmlreader` `libxml` | `maatwebsite/excel` + 框架 |
| `fileinfo` `zip` `zlib` | 文件上传、PhpSpreadsheet 读写 xlsx |
| `phar` `pcre` | Composer、正则 |

**强烈建议**：`opcache`（常驻内存服务性能）、`posix`（swoole daemon）、`bcmath`（金额/库存高精度计算，当前代码未直接调用但 Laravel 生态常用）

**可选**：
- `redis`（phpredis）：`.env.example` 默认 `CACHE_STORE=redis` / `QUEUE_CONNECTION=redis` / `REDIS_CLIENT=phpredis`，但应用代码**没有** `Redis::` 调用，`config/` 默认驱动是 `database`。不用 Redis 时把这两项改为 `database` 即可，属于可选的性能优化。
- `gd`：仅 PhpSpreadsheet 处理 Excel 内嵌图片时需要
- `intl`：应用代码未使用，可不安装

**mbstring 兜底**：生产 php8.5 未装 `ext-mbstring`，由 `symfony/polyfill-mbstring` + `app/Support/mb_polyfill.php` 补齐（后者专门补 `mb_split` / `mb_strimwidth`，这两个函数 symfony polyfill 不覆盖）。详见该文件头注释。

### 数据库字符集

`config/database.php` 中 mysql 连接默认 `utf8mb4` / `utf8mb4_unicode_ci`，可用 `DB_CHARSET` / `DB_COLLATION` 覆盖。建库时请显式指定，避免中文与 emoji 乱码：

```sql
CREATE DATABASE laradmin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### SQLite 的适用边界

- **迁移链可以跑 SQLite**：`tests/Feature/MigrationSmokeTest.php` 就在 SQLite 上跑 `migrate:fresh --seed` 并断言关键表齐全。`phpunit.xml` 默认 `sqlite :memory:`，本地无需额外配置。
- **应用代码不能跑 SQLite**：`modules/Business/Services/StockSnapshotService.php` 里有硬编码的 `ON DUPLICATE KEY UPDATE` 原生 SQL（MySQL 专用），另有 20 处 `updateOrCreate` / `updateOrInsert` / `upsert`。
- **CI 与本地口径不一致**：CI 的 `phpunit` job 起 MySQL 容器（贴近生产），本地默认 SQLite。SQLite 下 71 个用例全绿；MySQL 下当前 7 个失败，含 2 个名字带 `_on_sqlite`、断言「SQLite 上必然抛异常」的用例。见 DEPLOY.md 第九章第 5 节。

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
# phpunit.xml 默认用 SQLite 内存库，本地无需额外配置：
./vendor/bin/phpunit

# 贴近生产验证时用 MySQL 覆盖（迁移链两边都能跑，应用代码只能跑 MySQL，
# 见上方「SQLite 的适用边界」一节）：
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=laradmin_test php artisan test
```

- 测试在每次 push / PR 时由 GitHub Actions 自动运行（`.github/workflows/tests.yml`）
- 约定：**先写测试，再重构**，测试与被重构模块同目录对应（见 `tests/Feature/Business/`）
- 目前覆盖：库存核心服务（入库累加 / 出库扣减 / 库存不足 / 失败回滚 / 列表筛选 / 统计）

## 开源协议

本项目采用 [MIT](LICENSE) 协议开源。
