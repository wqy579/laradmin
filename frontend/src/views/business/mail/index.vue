<template>
	<div class="rp-page">
		<div class="rp-row">
			<!-- 左侧 200px 菜单 -->
			<div class="rp-aside">
				<div
					v-for="m in MENUS"
					:key="m.key"
					class="rp-aside-item"
					:class="{ 'is-active': activeMenu === m.key }"
					@click="onMenu(m.key)"
				>
					<el-icon><component :is="m.icon" /></el-icon>
					<span>{{ m.label }}</span>
					<el-badge
						v-if="m.key === 'inbox' && unreadCount > 0"
						:value="unreadCount"
						:max="99"
						class="rp-aside-badge"
					/>
				</div>
			</div>

			<div class="rp-main">
				<!-- 写邮件 -->
				<div v-if="activeMenu === 'compose'" class="mail-compose">
					<el-form label-width="72px" size="small">
						<el-form-item label="收件人">
							<el-select
								v-model="form.to_ids"
								multiple
								filterable
								clearable
								style="width: 100%"
								placeholder="选择收件人（可按姓名搜索）"
							>
								<el-option v-for="u in users" :key="u.value" :label="u.label" :value="u.value" />
							</el-select>
						</el-form-item>
						<el-form-item label="抄送">
							<el-select
								v-model="form.cc_ids"
								multiple
								filterable
								clearable
								style="width: 100%"
								placeholder="选择抄送人（可留空）"
							>
								<el-option v-for="u in users" :key="u.value" :label="u.label" :value="u.value" />
							</el-select>
						</el-form-item>
						<el-form-item label="主题">
							<el-input v-model="form.subject" placeholder="邮件主题" maxlength="200" show-word-limit />
						</el-form-item>
						<el-form-item label="内容">
							<el-input
								v-model="form.content"
								type="textarea"
								:rows="12"
								placeholder="邮件正文"
								resize="vertical"
							/>
						</el-form-item>
					</el-form>
					<div class="mail-compose-actions">
						<el-button class="rp-btn-primary" :loading="sending" @click="send('send')">发送</el-button>
						<el-button size="small" :loading="sending" @click="send('draft')">存草稿</el-button>
						<el-button size="small" @click="resetForm">清空</el-button>
					</div>
				</div>

				<!-- 帮助视频 -->
				<div v-else-if="activeMenu === 'help'" class="rp-help">
					<h3>内部邮件使用说明</h3>
					<ol>
						<li>写邮件：选择收件人与抄送人，填写主题与正文后点「发送」；未写完可先「存草稿」。</li>
						<li>收件箱：收到站内邮件，未读邮件加粗显示，进入菜单可看到未读数量。</li>
						<li>发件箱：自己已发出的邮件，可查看收件人的阅读情况。</li>
						<li>回收站：删除的邮件先进回收站，可从回收站恢复或彻底删除。</li>
						<li>批量操作：列表支持勾选后批量标记已读 / 未读 / 删除。</li>
					</ol>
				</div>

				<!-- 邮件列表 -->
				<template v-else>
					<div class="rp-toolbar">
						<el-input
							v-model="keyword"
							placeholder="搜索主题 / 正文"
							size="small"
							style="width: 240px"
							clearable
							@keyup.enter="onSearch"
							@clear="onSearch"
						/>
						<el-button size="small" class="rp-btn-primary" @click="onSearch">搜索</el-button>
						<span class="rp-spacer"></span>
						<el-button size="small" :disabled="!selected.length" @click="batch('batchRead')">标记已读</el-button>
						<el-button size="small" :disabled="!selected.length" @click="batch('batchUnread')">标记未读</el-button>
						<el-button size="small" type="danger" plain :disabled="!selected.length" @click="batch('batchDelete')">
							删除
						</el-button>
						<el-button size="small" circle title="刷新" @click="fetchList">
							<el-icon><Refresh /></el-icon>
						</el-button>
					</div>

					<div class="mail-table">
						<el-table
							v-loading="loading"
							:data="list"
							size="small"
							border
							height="100%"
							:row-style="{ height: '48px' }"
							:header-cell-style="{ height: '40px', background: '#f5f7fa', color: '#909399', fontWeight: 700, fontSize: '13px' }"
							@selection-change="selected = $event"
							@row-click="openMail"
						>
							<el-table-column type="selection" width="44" align="center" />
							<el-table-column label="" width="40" align="center">
								<template #default="{ row }">
									<span v-if="showReadState && !row.is_read" class="mail-dot" title="未读"></span>
									<el-icon v-else-if="showReadState" class="mail-read"><Message /></el-icon>
								</template>
							</el-table-column>
							<el-table-column :label="activeMenu === 'sent' || activeMenu === 'draft' ? '收件人' : '发件人'" width="140">
								<template #default="{ row }">
									<span>{{ activeMenu === 'sent' || activeMenu === 'draft' ? row.to_names || '—' : row.from_name }}</span>
								</template>
							</el-table-column>
							<el-table-column prop="subject" label="主题" min-width="260" show-overflow-tooltip>
								<template #default="{ row }">
									<span :class="{ 'mail-unread': showReadState && !row.is_read }">{{ row.subject || '(无主题)' }}</span>
									<el-icon v-if="row.has_attachment" class="mail-attach"><Paperclip /></el-icon>
								</template>
							</el-table-column>
							<el-table-column prop="content" label="摘要" min-width="220" show-overflow-tooltip>
								<template #default="{ row }">
									<span class="mail-digest">{{ digest(row.content) }}</span>
								</template>
							</el-table-column>
							<el-table-column prop="sent_at" label="时间" width="170" align="center" />
							<el-table-column label="操作" width="150" align="center" fixed="right">
								<template #default="{ row }">
									<el-button link size="small" @click.stop="openMail(row)">查看</el-button>
									<el-button
										v-if="showReadState"
										link
										size="small"
										@click.stop="toggleRead(row)"
									>
										{{ row.is_read ? '标未读' : '标已读' }}
									</el-button>
									<el-button link size="small" type="danger" @click.stop="removeMail(row)">删除</el-button>
								</template>
							</el-table-column>
						</el-table>
					</div>

					<div class="rp-grid-page">
						<el-pagination
							background
							size="small"
							layout="total, sizes, prev, pager, next"
							:total="total"
							:page-size="pageSize"
							:page-sizes="[10, 20, 30, 50, 100]"
							:current-page="page"
							@current-change="page = $event; fetchList()"
							@update:page-size="pageSize = $event; page = 1; fetchList()"
						/>
					</div>
				</template>
			</div>
		</div>

		<!-- 邮件详情 -->
		<el-dialog v-model="detailVisible" :title="current.subject || '(无主题)'" width="800px" top="6vh">
			<div v-loading="detailLoading" class="mail-detail">
				<div class="mail-detail-meta">
					<span>发件人：{{ current.from_name }}</span>
					<span>收件人：{{ current.to_names || '—' }}</span>
					<span v-if="current.cc_names">抄送：{{ current.cc_names }}</span>
					<span>时间：{{ current.sent_at || current.created_at }}</span>
				</div>
				<div class="mail-detail-body">{{ current.content || '（无正文）' }}</div>
			</div>
			<template #footer>
				<el-button size="small" @click="detailVisible = false">关闭</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh, Message, Paperclip, EditPen, FolderOpened, Promotion, Delete, VideoPlay } from '@element-plus/icons-vue'
