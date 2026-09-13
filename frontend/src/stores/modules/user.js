import { ref } from 'vue'
import { defineStore } from 'pinia'
import { resetRouter } from '../../router'
import userRoutes from '../../config/routes'
import authApi from '../../api/auth'
import { useDictionaryStore } from './dictionary'

export const useUserStore = defineStore(
	'user',
	() => {
		const token = ref(localStorage.getItem('laradmin_token') || '')
		const userInfo = ref(null)
		const menu = ref([])
		const permissions = ref([])

function setToken(newToken) {
        token.value = newToken
        localStorage.setItem('laradmin_token', newToken)
    }

		function setUserInfo(info) {
			userInfo.value = info
		}

		function setMenu(newMenu) {
			const staticMenus = userRoutes || []
			let mergedMenus = [...staticMenus]

			if (newMenu && newMenu.length > 0) {
				const menuMap = new Map()

				staticMenus.forEach((menu) => {
					if (menu.path) {
						menuMap.set(menu.path, menu)
					}
				})

				newMenu.forEach((menu) => {
					if (menu.path) {
						menuMap.set(menu.path, menu)
					}
				})

				mergedMenus = Array.from(menuMap.values())
			}
			menu.value = mergedMenus
		}

		function getMenu() {
			return menu.value
		}

		function clearMenu() {
			menu.value = []
		}

		function setPermissions(data) {
			permissions.value = data
		}

		async function logout() {
			if (token.value) {
				try {
					await authApi.logout.post()
				} catch (e) {
					// ignore logout API errors
				}
			}
			token.value = ''
			userInfo.value = null
			menu.value = []
			permissions.value = []
			resetRouter()
			const dictionaryStore = useDictionaryStore()
			dictionaryStore.clearCache()
		}

		function isLoggedIn() {
			return !!token.value
		}

		return {
			token,
			userInfo,
			menu,
			permissions,
			setToken,
			setUserInfo,
			setMenu,
			getMenu,
			clearMenu,
			setPermissions,
			logout,
			isLoggedIn,
		}
	},
	{
		persist: {
			key: 'user-store',
			pick: ['token', 'userInfo', 'permissions'],
		},
	},
)
