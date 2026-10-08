<template>
	<div class="rp-query">
		<div class="rp-query-body" :class="expanded ? 'is-expanded' : 'is-collapsed'">
			<div class="rp-query-grid">
				<template v-for="key in fields" :key="key">
					<!-- 日期类型 -->
					<div class="rp-field" v-if="key === 'date_type'">
						<span class="rp-label">日期类型</span>
						<div class="rp-control">
							<el-select v-model="model.date_type" size="small" style="width: 100%">
								<el-option v-for="o in options.date_types" :key="o.value" :label="o.label" :value="o.value">
									<span>{{ o.label }}</span>
									<span style="float: right; color: #909399; font-size: 12px">{{ o.desc }}</span>
								</el-option>
							</el-select>
						</div>
					</div>

					<!-- 日期区间 -->
					<div class="rp-field" v-else-if="key === 'date_range'">
						<span class="rp-label">日期区间</span>
						<div class="rp-control">
							<el-date-picker v-model="dateRange" type="daterange" size="small" style="width: 100%"
								range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期"
								value-format="YYYY-MM-DD" @change="onDateRange" />
						</div>
					</div>

					<div class="rp-field" v-else-if="key === 'start_time'">
						<span class="rp-label">开始时间</span>
						<div class="rp-control">
							<el-time-picker v-model="model.start_time" size="small" style="width: 100%" placeholder="开始时间"
								value-format="HH:mm:ss" />
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'end_time'">
						<span class="rp-label">结束时间</span>
						<div class="rp-control">
							<el-time-picker v-model="model.end_time" size="small" style="width: 100%" placeholder="结束时间"
								value-format="HH:mm:ss" />
						</div>
					</div>

					<!-- 客户 -->
					<div class="rp-field" v-else-if="key === 'customer_ids'">
						<span class="rp-label">客户</span>
						<div class="rp-control">
							<el-select v-model="model.customer_ids" multiple filterable remote reserve-keyword clearable
								size="small" style="width: 100%" placeholder="输入编码/名称搜索" :remote-method="searchCustomers"
								:loading="optLoading">
								<el-option v-for="o in customerOptions" :key="o.id" :label="`${o.code} ${o.name}`" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'customer_category'">
						<span class="rp-label">客户分类</span>
						<div class="rp-control">
							<el-select v-model="model.customer_category" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.customer_categories" :key="o" :label="o" :value="o" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'customer_level_ids'">
						<span class="rp-label">客户等级</span>
						<div class="rp-control">
							<el-select v-model="model.customer_level_ids" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.customer_levels" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'channel_category'">
						<span class="rp-label">渠道大类</span>
						<div class="rp-control">
							<el-select v-model="model.channel_category" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.channel_categories" :key="o" :label="o" :value="o" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'channel_sub_category'">
						<span class="rp-label">渠道小类</span>
						<div class="rp-control">
							<el-select v-model="model.channel_sub_category" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.channel_sub_categories" :key="o" :label="o" :value="o" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'customer_code'">
						<span class="rp-label">客户编码</span>
						<div class="rp-control">
							<el-input v-model="model.customer_code" size="small" placeholder="客户编码" clearable />
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'customer_name'">
						<span class="rp-label">客户名称</span>
						<div class="rp-control">
							<el-input v-model="model.customer_name" size="small" placeholder="客户名称" clearable />
						</div>
					</div>

					<!-- 商品 -->
					<div class="rp-field" v-else-if="key === 'product_ids'">
						<span class="rp-label">商品</span>
						<div class="rp-control">
							<el-select v-model="model.product_ids" multiple filterable remote reserve-keyword clearable
								size="small" style="width: 100%" placeholder="输入编码/名称搜索" :remote-method="searchProducts"
								:loading="optLoading">
								<el-option v-for="o in productOptions" :key="o.id" :label="`${o.code} ${o.name}`" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'brand_ids'">
						<span class="rp-label">品牌</span>
						<div class="rp-control">
							<el-select v-model="model.brand_ids" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.brands" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'main_category_ids'">
						<span class="rp-label">商品大类</span>
						<div class="rp-control">
							<el-select v-model="model.main_category_ids" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.main_categories" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'sub_category_ids'">
						<span class="rp-label">商品小类</span>
						<div class="rp-control">
							<el-select v-model="model.sub_category_ids" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.sub_categories" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'warehouse_ids'">
						<span class="rp-label">仓库</span>
						<div class="rp-control">
							<el-select v-model="model.warehouse_ids" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.warehouses" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'product_code'">
						<span class="rp-label">商品编码</span>
						<div class="rp-control">
							<el-input v-model="model.product_code" size="small" placeholder="商品编码" clearable />
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'product_name'">
						<span class="rp-label">商品名称</span>
						<div class="rp-control">
							<el-input v-model="model.product_name" size="small" placeholder="商品名称" clearable />
						</div>
					</div>

					<!-- 单据 -->
					<div class="rp-field" v-else-if="key === 'sale_types'">
						<span class="rp-label">销售类型</span>
						<div class="rp-control">
							<el-select v-model="model.sale_types" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.sale_types" :key="o.value" :label="o.label" :value="o.value" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'sources'">
						<span class="rp-label">单据来源</span>
						<div class="rp-control">
							<el-select v-model="model.sources" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.sources" :key="o.value" :label="o.label" :value="o.value" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'payment_methods'">
						<span class="rp-label">收款方式</span>
						<div class="rp-control">
							<el-select v-model="model.payment_methods" multiple collapse-tags collapse-tags-tooltip clearable
								size="small" style="width: 100%">
								<el-option v-for="o in options.payment_methods" :key="o" :label="o" :value="o" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'salesman_ids'">
						<span class="rp-label">业务员</span>
						<div class="rp-control">
							<el-select v-model="model.salesman_ids" multiple collapse-tags collapse-tags-tooltip filterable
								clearable size="small" style="width: 100%">
								<el-option v-for="o in options.salesmen" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'delivery_person_ids'">
						<span class="rp-label">配送员</span>
						<div class="rp-control">
							<el-select v-model="model.delivery_person_ids" multiple collapse-tags collapse-tags-tooltip
								filterable clearable size="small" style="width: 100%">
								<el-option v-for="o in options.delivery_persons" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'operator_ids'">
						<span class="rp-label">制单人</span>
						<div class="rp-control">
							<el-select v-model="model.operator_ids" multiple collapse-tags collapse-tags-tooltip filterable
								clearable size="small" style="width: 100%">
								<el-option v-for="o in options.salesmen" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'approver_ids'">
						<span class="rp-label">审核人</span>
						<div class="rp-control">
							<el-select v-model="model.approver_ids" multiple collapse-tags collapse-tags-tooltip filterable
								clearable size="small" style="width: 100%">
								<el-option v-for="o in options.salesmen" :key="o.id" :label="o.name" :value="o.id" />
							</el-select>
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'order_no'">
						<span class="rp-label">订单号</span>
						<div class="rp-control">
							<el-input v-model="model.order_no" size="small" placeholder="订单号" clearable />
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'delivery_no'">
						<span class="rp-label">出库单号</span>
						<div class="rp-control">
							<el-input v-model="model.delivery_no" size="small" placeholder="出库单号" clearable />
						</div>
					</div>
					<div class="rp-field" v-else-if="key === 'remark'">
						<span class="rp-label">备注</span>
						<div class="rp-control">
							<el-input v-model="model.remark" size="small" placeholder="备注关键字" clearable />
						</div>
					</div>

					<!-- 展示开关 -->
					<div class="rp-field is-check" v-else-if="key === 'include_red_flush'">
						<el-checkbox v-model="model.include_red_flush" size="small">含红冲单据</el-checkbox>
					</div>
					<div class="rp-field is-check" v-else-if="key === 'is_new'">
						<el-checkbox v-model="model.is_new" size="small">只看新品</el-checkbox>
					</div>
					<div class="rp-field is-check" v-else-if="key === 'is_key'">
						<el-checkbox v-model="model.is_key" size="small">只看重点商品</el-checkbox>
					</div>
					<div class="rp-field is-check" v-else-if="key === 'with_price'">
						<el-checkbox v-model="model.with_price" size="small">显示单价</el-checkbox>
					</div>
					<div class="rp-field is-check" v-else-if="key === 'include_zero'">
						<el-checkbox v-model="model.include_zero" size="small">统计为 0 商品</el-checkbox>
					</div>
					<div class="rp-field" v-else-if="key === 'zero_sale'">
						<span class="rp-label">零销售</span>
						<div class="rp-control">
							<el-select v-model="model.zero_sale" size="small" style="width: 100%">
								<el-option v-for="o in options.zero_sale_options" :key="o.value" :label="o.label" :value="o.value" />
							</el-select>
						</div>
					</div>
				</template>
			</div>
		</div>

		<!-- 按钮栏：40px -->
		<div class="rp-toolbar">
			<el-button class="rp-btn-primary" :loading="loading" @click="$emit('search')">查询</el-button>
			<el-button size="small" @click="onReset">重置</el-button>
			<el-button size="small" link @click="expanded = !expanded">
				{{ expanded ? '收起筛选' : '展开筛选' }}
				<el-icon style="margin-left: 4px">
					<component :is="expanded ? 'ArrowUp' : 'ArrowDown'" />
				</el-icon>
			</el-button>

			<!-- 查询模版 -->
			<el-select v-model="currentTemplate" placeholder="查询模版" size="small" style="width: 140px" clearable
				@change="applyTemplate">
				<el-option v-for="t in templates" :key="t.id" :label="t.name" :value="t.id">
					<span>{{ t.name }}</span>
					<el-button link type="danger" size="small" style="float: right"
						@click.stop="removeTemplate(t)">×</el-button>
				</el-option>
			</el-select>
			<el-button size="small" @click="openSaveTemplate">保存为模版</el-button>

			<span class="rp-spacer"></span>
			<slot name="actions" />
			<el-button size="small" :loading="exporting" @click="$emit('export')">导出 Excel</el-button>
			<el-button size="small" @click="$emit('print')">打印</el-button>
			<el-button size="small" circle title="刷新" @click="$emit('refresh')">
				<el-icon><Refresh /></el-icon>
			</el-button>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import businessApi from '@/api/business'

