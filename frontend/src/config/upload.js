// 分片上传配置
export const CHUNK_SIZE = 2 * 1024 * 1024 // 2MB 默认分片大小
export const CHUNK_MAX_CONCURRENT = 3 // 并发上传分片数
export const CHUNK_THRESHOLD = 10 * 1024 * 1024 // 超过此大小启用分片上传

// 文件大小限制（MB）
export const MAX_IMAGE_SIZE = 5
export const MAX_FILE_SIZE = 10
export const MAX_CHUNK_FILE_SIZE = 2048 // 2GB

// 图片类型白名单
export const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']
export const IMAGE_ACCEPT = IMAGE_EXTENSIONS.map((ext) => `image/${ext === 'jpg' ? 'jpeg' : ext}`).join(',')

// 通用文件类型白名单
export const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z', 'txt', 'csv', 'mp4', 'mp3', 'avi', 'mov']

// 文件类型映射（用于根据扩展名获取 MIME 类型）
const MIME_MAP = {
	jpg: 'image/jpeg',
	jpeg: 'image/jpeg',
	png: 'image/png',
	gif: 'image/gif',
	webp: 'image/webp',
	bmp: 'image/bmp',
	svg: 'image/svg+xml',
	pdf: 'application/pdf',
	doc: 'application/msword',
	docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	xls: 'application/vnd.ms-excel',
	xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	ppt: 'application/vnd.ms-powerpoint',
	pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	zip: 'application/zip',
	rar: 'application/x-rar-compressed',
	'7z': 'application/x-7z-compressed',
	txt: 'text/plain',
	csv: 'text/csv',
	mp4: 'video/mp4',
	mp3: 'audio/mpeg',
	avi: 'video/x-msvideo',
	mov: 'video/quicktime',
}

export function getAcceptString(extensions = ALLOWED_EXTENSIONS) {
	return extensions.map((ext) => `.${ext}`).join(',')
}

export function getMimeType(ext) {
	return MIME_MAP[ext.toLowerCase()] || 'application/octet-stream'
}

// 格式化文件大小
export function formatFileSize(bytes) {
	if (bytes === 0) return '0 B'
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	const i = Math.floor(Math.log(bytes) / Math.log(1024))
	return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 2 : 0) + ' ' + units[i]
}

// 验证文件扩展名
export function validateExtension(file, allowed = ALLOWED_EXTENSIONS) {
	const ext = file.name.split('.').pop().toLowerCase()
	return allowed.includes(ext)
}

// 验证文件大小（size 单位为字节）
export function validateSize(file, maxMB = MAX_FILE_SIZE) {
	return file.size <= maxMB * 1024 * 1024
}

/**
 * 增量 MD5 哈希计算，用于大文件分片上传的文件指纹
 */
class MD5Stream {
	constructor() {
		this.state = new Int32Array([1732584193, -271733879, -1732584194, 271733878])
		this.buf = new Uint8Array(64)
		this.bufLen = 0
		this.totalLen = 0
	}

	update(data) {
		const bytes = data instanceof Uint8Array ? data : new Uint8Array(data)
		let i = 0

		if (this.bufLen > 0) {
			const fill = Math.min(64 - this.bufLen, bytes.length)
			this.buf.set(bytes.subarray(0, fill), this.bufLen)
			this.bufLen += fill
			i = fill
			if (this.bufLen === 64) {
				this._transform(this.buf)
				this.bufLen = 0
			}
		}

		const blocks = (bytes.length - i) >> 6
		for (let j = 0; j < blocks; j++) {
			this._transform(bytes.subarray(i, i + 64))
			i += 64
		}

		if (i < bytes.length) {
			this.buf.set(bytes.subarray(i), this.bufLen)
			this.bufLen += bytes.length - i
		}

		this.totalLen += bytes.length
	}

