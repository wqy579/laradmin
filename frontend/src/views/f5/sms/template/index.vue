<template>
	<div class="f5-sms-page">
		<!-- 查询区 -->
		<div class="sms-query">
			<el-form inline :model="query" @submit.prevent>
				<el-form-item label="关键字">
					<el-input v-model="query.keyword" placeholder="编码/名称/内容" clearable @keyup.enter="load" @clear="load" />
				</el-form-item>
				<el-form-item label="状态">
					<el-select v-model="query.status" placeholder="全部" clearable style="width: 110px" @change="load">
						<el-option label="启用" :value="1" />
						<el-option label="停用" :value="0" />
					</el-select>
				</el-form-item>
				<el-form-item>
					<el-button type="primary" @click="load">查询</el-button>
					<el-button type="primary" plain @click="openCreate">
						<el-icon><Plus /></el-icon>新建模板
					</el-button>
				</el-form-item>
			</el-form>
		</div>

		<!-- 模板列表 -->
		<el-table :data="list" v-loading="loading" border>
			<el-table-column label="编码" width="140">
				<template #default="{ row }"><span class="sms-code">{{ row.code }}</span></template>
			</el-table-column>
			<el-table-column label="名称" width="160" prop="name" />
			<el-table-column label="模板内容" min-width="320">
				<template #default="{ row }">
					<span class="sms-content">{{ row.content }}</span>
				</template>
			</el-table-column>
			<el-table-column label="变量" width="180">
				<template #default="{ row }">
					<template v-if="Object.keys(row.variables || {}).length">
						<el-tag v-for="(v, k) in row.variables" :key="k" size="small" type="info" class="sms-var">{{ k }}</el-tag>
					</template>
					<span v-else class="sms-muted">无</span>
				</template>
			</el-table-column>
			<el-table-column label="状态" width="90" align="center">
				<template #default="{ row }">
					<el-tag :type="row.status_tag_type" size="small">{{ row.status_label }}</el-tag>
				</template>
			</el-table-column>
			<el-table-column label="更新时间" width="160">
				<template #default="{ row }">{{ row.created_at || '—' }}</template>
			</el-table-column>
			<el-table-column label="操作" width="200" fixed="right">
				<template #default="{ row }">
					<el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
					<el-button link type="warning" size="small" @click="openSend(row)">发送</el-button>
					<el-button link :type="row.status === 1 ? 'warning' : 'success'" size="small" @click="toggle(row)">
						{{ row.status === 1 ? '停用' : '启用' }}
					</el-button>
					<el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
				</template>
			</el-table-column>
		</el-table>

		<div class="sms-pager" v-if="total > query.page_size">
			<el-pagination background layout="prev, pager, next, total" :total="total" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load() }" />
		</div>

		<!-- 编辑弹窗（含变量实时预览） -->
		<el-dialog v-model="dlg" :title="form.id ? '编辑模板' : '新建模板'" width="700px">
			<el-form :model="form" label-width="90px">
				<el-form-item label="编码" required>
					<el-input v-model="form.code" placeholder="如 ORDER_NOTIFY，字母/数字/下划线" :disabled="!!form.id" />
				</el-form-item>
				<el-form-item label="名称" required>
					<el-input v-model="form.name" placeholder="模板名称" />
				</el-form-item>
				<el-form-item label="内容" required>
					<el-input v-model="form.content" type="textarea" :rows="5" placeholder="支持 {变量名} 占位，如：尊敬的{客户名}，您的订单{订单号}已发货。" @input="doPreview" />
					<div class="sms-hint">使用花括号 {变量名} 定义变量；变量将用示例值实时替换预览。</div>
				</el-form-item>
				<el-form-item label="示例值">
					<div class="sms-examples" v-if="vars.length">
						<div v-for="v in vars" :key="v" class="sms-example-row">
							<span class="sms-example-var">{{ v }}</span>
							<el-input v-model="exampleValues[v]" size="small" placeholder="示例值" style="width: 220px" @input="doPreview" />
						</div>
					</div>
					<span v-else class="sms-muted">内容里暂无变量</span>
				</el-form-item>
				<el-form-item label="预览">
					<div class="sms-preview">{{ previewText }}</div>
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="form.remark" type="textarea" :rows="2" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="dlg = false">取消</el-button>
				<el-button type="primary" :loading="submitting" @click="submit">保存</el-button>
			</template>
		</el-dialog>

		<!-- 发送弹窗 -->
		<el-dialog v-model="sendDlg" title="发送短信" width="520px">
			<el-form :model="sendForm" label-width="90px">
				<el-form-item label="模板">
					<span>{{ sendForm.template_name }}</span>
				</el-form-item>
				<el-form-item label="手机号" required>
					<el-input v-model="sendForm.mobile" placeholder="接收手机号" />
				</el-form-item>
				<el-form-item label="接收人">
					<el-input v-model="sendForm.receiver_name" placeholder="姓名" />
				</el-form-item>
				<el-form-item v-if="sendVars.length" label="变量值">
					<div class="sms-examples">
						<div v-for="v in sendVars" :key="v" class="sms-example-row">
							<span class="sms-example-var">{{ v }}</span>
							<el-input v-model="sendForm.variables[v]" size="small" placeholder="实际值" style="width: 220px" @input="refreshSendPreview" />
						</div>
					</div>
				</el-form-item>
				<el-form-item label="发送预览">
					<div class="sms-preview">{{ sendPreview }}</div>
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="sendDlg = false">取消</el-button>
				<el-button type="primary" :loading="sending" @click="doSend">发送</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import mallApi from '@/api/mall'

