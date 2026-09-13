import { ref, reactive, watch, onMounted } from 'vue'

export function useTable(options = {}) {
	const { apiObj = null, searchForm: initialSearchForm = {}, params: extraParams = {}, pageSize: defaultPageSize = 30, pageSizes = [30, 50, 100, 200, 500], hidePagination = false, remoteSort = false, remoteFilter = false, autoLoad = true } = options

	// ---- state ----
	const tableRef = ref(null)
	const data = ref([])
	const total = ref(0)
	const loading = ref(false)
	const emptyText = ref('暂无数据')

	const currentPage = ref(1)
	const innerPageSize = ref(defaultPageSize)

	const sortProp = ref(null)
	const sortOrder = ref(null)

	const searchForm = reactive({ ...initialSearchForm })
	const tableParams = reactive({ ...extraParams })

	const selectedRows = ref([])

	// ---- data fetching ----

	async function getData() {
		if (!apiObj) return

		loading.value = true
		const reqData = {
			page: currentPage.value,
			page_size: innerPageSize.value,
			prop: sortProp.value,
			order: sortOrder.value,
			...tableParams,
			...searchForm,
		}

		if (hidePagination) {
			delete reqData.page
			delete reqData.page_size
		}

		try {
			const res = await apiObj.get(reqData)
			const resData = res.data || {}
			data.value = resData.list || resData.rows || resData.data || []
			total.value = resData.pagination?.total || resData.total || 0
			emptyText.value = '暂无数据'
		} catch (error) {
			emptyText.value = error?.statusText || '请求失败'
		} finally {
			loading.value = false
			if (tableRef.value) {
				tableRef.value.scrollTo(0, 0)
			}
		}
	}

	function refresh() {
		tableRef.value?.clearCheckboxRow?.()
		getData()
	}

	function search() {
		currentPage.value = 1
		tableRef.value?.clearCheckboxRow?.()
		getData()
	}

	function upData(params, page = 1) {
		currentPage.value = page
		Object.assign(tableParams, params || {})
		tableRef.value?.clearCheckboxRow?.()
		getData()
	}

	function reload(params, page = 1) {
		currentPage.value = page
		Object.keys(tableParams).forEach((k) => delete tableParams[k])
		Object.assign(tableParams, params || {})
		tableRef.value?.clearCheckboxRow?.()
		tableRef.value?.clearSort?.()
		tableRef.value?.clearFilter?.()
		getData()
	}

	function resetSearch(callback) {
		Object.keys(searchForm).forEach((key) => {
			searchForm[key] = initialSearchForm[key] !== undefined ? initialSearchForm[key] : ''
		})
		Object.keys(tableParams).forEach((k) => delete tableParams[k])
		if (callback) callback()
		reload({}, 1)
	}

	// ---- pagination ----

	function handlePageChange(page) {
		currentPage.value = page
		getData()
	}

	function handlePageSizeChange(size) {
		innerPageSize.value = size
		getData()
	}

	// ---- sort / filter ----

	function handleSortChange({ column, order }) {
		if (!remoteSort) return
		sortProp.value = column?.field || null
		sortOrder.value = order || null
		getData()
	}

	function handleFilterChange({ column, filters }) {
		if (!remoteFilter) return
		const filterParams = {}
		if (column && filters) {
			filterParams[column.field] = Array.isArray(filters) ? filters.join(',') : filters
		}
		upData(filterParams)
	}

	// ---- selection ----

	function onSelectionChange(rows) {
		selectedRows.value = rows
	}

	function clearSelection() {
		selectedRows.value = []
		tableRef.value?.clearCheckboxRow?.()
	}

	// ---- pagination props for sTable ----

	const paginationProps = reactive({
		currentPage,
		pageSize: innerPageSize,
		total,
		pageSizes,
	})

	// ---- lifecycle ----

	if (apiObj && autoLoad) {
		onMounted(() => getData())
	}

	return {
		tableRef,
		data,
		total,
		loading,
		emptyText,
		selectedRows,
		searchForm,
		tableParams,
		paginationProps,

		refresh,
		search,
		upData,
		reload,
		resetSearch,
		getData,

		handlePageChange,
		handlePageSizeChange,
		handleSortChange,
		handleFilterChange,
		onSelectionChange,
		clearSelection,
	}
}
