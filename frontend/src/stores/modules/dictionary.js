import { ref } from 'vue'
import { defineStore } from 'pinia'
import systemApi from '../../api/system'

export const useDictionaryStore = defineStore(
	'dictionary',
	() => {
		// 按 code 缓存字典项列表，如 { gender: [{label:'男', value:1}, ...] }
		const dictionaries = ref({})
		const loading = ref(false)

		// 一次性加载全部字典数据
		async function loadAllDictionaries(forceRefresh = false) {
			if (!forceRefresh && Object.keys(dictionaries.value).length > 0) return

			loading.value = true
			try {
				const res = await systemApi.dictionaryItem.all.get()
				if (res.code === 200) {
					const list = res.data || []
					const grouped = {}
					list.forEach((dict) => {
						if (!dict.code) return
						grouped[dict.code] = dict.items || []
					})
					dictionaries.value = grouped
				}
			} catch (error) {
				console.error('加载字典数据失败:', error)
			} finally {
				loading.value = false
			}
		}

		// 按 code 获取字典数据数组
		function getDictionary(code) {
			return dictionaries.value[code] || []
		}

		// 按 code 和 value 反查 label
		function getLabelByValue(code, value) {
			const items = dictionaries.value[code] || []
			const item = items.find((i) => String(i.value) === String(value))
			return item?.label || value
		}

		// 清空所有字典缓存
		function clearCache() {
			dictionaries.value = {}
		}

		return {
			dictionaries,
			loading,
			loadAllDictionaries,
			getDictionary,
			getLabelByValue,
			clearCache,
		}
	},
	{
		persist: {
			key: 'dictionary-store',
			pick: ['dictionaries'],
		},
	},
)
