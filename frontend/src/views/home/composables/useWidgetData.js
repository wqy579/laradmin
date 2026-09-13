import { ref, computed } from 'vue'
import { useTimeoutFn } from '@vueuse/core'

export function useWidgetData(widgetId, fetchFn, options = {}) {
	const { cacheTime = 5 * 60 * 1000, retryCount = 3, retryDelay = 1000, autoFetch = true } = options

	const data = ref(null)
	const loading = ref(false)
	const error = ref(null)
	const lastFetchTime = ref(0)

	const isStale = computed(() => Date.now() - lastFetchTime.value > cacheTime)

	async function fetchData(force = false) {
		if (!force && loading.value) return
		if (!force && data.value && !isStale.value) return

		loading.value = true
		error.value = null

		let attempts = 0

		const tryFetch = async () => {
			try {
				const result = await fetchFn()
				data.value = result
				lastFetchTime.value = Date.now()
				error.value = null
			} catch (err) {
				attempts++
				if (attempts < retryCount) {
					const { start: scheduleRetry } = useTimeoutFn(tryFetch, retryDelay)
					scheduleRetry()
				} else {
					error.value = err.message || '数据加载失败'
				}
			} finally {
				if (attempts >= retryCount) {
					loading.value = false
				}
			}
		}

		await tryFetch()
	}

	function refresh() {
		return fetchData(true)
	}

	function setData(newData) {
		data.value = newData
		lastFetchTime.value = Date.now()
		error.value = null
	}

	if (autoFetch) {
		fetchData()
	}

	return {
		data,
		loading,
		error,
		isStale,
		fetchData,
		refresh,
		setData,
	}
}
