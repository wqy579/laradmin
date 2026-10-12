<script setup>
import { ref, watch, onMounted } from 'vue'
import businessApi from '../../api/business'
import { useUserStore } from '../../stores/modules/user'

const userStore = useUserStore()

const visible = ref(false)
const notice = ref(null)
const messageId = ref(null)
// 请求在途标志：登录切换与登出会重复触发 watcher，避免并发请求把弹窗抢成空
let fetching = false

/**
 * 取「登录弹窗公告」。
 *
 * 触发时机：登录成功后（token + userInfo 就绪）拉一次。后端只回「当前用户最新
 * 一条未读的弹窗公告」——即发布公告时勾了 is_popup 且已发布、且对本用户可见
 * 的公告，对应的消息仍未读。没有就返回 notice: null，此时静默不弹，不打扰用户。
 *
 * 401 / 网络异常时静默降级，绝不抛错——弹窗是增强体验，不能因接口失败打断主流程。
 * 关键防御：res 可能是 undefined（拦截器在某些异常态返回 undefined）、res.data 可能
 * 为 null，三者任一为空都不进入赋值/渲染，避免把 undefined 传给后续 el-dialog 渲染。
 *
 * 关闭时把对应消息标记已读，形成闭环：本条弹窗不会再弹，铃铛/消息中心的未读数也
 * 同步减少——未读态完全复用 messages 表，不引入第二套状态。
 */
async function fetchPending() {
	if (fetching) return
	fetching = true
	try {
		const res = await businessApi.notice.popupPending.get()
		// res 必须是对象且有 code 字段才算合法响应；拦截器 reject 时 res 为 undefined，
		// 直接 res.code 会二次抛错。data 为 null（无弹窗公告）时也不弹。
		if (res && typeof res === 'object' && res.code === 200 && res.data && res.data.notice) {
			notice.value = res.data.notice
			messageId.value = res.data.message_id
			visible.value = true
		}
	} catch (e) {
		// 静默降级：401/网络异常都不打断主流程，也不把 e 往外抛
		// （外抛会被 Vue 的 async error handling 捕获，在 appContext 过渡期触发
		// Object.create(undefined) 渲染崩溃）
	} finally {
		fetching = false
	}
}

/**
 * 确认弹窗：标记该公告消息已读并关闭。
 *
 * 幂等：点「知道了」会先把 visible 置 false，从而触发 el-dialog 的 @close，
 * @close 又回到这里。这里先清空 messageId 再发请求，第二次进入时已无 id 可发，
 * 不会重复标已读。
 */
async function handleAcknowledge() {
	visible.value = false
	notice.value = null

	const id = messageId.value
	messageId.value = null
	if (!id) return

	try {
		await businessApi.messages.read.post(id)
	} catch (e) {
		// 标记失败不影响用户继续操作，下次登录会再弹一次；同理不外抛
	}
}

// 登录成功（token 与用户信息就绪）时拉一次弹窗公告；登出时复位状态。
//
// 不用 immediate: true——immediate 会在组件 setup 阶段同步触发请求，此时应用
// 尚未完成挂载、router 未就绪；若此时 token 失效触发 401 → 拦截器 logout →
// router.push('/login')，根组件正处于挂载过渡期，appContext.provides 可能不完整，
// 正在渲染的组件会撞 Object.create(undefined) 崩溃。改用 onMounted 首次拉取，
// 此时应用上下文已就绪，401 跳转安全。
onMounted(() => {
	if (userStore.token && userStore.userInfo?.id) {
		fetchPending()
	}
})

// 登录/登出切换时（用户在已挂载的应用里登录或退出）再触发
watch(
	() => [userStore.token, userStore.userInfo?.id],
	([token, userId], [prevToken]) => {
		// 仅在「从未登录 → 已登录」的上升沿拉取；登出时复位状态即可
		if (token && userId && (!prevToken || !prevToken)) {
			fetchPending()
		} else if (!token) {
			visible.value = false
			notice.value = null
			messageId.value = null
		}
	}
)
</script>

<template>
	<el-dialog
		v-model="visible"
		width="500px"
		append-to-body
		:close-on-click-modal="false"
		:show-close="true"
		@close="handleAcknowledge"
		class="announcement-popup"
	>
		<template #header>
			<div class="announcement-popup__header">系统公告</div>
		</template>

		<div class="announcement-popup__body">
			<h3 class="announcement-popup__title">{{ notice?.title }}</h3>
			<div v-if="notice?.author_name || notice?.created_at" class="announcement-popup__meta">
				<span v-if="notice?.author_name">{{ notice.author_name }}</span>
				<span v-if="notice?.created_at">{{ notice.created_at }}</span>
			</div>
			<div class="announcement-popup__content">{{ notice?.content || '（无正文）' }}</div>
		</div>

		<template #footer>
			<button class="announcement-popup__btn" @click="handleAcknowledge">知道了</button>
		</template>
	</el-dialog>
</template>

<style scoped>
/* 顶栏：蓝色 #409EFF、白字、16px 加粗、居中、高 50px */
.announcement-popup__header {
	height: 50px;
	line-height: 50px;
	text-align: center;
	font-size: 16px;
	font-weight: 700;
	color: #fff;
	background: #409EFF;
}

/* 内容区：padding 20px，正文 14px #303133 行高 1.6 */
.announcement-popup__body {
	padding: 20px;
}

.announcement-popup__title {
	margin: 0 0 8px;
	font-size: 16px;
	font-weight: 600;
	color: #303133;
	line-height: 1.6;
}

.announcement-popup__meta {
	display: flex;
	gap: 14px;
	margin-bottom: 12px;
	font-size: 12px;
	color: #909399;
}

.announcement-popup__content {
	font-size: 14px;
	color: #303133;
	line-height: 1.6;
	white-space: pre-wrap;
	word-break: break-word;
	/* 公告可能很长，超出一屏时弹窗内滚动而非把整页撑高 */
	max-height: 60vh;
	overflow-y: auto;
}

/* 底部按钮栏：高 60px，上边框 #EBEEF5，按钮居中 */
.announcement-popup :deep(.el-dialog__footer) {
	height: 60px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-top: 1px solid #EBEEF5;
	padding: 0 20px;
}

.announcement-popup__btn {
	height: 36px;
	width: 100px;
	padding: 0;
	border: none;
	border-radius: 4px;
	background: #409EFF;
	color: #fff;
	font-size: 14px;
	cursor: pointer;
	transition: background-color 0.2s;
}
.announcement-popup__btn:hover {
	background: #66b1ff;
}
.announcement-popup__btn:active {
	background: #3a8ee6;
}

/* 弹窗本体：圆角 8px、阴影 */
.announcement-popup {
	--el-dialog-border-radius: 8px;
	--el-dialog-box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}
.announcement-popup .el-dialog {
	border-radius: 8px;
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
	overflow: hidden;
}
</style>
