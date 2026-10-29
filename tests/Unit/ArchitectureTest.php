<?php

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 架构守卫：用静态文件扫描断言模块边界，防止依赖方向退化。
 *
 * 纯扫描、不连数据库，秒级完成，适合每次提交都跑。
 * 目标形态见 docs/MODULARIZATION.md：Auth 为底层身份模块，
 * Business / System 单向依赖 Auth，模块间无环。
 */
class ArchitectureTest extends TestCase
{
    /**
     * 模块间允许的依赖边（自底向上，key 形如 "System->Auth"）
     *
     * 分层（2026-10 拆分后）：
     *   L0  Auth      身份/权限，不出任何模块边
     *   L1  Stock     库存+商品主数据，**零模块依赖**
     *       Business  业务残部（员工/考勤/费用）
     *   L2  System    系统能力
     *   L3  Order     订单，依赖 L1 的商品/库存主数据
     *
     * 方向自底向上，没有任何边指向更高层，因此结构上不可能成环。
     * 每条登记的边都写明理由：能改走 App\Contracts 内核契约的就该改，
     * 改不动的（通常是共享主数据表）才登记白名单。
     *
     * 名单是动态发现模块后按边校验的：新增模块若不带任何模块依赖则直接通过，
     * 一旦引用了别的模块就必须显式登记这条边并写明理由，不会静默放行。
     */
    private const ALLOWED_MODULE_EDGES = [
        'Business->Auth',   // 菜单 Seeder 写 Auth 的 Permission；System->Auth 同理
        'System->Auth',
        'Business->Stock',  // BusinessDataSeeder 写商品/单位/仓库/车辆主数据
        'Business->Order',  // BusinessDataSeeder 写客户/供应商/线路主数据
        'Business->Ledger', // 财务查询视图（B1，BUG-10）只读引用 A 机 ledger 的凭证/分录，
        // 凭证录入/审核统一走 ledger，finance/voucher 与日记账仅聚合展示，同库同事务、
        // 跨模块只读主数据（与 Performance->Report、Order->Member 同理），抽契约收益低于耦合成本
        'Order->Stock',     // 订单/退货/发货引用 Stock 的商品与仓库主数据表，
        // 两侧同库同事务，抽契约要引入跨事务一致性负担，
        // 收益低于耦合成本，保留共享主数据引用
        'Order->Business',  // 拜访单登记拜访人，引用 Business 的员工表
        'Order->Auth',      // 订单审批/下单记录发起人，引用 Auth 的用户表
        'Stock->Order',     // 价格体系 PriceService 复用 Order 的促销算价引擎，
        // 促销价是取价第一优先级，同库同事务，抽契约收益低
        'Delivery->Order',  // 配货单关联销售订单(取明细/推进状态)，同库同事务
        'Delivery->Stock',  // 配货冻结/装车出库复用 StockService，同库同事务
        'Delivery->Business', // 配送员员工档案、客户地址回溯引用 Business 主数据
        'Delivery->Auth',   // 配送员(auth_user)、菜单/操作人引用 Auth
        'Office->Auth',     // 内部邮件/公司公告的收发双方与可见范围都落在 Auth 的用户与部门表上，
        // 办公模块自身不维护人员主数据，抽契约只会把一次联表变成跨模块调用
        'VanSales->Stock',  // 车销复用 Stock 的商品/仓库/车辆主数据 + 库存服务（车上仓
        //   就是 type=vehicle 的仓库，借还换货都要走 StockService 出入库），
        //   与 Order->Stock 同理：同库同事务，抽契约收益低于耦合成本
        'VanSales->Order',  // 车销单据的客户快照引用 Order 的 Customer 主数据表，
        //   与 Order->Business（拜访人）同理，跨模块只读主数据
        'BorrowReturn->Stock',  // 仓库借还货复用 StockService 出入库 + 报损单（stock_adjusts），
        //   与 VanSales->Stock 同理：同库同事务
        'BorrowReturn->Order',  // 借货转销售生成 Order 的 SalesOrder，引用 Customer 主数据
        'Exchange->Stock',  // 换货换出/换入走 StockService 出入库，与 VanSales->Stock 同理
        'Exchange->Order',  // 换货单客户快照引用 Order 的 Customer 主数据表
        'Dashboard->Stock',  // 大屏聚合读 Stock 的库存/商品/品牌主数据（含测试夹具构造商品/仓库）
        'Dashboard->Order',  // 大屏聚合读 Order 的销售单/客户主数据（含测试夹具构造客户）
        'Member->Order',  // 会员档案绑定客户（members.customer_id 1:0..1），引用 Order 的
        // Customer 主数据表；销售订单也落 member_id 快照，同库同事务，抽契约收益低
        'Member->Auth',   // 储值/兑换单据记录审核人，引用 Auth 的用户表（与 Order->Auth 同理）
        'Member->Stock',  // 积分兑换商品审核扣减商品库存，测试夹具构造 Stock 商品（与 Dashboard->Stock 同理）
        'Order->Member',  // 销售订单审核/红冲调 MemberService 结算储值与积分（settleOrder/refundOrder），
        // 与 Member->Order 互为双向：同一会员-订单关系的两端操作，改走内核契约需先抽 Customer/Member 契约
        'Ledger->Auth',   // 凭证/模板的制单人、审核人落 maker_id/auditor_id，belongsTo Auth 的
        // 用户表（与 Order->Auth、Member->Auth 同理）；总账自身不维护人员主数据，
        // 内核亦无 User 契约，抽契约只会把一次外键回显变成跨模块调用
        'Performance->Report', // 提成/利润/账龄导出复用 Report 的 ReportFilter::csv（BOM/表头/编码统一，
        //   与 Report 自己各报表控制器同一套）；app/Contracts 无导出内核契约，该类是无状态字符串工具，
        //   同库只读、抽契约收益低于耦合成本
        'Performance->Auth',    // 绩效菜单 Seeder 写 Auth 的 Permission 与角色授权（与 Business->Auth、
        //   System->Auth 完全同因：菜单数据本就落在 Auth 的权限表上）
        'Cash->Member',     // 积分发放审核（D5）要同事务加 members.points 并写 member_consume_logs 流水，
        //   与 Order->Member 同理：同一会员-单据关系的两端操作，同库同事务，
        //   app/Contracts 无 Member 内核契约，抽契约要引入跨事务一致性负担
        'Cash->Order',      // 收入上交款（D2）要把业务员代收的客户货款回写到销售订单：
        //   明细关联 sales_orders 并累加 paid_amount、重算 payment_status，
        //   同库同事务（确认动作与订单收款回写必须原子），与 BorrowReturn->Order（借货转销售）同理；
        //   客户/订单名是冗余快照，跨模块只读主数据，抽契约收益低于耦合成本
        'Cash->Auth',       // 上交款单确认人落 confirmed_by、积分发放单落 operator_id/audited_by，
        //   belongsTo Auth 的用户表回显姓名（与 Ledger->Auth、Order->Auth 同理）；
        //   出纳域不维护人员主数据，内核亦无 User 契约
        'Hr->Business',     // 工资单的基本工资取自员工档案 employees.base_salary（F4），
        //   薪酬域不维护人员主数据，与 Order->Business（拜访人）、Delivery->Business 同理
        'Hr->Order',        // 工资发放必须走既有付款单通道：调 Order 的 PayService 生成 pays
        //   （pay_type=2 其他付款）并回写 pay_id/pay_no，同库同事务（付款单与工资单状态必须原子），
        //   与 Cash->Order（代收货款回写）同理；重建一套付款单等于绕过资金主线
        'Hr->Performance',  // 提成列与提成流水只读引用 perf 的提成单（audited/paid 的 actual_amount），
        //   任务书要求「与 C 域提成数据口径一致、只读引用不改 perf」，
        //   同库只读主数据 + 对账接口按同一口径比对，抽契约收益低于耦合成本
        'Hr->Report',       // 工资表/提成流水导出复用 ReportFilter::csv，与 Performance->Report 完全同因
        'Agreement->Order', // 协议档案的客户下拉引用 Order 的 Customer 主数据表（与 Exchange->Order 同理），
        //   协议域不维护客户主数据，customer_name 是冗余快照
        'Agreement->Report', // 协议档案导出复用 ReportFilter::csv，与 Performance->Report 完全同因
    ];

