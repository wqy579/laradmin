# 全模块化开发计划

> 基线 2026-09-14，提交 `7c15d04`。文内所有数据点均可在仓库内复现，命令附在各节。

## 进度（截至本次提交）

| 阶段 | 状态 |
|---|---|
| Phase 1a 断环（Auth↔System） | ✅ 完成 |
| Phase 1b 中间件归位 | ✅ 完成（别名注册留在 `bootstrap/app.php`，见该文件注释） |
| Phase 1c `config/laravels.php` 例外 | ✅ 已登记，不动 |
| **Phase 2a seeders 归位** | ✅ 完成 |
| **Phase 2b tests 归位** | ✅ 完成（见该节「已知缺口」） |
| Phase 2c config 分区 | ⬜ 只评估，未动 |
| Phase 3 前后端边界对齐 | ⬜ 未开始 |
| Phase 4 测试与 CI 口径 | ⬜ 未开始 |

第三节是 `7c15d04` 时点的实测基线，保留原样供对照；已完成的阶段在原节内标注了现状。

## 一、目标

**模块 = 自持边界 + 单向依赖 + 可独立验证。**

三条硬指标：

1. **无环依赖** —— 模块间依赖构成 DAG。目标形态：`Auth` 为底层身份模块，`Business → Auth`、`System → Auth` 单向
2. **内核纯净** —— `app/` 不 import `Modules\`（当前 4 处倒置需清除）
3. **资源随模块** —— 路由、迁移、seeder、测试都落在模块目录内

验收方式：每条指标都有对应的 grep 断言，纳入 CI（见第六节）。

## 二、非目标：不把共享内核抽成独立包

内核仅 13 个文件，单一消费方，拆包只买到版本号，不买到能力。三个反对理由：

1. **当前「内核」本身依赖模块**（4 处倒置），不是可直接抽取的内核。拆分前得先修倒置，而修完之后拆不拆变成了真可选
2. **部署协调成本**。项目跑在 `hhxsv5/laravel-s`（Swoole 常驻 worker）上，`DEPLOY.md` 已记录一整类「部署后运行时陈旧」故障：`route:cache` 在 worker 内 dispatch 即 fatal、子进程携带旧 autoloader 导致新加 autoload files 永远加载不到、reload 无法刷新 fork 继承的旧运行时。包版本错开会把这个故障面再放大一倍
3. **没有第二个消费方**。单消费方的包，维护的是变更日志和 semver，不是架构

替代方案：**用方向约束代替物理隔离**——`app/` 永不 import `Modules\`，比物理拆包便宜得多，且直击当前真实的耦合问题。

### 触发重新评估的条件

出现任意一条即应重新评估：

- `modules/Business` 被第二个项目复用（进销存模块本身有复用价值）
- 出现第二个后端，例如移动端从 UniApp 迁到独立 API 服务
- 团队分裂为多小组、各自独立发版

当前三条均不成立，仓库内无相关迹象。

## 三、现状基线

### 3.1 依赖矩阵

```
$ grep -rn 'Modules\\' modules/<Module> --include='*.php' | wc -l
```

| 引用方 ＼ 被引用方 | Auth | Business | System |
|---|---|---|---|
| **Auth** | 134 | **0** | **5** ← 成环 |
| **Business** | **3** | 265 | **0** |
| **System** | **7** ← 成环 | 0 | 175 |
| 内核 | 12 | 26 | 8 |

- `Business → System = 0`：这条边界很干净，是标杆
- `Business → Auth = 3`：合理，业务层依赖身份层
- **`Auth ↔ System` 成环**：`Auth` 5 处、`System` 7 处互引，是唯一的环

### 3.2 内核对模块的倒置（4 处）

```
$ grep -rn 'Modules\\' app config bootstrap | grep -v 'bootstrap/providers.php'
```

| 位置 | 依赖 | 性质 |
|---|---|---|
| `app/Http/Middleware/AuthCheckMiddleware.php` | `Modules\Auth\Services\PermissionCacheService` | **应归 Auth** |
| `app/Http/Middleware/LogRequestMiddleware.php` | `Modules\System\Services\LogService` | **应归 System** |
| `bootstrap/app.php` | `Modules\Business\...\StockSnapshotMiddleware` | 别名注册，可改由 Business Provider 注册 |
| `config/laravels.php` | 3 个 System 服务类 | **接受为例外**（laravel-s 配置必须给类名） |

`bootstrap/providers.php` 注册三个模块 Provider 无法避免，不计入。

### 3.3 模块完备性

```
$ find modules -maxdepth 2 -type d
```

| 维度 | Auth | Business | System |
|---|---|---|---|
| Http / Models / Services / Providers | ✓ | ✓ | ✓ |
| routes / database/migrations | ✓ | ✓ | ✓ |
| Jobs | ✓ | — | — |
| Console / Events / Listeners / Facades | — | — | ✓ |
| Exceptions | — | ✓ | — |
| **Seeders**（基线时无，Phase 2a 已建） | **✓** | **✓** | **✓** |
| **tests**（基线时无，Phase 2b 已建） | **✓** | **✓** | **—**（无模块专属测试，testsuite 已预留） |
| **config / resources/views / lang / public** | **—** | **—** | **—** |

### 3.4 集中式资源

- **seeders**：基线时 `database/seeders/` 5 个、互相跨模块（`BusinessSeeder` 引用 Auth，`SystemSeeder` 引用 Auth + System）。**现状（Phase 2a 后）**：只剩 `DatabaseSeeder.php` 一个编排文件，4 个模块种子数据已各自归位。种子数据间的跨模块依赖是**数据依赖**（Business 菜单需要 Auth 的权限表有数据），不是代码耦合，靠编排顺序保证。
- **config**：`config/` 14 个文件平铺，无模块分区
- **tests**：集中式，仅 Business 有 `tests/Feature/Business/`；`modules/*/tests` 不存在
- **前端**：`frontend/src/api/{auth,business,system,notification}.js` —— 三个与后端模块对应，`notification.js` 没有对应后端模块（通知逻辑在 System 内）

### 3.5 共享内核的真实耦合度

```
$ grep -rlF 'App\Http\Controllers\Controller' modules --include='*.php' | wc -l
```

| 内核类 | 被模块文件引用数 |
|---|---|
| `App\Http\Controllers\Controller` | 39 |
| `App\Traits\ModelTrait` | 12 |
| `App\Http\Requests\BaseFormRequest` | 12 |
| `App\Traits\ResponseTrait` | 5 |

结论：**共享内核是有用的共享层，不是死重**。现状准确说法是「模块共享一个内核」，这是合理的设计选择，只是意味着「模块」≠「可独立版本化的包」。

### 3.6 测试与 CI 口径

- SQLite（`phpunit.xml` 默认）：**71 测试 / 420 断言全绿**（基线值；Phase 2b 后为 **75 测试 / 425 断言**，增量来自后续补的架构断言与用例，与模块化无关）
- MySQL（CI `phpunit` job 实际使用的）：**7 个失败**，含 2 个名字带 `_on_sqlite`、断言「SQLite 上必然抛 `QueryException`」的用例——切到 MySQL 就不抛
- 已修的 CI 缺口：静态检查目录清单补上 `modules/`（原清单只覆盖 56 个文件，漏掉 181 个）

## 四、架构约定

| 归属 | 放哪 |
|---|---|
| 路由声明 | `modules/<M>/routes/{api,admin}.php`，只写 `Route::` 声明 |
| 信封（前缀 / 命名 / 横切中间件） | `bootstrap/app.php`，**不在模块里复制信封** |
| 中间件别名注册 | 模块自有中间件的别名在**该模块 Provider** 的 `boot()` 里 `Route::middlewareAliases()` |
| 迁移 | `modules/<M>/database/migrations/`，Provider 的 `boot()` 里 `loadMigrationsFrom` |
| 模块自有中间件 | `modules/<M>/Http/Middleware/`（Business 的 `StockSnapshotMiddleware` 已是范例） |
| 命令 / 事件 / 监听器 / Facade | 模块内同名目录，模块 Provider 注册 |
| 跨模块通信 | **派发领域事件**，禁止跨模块直接调用对方 Service |
| 共享内核 | `app/`（基类 Controller、Traits、Support、共享 Exports、基类 Request） |
| 禁止 | `app/` import `Modules\` |

## 五、阶段计划

### Phase 1：断环 + 清倒置（最高性价比，约 15 处改动）

#### 1a. 断 `Auth → System` 的环

`Auth` 的 4 个调用点全部是「导入/导出任务完成后发通知」，集中在 3 个文件：

```
modules/Auth/Jobs/UserImportJob.php      (2 处)
modules/Auth/Jobs/UserExportJob.php      (2 处)
modules/Auth/Services/UserService.php    (构造函数注入)
```

改法：`Auth` 派发领域事件，`System` 加监听器。`System` 的事件机制现成可用（已有 `Events/NotificationCreated.php` + `Listeners/SendNotificationViaWebSocket.php`，在 `SystemServiceProvider` 注册）。

- 新增 `modules/Auth/Events/UserImportCompleted.php`、`UserExportCompleted.php`
- 新增 `modules/System/Listeners/NotifyUserImportCompleted.php`、`NotifyUserExportCompleted.php`
- 改上述 3 个文件，把 `NotificationService` 注入换成 `event()` 派发

验收：

```
$ grep -rn 'Modules\\System' modules/Auth --include='*.php'   # 应为空
```

剩余 `System → Auth` 7 处（`User` / `Permission` / `Notification` 模型引用）是合理的单向依赖，不动。

#### 1b. 中间件归位

```
app/Http/Middleware/AuthCheckMiddleware.php  → modules/Auth/Http/Middleware/
app/Http/Middleware/LogRequestMiddleware.php → modules/System/Http/Middleware/
```

别名注册从 `bootstrap/app.php` 移到各自 Provider 的 `boot()`：

```php
public function boot(): void
{
    Route::middlewareAliases(['auth.check' => AuthCheckMiddleware::class]);
}
```

`RateLimitMiddleware` 无模块归属，留在 `app/`。

验收：

```
$ grep -rn 'Modules\\' app --include='*.php'   # 应为空
```

**风险**：中间件别名解析时机变化。`Route::middlewareAliases` 在 Provider `boot()` 执行，晚于路由定义但早于请求处理，理论安全；但 `stock.snapshot` 目前在内核信封里注册，若一并迁移需验证所有管理端路由仍能解析到中间件。**建议 `stock.snapshot` 暂不迁移**——它作用域是全部管理端请求，属横切关注点，留内核信封更诚实。

回归验证：登录链路、请求日志落库、权限缓存失效三条必须实测。

#### 1c. `config/laravels.php` 的 3 处 System 类引用

**接受为例外并登记**，不强改。laravel-s 配置结构要求直接给类名，抽象化收益为负。在 `bootstrap/app.php` 的模块化注释里补一行说明这个例外即可。

### Phase 2：资源随模块

#### 2a. seeders 归位（✅ 已完成）

⚠️ Laravel **没有** `loadSeedersFrom`（框架里只有 `loadMigrationsFrom`），所以 seeders 只能显式编排：文件移进模块，`DatabaseSeeder` 做中央编排调用。

实际落地路径（**与原计划的 `database/seeders/` 有偏差，原因见下**）：

```
database/seeders/DatabaseSeeder.php          → 留内核（编排）
database/seeders/AuthSeeder.php              → modules/Auth/Seeders/          Modules\Auth\Seeders
database/seeders/BusinessSeeder.php          → modules/Business/Seeders/      Modules\Business\Seeders
database/seeders/BusinessDataSeeder.php      → modules/Business/Seeders/      Modules\Business\Seeders
database/seeders/SystemSeeder.php            → modules/System/Seeders/        Modules\System\Seeders
```

**偏差原因**：seeders 是具名类，靠 PSR-4 按路径大小写解析类名，而生产机是 Linux（大小写敏感）。原计划的小写 `database/seeders/` 需要额外三条 PSR-4 例外映射才能解析；改用 PascalCase `Seeders/` 后由已有的 `Modules\` → `modules/` 一条规则直接覆盖，`composer.json` 零改动，也与模块内既有的 `Events/`、`Jobs/`、`Listeners/` 等具名类目录约定一致。`database/migrations` 保持小写不动——那里是 `return new class` 匿名类，由 `loadMigrationsFrom` glob 文件名加载，从不按路径解析类名。

**踩过的坑**：`DatabaseSeeder` 里不能写 `Modules\System\Seeders\SystemSeeder::class`。`::class` 与任何类名引用一样受当前命名空间影响，在 `namespace Database\Seeders` 下会解析成 `Database\Seeders\Modules\System\Seeders\SystemSeeder`，`Seeder::call()` → `Container::make()` 直接 `BindingResolutionException`。必须加 `use` 导入后用短名（或写全名前加反斜杠）。迁移其他模块类时同样适用。

验收（已实测通过）：

```
$ DB_CONNECTION=sqlite DB_DATABASE=/tmp/laradmin_seed.sqlite php artisan migrate:fresh --seed --force   # exit 0
$ find database/seeders -name '*.php' | wc -l    # 1（只剩 DatabaseSeeder）
$ ./vendor/bin/phpunit                            # OK (75 tests, 425 assertions)
```

种子数据基线未变：2 用户（admin/manager）、3 角色、88 权限、仓库 2、商品 5、单位 6、分类 5、**种子不预置库存行**（stocks=0）。

#### 2b. tests 归位（✅ 已完成）

归位规则：**测试只引用 `Modules\<M>\*`（且不引用其他模块）就归到 `<M>`；跨模块或无归属的全局守卫留 `tests/`。**

已迁移 6 个文件：

```
tests/Feature/AuthFeatureTest.php          → modules/Auth/tests/Feature/
tests/Feature/ProductCategoryFeatureTest.php → modules/Business/tests/Feature/
tests/Feature/OrderStateFeatureTest.php    → modules/Business/tests/Feature/
tests/Feature/StockFeatureTest.php         → modules/Business/tests/Feature/
tests/Feature/Business/StockServiceTest.php → modules/Business/tests/Feature/Business/
tests/Unit/StockSnapshotServiceTest.php    → modules/Business/tests/Unit/
```

留在 `tests/` 的 4 个：`RouteBaselineTest`、`MigrationSmokeTest`（全局守卫，计划明确点名保留）、`ArchitectureTest`（内核纯净 / 模块完备性断言，跨模块）、`ExampleTest`（框架骨架样板）。

`phpunit.xml` 新增三个 testsuite（`Auth` / `Business` / `System`），`<directory>` 递归扫描，Feature 与 Unit 都在各模块 testsuite 内。`composer.json` 的 `autoload-dev` 同步加了 `Tests\Auth\`、`Tests\Business\`、`Tests\System\` → `modules/<M>/tests/`。

**已知缺口（未处理，留给后续）**：迁过来的测试文件仍声明 `namespace Tests\Feature` / `Tests\Unit`（内容为原样搬运、未改类名），所以 `Tests\<M>\` 这三条 autoload-dev 映射暂时是**预留**的、没有类落进去。PHPUnit 按目录扫描文件、不依赖命名空间，所以不影响运行；等将来把模块测试命名空间改成 `Tests\<M>\Feature` 之类的形态时它们才生效。改的时候注意 `Tests\` 是父前缀，PSR-4 取最长匹配，不会冲突。

**System 模块**目前没有模块专属测试（通知 / 日志 / 配置域还没写测试），`modules/System/tests` 尚未创建；`--testsuite System` 对不存在的目录只打印 `No tests executed!`、**退出码 0**，CI 安全。建目录时建议用 `.gitkeep` 占位（仓库此前无此惯例，尚未添加）。

验收（已实测通过）：

```
$ ./vendor/bin/phpunit                              # OK (75 tests, 425 assertions)
$ ./vendor/bin/phpunit --testsuite Auth             # OK (15 tests, 89 assertions)
$ ./vendor/bin/phpunit --testsuite Business         # OK (52 tests, 263 assertions)
$ ./vendor/bin/phpunit --testsuite System           # No tests executed!（退出码 0）
$ php artisan route:list --json | wc -l             # 260，不变
$ git diff --stat -- tests/snapshots/routes.json    # 空
```

注意：全量跑一遍仍是 75 个用例——迁移只改路径不改内容，用例数不应变化。若这个数字变了，说明有测试被漏掉或重复计入。

#### 2c. config 分区（低优先，先评估）

`config/` 14 个文件，逐个判断归属。预计只有 `jwt.php`（Auth）可能值得动，其余多为框架级或 laravel-s 专用。**建议先只做清单评估，不急着迁移**——Laravel 的配置合并机制对模块分区支持有限，收益不确定。

### Phase 3：前后端边界对齐

`frontend/src/api/notification.js` 是第四个「模块」，但后端通知逻辑在 `System` 内，没有独立边界。

改法：并入 `system.js`。前端文件名跟着后端事实走，成本远低于给后端新建 Notification 子域。

验收：

```
$ ls frontend/src/api/*.js    # 与 modules/ 下的模块一一对应
```

### Phase 4：测试与 CI 口径统一

当前 SQLite 全绿、MySQL 7 个失败，CI 用 MySQL 但 `phpunit.xml` 写 SQLite，两套口径互相打架。

建议：

1. **主跑 SQLite**：快、无外部依赖、本地与 CI 一致
2. **MySQL 独立成 job**：只做迁移冒烟（`migrate:fresh --seed`），贴近生产但不阻塞日常
3. **那 2 个 `_on_sqlite` 用例**改成条件执行：`if (DB::getDriverName() !== 'sqlite') $this->markTestSkipped()`，名字里的 `_on_sqlite` 就名副其实了
4. **另外 5 个在 MySQL 上失败的用例**（`ProductCategoryFeatureTest` ×4、`StockServiceTest` ×1）单独排查——失败形态是计数断言不符（如 `2 is identical to 1`），怀疑数据隔离问题，与模块化无关，属既有缺陷

验收：CI 四个 job 全绿，且 SQLite 与 MySQL 的失败集合有明确解释。

## 六、守卫：CI 新增三道断言

加在 `.github/workflows/tests.yml` 的 `static` job（不新增 job id，避免破坏分支保护）：

```bash
# 1. 内核纯净：app/ 不得依赖模块
hits=$(grep -rn 'Modules\\' app --include='*.php' || true)
[ -z "$hits" ] || { echo "::error title=Kernel inverted::"; echo "$hits"; exit 1; }

# 2. 无环依赖：模块间互引检测
# 3. 模块完备性：每个 modules/<M> 必须有 routes/ + database/migrations/ + tests/
```

第 2 项需要脚本解析互引（两个模块互相引用即判环）。Phase 1 完成后此断言会开始生效。

## 七、执行顺序与风险

| 顺序 | 阶段 | 改动量 | 风险 | 前置 | 状态 |
|---|---|---|---|---|---|
| 1 | 1a 断环 | 3 文件 + 2 新类 + 2 新监听器 | 低 | — | ✅ |
| 2 | 1b 中间件归位 | 2 文件迁移 + 2 Provider | **中**（别名解析时机） | 1a | ✅ |
| 3 | 2a seeders 归位 | 5 文件迁移 | 低 | — | ✅ |
| 4 | 2b tests 归位 | 6 文件迁移 + 配置 | 中（快照路径引用） | — | ✅（下一个：Phase 4 或 Phase 3） |
| 5 | Phase 4 测试口径 | CI + 用例改造 | 中 | 4 | ⬜ |
| 6 | 3 前后端对齐 | 1 文件合并 | 低 | — | ⬜ |
| — | 1c / 2c | 只登记不动 | — | — | ✅ 已登记 |

**不要并行做 1a 和 1b**：都改 Provider 和中间件链路，混在一起出问题难以定位。

每一步都跑：

```bash
./vendor/bin/phpunit                    # 71/420
php artisan route:list --json | wc -l   # 260
```

`tests/snapshots/routes.json` **不应变化**——路由表逐条等价是所有阶段的不变量。

## 八、完成判定

全部做完的判定条件：

```bash
# 1. 无环                                              ✅ 已过
! grep -rq 'Modules\\System' modules/Auth --include='*.php'

# 2. 内核纯净（例外见注）                              ✅ 已过（按注中的排除口径）
! grep -rn 'Modules\\' app --include='*.php' | grep -vE ':\s*\*' | grep -v '^$'

# 3. 资源随模块                                        ✅ 已过
[ "$(find database/seeders -name '*.php' | wc -l)" -eq 1 ]
[ -d modules/Auth/tests ] && [ -d modules/Business/tests ]

# 4. 前后端边界一致                                    ⬜ Phase 3
ls frontend/src/api/*.js    # 期望 auth.js business.js system.js（现为 4 个，多 notification.js）

# 5. 不变量                                            ✅ 已过
./vendor/bin/phpunit && [ "$(git diff --stat tests/snapshots/routes.json)" = "" ]
```

注：

- **第 2 条的排除口径**：`app/Contracts/TaskNotification.php` 的 PHPDoc 里出现 `Modules\System` 字样（说明实现方是谁），是注释不是 import。CI 断言必须按上式排除注释行，否则永远红灯。`config/laravels.php` 的 3 处 System 类引用是 laravel-s 的已登记例外，`config/` 本就不在该断言范围内。
- **第 3 条**：原式含 `[ -d modules/System/tests ]`，但 System 目前无模块专属测试、目录未建。已改成只校验已建的两个；等 System 有测试并建目录时再加回。
- 前三条达成即可视为「全模块化」，2c 与 1c 是有意识保留的例外，不计入未完成项。**当前状态：前三条已达成，差 Phase 3（前后端边界）与 Phase 4（测试口径）两项收尾。**