const props = defineProps({
	/** 筛选条件对象（双向绑定，页面侧用 reactive 传入即可） */
	modelValue: { type: Object, required: true },
	/** 要渲染的字段 key 列表，顺序即展示顺序 */
	fields: { type: Array, default: () => [] },
	/** 报表标识，用于查询模版归属（sales / stock / salesman / recent-price） */
	report: { type: String, default: 'sales' },
	loading: { type: Boolean, default: false },
	exporting: { type: Boolean, default: false },
})
const emit = defineEmits(['search', 'refresh', 'export', 'print', 'reset'])

const model = props.modelValue
const expanded = ref(false)
const dateRange = ref(null)

/**
 * 选项数据做成模块级缓存：销售 / 库存 / 业务员三个报表页都会拉同一份 options，
 * 不做缓存就是进一次页面打三次接口，而这些都是字典级数据，分钟级一致足够。
 */
let optionCache = null
const options = reactive({
	date_types: [], sale_types: [], sources: [], zero_sale_options: [],
	warehouses: [], brands: [], main_categories: [], sub_categories: [],
	customer_levels: [], customer_categories: [], channel_categories: [],
	channel_sub_categories: [], payment_methods: [], salesmen: [], delivery_persons: [],
})
const templates = ref([])
const currentTemplate = ref(null)
const optLoading = ref(false)
const productOptions = ref([])
const customerOptions = ref([])

