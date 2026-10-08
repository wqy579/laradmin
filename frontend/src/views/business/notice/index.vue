<template>
	<div class="rp-page">
		<div class="rp-toolbar">
			<el-input
				v-model="keyword"
				placeholder="搜索公告标题 / 内容"
				size="small"
				style="width: 240px"
				clearable
				@keyup.enter="onSearch"
				@clear="onSearch"
			/>
			<el-button size="small" class="rp-btn-primary" @click="onSearch">搜索</el-button>
			<el-select v-model="typeFilter" placeholder="全部类型" size="small" style="width: 120px" clearable @change="onSearch">
				<el-option v-for="t in types" :key="t.value" :label="t.label" :value="t.value" />
			</el-select>
			<span class="rp-spacer"></span>
			<el-button size="small" type="primary" @click="openCreate">发布公告</el-button>
			<el-button size="small" circle title="刷新" @click="fetchList">
				<el-icon><Refresh /></el-icon>
			</el-button>
		</div>

		<div v-loading="loading" class="notice-scroll">
			<el-empty v-if="!list.length && !loading" description="暂无公告" />
			<div class="notice-grid">
				<div
					v-for="n in list"
					:key="n.id"
					class="notice-card"
					:class="{ 'is-top': n.is_top }"
					@click="openDetail(n)"
				>
					<div class="notice-card-head">
						<el-tag v-if="n.is_top" size="small" type="danger" effect="dark">置顶</el-tag>
						<el-tag size="small" :type="typeTag(n.type)" effect="plain">{{ typeLabel(n.type) }}</el-tag>
						<span class="notice-card-title">{{ n.title }}</span>
						<span class="notice-card-actions" @click.stop>
							<el-button v-if="n.can_edit" link size="small" @click="openEdit(n)">编辑</el-button>
							<el-button v-if="n.can_edit" link size="small" type="danger" @click="remove(n)">删除</el-button>
						</span>
					</div>
					<div class="notice-card-summary">{{ n.summary || '（无内容摘要）' }}</div>
					<div class="notice-card-foot">
						<span>{{ n.author_name }}</span>
						<span>{{ n.created_at }}</span>
						<span v-if="n.attachment_count">附件 {{ n.attachment_count }}</span>
						<span>阅读 {{ n.view_count || 0 }}</span>
					</div>
				</div>
			</div>
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

		<!-- 详情弹窗 800×600 -->
		<el-dialog v-model="detailVisible" :title="current.title" width="800px" top="6vh">
			<div v-loading="detailLoading" class="notice-detail">
				<div class="notice-detail-meta">
					<el-tag v-if="current.is_top" size="small" type="danger" effect="dark">置顶</el-tag>
					<el-tag size="small" :type="typeTag(current.type)" effect="plain">{{ typeLabel(current.type) }}</el-tag>
					<span>{{ current.author_name }}</span>
					<span>{{ current.created_at }}</span>
					<span>阅读 {{ current.view_count || 0 }}</span>
				</div>
				<div class="notice-detail-body">{{ current.content || '（无正文）' }}</div>
			</div>
			<template #footer>
				<el-button size="small" @click="detailVisible = false">关闭</el-button>
			</template>
		</el-dialog>

		<!-- 发布 / 编辑公告 -->
		<el-dialog v-model="editVisible" :title="editing.id ? '编辑公告' : '发布公告'" width="720px" top="6vh">
			<el-form :model="form" label-width="80px" size="small">
				<el-form-item label="标题" required>
					<el-input v-model="form.title" maxlength="200" show-word-limit placeholder="公告标题" />
				</el-form-item>
				<el-form-item label="类型">
					<el-radio-group v-model="form.type">
						<el-radio v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="置顶">
					<el-switch v-model="form.is_top" />
				</el-form-item>
				<el-form-item label="可见范围">
					<el-radio-group v-model="form.scope">
						<el-radio value="all">所有人</el-radio>
						<el-radio value="department">指定部门</el-radio>
						<el-radio value="user">指定人员</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item v-if="form.scope !== 'all'" label="选择对象">
					<el-select
						v-model="form.scope_ids"
						multiple
						filterable
						style="width: 100%"
						:placeholder="form.scope === 'department' ? '选择部门' : '选择人员'"
					>
						<el-option
							v-for="o in scopeOptions"
							:key="o.value"
							:label="o.label"
							:value="o.value"
						/>
					</el-select>
				</el-form-item>
				<el-form-item label="正文">
					<el-input v-model="form.content" type="textarea" :rows="10" placeholder="公告正文" resize="vertical" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button size="small" @click="editVisible = false">取消</el-button>
				<el-button size="small" class="rp-btn-primary" :loading="saving" @click="save">保存</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import businessApi from '@/api/business'

/** 类型配色：通知蓝、制度绿、活动橙、其他灰 */
const TYPE_COLORS = { notice: 'primary', rule: 'success', activity: 'warning', other: 'info' }

const list = ref([])
const types = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const keyword = ref('')
const typeFilter = ref('')

