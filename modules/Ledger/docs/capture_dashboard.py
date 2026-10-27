#!/usr/bin/env python3
"""
机器 A batch-1 交付：A8 总账驾驶舱浏览器实拍

前置（参考 DELIVER.md 第五节）：
  1. 前端构建产物已拷到 public/admin/
  2. sqlite 库已 migrate:fresh --seed（含 ledger.dashboard 菜单 + admin 账号）
  3. php -S 服务已起：APP_KEY=... DB_CONNECTION=sqlite DB_DATABASE=... \
        CACHE_STORE=file php -S 127.0.0.1:8001 /workspace/laradmin/router.php
  4. 若登录遇 429 限流，先清 storage/framework/cache/data

产出：modules/Ledger/docs/screenshots/18-dashboard.png
      modules/Ledger/docs/screenshots/19-dashboard-menu.png
"""
from playwright.sync_api import sync_playwright
import os, sys

BASE = 'http://127.0.0.1:8001'
SHOTS = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'screenshots')
os.makedirs(SHOTS, exist_ok=True)

errors = []

def run():
    with sync_playwright() as p:
        b = p.chromium.launch(
            executable_path='/usr/bin/chromium',
            headless=True,
        )
        pg = b.new_page(viewport={'width': 1440, 'height': 900})
        try:
            # 1. 登录（admin/admin888，AuthSeeder 预置 super_admin）
            pg.goto(BASE + '/admin/', wait_until='networkidle')
            pg.wait_for_timeout(1000)
            pg.fill('input[placeholder="用户名"]', 'admin')
            pg.fill('input[placeholder="密码"]', 'admin888')
            pg.click('button:has-text("登 录")')
            pg.wait_for_timeout(2500)

            if 'login' in pg.url:
                errors.append('登录失败，仍在登录页')
                pg.screenshot(path=os.path.join(SHOTS, '18-login-failed.png'))
                return False

            # 2. 侧栏特写：总账管理展开，含「总账驾驶舱」子菜单
            pg.goto(BASE + '/admin/', wait_until='networkidle')
            pg.wait_for_timeout(2500)
            # 点击「总账管理」展开子菜单
            try:
                pg.click('text=总账管理', timeout=3000)
                pg.wait_for_timeout(800)
            except Exception:
                pass
            pg.screenshot(path=os.path.join(SHOTS, '19-dashboard-menu.png'), full_page=False)

            # 3. 总账驾驶舱页面（4 统计卡 + 最近凭证 + 穿透）
            pg.evaluate('window.location.hash = "#/ledger/dashboard"')
            pg.wait_for_timeout(3000)
            # 移除部署提示浮层，避免遮挡统计卡
            pg.evaluate("document.querySelector('#deploy-overlay')?.remove()")
            pg.wait_for_timeout(500)
            pg.screenshot(path=os.path.join(SHOTS, '18-dashboard.png'), full_page=True)

            # 4. 穿透验证：点击「凭证总数」卡应跳转到 /ledger/voucher
            pg.evaluate('window.location.hash = "#/ledger/dashboard"')
            pg.wait_for_timeout(2500)
            pg.evaluate("document.querySelector('#deploy-overlay')?.remove()")
            pg.wait_for_timeout(300)
            try:
                pg.click('text=凭证总数', timeout=3000)
                pg.wait_for_timeout(2000)
                if 'voucher' not in pg.url:
                    errors.append('凭证总数卡未穿透到凭证页，仍停留在 %s' % pg.url)
                else:
                    pg.screenshot(path=os.path.join(SHOTS, '20-dashboard-drill.png'), full_page=False)
            except Exception as e:
                errors.append('穿透点击失败: %s' % e)

        finally:
            b.close()
    return len(errors) == 0

if __name__ == '__main__':
    ok = run()
    if errors:
        print('FAIL errors=%s' % errors)
        sys.exit(1)
    print('DONE')