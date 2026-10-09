<template>
	<el-dialog :model-value="visible" @update:model-value="$emit('update:visible', $event)" title="新增装车单" width="900px" :close-on-click-modal="false" destroy-on-close>
		<el-form :model="form" label-width="90px">
			<el-form-item label="配送员">
				<el-select v-model="form.delivery_person_id" filterable placeholder="请选择配送员" style="width: 200px">
					<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.user_id || e.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="车牌号">
				<el-input v-model="form.plate_no" placeholder="车牌号" style="width: 160px" />
			</el-form-item>
			<el-form-item label="装车日期">
				<el-date-picker v-model="form.load_date" type="date" value-format="YYYY-MM-DD" style="width: 180px" />
			</el-form-item>
		</el-form>
		<el-divider content-position="left">待装车验货单</el-divider>
		<el-table :data="checks" border size="small" @selection-change="onSelection">
			<el-table-column type="selection" width="40" />
			<el-table-column prop="check_no" label="验货单号" width="160" />
			<el-table-column prop="customer_name" label="客户名称" width="160" />
			<el-table-column prop="address" label="地址" width="200" show-overflow-tooltip />
			<el-table-column prop="phone" label="电话" width="120" />
			<el-table-column prop="total_qty" label="商品数量" width="90" align="center" />
		</el-table>
		<el-descriptions :column="3" border size="small" style="margin-top: 12px" v-if="selected.length">
			<el-descriptions-item label="订单数量">{{ selected.length }}</el-descriptions-item>
			<el-descriptions-item label="商品总数">{{ totalQty }}</el-descriptions-item>
		</el-descriptions>
		<template #footer>
			<el-button @click="$emit('update:visible', false)">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确认装车</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean })
const emit = defineEmits(['update:visible', 'success'])

const employees = ref([])
const checks = ref([])
const selected = ref([])
const form = reactive({ delivery_person_id: null, plate_no: '', load_date: new Date().toISOString().slice(0, 10) })
const submitting = ref(false)
const totalQty = computed(() => selected.value.reduce((s, r) => s + Number(r.total_qty || 0), 0))

const onSelection = (rows) => { selected.value = rows }

const handleSubmit = async () => {
	if (!form.delivery_person_id) return ElMessage.warning('请选择配送员')
	if (!selected.value.length) return ElMessage.warning('请选择验货单')
	submitting.value = true
	try {
		const payload = { ...form, check_ids: selected.value.map(r => r.id) }
		const res = await businessApi.deliveryLoad.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '创建成功'); emit('success'); emit('update:visible', false) }
	} catch (e) { if (e && e.message) ElMessage.error(e.message) } finally { submitting.value = false }
}

onMounted(async () => {
	const er = await businessApi.employee.list.get({ page_size: 9999, is_active: 1 })
	employees.value = er.data?.list || er.data || []
	const cr = await businessApi.deliveryCheck.unchecked.get()
	checks.value = cr.data || []
})
</script>
