# laradmin 项目 - GitHub 代码托管与自动构建部署说明

> 最后更新：2026-09-14
> 状态：✅ 全链路已跑通（云端构建 → 自动部署 → 服务器验证 200）
> 对外访问说明页：`https://laravel.qjwykj.com/docs/deploy-guide.html`（面向协作方，已去除运维敏感信息；完整部署手册以本文件为准）

---

## 一、仓库地址

| 用途 | 地址 |
|---|---|
| **GitHub（主仓库，触发自动构建）** | `https://github.com/wqy579/laradmin.git` |
| 服务器 | `115.191.21.67`（root，项目目录 `/www/wwwroot/laradmin`） |

**规则：所有代码提交以 GitHub 为准**。推送到 GitHub main 分支会自动触发云端构建 + 部署到服务器。

**仓库补充说明**：
- `wqy579/laradmin`：主仓库（代码已在，含自动构建 workflow）
- `wqy579/laradmin-legacy`：旧版代码备份（可忽略）
- `wqy579/laradmin-import`：导入中转仓库（可忽略）

---

## 二、克隆仓库（第一次拿代码）

```bash
git clone https://github.com/wqy579/laradmin.git
cd laradmin
```

### 注意
- 仓库是**私有仓库**，克隆时需登录 GitHub 账号（或用私人令牌）。
- 代码包含前端（`frontend/`）+ 后端（Laravel）。`vendor/`（PHP 依赖）、`node_modules/`、`.env`、`storage/` 均**不入库**，克隆后需要自行安装。

---

## 三、上传代码（日常提交）

### 1. 修改代码后提交
```bash
git add .
git commit -m "描述这次改了什么"
```

### 2. 推送到 GitHub（触发自动构建）
```bash
git push origin main
```

> ⚠️ **重要：如果本次修改涉及 `.github/workflows/` 目录**（构建流程本身），普通 Token 推送会被 GitHub 拒绝（提示需要 `workflow` 权限）。两种解决：
> 1. 在 GitHub 网页直接编辑 workflow 文件（Settings → 进入文件 → 编辑 → Commit），或
> 2. 使用**勾选了 `workflow` 权限**的 Personal Access Token 推送。
>
> 只改普通业务代码（app/、frontend/、routes/、database/ 等）不受此限制。

### 3. 注意事项
- **不要把构建产物提交上去**：`public/admin/` 目录由自动构建生成，手工修改会被覆盖。
- **不要提交敏感信息**：`.env`（数据库密码、密钥）永远不要 `git add`。
- 提交前养成 `git status` 看一眼的习惯，避免把无关文件带进去。
- 本地直接 push 大代码包偶尔会断（国内到 GitHub 网络不稳定），小改动推送基本稳定；失败就重试一次即可。

---

## 四、自动构建部署流程（GitHub Actions）

### 触发方式
**推送代码到 `main` 分支**即自动触发，无需手动操作。

### 流程
```
你 git push → GitHub Actions 云端执行：
  1. 安装 Node.js 20
  2. 云端构建前端（npm install + npm run build）
  3. 打包源码（排除 vendor/node_modules/.env/storage/public/admin）
  4. 通过 SSH 上传到服务器 /tmp/laradmin-deploy
  5. 服务器执行：
     - 同步源码到 /www/wwwroot/laradmin
     - composer install（后端依赖，在服务器装）
     - php artisan migrate（数据库迁移）
     - 清缓存、替换 public/admin 前端产物
     - 重启 laravels（网站无缝切换）
  6. 健康检查（curl 首页，期望 HTTP 200）
```

### 查看构建结果
1. 打开 `https://github.com/wqy579/laradmin/actions`
2. 点最新一次运行 → 可看每一步日志
3. 绿色对勾 = 成功；红色叉 = 失败（点开看哪一步报错）

### 手动触发
在 Actions 页面 → 左侧 `Build & Deploy` → 右侧 **Run workflow** 下拉 → 点 **Run workflow**（适合改完配置想立即部署，或跳过代码推送）。

---

## 五、服务器部署后的验证

部署完成后自动检查首页，你也可以手动验证：

