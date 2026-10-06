<template>
	<el-dialog
		v-model="visible"
		title="审核库存调整单"
		width="500px"
		top="15vh"
		@close="$emit('close')"
		destroy-on-close
	>
		<el-descriptions :column="1" border>
			<el-descriptions-item label="调整单号">{{ row.adjust_no }}</el-descriptions-item>
			<el-descriptions-item label="调整类型">
				<el-tag :type="typeMeta[row.adjust_type]?.type" size="small">{{ typeMeta[row.adjust_type]?.label || row.adjust_type }}</el-tag>
			</el-descriptions-item>
			<el-descriptions-item label="调整数量">{{ qtyFormat(row.total_qty) }}</el-descriptions-item>
			<el-descriptions-item label="调整金额">¥{{ fmtMoney(Math.abs(row.total_amount)) }}</el-descriptions-item>
			<el-descriptions-item label="调整原因">{{ row.reason || '-' }}</el-descriptions-item>
		</el-descriptions>

		<el-form ref="formRef" :model="form" :rules="rules" label-width="80px" style="margin-top: 20px">
			<el-form-item label="审核结果" prop="result">
				<el-radio-group v-model="form.result">
					<el-radio label="approve">通过</el-radio>
					<el-radio label="reject">驳回</el-radio>
				</el-radio-group>
			</el-form-item>
			<el-form-item label="审核意见" prop="comment">
				<el-input v-model="form.comment" type="textarea" :rows="3" placeholder="请输入审核意见（驳回时必填）" />
			</el-form-item>
		</el-form>

		<template #footer>
			<el-button @click="$emit('close')">取消</el-button>
			<el-button type="primary" @click="handleApprove">确认</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, watch } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({
	visible: Boolean,
	row: Object,
})
const emit = defineEmits(['close', 'done'])

const formRef = ref(null)
const form = reactive({
	result: 'approve',
	comment: '',
})

const rules = {
	result: [{ required: true, message: '请选择审核结果', trigger: 'change' }],
	comment: [{ required: true, message: '驳回时请填写审核意见', trigger: 'blur', validator: (rule, value, callback) => { if (form.result === 'reject' && !value) callback(new Error('驳回时请填写审核意见')); else callback(); } }],
}

watch(() => props.visible, (val) => {
	if (val) {
		form.result = 'approve'
		form.comment = ''
	}
})

const typeMeta = {
	stock_loss: { label: '库存损耗', type: 'danger' },
	stock_gain: { label: '库存溢余', type: 'success' },
	other: { label: '其他', type: 'info' },
}

function qtyFormat(v) {
	const n = Number(v || 0)
	return n > 0 ? `+${n}` : `${n}`
}

function fmtMoney(v) {
	return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function handleApprove() {
	try {
		await formRef.value.validate()
	} catch (e) {
		return
	}

	try {
		const payload = { approval_comment: form.comment }
		if (form.result === 'approve') {
			await businessApi.stockAdjust.approve.post(props.row.id, payload)
		} else {
			await businessApi.stockAdjust.reject.post(props.row.id, payload)
		}
		ElMessage.success(form.result === 'approve' ? '审核通过' : '已驳回')
		emit('done')
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '操作失败')
	}
}
</script>

<style scoped>
.el-descriptions { margin-bottom: 16px; }
</style>
