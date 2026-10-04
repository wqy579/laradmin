<template>
	<el-dialog model-value="visible" :title="row?.id ? '编辑促销单' : '新增促销单'" width="1100px" top="4vh" @close="$emit('close')" destroy-on-close>
		<div class="form-body" v-loading="loading">
			<!-- 基本信息 -->
			<div class="sec-title">基本信息</div>
			<el-form :model="form" label-position="top">
				<div class="grid">
					<el-form-item label="促销名称" required>
						<el-input v-model="form.name" maxlength="50" show-word-limit placeholder="例如：国庆满100减10" />
					</el-form-item>
					<el-form-item label="促销优先级">
						<el-input-number v-model="form.priority" :min="1" :max="100" style="width:110px" />
						<span class="tip">数字越大越优先</span>
					</el-form-item>
				</div>

				<div class="type-cards">
					<div v-for="t in types" :key="t.value" class="type-card" :class="{active: form.type===t.value}" @click="form.type=t.value">
						<div class="tname">{{ t.label }}</div>
						<div class="tdesc">{{ t.desc }}</div>
					</div>
				</div>

				<div class="grid">
					<el-form-item label="开始时间" required>
						<el-date-picker v-model="form.start_time" type="datetime" value-format="YYYY-MM-DD HH:mm" style="width:200px" />
					</el-form-item>
					<el-form-item label="结束时间" required>
						<el-date-picker v-model="form.end_time" type="datetime" value-format="YYYY-MM-DD HH:mm" style="width:200px" />
					</el-form-item>
					<el-form-item label="适用客户">
						<el-radio-group v-model="form.customer_scope">
							<el-radio value="all">全部客户</el-radio>
							<el-radio value="level">指定等级</el-radio>
							<el-radio value="specified">指定客户</el-radio>
						</el-radio-group>
					</el-form-item>
				</div>
				<el-form-item label="备注">
					<el-input v-model="form.remark" placeholder="可选填促销说明" />
				</el-form-item>
			</el-form>

			<!-- 规则区 -->
			<div class="sec-title">促销规则</div>

			<!-- 限时折扣 -->
			<div v-if="form.type==='discount'">
				<div class="rule-tip blue">选择参与促销的商品，设置折扣率，促销期间订单自动按折扣价计算</div>
				<div class="row-line">
					<span>统一折扣率：</span>
					<el-input-number v-model="globalRate" :min="0.1" :max="9.9" :step="0.1" :precision="1" style="width:110px" @change="applyGlobalRate" />
					<span class="tip">折（可在下方逐商品调整）</span>
				</div>
				<product-table :items="items" type="discount" @add="openPicker" @remove="(i)=>items.splice(i,1)" />
			</div>

			<!-- 特价 -->
			<div v-else-if="form.type==='special_price'">
				<div class="rule-tip red">选择参与特价的商品，设置特价金额（须小于原价），促销期间订单按特价计算</div>
				<product-table :items="items" type="special" @add="openPicker" @remove="(i)=>items.splice(i,1)" />
			</div>

			<!-- 满减 -->
			<div v-else-if="form.type==='full_reduction'">
				<div class="rule-tip orange">设置满减阶梯，订单金额达到对应档位自动减免，可设多档</div>
				<div v-for="(tier,i) in tiers" :key="i" class="tier-row">
					<span class="badge">第{{i+1}}档</span>
					<span>满</span><el-input-number v-model="tier.threshold_amount" :min="0" style="width:120px" />
					<span>元，减</span><el-input-number v-model="tier.discount_amount" :min="0" style="width:120px" />
					<span>元</span>
					<el-button v-if="i>0" link type="danger" @click="tiers.splice(i,1)">×</el-button>
				</div>
				<el-button style="color:#fa8c16;border-color:#fa8c16" :disabled="tiers.length>=5" @click="tiers.push({threshold_amount:0,discount_amount:0})">+ 添加档位</el-button>
				<el-checkbox v-model="form.allow_stack" style="margin-top:10px">允许与折扣/特价促销叠加</el-checkbox>
			</div>

			<!-- 买赠 -->
			<div v-else-if="form.type==='buy_gift'">
				<div class="rule-tip green">购买指定商品达到数量后自动赠送商品</div>
				<div class="row-line">
					<span>购买</span><el-input-number v-model="buyBuyQty" :min="1" style="width:90px" /><span>件，赠送</span>
					<el-input-number v-model="buyGiftQty" :min="1" style="width:90px" /><span>件</span>
				</div>
				<div class="sub-label">购买商品</div>
				<product-table :items="items" type="buy" @add="openPicker" @remove="(i)=>items.splice(i,1)" />
			</div>
		</div>

		<template #footer>
			<el-button @click="$emit('close')">取消</el-button>
			<el-button @click="save('draft')">保存草稿</el-button>
			<el-button type="success" @click="save('enabled')">立即启用</el-button>
		</template>

		<!-- 选商品弹窗 -->
		<el-dialog v-model="pickerVisible" title="选择商品" width="700px" append-to-body>
			<el-input v-model="kw" placeholder="搜索商品名称/编码" clearable style="width:240px;margin-bottom:10px" @keyup.enter="loadProducts" />
			<el-table :data="productOptions" height="360" size="small" border @selection-change="onSelChange">
				<el-table-column type="selection" width="45" />
				<el-table-column prop="code" label="编码" width="110" />
				<el-table-column prop="name" label="名称" min-width="160" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="90" />
				<el-table-column label="原价" width="90" align="right"><template #default="{row}">¥{{ row.price_small }}</template></el-table-column>
			</el-table>
			<template #footer>
				<el-button @click="pickerVisible=false">取消</el-button>
				<el-button type="primary" @click="confirmPick">确定</el-button>
			</template>
		</el-dialog>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import ProductTable from './promotion-product-table.vue'