const query = reactive({ keyword: '', status: null, page: 1, page_size: 10 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

const dlg = ref(false)
const submitting = ref(false)
const form = reactive({ id: null, code: '', name: '', content: '', remark: '', status: 1 })
const vars = ref([])
const exampleValues = reactive({})
const previewText = ref('')

const sendDlg = ref(false)
const sending = ref(false)
const sendForm = reactive({ template_id: null, template_name: '', mobile: '', receiver_name: '', variables: {} })
const sendVars = ref([])
const sendPreview = ref('')

async function load() {
	loading.value = true
	try {
		const res = await mallApi.sms.list({ keyword: query.keyword, status: query.status ?? '', page: query.page, page_size: query.page_size })
		if (res.code === 200) {
			list.value = res.data.list || []
			total.value = res.data.total || 0
		}
	} catch {
		ElMessage.error('加载模板失败')
	} finally {
		loading.value = false
	}
}

function openCreate() {
	Object.assign(form, { id: null, code: '', name: '', content: '', remark: '', status: 1 })
	Object.keys(exampleValues).forEach((k) => delete exampleValues[k])
	vars.value = []
	previewText.value = ''
	dlg.value = true
}
function openEdit(row) {
	Object.assign(form, { id: row.id, code: row.code, name: row.name, content: row.content, remark: row.remark || '', status: row.status })
	Object.keys(exampleValues).forEach((k) => delete exampleValues[k])
	Object.assign(exampleValues, row.variables || {})
	vars.value = Object.keys(row.variables || {})
	doPreview()
	dlg.value = true
}

/** 提取内容里的变量并调预览接口 */
async function doPreview() {
	const content = form.content
	try {
		const res = await mallApi.sms.preview({ content, variables: { ...exampleValues } })
		if (res.code === 200) {
			vars.value = res.data.variables || []
			previewText.value = res.data.preview || ''
			// 用后端返回的变量清单回填示例值（新增变量给默认示例）
			;(res.data.variables || []).forEach((v) => {
				if (exampleValues[v] === undefined) exampleValues[v] = `示例${v}`
			})
			if (res.data.variables?.length) {
				// 重新预览以带上默认示例值
				const res2 = await mallApi.sms.preview({ content, variables: { ...exampleValues } })
				if (res2.code === 200) previewText.value = res2.data.preview || ''
			}
		}
	} catch { /* 静默 */ }
}

async function submit() {
	if (!form.code) return ElMessage.warning('请输入编码')
	if (!form.name) return ElMessage.warning('请输入名称')
	if (!form.content) return ElMessage.warning('请输入内容')
	submitting.value = true
	try {
		const payload = { ...form, variables: { ...exampleValues }, status: form.status }
		delete payload.id
		let res
		if (form.id) res = await mallApi.sms.update(form.id, payload)
		else res = await mallApi.sms.add(payload)
		if (res.code === 200 || res.code === 201) {
			ElMessage.success('保存成功')
			dlg.value = false
			load()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || e?.message || '保存失败')
	} finally {
		submitting.value = false
	}
}

async function toggle(row) {
	try {
		const res = await mallApi.sms.toggle(row.id, { status: row.status === 1 ? 0 : 1 })
		if (res.code === 200) {
			ElMessage.success(res.msg || '已切换')
			load()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || '操作失败')
	}
}

async function remove(row) {
	try {
		await ElMessageBox.confirm(`确认删除模板「${row.name}」？`, '提示', { type: 'warning' })
	} catch { return }
	try {
		const res = await mallApi.sms.remove(row.id)
		if (res.code === 200) {
			ElMessage.success('已删除')
			load()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || '删除失败')
	}
}

function openSend(row) {
	Object.assign(sendForm, {
		template_id: row.id,
		template_name: row.name,
		mobile: '',
		receiver_name: '',
		variables: { ...(row.variables || {}) },
	})
	sendVars.value = Object.keys(row.variables || {})
	sendDlg.value = true
	refreshSendPreview()
}

async function refreshSendPreview() {
	try {
		const res = await mallApi.sms.preview({
			content: list.value.find((t) => t.id === sendForm.template_id)?.content || '',
			variables: { ...sendForm.variables },
		})
		if (res.code === 200) sendPreview.value = res.data.preview || ''
	} catch { /* 静默 */ }
}

async function doSend() {
	if (!sendForm.mobile) return ElMessage.warning('请输入手机号')
	sending.value = true
	try {
		const res = await mallApi.sms.send({
			template_id: sendForm.template_id,
			mobile: sendForm.mobile,
			receiver_name: sendForm.receiver_name,
			variables: sendForm.variables,
		})
		if (res.code === 200 || res.code === 201) {
			ElMessage.success('发送成功')
			sendDlg.value = false
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || e?.message || '发送失败')
	} finally {
		sending.value = false
	}
}

onMounted(load)
</script>

<style scoped>
.f5-sms-page { padding: 16px 20px; }
.sms-query { background: #fff; padding: 16px 20px 0; border: 1px solid #ebeef5; border-radius: 4px; margin-bottom: 16px; }
.sms-code { font-family: monospace; color: #409eff; }
.sms-content { color: #606266; }
.sms-var { margin-right: 4px; }
.sms-muted { color: #909399; font-size: 12px; }
.sms-pager { display: flex; justify-content: flex-end; margin-top: 16px; }
.sms-hint { font-size: 12px; color: #909399; margin-top: 4px; }
.sms-examples { width: 100%; }
.sms-example-row { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.sms-example-var { font-family: monospace; color: #e6a23c; width: 90px; }
.sms-preview { width: 100%; min-height: 40px; background: #f5f7fa; border: 1px dashed #dcdfe6; border-radius: 4px; padding: 8px 12px; font-size: 13px; color: #303133; white-space: pre-wrap; word-break: break-all; }
</style>