```bash
curl -I https://laravel.qjwykj.com/admin/        # 期望 HTTP 200
```

登录后台：`https://laravel.qjwykj.com/admin/`

---

## 六、GitHub 仓库需要配置的 Secrets（一次性）

在 GitHub 仓库 **Settings → Secrets and variables → Actions** 中配置以下 3 个（值由部署人员提供，切勿外泄）：

| 名称 | 值 |
|---|---|
| `SERVER_HOST` | `115.191.21.67` |
| `SERVER_USER` | `root` |
| `SERVER_SSH_KEY` | 部署用的 SSH 私钥（`github-actions-deploy-v2` 的私钥全文） |

> 服务器 `/root/.ssh/authorized_keys` 中已加入对应公钥。若更换服务器，需同步更新这 3 个值。

---

## 七、重要注意事项（必读）

### 1. 服务器上不要手动构建
现在构建全部在 GitHub 云端完成。**不要在服务器上运行 `npm run build`**——2G 内存机器跑 vite 会卡死整机（历史教训）。服务器只需要：
```bash
cd /www/wwwroot/laradmin
php8.5 artisan migrate --force      # 数据库迁移
php8.5 artisan config:clear         # 清缓存
systemctl restart laravels          # 重启服务
```

### 2. 服务器上不要改源码
服务器上的代码由部署自动同步，**手工改动会被下一次部署覆盖**。需要改代码 → 在本地改 → push GitHub。

### 3. 数据库与上传文件（服务器保留，不入库）
- 数据库：`laradmin` / `jxc_system` / `q_qjwykj_com` 三个库在服务器 MySQL，代码里不含数据。
- 上传文件：`/www/wwwroot/laradmin/storage/app/public/`（商品图片等），部署时**不会被覆盖**（已在部署脚本中排除）。
- 换服务器/重装系统前，务必先备份：数据库（mysqldump）+ `.env` + `storage` 上传目录 + nginx/SSL 配置。

### 4. 回滚
如果某次部署后网站异常：
- 简单回滚：去 GitHub Actions 找到上一次成功的运行，点 **Re-run**。
- 代码回滚：本地 `git log` 找到上一个正常版本号，`git reset --hard <版本号>` 后 `git push origin main --force`。

### 5. 其他
- 路由含闭包，**不要执行 `php artisan route:cache`**（会失败），部署脚本用的是 `route:clear`。
- PHP 命令统一用 `php8.5`（mbstring 缺口由 `app/Support/mb_polyfill.php` 兜底补齐，见 `composer.json` 的 autoload files）。
- 若修改了 `.github/workflows/deploy.yml`（构建流程），直接 push 即可生效。

---

## 八、内部部署手册网页版（可选，口令访问）

对外访问说明页（`/docs/deploy-guide.html`）已去除运维敏感信息；团队内部如需「网页随时可看」的完整版，仓库已提供：

- 内部页面：`public/docs/deploy-guide-internal.html`（部署后位于 `https://laravel.qjwykj.com/docs/deploy-guide-internal.html`）
- nginx Basic Auth 配置片段：`public/docs/deploy-guide-internal.nginx.conf.example`

**启用步骤（服务器上一次性执行）：**

```bash
# 1. 生成口令文件（首次用 -c；换口令直接重新执行并覆盖）
apt-get install -y apache2-utils                      # Ubuntu/Debian；CentOS 用 httpd-tools
htpasswd -c /etc/nginx/.htpasswd-laradmin ops         # 回车后输入两遍强口令
chown root:www /etc/nginx/.htpasswd-laradmin          # www = 宝塔 nginx 运行用户（系统自装多为 www-data）
chmod 640 /etc/nginx/.htpasswd-laradmin

# 2. 把 public/docs/deploy-guide-internal.nginx.conf.example 中的 location 段放进站点 server 块

# 3. 校验并重载
nginx -t && systemctl reload nginx
```

> - 口令文件只存在于服务器 `/etc/nginx/`（不在 web 根目录、也不入库），不会被下载。
> - 站点已 HTTPS，Basic Auth 口令走 TLS 加密，可安全使用。
> - 口令请用强随机值，且不要与任何 Git 凭据相同。
