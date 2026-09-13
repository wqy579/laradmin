// 部署守卫：后端不可达时显示全屏遮罩，服务恢复后自动退出
// 自包含实现：不依赖 Vue 组件挂载，直接注入遮罩 DOM
// 触发源：WebSocket 断开/报错、HTTP 请求失败时启动探测；探测失败才显示遮罩

let deploying = false // 是否显示遮罩
let timer = null
let probing = false // 是否正在探测
let failCount = 0
let recoverCount = 0
let overlayEl = null

const HEALTH_URL = '/admin/healthz'
const CHECK_INTERVAL = 2000 // 每 2s 探测一次
const FAIL_SHOW = 1 // 连续失败 1 次即认为部署中
const RECOVER_THRESHOLD = 1 // healthz 成功 1 次即视为恢复（nginx 是最后恢复的服务）

function ensureOverlay() {
        if (overlayEl) return overlayEl
        const el = document.createElement('div')
        el.id = 'deploy-overlay'
        el.style.cssText =
                'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,0.85);' +
                'display:flex;align-items:center;justify-content:center;cursor:wait;'
        const box = document.createElement('div')
        box.style.cssText = 'text-align:center;color:#fff;'
        const spin = document.createElement('div')
        spin.style.cssText =
                'width:44px;height:44px;margin:0 auto;border:4px solid rgba(255,255,255,0.25);' +
                'border-top-color:#fff;border-radius:50%;animation:deploySpin 1s linear infinite;'
        const t = document.createElement('p')
        t.style.cssText = 'font-size:22px;font-weight:600;margin-top:18px;letter-spacing:1px;'
        t.textContent = '系统升级中，请稍后…'
        const s = document.createElement('p')
        s.style.cssText = 'color:rgba(255,255,255,0.72);margin-top:10px;font-size:13px;'
        s.textContent = '服务器正在更新，期间服务暂时中断，请勿关闭页面'
        box.appendChild(spin)
        box.appendChild(t)
        box.appendChild(s)
        el.appendChild(box)
        const style = document.createElement('style')
        style.id = 'deploy-overlay-style'
        style.textContent = '@keyframes deploySpin{to{transform:rotate(360deg)}}'
        document.head.appendChild(style)
        document.body.appendChild(el)
        overlayEl = el
        return el
}

function showOverlay() {
        deploying = true
        ensureOverlay().style.display = 'flex'
}

function hideOverlay() {
        deploying = false
        if (overlayEl) {
                overlayEl.parentNode && overlayEl.parentNode.removeChild(overlayEl)
                overlayEl = null
        }
        const style = document.getElementById('deploy-overlay-style')
        if (style) style.parentNode && style.parentNode.removeChild(style)
}

function check() {
        fetch(HEALTH_URL, { cache: 'no-store' })
                .then((res) => {
                        if (res.ok) {
                                failCount = 0
                                recoverCount += 1
                                // 一旦探测到服务恢复，立即隐藏遮罩
                                if (deploying && recoverCount >= RECOVER_THRESHOLD) {
                                        stop()
                                }
                        } else {
                                recoverCount = 0
                                failCount += 1
                                if (!deploying && failCount >= FAIL_SHOW) {
                                        showOverlay()
                                }
                        }
                })
                .catch(() => {
                        recoverCount = 0
                        failCount += 1
                        if (!deploying && failCount >= FAIL_SHOW) {
                                showOverlay()
                        }
                })
}

// 启动部署探测（由 WebSocket 断开/报错、请求失败触发）
export function startDeployProbe() {
        if (probing) return
        probing = true
        failCount = 0
        recoverCount = 0
        check()
        if (timer) clearInterval(timer)
        timer = setInterval(check, CHECK_INTERVAL)
}

// 停止探测并隐藏遮罩
export function stopDeployProbe() {
        hideOverlay()
        probing = false
        failCount = 0
        recoverCount = 0
        if (timer) {
                clearInterval(timer)
                timer = null
        }
}

export function isDeploying() {
        return deploying
}