const detailVisible = ref(false)
const detailLoading = ref(false)
const current = ref({})
const editVisible = ref(false)
const saving = ref(false)
const editing = ref({})
const form = reactive({ title: '', type: 'notice', content: '', is_top: false, scope: 'all', scope_ids: [], attachments: [] })

/** 可见范围下拉：部门 / 人员共用 mail 的通讯录接口，避免再造一个用户字典接口 */
const contacts = ref({ departments: [], users: [] })
const scopeOptions = computed(() =>
	form.scope === 'user'
		? contacts.value.users
		: (contacts.value.departments || []).map((d) => ({ value: d.id, label: d.name }))
)

const typeLabel = (v) => types.value.find((t) => t.value === v)?.label || v || '其他'
const typeTag = (v) => TYPE_COLORS[v] || 'info'

async function fetchList() {
	loading.value = true
	try {
		const res = await businessApi.notice.list.get({
			keyword: keyword.value,
			type: typeFilter.value,
			page: page.value,
			page_size: pageSize.value,
		})
		if (res.code === 200) {
			list.value = res.data?.list || []
			total.value = res.data?.total || 0
			if (Array.isArray(res.data?.types) && res.data.types.length) types.value = res.data.types
		}
	} catch {
		ElMessage.error('加载公告失败')
	} finally {
		loading.value = false
	}
}

async function fetchContacts() {
	const res = await businessApi.mail.contacts.get()
	if (res.code === 200) contacts.value = res.data || contacts.value
}

async function openDetail(row) {
	detailVisible.value = true
	detailLoading.value = true
	try {
		const res = await businessApi.notice.detail.get(row.id)
		if (res.code === 200) current.value = res.data || row
	} finally {
		detailLoading.value = false
	}
	// 阅读数由后端 +1，这里乐观更新避免弹窗关闭前数字不动
	row.view_count = (row.view_count || 0) + 1
}

function blankForm() {
	form.title = ''
	form.type = 'notice'
	form.content = ''
	form.is_top = false
	form.scope = 'all'
	form.scope_ids = []
	form.attachments = []
}

function openCreate() {
	editing.value = {}
	blankForm()
	editVisible.value = true
}
function openEdit(row) {
	editing.value = row
	Object.assign(form, {
		title: row.title || '',
		type: row.type || 'notice',
		content: row.content || '',
		is_top: !!row.is_top,
		scope: row.scope || 'all',
		scope_ids: row.scope_ids || [],
		attachments: row.attachments || [],
	})
	editVisible.value = true
}

async function save() {
	if (!form.title.trim()) return ElMessage.warning('请填写公告标题')
	saving.value = true
	try {
		const payload = { ...form }
		const res = editing.value.id
			? await businessApi.notice.update.put(editing.value.id, payload)
			: await businessApi.notice.create.post(payload)
		if (res.code === 200 || res.code === 201) {
			ElMessage.success('已保存')
			editVisible.value = false
			fetchList()
		}
	} catch {
		ElMessage.error('保存失败')
	} finally {
		saving.value = false
	}
}

async function remove(row) {
	await ElMessageBox.confirm('删除后不可恢复，确定删除该公告？', '删除公告', { type: 'warning' })
	await businessApi.notice.delete.delete(row.id)
	ElMessage.success('已删除')
	fetchList()
}

function onSearch() {
	page.value = 1
	fetchList()
}

onMounted(() => {
	fetchContacts()
	fetchList()
})
</script>

<style scoped>
.notice-scroll {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding: 0 16px;
}
.notice-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
	gap: 12px;
	padding-bottom: 12px;
}
.notice-card {
	border: 1px solid #e4e7ed;
	border-radius: 4px;
	padding: 12px 14px;
	background: #fff;
	cursor: pointer;
	transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.notice-card:hover {
	border-color: #409eff;
	box-shadow: 0 2px 8px rgba(64, 158, 255, 0.12);
}
.notice-card.is-top {
	background: #fefaf6;
	border-color: #fcd3b6;
}
.notice-card-head {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}
.notice-card-title {
	font-size: 15px;
	font-weight: 700;
	color: #303133;
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.notice-card-actions {
	display: flex;
	gap: 4px;
}
.notice-card-summary {
	margin-top: 8px;
	font-size: 13px;
	color: #606266;
	line-height: 20px;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
	min-height: 40px;
}
.notice-card-foot {
	margin-top: 10px;
	display: flex;
	gap: 14px;
	font-size: 12px;
	color: #909399;
	flex-wrap: wrap;
}
.rp-grid-page {
	display: flex;
	align-items: center;
	padding: 8px 16px;
	flex-shrink: 0;
}
.notice-detail-meta {
	display: flex;
	align-items: center;
	gap: 12px;
	font-size: 13px;
	color: #606266;
	padding-bottom: 12px;
	border-bottom: 1px solid #e4e7ed;
	margin-bottom: 12px;
}
.notice-detail-body {
	font-size: 13px;
	line-height: 22px;
	color: #303133;
	white-space: pre-wrap;
	min-height: 200px;
	max-height: 60vh;
	overflow: auto;
}
</style>
