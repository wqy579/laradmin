import { useUserStore } from '@/stores/modules/user'

/**
 * 权限判断 composable
 * 前端按钮显隐用，仅体验优化；真实拦截在后端 authorizeContent。
 * 超管登录时 permissions 已含全部权限码，故无需单独判超管标记。
 */
export function usePermission() {
	const userStore = useUserStore()

	// 是否拥有指定权限码
	function has(code) {
		return (userStore.permissions || []).includes(code)
	}

	// 是否拥有任意一个权限码
	function hasAny(codes) {
		return codes.some((c) => has(c))
	}

	// 是否拥有全部权限码
	function hasAll(codes) {
		return codes.every((c) => has(c))
	}

	return { has, hasAny, hasAll }
}
