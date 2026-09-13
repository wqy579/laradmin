import { useBreakpoints } from '@vueuse/core'

const breakpoints = useBreakpoints({
	mobile: 768,
	tablet: 1024,
})

export function useResponsive() {
	const isMobile = breakpoints.smaller('mobile')
	const isTablet = breakpoints.between('mobile', 'tablet')
	const isDesktop = breakpoints.greater('tablet')

	return { isMobile, isTablet, isDesktop }
}
