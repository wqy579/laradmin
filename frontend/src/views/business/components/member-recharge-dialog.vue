<template>
	<el-dialog v-model="visible" :title="record ? '编辑充值单' : '新增充值'" width="560px" destroy-on-close @close="resetForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
			<el-form-item label="会员" prop="member_id">
				<el-select v-model="form.member_id" filterable remote :remote-method="searchMembers"
					:loading="memberLoading" placeholder="搜索会员编号/名称/手机" style="width:100%">
					<el-option v-for="m in members" :key="m.id" :value="m.id"
						:label="`${m.member_no} ${m.name}（储值 ${fmt(m.balance)} / 积分 ${m.points}）`"
						:disabled="m.status !== 1">
						<span>{{ m.member_no }} {{ m.name }}</span>
						<span style="float:right;color:#999;font-size:12px">
							储值 {{ fmt(m.balance) }} · 积分 {{ m.points }}
						</span>
					</el-option>
				</el-select>
			</el-form-item>
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="充值金额" prop="recharge_amount">
						<el-input-number v-model="form.recharge_amount" :min="0.01" :precision="2" :step="100" style="width:100%" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="赠送金额" prop="gift_amount">
						<el-input-number v-model="form.gift_amount" :min="0" :precision="2" :step="50" style="width:100%" />
					</el-form-item>
				</el-col>
			</el-row>
			<el-alert :title="`到账储值合计：${fmt((Number(form.recharge_amount) || 0) + (Number(form.gift_amount) || 0))} 元`"
				type="info" :closable="false" style="margin-bottom:14px" />
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="支付方式" prop="pay_method">
						<el-select v-model="form.pay_method" style="width:100%">
							<el-option label="现金" value="cash" />
							<el-option label="微信" value="wechat" />
							<el-option label="支付宝" value="alipay" />
							<el-option label="银行卡" value="bank" />
							<el-option label="其他" value="other" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="充值日期" prop="recharge_date">
						<el-date-picker v-model="form.recharge_date" type="date" value-format="YYYY-MM-DD" style="width:100%" />
					</el-form-item>
				</el-col>
			</el-row>
			<el-form-item label="备注">
				<el-input v-model="form.remark" maxlength="1000" type="textarea" :rows="2" />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">保存（待审核）</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)
const members = ref([])
const memberLoading = ref(false)

const empty = { member_id: null, recharge_amount: 100, gift_amount: 0, pay_method: 'cash', recharge_date: new Date().toISOString().slice(0, 10), remark: '' }
const form = ref({ ...empty })

const rules = {
	member_id: [{ required: true, message: '请选择会员', trigger: 'change' }],
	recharge_amount: [{ required: true, message: '请输入充值金额', trigger: 'blur' }],
}

const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

const fmt = (n) => (n == null ? '0.00' : Number(n).toFixed(2))

watch(() => props.record, (val) => {
	if (!val) { form.value = { ...empty }; return }
	form.value = {
		...empty,
		...val,
		recharge_date: val.recharge_date ? String(val.recharge_date).slice(0, 10) : empty.recharge_date,
		gift_amount: Number(val.gift_amount || 0),
	}
}, { immediate: true })

const resetForm = () => { form.value = { ...empty }; members.value = [] }

const searchMembers = async (kw) => {
	memberLoading.value = true
	try {
		const res = await businessApi.member.search.get({ keyword: kw, limit: 20 })
		members.value = res.data || []
	} finally {
		memberLoading.value = false
	}
}

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = props.record
			? await businessApi.memberRecharge.edit.put(props.record.id, form.value)
			: await businessApi.memberRecharge.add.post(form.value)
		if (res.code === 200) { ElMessage.success(res.message || '已保存，待审核'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>
