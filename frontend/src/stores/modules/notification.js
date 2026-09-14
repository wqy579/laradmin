import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import systemApi from '@/api/system'

export const useNotificationStore = defineStore(
	'notification',
	() => {
		const unreadCount = ref(0)
		const unreadList = ref([])
		const unreadLoading = ref(false)
		const unreadPage = ref(1)
		const unreadPageSize = ref(20)
		const unreadTotal = ref(0)
		const hasMore = computed(() => unreadList.value.length < unreadTotal.value)

		async function fetchUnreadCount() {
			try {
				const res = await systemApi.notification.unreadCount.get()
				if (res.code === 200) {
					unreadCount.value = res.data.count ?? 0
				}
			} catch (e) {
				console.error('获取未读数量失败:', e)
			}
		}

		async function fetchUnreadList(append = false) {
			if (unreadLoading.value) return
			unreadLoading.value = true
			try {
				if (!append) unreadPage.value = 1
				const res = await systemApi.notification.unread.get({
					page: unreadPage.value,
					page_size: unreadPageSize.value,
				})
				if (res.code === 200) {
					if (append) {
						unreadList.value.push(...res.data.list)
					} else {
						unreadList.value = res.data.list
					}
					unreadTotal.value = res.data.total ?? 0
				}
			} catch (e) {
				console.error('获取未读列表失败:', e)
			} finally {
				unreadLoading.value = false
			}
		}

		async function loadMore() {
			if (!hasMore.value || unreadLoading.value) return
			unreadPage.value++
			await fetchUnreadList(true)
		}

		async function markAsRead(id) {
			try {
				const res = await systemApi.notification.markRead.post(id)
				if (res.code === 200) {
					const idx = unreadList.value.findIndex((n) => n.id === id)
					if (idx > -1) unreadList.value.splice(idx, 1)
					unreadCount.value = Math.max(0, unreadCount.value - 1)
				}
			} catch (e) {
				console.error('标记已读失败:', e)
			}
		}

		async function markAllAsRead() {
			try {
				const res = await systemApi.notification.readAll.post()
				if (res.code === 200) {
					unreadList.value = []
					unreadCount.value = 0
				}
			} catch (e) {
				console.error('全部已读失败:', e)
			}
		}

		async function deleteNotification(id) {
			try {
				const res = await systemApi.notification.delete.delete(id)
				if (res.code === 200) {
					unreadList.value = unreadList.value.filter((n) => n.id !== id)
					await fetchUnreadCount()
				}
			} catch (e) {
				console.error('删除通知失败:', e)
			}
		}

		async function clearRead() {
			try {
				const res = await systemApi.notification.clearRead.post()
				if (res.code === 200) {
					await fetchUnreadList()
				}
			} catch (e) {
				console.error('清空已读失败:', e)
			}
		}

		function handleRealtimeNotification(data) {
			unreadCount.value++
			unreadList.value.unshift({
				...data,
				is_read: false,
				created_at: new Date().toISOString(),
			})
			if (unreadList.value.length > 50) {
				unreadList.value = unreadList.value.slice(0, 50)
			}
		}

		async function init() {
			await Promise.all([fetchUnreadCount(), fetchUnreadList()])
		}

		function reset() {
			unreadCount.value = 0
			unreadList.value = []
			unreadPage.value = 1
			unreadTotal.value = 0
		}

		return {
			unreadCount,
			unreadList,
			unreadLoading,
			unreadTotal,
			hasMore,
			fetchUnreadCount,
			fetchUnreadList,
			loadMore,
			markAsRead,
			markAllAsRead,
			deleteNotification,
			clearRead,
			handleRealtimeNotification,
			init,
			reset,
		}
	},
	{
		persist: {
			key: 'notification-store',
			pick: ['unreadCount'],
		},
	},
)