    /**
     * 扫描目录下所有 PHP 文件，返回引用了 Modules\<module>\ 的文件路径
     *
     * 先剥掉注释再匹配：契约与文档注释里会提到模块命名空间来解释设计，
     * 那不是真实依赖。用 tokenizer 而不是正则，避免被字符串字面量误判。
     */
    private function refsTo(string $dir, string $module): array
    {
        $needle = 'Modules\\'.$module.'\\';
        $hits = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = $this->stripComments((string) file_get_contents($file->getPathname()));

            if (str_contains($content, $needle)) {
                $hits[] = $file->getPath();
            }
        }

        return array_values(array_unique($hits));
    }

    /** 剥掉单行注释与文档注释，保留字符串字面量 */
    private function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * 模块 → 模块的依赖边：key 形如 "System->Auth"，value 为引用源文件路径
     *
     * 模块清单由 glob 动态发现，因此新增模块时本用例会自动覆盖它，
     * 而不用同步改这份测试。
     */
    private function moduleEdges(): array
    {
        $modules = array_values(array_map(
            'basename',
            glob(base_path('modules/*'), GLOB_ONLYDIR) ?: []
        ));
        $edges = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('modules'), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(base_path().'/', '', $file->getPath());
            $content = $this->stripComments((string) file_get_contents($file->getPathname()));

            foreach ($modules as $from) {
                if (! str_starts_with($path, "modules/$from/")) {
                    continue;
                }

                foreach ($modules as $to) {
                    if ($from === $to || ! str_contains($content, "Modules\\$to\\")) {
                        continue;
                    }

                    $edges["$from->$to"][] = $path;
                }
            }
        }

        return $edges;
    }

    /**
     * Auth 是底层身份模块，不得反向依赖 System。
     *
     * 历史上 modules/Auth 的导入导出 Job 直接调用 System 的 NotificationService，
     * 与 System → Auth 的模型依赖构成环。现已改为走内核契约，这里锁住不再回流。
     */
    public function test_auth_module_does_not_depend_on_system(): void
    {
        $this->assertSame(
            [],
            $this->refsTo(base_path('modules/Auth'), 'System'),
            'modules/Auth 依赖了 Modules\System，模块间出现环。'
            .'发送通知请 type-hint App\Contracts\TaskNotification，'
            .'不要直接引用 System 的 Service 或 Model。'
        );
    }

    /**
     * Business 与 System 之间当前零引用，是三条边界里最干净的一条。
     * 守住它，业务模块就不会悄悄长出对系统模块的依赖。
     */
    public function test_business_module_does_not_depend_on_system(): void
    {
        $this->assertSame(
            [],
            $this->refsTo(base_path('modules/Business'), 'System'),
            'modules/Business 依赖了 Modules\System，模块间出现横向耦合。'
        );
    }

    /**
     * 所有模块间依赖边都必须落在白名单内。
     *
     * 上面两条只点名了 Auth↔System、Business↔System 两条边，本用例兜住其余所有
     * 组合（含 System→Business、任何指向 Auth 的反向边），并在结构上保证无环。
     * 两条定向用例保留不动：它们的提示更具体，指出该走哪个内核契约。
     */
    public function test_module_dependencies_stay_within_allowlist(): void
    {
        $edges = $this->moduleEdges();
        $unknown = array_values(array_diff(array_keys($edges), self::ALLOWED_MODULE_EDGES));

        $this->assertSame(
            [],
            $unknown,
            '模块间出现了不在白名单里的依赖边。'
            .'当前边：'.implode(', ', array_keys($edges))
            .'；允许：'.implode(', ', self::ALLOWED_MODULE_EDGES)
            ."\n有模块引用了另一模块，先问一句：能不能改走 App\Contracts 里的内核契约？"
            .'不能时在 ALLOWED_MODULE_EDGES 里登记这条边并写明理由。'
        );
    }

    /**
     * 共享内核不得依赖任何模块（依赖方向只能向内）。
     *
     * 已登记的例外：bootstrap/app.php 的 stock.snapshot 别名指向 Business 的
     * StockSnapshotMiddleware，但作用域是「全部管理端请求」，属横切关注点，
     * 留在内核信封更诚实——所以本用例只查 app/，不含 bootstrap/。
     */
    public function test_kernel_does_not_depend_on_modules(): void
    {
        $bad = [];

        foreach (['Auth', 'Business', 'System', 'Stock', 'Order'] as $module) {
            foreach ($this->refsTo(base_path('app'), $module) as $file) {
                $bad[] = "Modules\\$module  ←  $file";
            }
        }

        $this->assertSame(
            [],
            $bad,
            'app/ 依赖了模块代码（依赖倒置）。共享内核不得 import Modules\\：'
            ."\n".implode("\n", $bad)
            .'模块自有中间件请放到 modules/<M>/Http/Middleware/；'
            .'中间件别名仍统一在 bootstrap/app.php 的 middlewareAliases() 里注册'
            .'（别名表是全局中间件词汇表，属配置而非代码耦合）。'
            .'config/ 与 bootstrap/ 里指向模块类的配置例外已逐处登记，见 docs/MODULARIZATION.md 第三节。'
        );
    }

    /**
     * 每个模块必须自持路由与迁移目录。
     * 缺一项说明模块还是「挂在集中目录里的命名空间」，不算模块化。
     *
     * tests/ 只要求「目录存在」，不要求里面有 .php。System 曾经只有空 Feature/
     * 目录 + .gitkeep 占位——占位不是风格问题，phpunit.xml 已声明该 testsuite，
     * 目录缺失会让整个 phpunit run 中止（见 test_declared_testsuite_directories_exist），
     * 现在有 NotificationFeatureTest 了。不要求有 .php 是因为「哪个模块算有测试」
     * 是产品决策，本守卫只挡结构性缺失。
     */
    public function test_every_module_owns_routes_and_migrations(): void
    {
        $modules = array_values(array_map(
            'basename',
            glob(base_path('modules/*'), GLOB_ONLYDIR) ?: []
        ));

        $this->assertNotEmpty($modules, 'modules/ 下没有任何模块目录');

        $missing = [];
        foreach ($modules as $module) {
            foreach (['routes', 'database/migrations'] as $sub) {
                $path = base_path("modules/$module/$sub");
                if (! is_dir($path) || empty(glob("$path/*.php"))) {
                    $missing[] = "modules/$module/$sub";
                }
            }

            if (! is_dir(base_path("modules/$module/tests"))) {
                $missing[] = "modules/$module/tests";
            }
        }

        $this->assertSame(
            [],
            $missing,
            '以下模块缺少自持目录或目录为空：'.implode(', ', $missing)
        );
    }

    /**
     * 每个测试类的声明命名空间必须能被 composer 解析回它自己所在的那个文件。
     *
     * 回归点：测试按模块归位后（Phase 2b）文件挪到了 modules/<M>/tests/，但类里的
     * namespace 仍是原样搬运的 Tests\Feature / Tests\Unit。结果三条
     * Tests\<M>\ autoload-dev 映射一直是空转的——composer dump-autoload 每次刷十几个
     * "does not comply with psr-4 autoloading standard" 警告，而
     * Tests\Business\Feature\StockFeatureTest 这类 FQCN 根本解析不到文件。
     *
     * PHPUnit 按目录扫描、不依赖命名空间，所以测试照跑、套件照绿，问题一直是静默的：
     * 只有 IDE 导航、覆盖率工具、按类名 filter 才会踩到。所以断言不能只看「类存在」，
     * 还要看「解析到的是不是这一个文件」——否则只要存在同名类就会假通过。
     */
    public function test_test_classes_resolve_through_autoloader(): void
    {
        $files = [];
        $roots = array_merge(
            [base_path('tests')],
            glob(base_path('modules/*/tests'), GLOB_ONLYDIR) ?: []
        );

        foreach ($roots as $root) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root)
            );
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
                    $files[] = $f;
                }
            }
        }

        $this->assertNotEmpty($files, '没扫到任何 *Test.php');

        $bad = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file->getPathname());
            $ns = preg_match('/^namespace\s+([^;]+);/m', $src, $m) ? $m[1] : '';
            $cls = preg_match('/^\s*(?:abstract\s+)?class\s+(\w+)/m', $src, $m) ? $m[1] : '';

            $fqcn = ($ns !== '' ? $ns.'\\' : '').$cls;

            if (! class_exists($fqcn)) {
                $bad[] = "$fqcn  →  无法解析（namespace 与文件路径不符，或 composer.json 缺映射）";

                continue;
            }

            $resolved = (new \ReflectionClass($fqcn))->getFileName();
            if ($resolved !== $file->getPathname()) {
                $bad[] = "$fqcn  →  解析到 "
                    .str_replace(base_path().'/', '', $resolved)
                    .'，不是它自己所在的 '
                    .str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $bad,
            '以下测试类的声明命名空间与 composer 映射不一致：'.implode("\n", $bad)
            ."\n模块测试的命名空间必须是 Tests\\<M>\\Feature / Tests\\<M>\\Unit，"
            .'这样 tests/ 与 modules/<M>/tests/ 两处都不会解析到同一个类名。'
        );
    }

    /**
     * phpunit.xml 声明的每个 <directory> 必须真实存在。
     *
     * 这是踩过的坑：modules/System/tests 当初只有本地空目录、git 不跟踪，
     * 检出后目录不存在，PHPUnit 11 不是跳过那一个 testsuite，而是中止整个 run
     * （exit 2，零个测试执行）。于是 CI 全红，而且红的不是任何一条断言。
     * 空目录占位用 .gitkeep（无 .php 后缀，不会被当成测试收进去）。
     */
    public function test_declared_testsuite_directories_exist(): void
    {
        $xml = (string) file_get_contents(base_path('phpunit.xml'));

        preg_match_all('/<directory>(.*?)<\/directory>/', $xml, $matches);
        $declared = array_values(array_unique(
            array_map('trim', $matches[1] ?? [])
        ));

        $this->assertNotEmpty($declared, 'phpunit.xml 里没扫到任何 <directory>');

        $missing = array_values(array_filter(
            $declared,
            fn ($rel) => ! is_dir(base_path($rel))
        ));

        $this->assertSame(
            [],
            $missing,
            'phpunit.xml 声明了不存在的目录：'.implode(', ', $missing)
            .'（PHPUnit 遇到声明的目录缺失会中止整个 run，不是跳过该 testsuite。'
            .'空目录 git 不跟踪，放一个 .gitkeep 占位。）'
        );
    }
}
