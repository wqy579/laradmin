import { ElMessage } from 'element-plus'

/**
 * 下载 Blob 文件
 * @param {Blob} blob - 文件 Blob 对象
 * @param {string} filename - 文件名
 */
export function downloadBlob(blob, filename) {
	const url = window.URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	window.URL.revokeObjectURL(url)
}

/**
 * 从 URL 下载文件
 * @param {string} url - 文件 URL
 * @param {string} filename - 文件名
 */
export function downloadFromUrl(url, filename) {
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	link.target = '_blank'
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
}

/**
 * 从响应头提取文件名
 * @param {string} contentDisposition - Content-Disposition 头
 * @returns {string} 文件名
 */
export function extractFilenameFromHeader(contentDisposition) {
	if (!contentDisposition) return 'export.xlsx'

	const filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/
	const matches = filenameRegex.exec(contentDisposition)

	if (matches && matches[1]) {
		let filename = matches[1].replace(/['"]/g, '')
		if (filename.includes('UTF-8')) {
			const utf8Regex = /UTF-8''(.+)/
			const utf8Matches = utf8Regex.exec(filename)
			if (utf8Matches) {
				filename = decodeURIComponent(utf8Matches[1])
			}
		}
		return filename
	}

	return 'export.xlsx'
}

/**
 * 下载 API 响应的文件
 * @param {Object} response - axios 响应对象
 * @param {string} defaultFilename - 默认文件名
 */
export function downloadApiResponse(response, defaultFilename = 'export.xlsx') {
	try {
		const blob = response.data || response

		if (response.headers) {
			const contentDisposition = response.headers['content-disposition']
			if (contentDisposition) {
				const filename = extractFilenameFromHeader(contentDisposition)
				downloadBlob(blob, filename)
				return
			}
		}

		downloadBlob(blob, defaultFilename)
	} catch (error) {
		console.error('下载文件失败:', error)
		ElMessage.error('下载文件失败')
	}
}
