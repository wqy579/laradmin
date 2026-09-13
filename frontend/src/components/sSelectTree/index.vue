<template>
	<el-tree-select v-bind="$attrs" :loading="innerLoading" :data="innerOptions" :props="treeProps" :render-after-expand="renderAfterExpand" :check-strictly="checkStrictly" />
</template>

<script setup>
import { ref, watch, onMounted, computed } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
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
	renderAfterExpand: {
		type: Boolean,
		default: true,
	},
	checkStrictly: {
		type: Boolean,
		default: false,
	},
	lazy: {
		type: Boolean,
		default: false,
	},
	loadMethod: {
		type: String,
		default: 'get',
	},
})

const innerOptions = ref([])
const innerLoading = ref(false)

// 树形结构字段映射
const treeProps = computed(() => ({
	label: props.params.label || 'label',
	value: props.params.value || 'value',
	children: props.params.children || 'children',
	disabled: props.params.disabled || 'disabled',
	isLeaf: props.params.isLeaf || 'isLeaf',
}))

// 处理节点数据
const mapNode = (node) => {
	const childrenKey = props.params.children || 'children'
	const result = {
		...node,
	}

	if (node[childrenKey] && Array.isArray(node[childrenKey])) {
		result[childrenKey] = node[childrenKey].map(mapNode)
	}

	return result
}

// 加载选项
const loadOptions = () => {
	if (props.apiObj) {
		loadFromApi()
	} else if (Array.isArray(props.options)) {
		innerOptions.value = props.options.map(mapNode)
	}
}

// 从接口加载
const loadFromApi = async () => {
	try {
		innerLoading.value = true
		const params = { ...props.apiParams }
		let res
		if (typeof props.apiObj === 'function') {
			res = await props.apiObj(params)
		} else if (props.apiObj?.[props.loadMethod]) {
			res = await props.apiObj[props.loadMethod](params)
		}
		if (res?.code === 200) {
			const data = res.data || []
			innerOptions.value = (Array.isArray(data) ? data : data.list || data.rows || data.data || []).map(mapNode)
		}
	} catch (error) {
		console.error('SSelectTree 加载数据失败:', error)
	} finally {
		innerLoading.value = false
	}
}

// 懒加载节点数据（供 el-tree-select 的 lazy load 方法使用）
const loadNodeData = async (node, resolve) => {
	if (!props.apiObj || !props.lazy) return

	try {
		innerLoading.value = true
		const params = { ...props.apiParams, parent_id: node.key }
		let res
		if (typeof props.apiObj === 'function') {
			res = await props.apiObj(params)
		} else if (props.apiObj?.[props.loadMethod]) {
			res = await props.apiObj[props.loadMethod](params)
		}
		if (res?.code === 200) {
			const data = res.data || []
			const nodes = (Array.isArray(data) ? data : data.list || data.rows || data.data || []).map(mapNode)
			resolve(nodes)
		} else {
			resolve([])
		}
	} catch (error) {
		console.error('SSelectTree 加载子节点失败:', error)
		resolve([])
	} finally {
		innerLoading.value = false
	}
}

watch(() => props.options, loadOptions, { deep: true })

onMounted(loadOptions)

defineExpose({ loadNodeData })
</script>