const props = defineProps({ visible: Boolean, row: { type: Object, default: null } })
const emit = defineEmits(['close', 'saved'])

const types = [
	{ value: 'discount', label: '限时折扣', desc: '指定商品限时打折' },
	{ value: 'full_reduction', label: '满减促销', desc: '满X元减Y元' },
	{ value: 'buy_gift', label: '买赠促销', desc: '买X件赠Y件' },
	{ value: 'special_price', label: '特价促销', desc: '指定商品特价销售' },
]

const form = reactive({
	name: '', type: 'discount', start_time: '', end_time: '', customer_scope: 'all',
	customer_levels: [], customer_ids: [], priority: 1, allow_stack: false, remark: '', status: 'draft',
})
const items = ref([])
const tiers = ref([{ threshold_amount: 0, discount_amount: 0 }])
const globalRate = ref(8.5)
const buyBuyQty = ref(2), buyGiftQty = ref(1)
const loading = ref(false)

const pickerVisible = ref(false), productOptions = ref([]), kw = ref(''), selected = ref([])

onMounted(async () => {
	if (props.row?.id) {
		loading.value = true
		try {
			const res = await businessApi.promotion.detail.get(props.row.id)
			const d = res.data
			Object.assign(form, {
				name: d.name, type: d.type, start_time: (d.start_time||'').replace('T',' ').slice(0,16),
				end_time: (d.end_time||'').replace('T',' ').slice(0,16), customer_scope: d.customer_scope,
				customer_levels: d.customer_levels||[], customer_ids: d.customer_ids||[],
				priority: d.priority, allow_stack: !!d.allow_stack, remark: d.remark||'',
			})
			items.value = (d.items||[]).map(i => ({...i}))
			tiers.value = (d.tiers&&d.tiers.length) ? d.tiers.map(t=>({threshold_amount:Number(t.threshold_amount),discount_amount:Number(t.discount_amount)})) : [{threshold_amount:0,discount_amount:0}]
			const buy = items.value.find(i=>i.buy_qty)
			if (buy) { buyBuyQty.value = buy.buy_qty; buyGiftQty.value = buy.gift_qty }
		} catch(e){ ElMessage.error('加载失败') }
		finally { loading.value = false }
	}
})

function applyGlobalRate() { items.value.forEach(i => i.discount_rate = globalRate.value) }
async function openPicker() { kw.value=''; pickerVisible.value=true; await loadProducts() }
async function loadProducts() {
	const res = await businessApi.promotion.products.get({ keyword: kw.value })
	productOptions.value = res.data?.list || []
}
function onSelChange(rows){ selected.value = rows }
function confirmPick() {
	selected.value.forEach(p => {
		if (items.value.some(i => i.product_id === p.id)) return
		items.value.push({
			product_id: p.id, product_code: p.code, product_name: p.name, spec: p.spec, unit: p.unit,
			original_price: Number(p.price_small)||0, discount_rate: globalRate.value, special_price: null,
			buy_qty: buyBuyQty.value, gift_qty: buyGiftQty.value,
		})
	})
	pickerVisible.value = false
}

async function save(status) {
	if (!form.name) return ElMessage.warning('请填写促销名称')
	if (!form.start_time || !form.end_time) return ElMessage.warning('请选择促销时间')
	const payload = { ...form, status, items: items.value, tiers: tiers.value }
	try {
		if (props.row?.id) await businessApi.promotion.update.put(props.row.id, payload)
		else await businessApi.promotion.create.post(payload)
		ElMessage.success(status==='enabled' ? '已启用' : '草稿已保存')
		emit('saved')
	} catch(e) { ElMessage.error(e?.response?.data?.message || '保存失败') }
}
</script>

<style scoped>
.form-body { max-height: 74vh; overflow-y: auto; padding: 0 4px; }
.sec-title { font-size: 16px; font-weight: bold; color: #333; margin: 16px 0 12px; padding-left: 10px; border-left: 3px solid #1890ff; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; }
.type-cards { display: flex; gap: 12px; margin: 8px 0 16px; }
.type-card { width: 200px; height: 78px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 10px 14px; cursor: pointer; }
.type-card.active { border-color: #1890ff; background: #e6f7ff; box-shadow: 0 0 0 2px rgba(24,144,255,.2); }
.tname { font-size: 15px; font-weight: bold; color: #333; } .tdesc { font-size: 12px; color: #999; margin-top: 4px; }
.rule-tip { height: 32px; display: flex; align-items: center; padding: 0 12px; border-radius: 4px; margin-bottom: 12px; font-size: 13px; }
.rule-tip.blue { background: #e6f7ff; border: 1px solid #91d5ff; color: #1890ff; }
.rule-tip.orange { background: #fff7e6; border: 1px solid #ffe7ba; color: #fa8c16; }
.rule-tip.green { background: #f6ffed; border: 1px solid #b7eb8f; color: #52c41a; }
.rule-tip.red { background: #fff1f0; border: 1px solid #ffa39e; color: #f5222d; }
.row-line { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.tip { font-size: 12px; color: #999; }
.tier-row { display: flex; align-items: center; gap: 8px; background: #fafafa; padding: 6px 12px; border-radius: 4px; margin-bottom: 8px; }
.badge { width: 50px; text-align: center; font-weight: bold; color: #333; }
.sub-label { font-weight: bold; color: #333; margin: 10px 0 6px; }
</style>
