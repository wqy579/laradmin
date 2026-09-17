# 全模块化开发计划

> 基线 2026-09-14，提交 `7c15d04`。文内所有数据点均可在仓库内复现，命令附在各节。

## 进度（截至本次提交）

| 阶段 | 状态 |
|---|---|
| Phase 1a 断环（Auth↔System） | ✅ 完成 |
| Phase 1b 中间件归位 | ✅ 完成（别名注册留在 `bootstrap/app.php`，见该文件注释） |
| Phase 1c `config/` 例外 | ✅ 已登记，不动（`config/laravels.php` 3 处 + 基线后发现补登的 `config/auth.php` 1 处，见 3.2） |
| **Phase 2a seeders 归位** | ✅ 完成 |
| **Phase 2b tests 归位** | ✅ 完成（见该节「已知缺口」） |
| Phase 2c config 分区 | ⬜ 只评估，未动 |
| Phase 3 前后端边界对齐 | ✅ 完成（`notification.js` 并入 `system.js`） |
| Phase 4 测试与 CI 口径 | ✅ 完成（实测结果推翻了原「主跑 SQLite」的建议，见该节） |
| **Phase 5 testsuite 目录占位与守卫** | ✅ 完成（修掉「整套测试静默归零」的坑，2026-09-15 发现） |

第三节是 `7c15d04` 时点的实测基线，保留原样供对照；已完成的阶段在原节内标注了现状。
当前基线（2026-10 拆分后）：`php artisan test` → **113 用例 / 655 断言**；274 条路由
（拆分后路由数不变，仅 123 条的 action 控制器命名空间由 `Business\` 变为 `Stock\`/`Order\`，
uri 与 middleware 零变化）。历史基线为 106 用例 / 616 断言。

（分支已于 2026-09-15 rebase 到 main：`61390b1` 菜单图标修复带进来 4 个用例，
76 → 80。rebase 前是 76/426。

之后又长到 106/616、路由 260 → 274。增量全部来自功能与守卫新增（Phase 3 契约
守卫、System 通知模块首个模块专属测试、退货/采购状态机、关联完整性守卫），
不是模块化归位——归位本身只改路径不改用例数，那一步的不变量在当时提交上成立。）

## 一、目标

**模块 = 自持边界 + 单向依赖 + 可独立验证。**

三条硬指标：

1. **无环依赖** —— 模块间依赖构成 DAG。目标形态：`Auth` 为底层身份模块，`Business → Auth`、`System → Auth` 单向
2. **内核纯净** —— `app/` 不 import `Modules\`（3.2 的 4 处倒置里 2 处已迁进模块，`app/` 现已零倒置；其余是 `bootstrap/` 与 `config/` 里逐处登记的配置例外，现状共 3 处）
3. **资源随模块** —— 路由、迁移、seeder、测试都落在模块目录内

验收方式：每条指标都有对应的 grep 断言，纳入 CI（见第六节）。

## 二、非目标：不把共享内核抽成独立包

内核仅 13 个文件，单一消费方，拆包只买到版本号，不买到能力。三个反对理由：

1. **「内核」曾经依赖模块**（3.2 的 4 处倒置），不是可直接抽取的内核。现在 `app/` 已零倒置、剩下 3 处是 `bootstrap/` 与 `config/` 里必须写类名的配置例外——拆不拆确实变成了真可选，这条理由的权重因此下降。但它从未成立为「当前仍依赖」，写这条时得说清是哪个时点
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

> ⚠️ 本节 3.1–3.5 是 **2026-09-14** 的历史基线，保留原样供对照，勿据此判断当前拓扑。
> 当前拓扑见新增的 **3.0 当前模块拓扑（2026-10 拆分后）**。

### 3.0 当前模块拓扑（2026-10 拆分后）

`modules/Business` 于 2026-10 拆成三个模块，`Business` 保留为残部：

| 模块 | 命名空间 | 领域 |
|---|---|---|
| `Auth` | `Modules\Auth` | 用户 / 角色 / 权限 / 菜单 |
| `System` | `Modules\System` | 通知 / 日志 / 调度 / WebSocket |
| **`Stock`** | `Modules\Stock` | 库存 / 出入库 / 调拨 / 盘点 / 库存核对与监控 + **商品 / 分类 / 单位 / 仓库 / 车辆主数据** |
| **`Order`** | `Modules\Order` | 销售 / 采购 / 退货 / 发货订单 + 客户 / 供应商 / 线路 / 拜访 + 收付款 |
| `Business` | `Modules\Business` | 残部：员工 / 考勤 / 费用 + 菜单与主数据 Seeder + 历史迁移 |

分层与依赖方向（自底向上，无环）：

```
L0  Auth                ← 不出任何模块边
L1  Stock               ← 零模块依赖（商品是库存主数据，故与库存同模块）
    Business            ← 只指向 L0/L1
