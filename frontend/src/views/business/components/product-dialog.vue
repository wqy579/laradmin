<template>
	<el-dialog v-model="visible" :title="record ? '编辑产品' : '新增产品'" width="900px" destroy-on-close @close="handleClose">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
		<el-form-item label="产品名称" prop="name">
			<el-input v-model="form.name" placeholder="请输入产品名称" maxlength="200" />
		</el-form-item>
		<el-form-item label="主分类" prop="main_category_id">
				<el-select v-model="form.main_category_id" placeholder="请选择主分类" clearable style="width:100%" @change="onMainChange">
					<el-option v-for="cat in mainCats" :key="cat.id" :label="cat.name" :value="cat.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="副分类" prop="sub_category_id">
				<el-select v-model="form.sub_category_id" placeholder="请选择副分类" clearable style="width:100%">
					<el-option v-for="cat in subCats" :key="cat.id" :label="cat.name" :value="cat.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="大单位" prop="price_unit">
				<el-select v-model="form.price_unit" placeholder="请选择大单位" clearable style="width:100%" @change="onUnitChange">
					<el-option v-for="u in units" :key="u.value" :label="u.label" :value="u.value" />
				</el-select>
			</el-form-item>
			<el-form-item label="中单位" prop="barcode_medium_unit">
				<el-select v-model="form.barcode_medium_unit" placeholder="请选择中单位（可选）" clearable style="width:100%" @change="onMediumUnitChange">
					<el-option v-for="u in units" :key="u.value" :label="u.label" :value="u.value" />
				</el-select>
			</el-form-item>
			<el-form-item label="小单位" prop="price_unit_small">
				<el-select v-model="form.price_unit_small" placeholder="请选择小单位" clearable style="width:100%" @change="onUnitChange">
					<el-option v-for="u in units" :key="u.value" :label="u.label" :value="u.value" />
				</el-select>
			</el-form-item>
			<el-form-item label="条码" prop="barcode_small">
				<el-input v-model="form.barcode_small" placeholder="请输入条码" maxlength="50" />
			</el-form-item>
			<el-divider content-position="left">换算关系</el-divider>
			<el-form-item label="换算关系">
				<div style="width:100%; display:flex; flex-direction:column; gap:8px;">
					<div style="display:flex; align-items:center; gap:4px;">
						<span>1</span>
						<el-tag size="small" type="primary">{{ form.price_unit || '大单位' }}</el-tag>
						<span>=</span>
						<el-input-number v-model="form.unit_conversion" :min="1" :precision="0" placeholder="输入数量" style="width:100px" @change="onConversionChange" />
						<el-tag size="small">{{ form.price_unit_small || '小单位' }}</el-tag>
					</div>
					<div v-if="form.barcode_medium_unit" style="display:flex; align-items:center; gap:4px;">
						<span>1</span>
						<el-tag size="small" type="success">{{ form.barcode_medium_unit }}</el-tag>
						<span>=</span>
						<el-input-number v-model="form.unit_conversion_medium" :min="1" :precision="0" placeholder="输入数量" style="width:100px" @change="onConversionChange" />
						<el-tag size="small">{{ form.price_unit_small || '小单位' }}</el-tag>
					</div>
				</div>
			</el-form-item>
			<el-divider content-position="left">标准价</el-divider>
			<el-form-item :label="`标准价(${form.price_unit || '大单位'})`" prop="price_large">
				<el-input-number v-model="form.price_large" :precision="2" :min="0" :step="0.01" style="width:100%" @change="onPriceChange('large')" />
			</el-form-item>
			<el-form-item v-if="form.barcode_medium_unit" :label="`标准价(${form.barcode_medium_unit})`" prop="price_medium">
				<el-input-number v-model="form.price_medium" :precision="2" :min="0" :step="0.01" style="width:100%" @change="onPriceChange('medium')" />
			</el-form-item>
			<el-form-item :label="`标准价(${form.price_unit_small || '小单位'})`" prop="price_small">
				<el-input-number v-model="form.price_small" :precision="2" :min="0" :step="0.01" style="width:100%" @change="onPriceChange('small')" />
			</el-form-item>
			<el-form-item label="图片">
				<sUpload :max-count="1" :max-size="5" @success="handleImageUpload" />
			</el-form-item>
			<el-form-item label="保质天数" prop="shelf_life_days">
				<el-input-number v-model="form.shelf_life_days" :min="0" style="width:100%" />
			</el-form-item>
			<el-form-item label="状态" prop="is_active">
				<el-radio-group v-model="form.is_active">
					<el-radio :value="1">启用</el-radio>
					<el-radio :value="0">作废</el-radio>
				</el-radio-group>
			</el-form-item>
		</el-form>

		<el-divider content-position="left">BOM 物料清单（组装/拆分模板）</el-divider>
		<div style="padding:0 20px">
			<div style="margin-bottom:8px;font-weight:bold;color:#52c41a">组装 BOM（组装一件本商品所需子件）</div>
			<vxe-table :data="bomAssembly" border size="small" style="margin-bottom:8px">
				<vxe-column title="子件商品" width="240">
					<template #default="{ row }">
						<el-select v-model="row.product_id" placeholder="选择子件" filterable size="small" style="width:220px">
							<el-option v-for="p in bomProducts" :key="p.id" :label="`${p.name}${p.spec ? ' | '+p.spec : ''}`" :value="p.id" />
						</el-select>
					</template>
				</vxe-column>
				<vxe-column title="单位用量" width="120" align="center">
					<template #default="{ row }"><el-input-number v-model="row.unit_usage" :min="0.001" :precision="3" :step="1" size="small" controls-position="right" style="width:100px" /></template>
				</vxe-column>
				<vxe-column title="预设成本" width="120" align="right">
					<template #default="{ row }"><el-input-number v-model="row.unit_cost" :precision="2" :min="0" size="small" controls-position="right" style="width:100px" /></template>
				</vxe-column>
				<vxe-column title="操作" width="70" align="center"><template #default="{ $rowIndex }"><el-button link type="danger" size="small" @click="bomAssembly.splice($rowIndex, 1)">删除</el-button></template></vxe-column>
			</vxe-table>
			<el-button size="small" @click="bomAssembly.push({ product_id: null, unit_usage: 1, unit_cost: 0 })">+ 添加组装子件</el-button>

			<div style="margin:16px 0 8px;font-weight:bold;color:#fa8c16">拆分 BOM（一件本商品拆出哪些子件）</div>
			<vxe-table :data="bomSplit" border size="small" style="margin-bottom:8px">
				<vxe-column title="子件商品" width="240">
					<template #default="{ row }">
						<el-select v-model="row.product_id" placeholder="选择子件" filterable size="small" style="width:220px">
							<el-option v-for="p in bomProducts" :key="p.id" :label="`${p.name}${p.spec ? ' | '+p.spec : ''}`" :value="p.id" />
						</el-select>
					</template>
				</vxe-column>
				<vxe-column title="拆分数量" width="120" align="center">
					<template #default="{ row }"><el-input-number v-model="row.unit_usage" :min="0.001" :precision="3" :step="1" size="small" controls-position="right" style="width:100px" /></template>
				</vxe-column>
				<vxe-column title="预设成本" width="120" align="right">
					<template #default="{ row }"><el-input-number v-model="row.unit_cost" :precision="2" :min="0" size="small" controls-position="right" style="width:100px" /></template>
				</vxe-column>
				<vxe-column title="操作" width="70" align="center"><template #default="{ $rowIndex }"><el-button link type="danger" size="small" @click="bomSplit.splice($rowIndex, 1)">删除</el-button></template></vxe-column>
			</vxe-table>
			<el-button size="small" @click="bomSplit.push({ product_id: null, unit_usage: 1, unit_cost: 0 })">+ 添加拆分子件</el-button>
		</div>

		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sUpload from '@/components/sUpload/index.vue'