	digest() {
		const padLen = this.bufLen < 56 ? 64 : 128
		const pad = new Uint8Array(padLen)
		pad[0] = 0x80
		const bits = this.totalLen * 8
		const dv = new DataView(pad.buffer)
		dv.setInt32(padLen - 8, bits, true)
		dv.setInt32(padLen - 4, bits / 0x100000000, true)
		this.update(pad)

		const result = new Uint8Array(this.state.buffer)
		let hex = ''
		for (let i = 0; i < 16; i++) {
			hex += (result[i] < 16 ? '0' : '') + result[i].toString(16)
		}
		return hex
	}

	_transform(block) {
		const x = new Int32Array(16)
		const dv = new DataView(block.buffer, block.byteOffset, 64)
		for (let i = 0; i < 16; i++) x[i] = dv.getInt32(i * 4, true)

		let a = this.state[0]
		let b = this.state[1]
		let c = this.state[2]
		let d = this.state[3]
		const oa = a
		const ob = b
		const oc = c
		const od = d

		// Round 1
		a = this._ff(a, b, c, d, x[0], 7, -680876936)
		d = this._ff(d, a, b, c, x[1], 12, -389564586)
		c = this._ff(c, d, a, b, x[2], 17, 606105819)
		b = this._ff(b, c, d, a, x[3], 22, -1044525330)
		a = this._ff(a, b, c, d, x[4], 7, -176418897)
		d = this._ff(d, a, b, c, x[5], 12, 1200080426)
		c = this._ff(c, d, a, b, x[6], 17, -1473231341)
		b = this._ff(b, c, d, a, x[7], 22, -45705983)
		a = this._ff(a, b, c, d, x[8], 7, 1770035416)
		d = this._ff(d, a, b, c, x[9], 12, -1958414417)
		c = this._ff(c, d, a, b, x[10], 17, -42063)
		b = this._ff(b, c, d, a, x[11], 22, -1990404162)
		a = this._ff(a, b, c, d, x[12], 7, 1804603682)
		d = this._ff(d, a, b, c, x[13], 12, -40341101)
		c = this._ff(c, d, a, b, x[14], 17, -1502002290)
		b = this._ff(b, c, d, a, x[15], 22, 1236535329)

		// Round 2
		a = this._gg(a, b, c, d, x[1], 5, -165796510)
		d = this._gg(d, a, b, c, x[6], 9, -1069501632)
		c = this._gg(c, d, a, b, x[11], 14, 643717713)
		b = this._gg(b, c, d, a, x[0], 20, -373897302)
		a = this._gg(a, b, c, d, x[5], 5, -701558691)
		d = this._gg(d, a, b, c, x[10], 9, 38016083)
		c = this._gg(c, d, a, b, x[15], 14, -660478335)
		b = this._gg(b, c, d, a, x[4], 20, -405537848)
		a = this._gg(a, b, c, d, x[9], 5, 568446438)
		d = this._gg(d, a, b, c, x[14], 9, -1019803690)
		c = this._gg(c, d, a, b, x[3], 14, -187363961)
		b = this._gg(b, c, d, a, x[8], 20, 1163531501)
		a = this._gg(a, b, c, d, x[13], 5, -1444681467)
		d = this._gg(d, a, b, c, x[2], 9, -51403784)
		c = this._gg(c, d, a, b, x[7], 14, 1735328473)
		b = this._gg(b, c, d, a, x[12], 20, -1926607734)

		// Round 3
		a = this._hh(a, b, c, d, x[5], 4, -378558)
		d = this._hh(d, a, b, c, x[8], 11, -2022574463)
		c = this._hh(c, d, a, b, x[11], 16, 1839030562)
		b = this._hh(b, c, d, a, x[14], 23, -35309556)
		a = this._hh(a, b, c, d, x[1], 4, -1530992060)
		d = this._hh(d, a, b, c, x[4], 11, 1272893353)
		c = this._hh(c, d, a, b, x[7], 16, -155497632)
		b = this._hh(b, c, d, a, x[10], 23, -1094730640)
		a = this._hh(a, b, c, d, x[13], 4, 681279174)
		d = this._hh(d, a, b, c, x[0], 11, -358537222)
		c = this._hh(c, d, a, b, x[3], 16, -722521979)
		b = this._hh(b, c, d, a, x[6], 23, 76029189)
		a = this._hh(a, b, c, d, x[9], 4, -640364487)
		d = this._hh(d, a, b, c, x[12], 11, -421815835)
		c = this._hh(c, d, a, b, x[15], 16, 530742520)
		b = this._hh(b, c, d, a, x[2], 23, -995338651)

		// Round 4
		a = this._ii(a, b, c, d, x[0], 6, -198630844)
		d = this._ii(d, a, b, c, x[7], 10, 1126891415)
		c = this._ii(c, d, a, b, x[14], 15, -1416354905)
		b = this._ii(b, c, d, a, x[5], 21, -57434055)
		a = this._ii(a, b, c, d, x[12], 6, 1700485571)
		d = this._ii(d, a, b, c, x[3], 10, -1894986606)
		c = this._ii(c, d, a, b, x[10], 15, -1051523)
		b = this._ii(b, c, d, a, x[1], 21, -2054922799)
		a = this._ii(a, b, c, d, x[8], 6, 1873313359)
		d = this._ii(d, a, b, c, x[15], 10, -30611744)
		c = this._ii(c, d, a, b, x[6], 15, -1560198380)
		b = this._ii(b, c, d, a, x[13], 21, 1309151649)
		a = this._ii(a, b, c, d, x[4], 6, -145523070)
		d = this._ii(d, a, b, c, x[11], 10, -1120210379)
		c = this._ii(c, d, a, b, x[2], 15, 718787259)
		b = this._ii(b, c, d, a, x[9], 21, -343485551)

		this.state[0] = (a + oa) | 0
		this.state[1] = (b + ob) | 0
		this.state[2] = (c + oc) | 0
		this.state[3] = (d + od) | 0
	}

