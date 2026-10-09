<template>
	<el-dialog :model-value="visible" @update:model-value="$emit('update:visible', $event)" :title="record?.status === 'picked' ? '拣货单详情' : '拣货操作'" width="800px" :close-on-click-modal="false" destroy-on-close>
		<el-descriptions :column="3" border size="small" v-if="detail">
			<el-descriptions-item label="拣货单号">{{ detail.pick_no }}</el-descriptions-item>
			<el-descriptions-item label="配货单号">{{ detail.picking_no }}</el-descriptions-item>
			<el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
		</el-descriptions>
		<el-table :data="form.items" border size="small" style="margin-top: 12px">
			<el-table-column prop="product_code" label="商品编码" width="120" />
			<el-table-column prop="product_name" label="商品名称" width="160" />
			<el-table-column prop="spec" label="规格" width="90" />
			<el-table-column prop="unit" label="单位" width="60" />
			<el-table-column prop="pick_qty" label="应拣数量" width="90" align="center" />
			<el-table-column label="实拣数量" width="130">
				<template #default="{ row }">
					<el-input-number v-if="editable" v-model="row.actual_qty" :min="0" :max="row.pick_qty" size="small" controls-position="right" style="width: 120px" />
					<span v-else>{{ row.actual_qty }}</span>
				</template>
			</el-table-column>
			<el-table-column label="货位" width="120">
				<template #default="{ row }">
					<el-input v-if="editable" v-model="row.bin_location" size="small" placeholder="货位" />
					<span v-else>{{ row.bin_location }}</span>
				</template>
			</el-table-column>
		</el-table>
		<template #footer>
			<el-button @click="$emit('update:visible', false)">取消</el-button>
			<el-button v-if="editable" type="primary" :loading="submitting" @click="handleSubmit">确认拣货</el-button>
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
const editable = computed(() => props.record && props.record.status !== 'picked' && props.record.status !== 'cancelled')

const handleSubmit = async () => {
	if (!form.items.length) return
	submitting.value = true
	try {
		const payload = { items: form.items.map(it => ({ id: it.id, actual_qty: it.actual_qty, bin_location: it.bin_location, remark: it.remark })) }
		const res = await businessApi.deliveryPick.confirm.post(props.record.id, payload)
		if (res.code === 200) { ElMessage.success(res.message || '拣货确认成功'); emit('success'); emit('update:visible', false) }
	} catch (e) { if (e && e.message) ElMessage.error(e.message) } finally { submitting.value = false }
}

watch(() => props.visible, async (v) => {
	if (v && props.record) {
		const res = await businessApi.deliveryPick.detail.get(props.record.id)
		detail.value = res.data
		form.items = (res.data?.items || []).map(it => ({ ...it, actual_qty: it.actual_qty ?? it.pick_qty }))
	}
}, { immediate: true })
</script>
