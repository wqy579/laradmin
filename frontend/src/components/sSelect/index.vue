<template>
	<el-select v-bind="$attrs" :loading="innerLoading" :remote-method="remoteSearch" :filterable="filterable" :remote="remote">
		<slot />
		<el-option v-for="item in innerOptions" :key="item.value" :label="item.label" :value="item.value" :disabled="item.disabled" />
	</el-select>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { useDictionaryStore } from '@/stores/modules/dictionary'

defineOptions({ inheritAttrs: false })

const props = defineProps({
	dict: {
		type: String,
		default: '',
	},
	apiObj: {
		type: [Object, Function],
		default: null,
	},
	options: {
		type: Array,
		default: () => [],
	},
	params: {
		type: Object,
		default: () => ({}),
	},
	apiParams: {
		type: Object,
		default: () => ({}),
	},
	remote: {
		type: Boolean,
		default: false,
	},
	filterable: {
		type: Boolean,
		default: false,
	},
	searchKey: {
		type: String,
		default: 'keyword',
	},
})

const dictionaryStore = useDictionaryStore()
const innerOptions = ref([])
const innerLoading = ref(false)

// 字段映射
const mapItem = (item) => {
	const labelKey = props.params.label || 'label'
	const valueKey = props.params.value || 'value'
	return {
		label: item[labelKey],
		value: item[valueKey],
		disabled: item.status === false,
	}
}

// 加载选项
const loadOptions = () => {
	if (props.dict) {
		const items = dictionaryStore.getDictionary(props.dict) || []
		innerOptions.value = items.filter((item) => item.status !== false).map(mapItem)
	} else if (props.apiObj) {
		loadFromApi()
	} else if (Array.isArray(props.options)) {
		innerOptions.value = props.options.map(mapItem)
	}
}

// 从接口加载
const loadFromApi = async (searchParams = {}) => {
	try {
		innerLoading.value = true
		const params = { ...props.apiParams, ...searchParams }
		let res
		if (typeof props.apiObj === 'function') {
			res = await props.apiObj(params)
		} else if (props.apiObj?.get) {
			res = await props.apiObj.get(params)
		}
		if (res?.code === 200) {
			const data = res.data || []
			innerOptions.value = (Array.isArray(data) ? data : data.list || data.rows || data.data || []).map(mapItem)
		}
	} catch (error) {
		console.error('SSelect 加载数据失败:', error)
	} finally {
		innerLoading.value = false
	}
}

// 远程搜索
const remoteSearch = (query) => {
	if (!props.apiObj || !query) return
	loadFromApi({ [props.searchKey]: query })
}

watch(() => props.options, loadOptions, { deep: true })
watch(() => props.dict, loadOptions)

onMounted(loadOptions)
</script>