const props = defineProps({
	visible: Boolean,
	record: Object,
	categories: { type: Array, default: () => [] },
	prefillMain: { type: [Number, null], default: null },
	prefillSub: { type: [Number, null], default: null },
})
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)
const units = ref([])
const calculating = ref(false)
const bomAssembly = ref([])
const bomSplit = ref([])
const bomProducts = ref([])

	const form = ref({
	name: '', main_category_id: null, sub_category_id: null,
	price_unit: '', barcode_medium_unit: '', price_unit_small: '',
	barcode_small: '',
	price_large: null, price_medium: null, price_small: null, shelf_life_days: null, is_active: 1,
	unit_conversion: null, unit_conversion_medium: null,
	image: '',
})

const rules = {
	name: [{ required: true, message: '请输入产品名称', trigger: 'blur' }],
	main_category_id: [{ required: true, message: '请选择主分类', trigger: 'change' }],
	sub_category_id: [{ required: true, message: '请选择副分类', trigger: 'change' }],
	price_unit: [{ required: true, message: '请选择大单位', trigger: 'change' }],
	price_unit_small: [{ required: true, message: '请选择小单位', trigger: 'change' }],
}

const visible = computed({
	get: () => props.visible,
	set: (v) => emit('update:visible', v),
})

const mainCats = computed(() => props.categories.filter(c => c.is_main))
const subCats = computed(() => props.categories.filter(c => !c.is_main))

const loadBom = async (id) => {
	if (!id) { bomAssembly.value = []; bomSplit.value = []; return }
	try {
		const res = await businessApi.product.bom.get(id)
		if (res.code === 200) {
			bomAssembly.value = (res.data?.assembly?.items || []).map(i => ({ product_id: i.product_id, unit_usage: Number(i.unit_usage), unit_cost: Number(i.unit_cost) }))
			bomSplit.value = (res.data?.split?.items || []).map(i => ({ product_id: i.product_id, unit_usage: Number(i.unit_usage), unit_cost: Number(i.unit_cost) }))
		}
	} catch {}
}

