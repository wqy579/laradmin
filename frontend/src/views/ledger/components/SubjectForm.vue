<template>
	<el-dialog v-model="visible" :title="title" width="640px" :close-on-click-modal="false" destroy-on-close>
		<el-form ref="formRef" :model="form" :rules="rules" label-width="120px">
			<el-form-item label="上级科目" prop="parent_id">
				<el-tree-select v-model="form.parent_id" :data="parentOptions" :props="{ value: 'id', label: 'label', children: 'children' }" placeholder="顶级科目留空" clearable filterable check-strictly style="width:100%" @node-click="onParentChange" />
			</el-form-item>
			<el-form-item label="科目编码" prop="code">
				<el-input v-model="form.code" placeholder="如 1001 或 100101" @input="validateCode" />
				<div v-if="codeError" class="err-hint">{{ codeError }}</div>
			</el-form-item>
			<el-form-item label="科目名称" prop="name">
				<el-input v-model="form.name" placeholder="最多 50 字" maxlength="50" show-word-limit />
			</el-form-item>
			<el-form-item label="科目类别" prop="category">
				<el-select v-model="form.category" :disabled="form.parent_id !== 0 && form.parent_id" style="width:100%">
					<el-option label="资产类" value="asset" />
					<el-option label="负债类" value="liability" />
					<el-option label="共同类" value="common" />
					<el-option label="所有者权益类" value="equity" />
					<el-option label="成本类" value="cost" />
					<el-option label="损益类" value="profit_loss" />
				</el-select>
			</el-form-item>
			<el-form-item label="余额方向" prop="balance_direction">
				<el-radio-group v-model="form.balance_direction">
					<el-radio value="debit">借</el-radio>
					<el-radio value="credit">贷</el-radio>
				</el-radio-group>
			</el-form-item>
			<el-form-item label="辅助核算" prop="aux_types">
				<el-checkbox-group v-model="form.aux_types" :disabled="!form.is_leaf">
					<el-checkbox value="customer">客户</el-checkbox>
					<el-checkbox value="supplier">供应商</el-checkbox>
					<el-checkbox value="employee">员工</el-checkbox>
					<el-checkbox value="department">部门</el-checkbox>
					<el-checkbox value="project">项目</el-checkbox>
				</el-checkbox-group>
			</el-form-item>
			<el-form-item label="是否末级" prop="is_leaf">
				<el-switch v-model="form.is_leaf" :active-value="true" :inactive-value="false" />
				<span class="hint">末级科目才可在凭证分录中选用</span>
			</el-form-item>
			<el-form-item label="排序" prop="sort">
				<el-input-number v-model="form.sort" :min="0" controls-position="right" style="width:120px" />
			</el-form-item>
			<el-form-item label="状态" prop="status">
				<el-radio-group v-model="form.status">
					<el-radio value="enabled">启用</el-radio>
					<el-radio value="disabled">停用</el-radio>
				</el-radio-group>
			</el-form-item>
			<el-form-item label="备注" prop="remark">
				<el-input v-model="form.remark" type="textarea" :rows="2" placeholder="备注" />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" @click="submit">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import ledgerApi from '@/api/ledger'

const emit = defineEmits(['saved'])
const visible = ref(false)
const title = ref('')
const formRef = ref(null)
const codeError = ref('')
const parentOptions = ref([])

const form = reactive({ id: null, parent_id: 0, code: '', name: '', category: 'asset', balance_direction: 'debit', aux_types: [], is_leaf: true, sort: 0, status: 'enabled', remark: '' })

const rules = {
	code: [{ required: true, message: '请输入科目编码', trigger: 'blur' }],
	name: [{ required: true, message: '请输入科目名称', trigger: 'blur' }],
	category: [{ required: true, message: '请选择科目类别', trigger: 'change' }],
	balance_direction: [{ required: true, message: '请选择余额方向', trigger: 'change' }],
}

const open = async (row, parent) => {
	codeError.value = ''
	Object.assign(form, { id: null, parent_id: 0, code: '', name: '', category: 'asset', balance_direction: 'debit', aux_types: [], is_leaf: true, sort: 0, status: 'enabled', remark: '' })
	await loadParentOptions()
	if (parent) {
		form.parent_id = parent.id
		form.category = parent.category
		title.value = '新增下级科目'
	} else if (row) {
		const res = await ledgerApi.subject.detail.get(row.id)
		const s = res.data?.subject
		if (s) {
			Object.assign(form, { id: s.id, parent_id: s.parent_id, code: s.code, name: s.name, category: s.category, balance_direction: s.balance_direction, aux_types: s.aux_types || [], is_leaf: s.is_leaf, sort: s.sort, status: s.status, remark: s.remark })
		}
		title.value = '编辑科目'
	} else {
		title.value = '新增科目'
	}
	visible.value = true
}
defineExpose({ open })

const loadParentOptions = async () => {
	const res = await ledgerApi.subject.tree.get()
	const build = (nodes) => nodes.map(n => ({ id: n.id, label: `${n.code} ${n.name}`, children: n.children?.length ? build(n.children) : undefined }))
	parentOptions.value = build(res.data?.tree || [])
}

const onParentChange = (node) => {
	if (node) {
		// 子级继承父级类别且不可改
		const cat = String(node.label).match(/资产|负债|共同|权益|成本|损益/)
		if (cat) {
			const map = { 资产: 'asset', 负债: 'liability', 共同: 'common', 权益: 'equity', 成本: 'cost', 损益: 'profit_loss' }
			form.category = map[cat[0]] || form.category
		}
	}
}

const validateCode = () => {
	codeError.value = ''
	if (!form.code) return
	if (form.parent_id) {
		const parent = findNode(parentOptions.value, form.parent_id)
		if (parent) {
			const parentCode = parent.label.split(' ')[0]
			if (!form.code.startsWith(parentCode)) {
				codeError.value = `编码不符合层级规则，应以父级编码 ${parentCode} 为前缀`
			}
		}
	}
}

const findNode = (nodes, id) => {
	for (const n of nodes) {
		if (n.id === id) return n
		if (n.children) {
			const f = findNode(n.children, id)
			if (f) return f
		}
	}
	return null
}

const submit = async () => {
	await formRef.value?.validate(async (valid) => {
		if (!valid) return
		try {
			if (form.id) {
				await ledgerApi.subject.edit.put(form.id, { ...form })
			} else {
				await ledgerApi.subject.add.post({ ...form })
			}
			ElMessage.success('保存成功')
			visible.value = false
			emit('saved')
		} catch (e) {
			ElMessage.error(e?.message || '保存失败')
		}
	})
}
</script>

<style scoped>
.err-hint { color: #f56c6c; font-size: 12px; line-height: 1.5; }
.hint { color: #909399; font-size: 12px; margin-left: 8px; }
</style>