async function loadOptions() {
	if (optionCache) {
		Object.assign(options, optionCache)
	} else {
		const res = await businessApi.report.options.get()
		if (res.code === 200) {
			optionCache = res.data || {}
			Object.assign(options, optionCache)
		}
	}
	loadTemplates()
}

async function loadTemplates() {
	const res = await businessApi.report.templates.list.get({ report: props.report })
	if (res.code === 200) templates.value = res.data?.list || res.data || []
}

/** 商品 / 客户是大表，只在用户输入时按关键字取前 50 条 */
async function searchProducts(keyword) {
	if (!keyword) return
	optLoading.value = true
	try {
		const res = await businessApi.report.options.get({ keyword })
		const list = res.data?.products || []
		// 已选中的项要保留在选项里，否则回显时显示成裸 id
		const picked = model.product_ids || []
		productOptions.value = mergePicked(list, picked, productOptions.value)
	} finally {
		optLoading.value = false
	}
}
async function searchCustomers(keyword) {
	if (!keyword) return
	optLoading.value = true
	try {
		const res = await businessApi.report.options.get({ keyword })
		const list = res.data?.customers || []
		const picked = model.customer_ids || []
		customerOptions.value = mergePicked(list, picked, customerOptions.value)
	} finally {
		optLoading.value = false
	}
}
function mergePicked(list, picked, prev) {
	const map = new Map()
	prev.forEach((i) => map.set(i.id, i))
	list.forEach((i) => map.set(i.id, i))
	// 选中项若不在结果里，补一个占位，至少把 id 显示出来而不是空白
	picked.forEach((id) => { if (!map.has(id)) map.set(id, { id, code: '', name: `#${id}` }) })
	return [...map.values()]
}