watch(() => props.record, (val) => {
	if (val) {
		form.value = {
			name: val.name || '',
			main_category_id: val.main_category_id || null,
			sub_category_id: val.sub_category_id || null,
			price_unit: val.price_unit || '',
			barcode_medium_unit: val.barcode_medium_unit || '',
			price_unit_small: val.price_unit_small || '',
			barcode_small: val.barcode_small || '',
			price_large: val.price_large || null,
			price_medium: val.price_medium || null,
			price_small: val.price_small || null,
			shelf_life_days: val.shelf_life_days || null,
			is_active: val.is_active ? 1 : 0,
			unit_conversion: val.unit_conversion ? parseInt(val.unit_conversion) : null,
			unit_conversion_medium: val.unit_conversion_medium ? parseFloat(val.unit_conversion_medium) : null,
			image: val.image || '',
		}
		loadBom(val.id)
	} else {
		form.value = {
			name: '', main_category_id: props.prefillMain,
			sub_category_id: props.prefillSub,
			price_unit: '', barcode_medium_unit: '', price_unit_small: '',
			barcode_small: '',
			price_large: null, price_medium: null, price_small: null, shelf_life_days: null, is_active: 1,
			unit_conversion: null, unit_conversion_medium: null,
			image: '',
		}
		bomAssembly.value = []; bomSplit.value = []
	}
}, { immediate: true })

const onMainChange = (val) => {
	if (!val) form.value.sub_category_id = null
}

const onUnitChange = () => {
	if (calculating.value) return
	recalculatePrices()
}

const onMediumUnitChange = (val) => {
	if (calculating.value) return
	if (!val) {
		form.value.unit_conversion_medium = null
		form.value.price_medium = null
	}
	recalculatePrices()
}

const onConversionChange = () => {
	if (calculating.value) return
	recalculatePrices()
}

const onPriceChange = (field) => {
	calculating.value = true
	const uc = form.value.unit_conversion
	const ucm = form.value.unit_conversion_medium
	const priceLarge = form.value.price_large
	const priceMedium = form.value.price_medium
	const priceSmall = form.value.price_small

	if (field === 'large' && uc > 0 && priceLarge != null) {
		form.value.price_small = parseFloat((priceLarge / uc).toFixed(2))
		if (ucm > 0) {
			form.value.price_medium = parseFloat((priceLarge / ucm).toFixed(2))
		}
	} else if (field === 'small' && uc > 0 && priceSmall != null) {
		form.value.price_large = parseFloat((priceSmall * uc).toFixed(2))
		if (ucm > 0) {
			form.value.price_medium = parseFloat((priceSmall * uc / ucm).toFixed(2))
		}
	} else if (field === 'medium' && ucm > 0 && priceMedium != null) {
		form.value.price_large = parseFloat((priceMedium * ucm).toFixed(2))
		form.value.price_small = parseFloat((priceMedium * ucm / uc).toFixed(2))
	}

	calculating.value = false
}

const recalculatePrices = () => {
	if (calculating.value) return
	const uc = form.value.unit_conversion
	const ucm = form.value.unit_conversion_medium
	const priceLarge = form.value.price_large
	const priceMedium = form.value.price_medium
	const priceSmall = form.value.price_small

	if (priceLarge != null && uc > 0) {
		form.value.price_small = parseFloat((priceLarge / uc).toFixed(2))
		if (ucm > 0) {
			form.value.price_medium = parseFloat((priceLarge / ucm).toFixed(2))
		}
	}
	else if (priceSmall != null && uc > 0) {
		form.value.price_large = parseFloat((priceSmall * uc).toFixed(2))
		if (ucm > 0) {
			form.value.price_medium = parseFloat((priceSmall * uc / ucm).toFixed(2))
		}
	}
	else if (priceMedium != null && ucm > 0) {
		form.value.price_large = parseFloat((priceMedium * ucm).toFixed(2))
		if (uc > 0) {
			form.value.price_small = parseFloat((priceMedium * ucm / uc).toFixed(2))
		}
	}
}

const handleImageUpload = (data) => {
	form.value.image = data?.url || ''
}

const loadUnits = async () => {
	try {
		const res = await businessApi.product.units.get()
		if (res.code === 200) {
			units.value = res.data || []
		}
	} catch {}
}

const loadBomProducts = async () => {
	try {
		const res = await businessApi.product.list.get({ page_size: 1000, is_active: 1 })
		bomProducts.value = res.data?.list || res.data?.data || []
	} catch {}
}

onMounted(() => {
	loadUnits()
	loadBomProducts()
})

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = props.record
			? await businessApi.product.edit.put(props.record.id, form.value)
			: await businessApi.product.add.post(form.value)
		if (res.code === 200) {
			const productId = props.record ? props.record.id : res.data?.id
			if (productId) {
				try {
					await businessApi.product.bom.save(productId, {
						assembly: bomAssembly.value.filter(i => i.product_id),
						split: bomSplit.value.filter(i => i.product_id),
					})
				} catch {}
			}
			ElMessage.success(res.message || '操作成功')
			emit('success')
			visible.value = false
		}
	} finally {
		submitting.value = false
	}
}

const handleClose = () => { formRef.value?.resetFields() }
</script>