import businessApi from '@/api/business'

// icon 直接放组件对象：boot.js 全局注册的是带 ElIcon 前缀的名字，
// 这里用对象既能避开前缀约定，也能让打包器静态分析到引用。
const MENUS = [
	{ key: 'compose', label: '写邮件', icon: EditPen },
	{ key: 'inbox', label: '收件箱', icon: FolderOpened },
	{ key: 'sent', label: '发件箱', icon: Promotion },
	{ key: 'trash', label: '回收站', icon: Delete },
	{ key: 'help', label: '帮助视频', icon: VideoPlay },
]

const activeMenu = ref('inbox')
const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const keyword = ref('')
const selected = ref([])
const users = ref([])
const unreadCount = ref(0)

const detailVisible = ref(false)
const detailLoading = ref(false)
const current = ref({})
const sending = ref(false)
const form = reactive({ to_ids: [], cc_ids: [], subject: '', content: '', attachments: [] })

/** 只有收件箱 / 回收站有「已读未读」语义，发件箱没有 */
const showReadState = computed(() => activeMenu.value === 'inbox' || activeMenu.value === 'trash')

function onMenu(key) {
	activeMenu.value = key
	page.value = 1
	selected.value = []
	if (isListBox(key)) fetchList()
}
function isListBox(key) {
	return ['inbox', 'sent', 'trash'].includes(key)
}