L2  System
L3  Order               ← 指向 Stock / Business / Auth
```

三条需要记牢的拆分理由（都是实测出来的约束，不是拍脑袋）：

1. **商品必须跟库存同模块。** `Product` 与 `Stock` 的模型层是双向关联
   （`Product hasMany Stock` / `Stock belongsTo Product`），分到两个模块就是环。
   `ArchitectureTest` 的白名单会挡住，所以商品主数据进了 `Stock`。
2. **历史迁移不拆。** `create_business_tables` 一个文件跨 customers/products/units/
   vehicles/warehouses，`create_transaction_tables` 一个文件跨订单+库存+出入库。
   按领域拆只能重写已执行的迁移、污染既有库的 `migrations` 记录，所以 30 个历史迁移
   全部留在 `modules/Business/database/migrations`。`Stock`/`Order` 各自的
   `database/migrations` 只承接 2026-10 之后的**新增**表。
3. **URL 不动。** 路由前缀仍是 `business/*`，只改后端归属。改 URL 需要前后端同步
   发版，不属于本次范围。`bootstrap/app.php` 用 `glob(modules/*/routes/*.php)` 发现
   模块路由，新增模块零配置。

新增模块的清单（照抄 Auth/System 的骨架即可被守卫覆盖）：

```
modules/<M>/
  Providers/<M>ServiceProvider.php   # 必须 loadMigrationsFrom，否则守卫会报
  routes/admin.php                   # 必须存在且非空
  database/migrations/               # 必须存在且非空（守卫只挡结构性缺失，不要求有测试）
  tests/                             # 目录必须存在
```

同步要改的四处：`composer.json` 的 `psr-4` + `autoload-dev`、`bootstrap/providers.php`、
`phpunit.xml` 的 testsuite、以及 `ArchitectureTest::ALLOWED_MODULE_EDGES`（**有模块依赖才需要**，
且必须写明理由；能改走 `App\Contracts` 内核契约的就不要登记白名单）。

模块测试的命名空间必须是 `Tests\<M>\Feature` / `Tests\<M>\Unit`，
对应 `autoload-dev` 的 `Tests\<M>\` 映射，`ArchitectureTest` 会逐个校验解析目标。

### 3.1 依赖矩阵（2026-09-14 历史基线）

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

> **基线后发现第 5 处（2026-09-15）**：`config/auth.php:75`
> `env('AUTH_MODEL', Modules\Auth\Models\User::class)` —— 用户 provider 的 model
> 必须给类名，和 laravel-s 那 3 处同性质，**登记为已接受例外**，不迁。
> 试过把 `config/auth.php` 整份挪进模块用 `mergeConfigFrom` 解决：它是浅合并，
> `providers` 这个键会被整体替换、`providers.users` 直接丢，要做递归合并才能保住，
> 复杂度远大于收益。`config/` 也不在 ArchitectureTest 的内核纯净扫描范围内
> （那只扫 `app/`），所以这条靠本文档登记，不靠守卫——登记表就是唯一的防线。
>
> 现状：`app/` 零倒置；例外共 3 处，全在 `bootstrap/` 与 `config/`，逐处登记于此节。


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
| **tests**（基线时无，Phase 2b 已建） | **✓** | **✓** | **✓**（通知模块 6 用例 39 断言，占位 `.gitkeep` 已删，见 2b） |
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

- SQLite（`phpunit.xml` 默认）：**106 测试 / 616 断言全绿**（2026-09-15 实测；
  71/420 → Phase 2b 75/425 → rebase 后 81/435 → 现 106/616。81→106 的增量来自
  Phase 3 契约守卫、System 通知模块测试、退货/采购状态机与关联完整性守卫，
  与模块化归位本身无关）
- MySQL（CI `phpunit` job 实际使用的）：**7 个失败**，含 2 个名字带 `_on_sqlite`、断言「SQLite 上必然抛 `QueryException`」的用例——切到 MySQL 就不抛。**现状（Phase 4 后）：0 失败 0 错误，1 个跳过**，见 Phase 4 验收
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

**✅ 已处理（2026-09-15）**：原缺口是「测试文件挪进 modules/ 后 namespace 仍是原样搬运的 `Tests\Feature` / `Tests\Unit`」，导致 `Tests\<M>\` 三条 autoload-dev 映射一直空转——`composer dump-autoload` 每次刷十几个 `does not comply with psr-4 autoloading standard` 警告，而 `Tests\Business\Feature\StockFeatureTest` 这类 FQCN 根本解析不到文件。

原因分析要写清：**PHPUnit 按目录扫描、不依赖命名空间，所以套件照绿**，这条一直是静默的，只有 `composer dump-autoload` 的警告、IDE 导航、覆盖率工具、按类名 filter 才踩得到。

修法：8 个模块测试文件的命名空间改为 `Tests\<M>\Feature` / `Tests\<M>\Unit`（含 `Tests\Business\Feature\Business\StockServiceTest` 那一层）。`Tests\` 是父前缀、PSR-4 取最长匹配，所以 `Tests\Business\` 永远赢过 `Tests\`，不与 tests/ 下的全局守卫冲突。

守卫已补：`ArchitectureTest::test_test_classes_resolve_through_autoloader` 逐个断言每个 `*Test.php` 的声明命名空间能被 composer 解析**回它自己所在的文件**——只看「类存在」不够，同名类就会假通过。反向验证过：把 NotificationFeatureTest 的命名空间改回 `Tests\Feature`，守卫立刻报「无法解析」。

**System 模块**是三个模块里最后补上专属测试的：`modules/System/tests/Feature/NotificationFeatureTest.php`，6 用例 39 断言（无鉴权 404、未读数与统计可达、show→markRead→delete 生命周期、批量读/删的真实受影响计数、只作用于本人的范围限定）。`Feature/.gitkeep` 占位随之删除——占位当时防的是「空目录让整套测试归零」，那件事现在由两道守卫负责，不靠文件留着。日志与配置域仍未写测试。

⚠️ **这里踩过一个会让整套测试静默归零的坑**，之前的判断是错的：

- 原来的判断：目录不存在时 phpunit 只跳过该 testsuite、退出码 0、CI 安全。**错。**
- 实测（PHPUnit 11.5.56）：`phpunit.xml` 声明的 `<directory>` 不存在时，**无论全量跑还是
  `--testsuite System` 单跑，都是 exit 2 且零个测试执行**，报错 `Test directory ... not found`。
  空目录 git 不跟踪，所以任何一次干净检出都会踩中——CI 全红，而且红的不是任何一条断言。
- `No tests executed!` + **退出码 0** 只在 `--testsuite` 指定的**名字**不存在时出现。
  两个现象长得像，退出码相反，本节的原始表述就是把它们混了。
- 守卫已补：`ArchitectureTest::test_declared_testsuite_directories_exist` 逐条核对
  phpunit.xml 的 `<directory>` 是否真实存在，`test_every_module_owns_routes_and_migrations`
  追加了 `tests` 目录必须存在（允许只有占位文件）。两处都在 phpunit job 里，删掉占位立刻红灯。

验收（已实测通过）：

```
$ ./vendor/bin/phpunit                              # OK (75 tests, 425 assertions)
$ ./vendor/bin/phpunit --testsuite Auth             # OK (15 tests, 89 assertions)
$ ./vendor/bin/phpunit --testsuite Business         # OK (52 tests, 263 assertions)
$ ./vendor/bin/phpunit --testsuite System           # OK (0 tests)——System 暂无测试，占位目录存在所以不会中止 run
$ php artisan route:list --json | wc -l             # 260，不变
$ git diff --stat -- tests/snapshots/routes.json    # 空
```

注意：全量跑一遍仍是 75 个用例——迁移只改路径不改内容，用例数不应变化。若这个数字变了，说明有测试被漏掉或重复计入。

**当前值（2026-09-15，用例数含后续阶段新增）**：

```
$ ./vendor/bin/phpunit                              # OK (106 tests, 616 assertions)
$ ./vendor/bin/phpunit --testsuite Auth             # OK (16 tests, 106 assertions)
$ ./vendor/bin/phpunit --testsuite Business         # OK (62 tests, 358 assertions)
$ ./vendor/bin/phpunit --testsuite System           # OK (6 tests, 39 assertions)
$ php artisan route:list --json | wc -l             # 274
```

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

原判断：SQLite 全绿、MySQL 7 个失败，CI 用 MySQL 但 `phpunit.xml` 写 SQLite，两套口径互相打架。

**实测后这条建议不成立，主门槛应保持 MySQL。** 理由是实测抓到了一个只有 MySQL 会暴露的缺陷：

```
StockService::statistics()  里  Stock::whereColumn('quantity', '<', 10)
```

`whereColumn` 的第三个参数是「另一列」而非字面量，于是拼成 `where quantity < 10`，
把 10 当列名。MySQL 报 `SQLSTATE[42S22] Unknown column '10'`，统计接口直接 500；
SQLite 因类型亲和性把 10 当字面量、照常通过。**换成 SQLite 当唯一门禁会把这类缺陷放过去。**

顺带核实：原注释里「迁移含 MySQL 专有语法、SQLite 无法执行」是错的——全量迁移搜不到
`ON DUPLICATE KEY UPDATE` / `ENGINE=` / `FULLTEXT` / `MODIFY COLUMN` 等任何专有构造，
SQLite 跑全量是 76/76 全绿。`tests.yml` 里那两行注释已改写。

实际做了的：

1. ~~主跑 SQLite~~ **不做**。SQLite 快、无外部依赖，适合本地迭代，但不适合当唯一门禁。
   CI 保持 MySQL 主跑，理由已写进 `tests.yml` 的服务容器注释。
2. ~~MySQL 独立成 job~~ **不做**。MySQL 主跑本身已绿，拆 job 没有收益。
   MySQL 侧的建库覆盖仍由 phpunit job 里的 `Migration smoke test` 步骤承担。
3. ✅ **`_on_sqlite` 用例加驱动条件跳过**，共 3 个（不是原估的 2 个）：
   `MigrationSmokeTest` 的建库用例，以及 `StockSnapshotServiceTest` 的 2 个钉住
   「快照 SQL 不可移植」的缺陷用例。后两者的跳过必须放在 `expectException` 之前。
   **现状：跳过集合降到 1 个。** `StockSnapshotService::snapshotToday()` 已改成
   逐行 `updateOrInsert` 的可移植写法（原 `INSERT ... SELECT ... ON DUPLICATE KEY`
   是 MySQL 专有语法，SQLite 上必然抛错，而该中间件挂在所有 `/admin/*` 上），
   缺陷本身消失，那 2 个跳过随之删除；剩 `MigrationSmokeTest` 的建库用例按设计跳过。
4. ✅ **MySQL 失败集合查清了**，与推测的「数据隔离」无关：
   - `StockServiceTest` ×1 → 上述 `whereColumn` 真 bug，已修。
   - `StockSnapshotServiceTest` ×2 → 用例本身按设计只该在 SQLite 上跑，已跳过。
   - `ProductCategoryFeatureTest` ×4 → 早已不复现，当前 MySQL 上通过。

验收（本地 MariaDB 10.11 实测）：SQLite 106/616 全绿；MySQL 106 用例 555 断言
0 失败 0 错误、1 个跳过（Phase 4 时是 81 用例 363 断言 3 跳过；跳过集合从 3 降到
1 见第 3 项，不是新加的跳过）。两个驱动的跳过集合各有明确解释，不是「凑绿的跳过」。

复现命令：

```
DB_CONNECTION=mysql DB_DATABASE=laradmin_test ./vendor/bin/phpunit
```

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

**实际落地方式：没往 `static` job 里加 shell 断言，改由 `tests/Unit/ArchitectureTest.php`
在 phpunit job 里承担，三道都已覆盖。** 原因是第 1 项的朴素 `grep` 在这个仓库里会误判：
`app/Contracts/TaskNotification.php` 的 PHPDoc 注释里写着 `Modules\System\Services\NotificationService`
来解释契约的设计（注释是 `app/` 唯一提到模块命名空间的地方），
`grep -rn 'Modules\\' app` 会直接判红。所以扫描必须剥注释——那就是 PHP 了，
而 phpunit 本来就在跑 ArchitectureTest，没必要再写一份 shell 版。

已覆盖的三道：

| 断言 | 对应用例 |
|---|---|
| 1 内核纯净 | `test_kernel_does_not_depend_on_modules`（tokenizer 剥注释后扫 `app/`） |
| 2 无环依赖 | `test_module_dependencies_stay_within_allowlist`（白名单差集，结构上不可能成环）+ 两条定向用例 |
| 3 模块完备性 | `test_every_module_owns_routes_and_migrations` |

第 3 项故意不含 `tests/`：`modules/System/tests/` 目前只有空的 `Feature/` 目录，
断言会让 CI 因为一个已登记的已知缺口而长期亮红（见 2b 节）。
想让 `static` job 也快速失败的话，正确做法是抽一个不启动框架的扫描类
（`tests/support/`，靠 `autoload-dev` 的 `Tests\` 映射），
让脚本和 ArchitectureTest 共用——那样只有一份实现，不用维护两套。

## 七、执行顺序与风险

| 顺序 | 阶段 | 改动量 | 风险 | 前置 | 状态 |
|---|---|---|---|---|---|
| 1 | 1a 断环 | 3 文件 + 2 新类 + 2 新监听器 | 低 | — | ✅ |
| 2 | 1b 中间件归位 | 2 文件迁移 + 2 Provider | **中**（别名解析时机） | 1a | ✅ |
| 3 | 2a seeders 归位 | 5 文件迁移 | 低 | — | ✅ |
| 4 | 2b tests 归位 | 6 文件迁移 + 配置 | 中（快照路径引用） | — | ✅ |
| 5 | Phase 4 测试口径 | 修 1 个真 bug + 3 用例加跳过 + 注释纠偏 | 中 | 4 | ✅ |
| 6 | 3 前后端对齐 | 1 文件合并 + 2 引用点改写 | 低 | — | ✅ |
| — | 1c / 2c | 只登记不动 | — | — | ✅ 已登记 |

**不要并行做 1a 和 1b**：都改 Provider 和中间件链路，混在一起出问题难以定位。

每一步都跑：

```bash
./vendor/bin/phpunit                    # 106/616
php artisan route:list --json | wc -l   # 274
```

`tests/snapshots/routes.json` **不应变化**——路由表逐条等价是所有阶段的不变量。

## 八、完成判定

全部做完的判定条件：

```bash
# 1-3 架构三道（无环 / 内核纯净 / 模块完备性）          ✅ 已过
./vendor/bin/phpunit tests/Unit/ArchitectureTest.php   # 6 tests, 8 assertions
#   别用 grep 手验：app/Contracts/TaskNotification.php 的 PHPDoc 里提到
#   Modules\System 来解释设计，朴素 grep 会误判，必须剥注释（见第六节）

# 4. 前后端边界一致                                    ✅ 已过
ls frontend/src/api/*.js    # auth.js business.js system.js ↔ modules/{Auth,Business,System}

# 5. 不变量                                            ✅ 已过
./vendor/bin/phpunit && [ "$(git diff --stat tests/snapshots/routes.json)" = "" ]

# 6. 两个驱动的失败集合都有解释                        ✅ 已过
DB_CONNECTION=mysql DB_DATABASE=laradmin_test ./vendor/bin/phpunit   # 0 失败 0 错误 1 跳过
```

注：

- **第 2 条的排除口径**：`app/Contracts/TaskNotification.php` 的 PHPDoc 里出现 `Modules\System` 字样（说明实现方是谁），是注释不是 import。CI 断言必须按上式排除注释行，否则永远红灯。`config/laravels.php` 的 3 处 System 类引用是 laravel-s 的已登记例外，`config/` 本就不在该断言范围内。
- **第 3 条**：原式含 `[ -d modules/System/tests ]`，当时 System 无模块专属测试、目录未建，所以只校验已建的两个。**已补齐**：System 有了 `NotificationFeatureTest.php`；且 PHP 版守卫靠 `glob(modules/*)` 动态发现模块、对每个模块都要求 `routes/` + `database/migrations/` + `tests/`，新模块自动纳入，不需要单独加回。
- 前三条达成即可视为「全模块化」，2c 与 1c 是有意识保留的例外，不计入未完成项。**当前状态：全部阶段完成**（Phase 3 前后端边界、Phase 4 测试口径均已在 `6b3603d` 前后收尾，本文档原写「差 Phase 3 与 Phase 4 两项收尾」是收尾提交后漏改的残留句，2026-09-15 已更正）。
- **已修的坑（2026-09-15）**：`phpunit.xml` 声明的 `<directory>modules/System/tests</directory>` 指向 git 不跟踪的空目录，任何干净检出都会让 `./vendor/bin/phpunit` exit 2、零个测试执行。已加 `Feature/.gitkeep` 占位 + 两道守卫（见 2b）。占位后来被真实测试取代、`.gitkeep` 已删，两道守卫保留——它们防的是「声明了不存在的 testsuite 目录」，不是防「缺少测试」。
- **尚未做的**：System 的日志与配置域无模块专属测试（通知模块已补）；`config/` 未分区（2c，评估过不做）；System 模块的 Log / Config / Dictionary / Scheduled / Attachment / Upload / WebSocket 等控制器、Excel 导入导出、Artisan 命令与 Jobs 全部无测试覆盖。
- **已做的（2026-09-15）**：模块测试命名空间改为 `Tests\<M>\Feature` / `Tests\<M>\Unit`，`Tests\<M>\` 三条 autoload-dev 映射不再空转，`composer dump-autoload` 的 PSR-4 警告归零；新增 `test_test_classes_resolve_through_autoloader` 守卫（见 2b）。
