#!/usr/bin/env python3
"""
盘点工具巡检守护脚本
使用锁机制检测新上传：比较当前批次 ID 与锁文件中的批次 ID
如果批次 ID 变化，说明有新上传，解锁并运行盘点工具
"""

import json
import os
import subprocess
import sys
import time
from pathlib import Path
from datetime import datetime

LOCK_FILE = Path("/workspace/.monkeycode/scan.lock")
API_URL = "https://q.qjwykj.com/public/index.php/upload-check?token=wqy-pandian-monitor-2026"
PANDIAN_SCRIPT = "/workspace/盘点工具.py"
CHECK_INTERVAL = 1800  # 30 分钟检查一次
LOG_FILE = Path("/workspace/.monkeycode/pandian_monitor.log")


def log(msg):
    """输出日志到文件和标准输出"""
    timestamp = datetime.now().isoformat()
    line = f"[{timestamp}] {msg}"
    print(line)
    try:
        with open(LOG_FILE, 'a') as f:
            f.write(line + '\n')
    except:
        pass


def read_lock():
    """读取锁文件，返回上次记录的批次信息"""
    if not LOCK_FILE.exists():
        return None
    try:
        with open(LOCK_FILE, 'r') as f:
            return json.load(f)
    except Exception as e:
        log(f"读取锁文件失败: {e}")
        return None


def write_lock(batch_id, batch_name, pandian_user):
    """写入锁文件，记录已处理的批次信息"""
    try:
        LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
        data = {
            "batch_id": batch_id,
            "batch_name": batch_name,
            "pandian_user": pandian_user,
            "last_check": datetime.now().isoformat(),
            "updated_at": datetime.now().isoformat()
        }
        with open(LOCK_FILE, 'w') as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        return True
    except Exception as e:
        log(f"写入锁文件失败: {e}")
        return False


def check_upload():
    """检查 API 获取当前批次信息"""
    try:
        result = subprocess.run(
            ["curl", "-s", API_URL],
            capture_output=True,
            text=True,
            timeout=10
        )
        if result.returncode != 0:
            log(f"API 请求失败: {result.stderr}")
            return None
        data = json.loads(result.stdout)
        if not data.get("success"):
            log("API 返回 success=false")
            return None
        return data
    except Exception as e:
        log(f"检查上传状态异常: {e}")
        return None


def run_pandian_tool(pandian_user):
    """运行盘点工具"""
    log(f"检测到新上传，开始运行盘点工具...")
    log(f"  盘点员: {pandian_user['name']} (userid={pandian_user['userid']})")
    log(f"  批次: {pandian_user['batch_name']} ({pandian_user['dp']})")
    
    # 清理残留进程
    log("  清理残留浏览器进程...")
    subprocess.run(['pkill', '-f', 'geckodriver'], capture_output=True)
    subprocess.run(['pkill', '-f', 'firefox-esr'], capture_output=True)
    time.sleep(1)
    
    try:
        # 根据盘点员选择用户编号
        user_choice = "1" if pandian_user['userid'] == '84522' else "2"
        
        env = os.environ.copy()
        env['TERM'] = 'dumb'
        env['DISPLAY'] = ':99'
        
        proc = subprocess.Popen(
            ['xvfb-run', '--auto-servernum', 'python3', PANDIAN_SCRIPT],
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            env=env
        )
        
        # 发送用户选择
        proc.stdin.write(user_choice + '\n')
        proc.stdin.flush()
        
        # 等待完成（最多 10 分钟）
        try:
            stdout, _ = proc.communicate(timeout=600)
            # 清理残留进程
            subprocess.run(['pkill', '-f', 'geckodriver'], capture_output=True)
            subprocess.run(['pkill', '-f', 'firefox-esr'], capture_output=True)
            # 输出最后 500 字符
            last_output = stdout[-500:] if len(stdout) > 500 else stdout
            log(f"  盘点完成:\n{last_output}")
            return True
        except subprocess.TimeoutExpired:
            proc.kill()
            # 清理残留进程
            subprocess.run(['pkill', '-9', '-f', 'geckodriver'], capture_output=True)
            subprocess.run(['pkill', '-9', '-f', 'firefox-esr'], capture_output=True)
            log(f"  盘点工具超时（10分钟），已终止")
            return False
            
    except Exception as e:
        log(f"  运行盘点工具失败: {e}")
        # 清理残留进程
        subprocess.run(['pkill', '-f', 'geckodriver'], capture_output=True)
        subprocess.run(['pkill', '-f', 'firefox-esr'], capture_output=True)
        return False


def main():
    log("=" * 50)
    log("盘点巡检守护启动")
    log(f"  锁文件: {LOCK_FILE}")
    log(f"  检查间隔: {CHECK_INTERVAL} 秒")
    log(f"  日志文件: {LOG_FILE}")
    log("=" * 50)
    
    while True:
        try:
            # 检查 API
            api_data = check_upload()
            if api_data is None:
                log("API 检查失败，跳过本轮")
                time.sleep(CHECK_INTERVAL)
                continue
            
            current_batch_id = api_data['latest_batch']['id']
            current_batch_name = api_data['latest_batch']['batch_name']
            current_dp = api_data['latest_batch']['dp']
            current_user = api_data['pandian_user']
            
            # 读取锁
            lock_data = read_lock()
            
            if lock_data is None:
                # 首次运行，记录当前状态
                log(f"首次检测，记录批次 {current_batch_id}: {current_batch_name}")
                write_lock(current_batch_id, current_batch_name, {
                    'name': current_user['name'],
                    'userid': current_user['userid'],
                    'batch_name': current_batch_name,
                    'dp': current_dp
                })
            elif lock_data['batch_id'] != current_batch_id:
                # 批次 ID 变化，有新上传！
                log(f"检测到新上传!")
                log(f"  上次批次: {lock_data['batch_id']} - {lock_data.get('batch_name', 'unknown')}")
                log(f"  当前批次: {current_batch_id} - {current_batch_name} ({current_dp})")
                
                # 更新锁
                write_lock(current_batch_id, current_batch_name, {
                    'name': current_user['name'],
                    'userid': current_user['userid'],
                    'batch_name': current_batch_name,
                    'dp': current_dp
                })
                
                # 运行盘点工具
                success = run_pandian_tool({
                    'name': current_user['name'],
                    'userid': current_user['userid'],
                    'batch_name': current_batch_name,
                    'dp': current_dp
                })
                
                if success:
                    log("盘点完成")
                else:
                    log("盘点失败，将在下次检查时重试")
            else:
                # 无新上传
                log(f"无新上传 (批次 {current_batch_id})，保持锁定")
            
        except KeyboardInterrupt:
            log("收到中断信号，退出守护进程")
            sys.exit(0)
        except Exception as e:
            log(f"意外错误: {e}")
        
        # 等待下次检查
        log(f"下次检查: {CHECK_INTERVAL} 秒后...")
        time.sleep(CHECK_INTERVAL)


if __name__ == "__main__":
    main()

