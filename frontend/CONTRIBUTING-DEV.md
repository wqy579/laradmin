# laradmin 多机协同开发规范（6 机并行 · 必读）

## 0. 最高优先级：保护录单

本机同时运行**订单录入自动化**（Playwright + laradmin 管理端）。开发时：

- **禁止** kill / restart / 修改任何录单进程：`enter_batch_monthly.py`、`order_ui_enter.py`、`laradmin_ui.py`，以及 `/root/liankai/` 目录、`/tmp/entered_*.txt`、`/tmp/enter_loop_*.log`、`/tmp/.laradmin_state.json`。
- **禁止**登录 laradmin 管理端做开发验证（会触发登录限流 429，打断录单）。前端开发一律用 **mock 数据**（本地 json / msw / 写死 fixtures），页面自测通过即可。
- **禁止**动后端 `routes/`、`database/`、`.env`、`storage/`。前端只改 `frontend/`。
- `npm install` / `vite build` 等重活加 `nice -n 19`，并错峰执行（先在本任务内做完再跑，不要并发 6 台同时 build）。

## 1. 仓库与分支

- 仓库：`/workspace/laradmin`（monorepo，前端在 `frontend/`）。
- **只 push 自己的 feat 分支**：`git push origin feat/<你的模块编号>`。禁止直接 push main；合并由管理员统一做。
- 每天开工先 `git fetch origin && git checkout main && git pull --ff-only`，再 `git rebase main` 到自己的 feat 分支，保持领先冲突最少。
- 提交信息用约定式提交：`feat(finance): 凭证列表筛选与分组表格`、`fix(voucher): 红冲金额符号`、`chore: tokens.css` 等。

## 2. 目录与文件边界（防冲突）

- 新页面放 `frontend/src/views/<域>/<模块>.vue`（域：finance / cash / asset / points / report / stock）。
- **新路由一律放 `frontend/src/router/modules/<你的文件>.js`**（默认导出数组）。基建已把 `systemRoutes.js` 改为从 `modules/index.js` 自动聚合（import.meta.glob），**不要再改 systemRoutes.js**。
- 全局样式只引用 `frontend/src/styles/tokens.css` 的 CSS 变量（`var(--color-primary)` 等），禁止写死色值/字号。
- 图标用 `@element-plus/icons-vue`；金额/数量/编号/日期一律加 `tabular-nums`（class `tabular` 或 `font-variant-numeric`）。

## 3. 设计依据

- 《未实现模块详细设计_总文档》（资料库，page-id: `PRxu9Ul8SghrVlz97KTuox`）。每台按分配到的模块号读对应章节（卷00 是全局规范，必读）。
- 每个元素的尺寸/对齐/字号/颜色以文档表格为准；文档写 13px 就不要用 14px。

## 4. 自测与交付

- 每完成一个小任务就 commit（小步提交），不要攒成大提交。
- 页面能 `vite dev` 起来、路由可进、表格/表单渲染正常即算完成；数据用 mock。
- 遇到需要后端接口的：按文档"数据表建议"先写前端，接口 path 按 REST 约定预留，`TODO(api)` 标注，不要自己改后端。
