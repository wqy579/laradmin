<template>
	<el-dialog :model-value="visible" @update:model-value="$emit('update:visible', $event)" :title="record?.status === 'checked' || record?.status === 'exception' ? '验货单详情' : '验货操作'" width="800px" :close-on-click-modal="false" destroy-on-close>
		<el-descriptions :column="3" border size="small" v-if="detail">
			<el-descriptions-item label="验货单号">{{ detail.check_no }}</el-descriptions-item>
			<el-descriptions-item label="拣货单号">{{ detail.pick_no }}</el-descriptions-item>
			<el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
		</el-descriptions>
		<el-table :data="form.items" border size="small" style="margin-top: 12px">
			<el-table-column prop="product_code" label="商品编码" width="120" />
			<el-table-column prop="product_name" label="商品名称" width="160" />
			<el-table-column prop="spec" label="规格" width="90" />
			<el-table-column prop="unit" label="单位" width="60" />
			<el-table-column prop="pick_qty" label="拣货数量" width="90" align="center" />
			<el-table-column label="实验数量" width="130">
				<template #default="{ row }">
					<el-input-number v-if="editable" v-model="row.actual_qty" :min="0" :max="row.pick_qty" size="small" controls-position="right" style="width: 120px" @change="row.diff_qty = row.pick_qty - row.actual_qty" />
					<span v-else>{{ row.actual_qty }}</span>
				</template>
			</el-table-column>
			<el-table-column label="差异" width="80" align="center">
				<template #default="{ row }">
					<span :style="{ color: row.diff_qty ? '#F56C6C' : '#606266' }">{{ row.diff_qty }}</span>
				</template>
			</el-table-column>
			<el-table-column label="备注" width="180">
				<template #default="{ row }">
					<el-input v-if="editable" v-model="row.remark" size="small" placeholder="差异必填" />
					<span v-else>{{ row.remark }}</span>
				</template>
			</el-table-column>
		</el-table>
		<template #footer>
			<el-button @click="$emit('update:visible', false)">取消</el-button>
			<el-button v-if="editable" type="primary" :loading="submitting" @click="handleSubmit">确认验货</el-button>
			<el-button v-else @click="$emit('update:visible', false)">关闭</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])

const detail = ref(null)
const form = reactive({ items: [] })
const submitting = ref(false)
const editable = computed(() => props.record && props.record.status !== 'checked' && props.record.status !== 'exception' && props.record.status !== 'cancelled')

const handleSubmit = async () => {
	submitting.value = true
	try {
		const payload = { items: form.items.map(it => ({ id: it.id, actual_qty: it.actual_qty, remark: it.remark })) }
		const res = await businessApi.deliveryCheck.confirm.post(props.record.id, payload)
		if (res.code === 200) { ElMessage.success(res.message || '验货确认成功'); emit('success'); emit('update:visible', false) }
	} catch (e) { if (e && e.message) ElMessage.error(e.message) } finally { submitting.value = false }
}

watch(() => props.visible, async (v) => {
	if (v && props.record) {
		const res = await businessApi.deliveryCheck.detail.get(props.record.id)
		detail.value = res.data
		form.items = (res.data?.items || []).map(it => ({ ...it, actual_qty: it.actual_qty ?? it.pick_qty, diff_qty: it.diff_qty ?? (it.pick_qty - (it.actual_qty ?? it.pick_qty)) }))
	}
}, { immediate: true })
</script>
