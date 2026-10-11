<template>
	<el-dialog v-model="visible" title="代客发起兑换" width="640px" destroy-on-close @close="resetForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
			<el-form-item label="会员" prop="member_id">
				<el-select v-model="form.member_id" filterable remote :remote-method="searchMembers"
					:loading="memberLoading" placeholder="搜索会员编号/名称/手机" style="width:100%">
					<el-option v-for="m in members" :key="m.id" :value="m.id"
						:label="`${m.member_no} ${m.name}`" :disabled="m.status !== 1">
						<span>{{ m.member_no }} {{ m.name }}</span>
						<span style="float:right;color:#999;font-size:12px">积分 {{ m.points }} · 储值 {{ fmt(m.balance) }}</span>
					</el-option>
				</el-select>
			</el-form-item>
			<el-form-item label="兑换商品" prop="product_id">
				<el-select v-model="form.product_id" filterable placeholder="请选择兑换商品" style="width:100%" @change="onProductChange">
					<el-option v-for="p in products" :key="p.id" :value="p.id"
						:label="`${p.name}${p.spec ? '（' + p.spec + '）' : ''}`"
						:disabled="p.stock <= 0">
						<span>{{ p.name }}{{ p.spec ? '（' + p.spec + '）' : '' }}</span>
						<span style="float:right;color:#999;font-size:12px">{{ p.points }} 积分 / 库存 {{ p.stock }}</span>
					</el-option>
				</el-select>
			</el-form-item>
			<el-form-item label="数量" prop="quantity">
				<el-input-number v-model="form.quantity" :min="1" :max="maxQuantity" :step="1" style="width:160px" @change="onQuantityChange" />
				<span class="hint" v-if="currentProduct">
					共需 <span class="strong">{{ currentProduct.points * (form.quantity || 0) }}</span> 积分
				</span>
			</el-form-item>
			<el-alert v-if="form.member_id && currentProduct && currentMember" :type="canExchange ? 'info' : 'error'"
				:closable="false" style="margin-bottom:14px"
				:title="canExchange
					? `会员当前积分 ${currentMember.points}，本次需扣 ${currentProduct.points * (form.quantity || 0)} 积分`
					: `积分不足：当前 ${currentMember.points}，本次需 ${currentProduct.points * (form.quantity || 0)}`" />
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="收货手机">
						<el-input v-model="form.contact_phone" maxlength="20" placeholder="默认用会员手机" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="收货地址">
						<el-input v-model="form.shipping_address" maxlength="255" />
					</el-form-item>
				</el-col>
				<el-col :span="24">
					<el-form-item label="备注">
						<el-input v-model="form.remark" maxlength="1000" type="textarea" :rows="2" />
					</el-form-item>
				</el-col>
			</el-row>
		</el-form>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" :loading="submitting" :disabled="!canExchange" @click="handleSubmit">
				创建兑换单（待审核）
			</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean })
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)
const members = ref([])
const memberLoading = ref(false)
const products = ref([])

const empty = { member_id: null, product_id: null, quantity: 1, contact_phone: '', shipping_address: '', remark: '' }
const form = ref({ ...empty })

const rules = {
	member_id: [{ required: true, message: '请选择会员', trigger: 'change' }],
	product_id: [{ required: true, message: '请选择兑换商品', trigger: 'change' }],
	quantity: [{ required: true, message: '请输入数量', trigger: 'blur' }],
}

const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })
const fmt = (n) => (n == null ? '0.00' : Number(n).toFixed(2))

const currentMember = computed(() => members.value.find((m) => m.id === form.value.member_id))
const currentProduct = computed(() => products.value.find((p) => p.id === form.value.product_id))
const canExchange = computed(() => {
	if (!currentMember.value || !currentProduct.value) return false
	return currentMember.value.points >= currentProduct.value.points * (form.value.quantity || 0)
})
const maxQuantity = computed(() => {
	if (!currentMember.value || !currentProduct.value) return 99
	return Math.max(1, Math.floor(currentMember.value.points / currentProduct.value.points))
})

watch(() => props.visible, async (show) => {
	if (show) {
		form.value = { ...empty }
		products.value = await fetchProducts()
	}
})

const loadMembers = async (kw) => {
	memberLoading.value = true
	try {
		const res = await businessApi.member.search.get({ keyword: kw, limit: 20 })
		members.value = res.data || []
	} finally { memberLoading.value = false }
}
const searchMembers = loadMembers

const fetchProducts = async () => {
	const res = await businessApi.pointProduct.onShelf.get({})
	return res.data || []
}

const onProductChange = () => { form.value.quantity = 1 }
const onQuantityChange = () => {}

const resetForm = () => { form.value = { ...empty }; members.value = [] }

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = await businessApi.pointOrder.add.post(form.value)
		if (res.code === 200) { ElMessage.success(res.message || '兑换单已创建，待审核'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>

<style scoped>
.hint { margin-left: 12px; color: #666; font-size: 13px; }
.strong { color: var(--el-color-primary); font-weight: 700; font-size: 15px; }
</style>
