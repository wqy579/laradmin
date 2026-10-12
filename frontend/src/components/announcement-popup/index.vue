<script setup>
import { ref, watch } from 'vue'
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
 * 触发时机：登录成功后（token + userInfo 就绪）由 App.vue 的 watcher 调一次。
 * 后端只回「当前用户最新一条未读的弹窗公告」——即发布公告时勾了 is_popup 且
 * 已发布、且对本用户可见的公告，对应的消息仍未读。没有就返回 notice: null，
 * 此时静默不弹，不打扰用户。
 *
 * 关闭时把对应消息标记已读，形成闭环：本条弹窗不会再弹，铃铛/消息中心的
 * 未读数也同步减少——未读态完全复用 messages 表，不引入第二套状态。
 */
async function fetchPending() {
	if (fetching) return
	fetching = true
	try {
		const res = await businessApi.notice.popupPending.get()
		if (res.code === 200 && res.data?.notice) {
			notice.value = res.data.notice
			messageId.value = res.data.message_id
			visible.value = true
		}
	} catch (e) {
		// 弹窗取不到不应阻断任何操作，静默降级
		console.error('获取登录弹窗公告失败:', e)
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
		// 标记失败不影响用户继续操作，下次登录会再弹一次
		console.error('标记公告已读失败:', e)
	}
}

// 登录成功（token 与用户信息就绪）时拉一次弹窗公告；登出时复位状态。
// 与 App.vue 里初始化 WebSocket 的触发条件保持一致，登录瞬间一起执行。
watch(
	() => [userStore.token, userStore.userInfo?.id],
	([token, userId]) => {
		if (token && userId) {
			fetchPending()
		} else {
			visible.value = false
			notice.value = null
			messageId.value = null
		}
	},
	{ immediate: true }
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