async function fetchList() {
	if (!isListBox(activeMenu.value)) return
	loading.value = true
	try {
		const res = await businessApi.mail.list.get({
			box: activeMenu.value,
			keyword: keyword.value,
			page: page.value,
			page_size: pageSize.value,
		})
		if (res.code === 200) {
			list.value = res.data?.list || []
			total.value = res.data?.total || 0
		}
	} catch {
		ElMessage.error('加载邮件失败')
	} finally {
		loading.value = false
	}
}

async function fetchUnread() {
	const res = await businessApi.mail.unreadCount.get()
	if (res.code === 200) unreadCount.value = res.data?.count || 0
}

async function fetchContacts() {
	const res = await businessApi.mail.contacts.get()
	if (res.code === 200) users.value = res.data?.users || []
}

function digest(content) {
	// 后端已有 summary 的话优先用，没有就本地截一段，避免富文本标签直接渲染
	return String(content || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').slice(0, 60) || '—'
}

async function openMail(row) {
	detailVisible.value = true
	detailLoading.value = true
	try {
		const res = await businessApi.mail.detail.get(row.id)
		if (res.code === 200) current.value = res.data || row
		if (showReadState.value && !row.is_read) {
			await businessApi.mail.read.post(row.id)
			row.is_read = true
			fetchUnread()
		}
	} finally {
		detailLoading.value = false
	}
}

async function toggleRead(row) {
	if (row.is_read) await businessApi.mail.unread.post(row.id)
	else await businessApi.mail.read.post(row.id)
	row.is_read = !row.is_read
	fetchUnread()
}

async function removeMail(row) {
	await ElMessageBox.confirm('删除后邮件会移入回收站，确定继续？', '删除邮件', { type: 'warning' })
	await businessApi.mail.delete.delete(row.id)
	ElMessage.success('已移入回收站')
	fetchList()
	fetchUnread()
}

async function batch(action) {
	const ids = selected.value.map((r) => r.id)
	if (!ids.length) return
	await businessApi.mail[action].post({ ids })
	ElMessage.success('操作完成')
	fetchList()
	fetchUnread()
}

async function send(saveType) {
	if (saveType === 'send' && !form.to_ids.length) {
		return ElMessage.warning('请先选择收件人')
	}
	sending.value = true
	try {
		const res = await businessApi.mail.send.post({
			subject: form.subject,
			content: form.content,
			to_ids: form.to_ids,
			cc_ids: form.cc_ids,
			attachments: form.attachments,
			save_type: saveType,
		})
		if (res.code === 200 || res.code === 201) {
			ElMessage.success(saveType === 'send' ? '邮件已发送' : '草稿已保存')
			resetForm()
			activeMenu.value = saveType === 'send' ? 'sent' : 'inbox'
			fetchList()
		}
	} catch {
		ElMessage.error('操作失败')
	} finally {
		sending.value = false
	}
}

function resetForm() {
	form.to_ids = []
	form.cc_ids = []
	form.subject = ''
	form.content = ''
	form.attachments = []
}

function onSearch() {
	page.value = 1
	fetchList()
}

onMounted(() => {
	fetchContacts()
	fetchUnread()
	fetchList()
})
</script>

<style scoped>
.mail-compose {
	padding: 16px;
	overflow: auto;
	flex: 1;
}
.mail-compose-actions {
	display: flex;
	gap: 8px;
	padding-left: 72px;
}
.mail-table {
	flex: 1;
	min-height: 0;
}
.rp-grid-page {
	display: flex;
	align-items: center;
	padding: 8px 16px;
	flex-shrink: 0;
}
.mail-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: #f56c6c;
}
.mail-read {
	color: #c0c4cc;
}
.mail-unread {
	font-weight: 700;
	color: #303133;
}
.mail-attach {
	margin-left: 6px;
	color: #909399;
	vertical-align: -2px;
}
.mail-digest {
	color: #909399;
}
.mail-detail-meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
	color: #606266;
	padding-bottom: 12px;
	border-bottom: 1px solid #e4e7ed;
	margin-bottom: 12px;
}
.mail-detail-body {
	font-size: 13px;
	line-height: 22px;
	color: #303133;
	white-space: pre-wrap;
	min-height: 120px;
	max-height: 60vh;
	overflow: auto;
}
</style>