function onDateRange(val) {
	model.start_date = val?.[0] || ''
	model.end_date = val?.[1] || ''
}
watch(dateRange, onDateRange)

function onReset() {
	Object.keys(model).forEach((k) => {
		const v = model[k]
		model[k] = Array.isArray(v) ? [] : typeof v === 'boolean' ? false : ''
	})
	// 重置后回到设计默认值：默认按申报日期、默认显示单价
	model.date_type = 'declare'
	model.with_price = true
	dateRange.value = null
	currentTemplate.value = null
	emit('reset')
	emit('search')
}

async function openSaveTemplate() {
	const { value } = await ElMessageBox.prompt('模版名称', '保存查询模版', {
		inputPlaceholder: '例如：上月促销销售',
		inputValidator: (v) => (v && v.trim() ? true : '请输入模版名称'),
	})
	if (!value) return
	const res = await businessApi.report.templates.save.post({
		report: props.report,
		name: value.trim(),
		conditions: JSON.parse(JSON.stringify(model)),
		is_public: 0,
	})
	if (res.code === 200) {
		ElMessage.success('模版已保存')
		loadTemplates()
	} else {
		ElMessage.error(res.message || '保存失败')
	}
}

function applyTemplate(id) {
	if (!id) return
	const t = templates.value.find((x) => x.id === id)
	if (!t) return
	Object.assign(model, t.conditions || {})
	dateRange.value = model.start_date && model.end_date ? [model.start_date, model.end_date] : null
	emit('search')
}

async function removeTemplate(t) {
	await businessApi.report.templates.delete.delete(t.id)
	ElMessage.success('已删除')
	if (currentTemplate.value === t.id) currentTemplate.value = null
	loadTemplates()
}

onMounted(async () => {
	await loadOptions()
	// 页面可能已经预设了默认区间（比如进页面就查本月），回显到日期控件上
	dateRange.value = model.start_date && model.end_date ? [model.start_date, model.end_date] : null
})

defineExpose({ loadOptions })
</script>
