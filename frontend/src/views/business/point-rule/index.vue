<template>
	<div class="page">
		<div class="header">
			<span class="title">积分规则配置</span>
			<div>
				<el-button @click="handleReset">重置为默认值</el-button>
				<el-button type="primary" :loading="saving" @click="handleSave">保存配置</el-button>
			</div>
		</div>
		<el-card shadow="never" class="card" v-loading="loading">
			<el-form label-width="150px" class="rule-form">
				<!-- 消费积分比例 -->
				<el-form-item label="消费积分比例">
					<el-input-number v-model="consume.amount" :min="0.01" :precision="2" style="width:110px" />
					<span class="hint">元获得</span>
					<el-input-number v-model="consume.points" :min="1" style="width:110px" />
					<span class="hint">积分（每消费多少元得多少分）</span>
				</el-form-item>
				<el-form-item label="注册赠送积分">
					<el-input-number v-model="register.points" :min="0" style="width:140px" />
					<span class="hint">新会员注册即送积分</span>
				</el-form-item>
				<el-form-item label="生日赠送积分">
					<el-input-number v-model="birthday.points" :min="0" style="width:140px" />
					<span class="hint">会员生日当月赠送</span>
				</el-form-item>
				<el-form-item label="邀请赠送积分">
					<span class="hint">邀请人</span>
					<el-input-number v-model="invite.inviter" :min="0" style="width:120px" />
					<span class="hint">/ 被邀请人</span>
					<el-input-number v-model="invite.invitee" :min="0" style="width:120px" />
				</el-form-item>
				<el-divider />
				<el-form-item label="积分抵扣比例">
					<el-input-number v-model="deductRatio.points" :min="1" style="width:120px" />
					<span class="hint">积分抵扣</span>
					<el-input-number v-model="deductRatio.amount" :min="0.01" :precision="2" style="width:120px" />
					<span class="hint">元</span>
				</el-form-item>
				<el-form-item label="单笔抵扣上限">
					<el-select v-model="deductLimit.type" style="width:140px">
						<el-option label="不限制" value="unlimited" />
						<el-option label="按订单比例" value="percent" />
						<el-option label="固定金额" value="fixed" />
					</el-select>
					<el-input-number v-if="deductLimit.type !== 'unlimited'"
						v-model="deductLimit.value" :min="0" :precision="2" style="width:140px" />
					<span class="hint">{{ deductLimit.type === 'percent' ? '占订单金额百分比' : deductLimit.type === 'fixed' ? '固定金额上限(元)' : '' }}</span>
				</el-form-item>
				<el-form-item label="积分使用门槛">
					<el-input-number v-model="deductThreshold.amount" :min="0" :precision="2" style="width:140px" />
					<span class="hint">订单实付满此金额才可使用积分</span>
				</el-form-item>
				<el-form-item label="积分有效期">
					<el-select v-model="expire.type" style="width:140px">
						<el-option label="永久有效" value="forever" />
						<el-option label="按年过期" value="yearly" />
					</el-select>
				</el-form-item>
				<el-form-item label="过期提醒">
					<el-tag v-for="d in expireRemind.days" :key="d" closable @close="expireRemind.days.splice(expireRemind.days.indexOf(d), 1)">
						提前 {{ d }} 天
					</el-tag>
					<el-input v-model="newDay" size="small" style="width:90px" placeholder="天数"
						@keyup.enter="addRemindDay" />
					<el-button size="small" @click="addRemindDay">添加</el-button>
				</el-form-item>
				<el-divider />
				<el-form-item label="分类积分比例">
					<div class="hint" style="margin-bottom:8px">按商品分类覆盖积分比例（第一期不启用，可在扩展时配置）</div>
					<div v-for="(c, i) in categoryRatio" :key="i" style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
						<el-input v-model="c.category_id" placeholder="分类ID" style="width:120px" />
						<el-input-number v-model="c.amount" :min="0.01" :precision="2" style="width:110px" />
						<span class="hint">元</span>
						<el-input-number v-model="c.points" :min="1" style="width:110px" />
						<span class="hint">积分</span>
						<el-button type="danger" link size="small" @click="categoryRatio.splice(i, 1)">删除</el-button>
					</div>
					<el-button size="small" @click="categoryRatio.push({ category_id: '', amount: 1, points: 1 })">添加分类比例</el-button>
				</el-form-item>
			</el-form>
		</el-card>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'