	_cmn(q, a, b, x, s, t) {
		a = (a + q + x + t) | 0
		return (((a << s) | (a >>> (32 - s))) + b) | 0
	}
	_ff(a, b, c, d, x, s, t) {
		return this._cmn((b & c) | (~b & d), a, b, x, s, t)
	}
	_gg(a, b, c, d, x, s, t) {
		return this._cmn((b & d) | (c & ~d), a, b, x, s, t)
	}
	_hh(a, b, c, d, x, s, t) {
		return this._cmn(b ^ c ^ d, a, b, x, s, t)
	}
	_ii(a, b, c, d, x, s, t) {
		return this._cmn(c ^ (b | ~d), a, b, x, s, t)
	}
}

/**
 * 计算文件的 MD5 哈希值（分块读取，不阻塞 UI）
 * @param {File} file 文件对象
 * @param {(percent: number) => void} onProgress 进度回调 0-100
 * @returns {Promise<string>} 32 位十六进制 MD5 字符串
 */
export function computeFileMD5(file, onProgress) {
	return new Promise((resolve, reject) => {
		const chunkSize = 2 * 1024 * 1024
		const chunks = Math.ceil(file.size / chunkSize)
		let current = 0
		const md5 = new MD5Stream()
		const reader = new FileReader()

		reader.onload = (e) => {
			md5.update(e.target.result)
			current++
			if (onProgress) onProgress(Math.round((current / chunks) * 100))
			if (current < chunks) {
				loadNext()
			} else {
				resolve(md5.digest())
			}
		}
		reader.onerror = () => reject(new Error('文件读取失败'))

		function loadNext() {
			const start = current * chunkSize
			const end = Math.min(start + chunkSize, file.size)
			reader.readAsArrayBuffer(file.slice(start, end))
		}

		loadNext()
	})
}