const loading = ref(false)
const saving = ref(false)
const newDay = ref('')

// 各规则的编辑态对象，load() 里从后端填充
const consume = reactive({ amount: 1, points: 1 })
const categoryRatio = ref([])
const register = reactive({ points: 0 })
const birthday = reactive({ points: 0 })
const invite = reactive({ inviter: 0, invitee: 0 })
const deductRatio = reactive({ points: 100, amount: 1 })
const deductLimit = reactive({ type: 'unlimited', value: 0 })
const deductThreshold = reactive({ amount: 0 })
const expire = reactive({ type: 'forever', value: '' })
const expireRemind = reactive({ days: [] })

const parse = (json, fallback = {}) => {
	if (!json) return { ...fallback }
	if (typeof json === 'string') {
		try { return { ...fallback, ...JSON.parse(json) } } catch { return { ...fallback } }
	}
	return { ...fallback, ...json }
}

const load = async () => {
	loading.value = true
	try {
		const res = await businessApi.pointRule.list.get()
		const rows = res.data || []
		const byKey = Object.fromEntries(rows.map((r) => [r.rule_key, r.rule_value]))

		Object.assign(consume, parse(byKey.consume_ratio, consume))
		categoryRatio.value = Array.isArray(byKey.category_ratio) ? byKey.category_ratio : []
		Object.assign(register, parse(byKey.register_gift, register))
		Object.assign(birthday, parse(byKey.birthday_gift, birthday))
		Object.assign(invite, parse(byKey.invite_gift, invite))
		Object.assign(deductRatio, parse(byKey.deduct_ratio, deductRatio))
		Object.assign(deductLimit, parse(byKey.deduct_limit, deductLimit))
		Object.assign(deductThreshold, parse(byKey.deduct_threshold, deductThreshold))
		Object.assign(expire, parse(byKey.expire_type, expire))
		Object.assign(expireRemind, parse(byKey.expire_remind, expireRemind))
	} finally {
		loading.value = false
	}
}

const addRemindDay = () => {
	const d = parseInt(newDay.value, 10)
	if (!d || d <= 0) return
	if (!expireRemind.days.includes(d)) expireRemind.days.push(d)
	newDay.value = ''
}

const handleSave = async () => {
	saving.value = true
	try {
		const res = await businessApi.pointRule.save.put({
			rules: [
				{ rule_key: 'consume_ratio', rule_value: consume },
				{ rule_key: 'category_ratio', rule_value: categoryRatio.value },
				{ rule_key: 'register_gift', rule_value: register },
				{ rule_key: 'birthday_gift', rule_value: birthday },
				{ rule_key: 'invite_gift', rule_value: invite },
				{ rule_key: 'deduct_ratio', rule_value: deductRatio },
				{ rule_key: 'deduct_limit', rule_value: deductLimit },
				{ rule_key: 'deduct_threshold', rule_value: deductThreshold },
				{ rule_key: 'expire_type', rule_value: expire },
				{ rule_key: 'expire_remind', rule_value: expireRemind },
			],
		})
		if (res.code === 200) ElMessage.success(res.message || '已保存')
	} finally {
		saving.value = false
	}
}

const handleReset = () => {
	ElMessageBox.confirm('确定将所有积分规则重置为系统默认值？', '提示', { type: 'warning' })
		.then(async () => {
			const res = await businessApi.pointRule.reset.post()
			if (res.code === 200) { ElMessage.success(res.message || '已重置'); load() }
		}).catch(() => {})
}

onMounted(load)
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; padding: 0; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.rule-form { max-width: 900px; }
.hint { margin: 0 8px; color: #999; font-size: 13px; }
</style>
