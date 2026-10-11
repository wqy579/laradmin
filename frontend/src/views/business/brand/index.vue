<template>
	<div class="page">
		<div class="header">
			<span class="title">品牌档案</span>
			<el-button type="primary" @click="openForm()">新增品牌</el-button>
		</div>

		<el-card shadow="never" class="card">
			<!-- 查询区 -->
			<div class="filter">
				<el-input v-model="search.keyword" clearable placeholder="品牌名称/编码" style="width:200px" @clear="load" @keyup.enter="load" />
				<el-select v-model="search.is_active" clearable placeholder="状态" style="width:120px;margin-left:8px" @change="load">
					<el-option label="启用" :value="1" />
					<el-option label="停用" :value="0" />
				</el-select>
				<el-button type="primary" style="margin-left:12px" @click="load">查询</el-button>
				<el-button style="margin-left:8px" @click="resetSearch">重置</el-button>
			</div>

			<!-- 操作区 -->
			<div class="ops" v-if="selection.length">
				<el-button type="success" size="small" @click="batchStatus(true)">批量启用</el-button>
				<el-button type="warning" size="small" @click="batchStatus(false)">批量停用</el-button>
				<span class="muted">已选 {{ selection.length }} 项</span>
			</div>

			<el-table :data="list" border stripe v-loading="loading" size="small" @selection-change="onSelectionChange">
				<el-table-column type="selection" width="45" />
				<el-table-column prop="code" label="品牌编码" width="120" align="center" />
				<el-table-column label="品牌名称" min-width="200">
					<template #default="{ row }">
						<el-button link type="primary" @click="viewProducts(row)">{{ row.name }}</el-button>
					</template>
				</el-table-column>
				<el-table-column label="关联分类" width="160" align="center">
					<template #default="{ row }">{{ row.category?.name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="product_count" label="商品数量" width="100" align="center">
					<template #default="{ row }">
						<span :class="{ link: row.product_count > 0 }" @click="row.product_count > 0 && viewProducts(row)">{{ row.product_count }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="sort" label="排序" width="80" align="center" />
				<el-table-column label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="150" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" @click="openForm(row)">编辑</el-button>
						<el-button link type="danger" @click="onDelete(row)">删除</el-button>
					</template>
				</el-table-column>
			</el-table>

			<el-pagination class="pager" background layout="total, prev, pager, next, sizes"
				:total="total" v-model:current-page="page" v-model:page-size="pageSize"
				:page-sizes="[10, 20, 50]" @current-change="load" @size-change="onPageSize" />
		</el-card>

		<!-- 新增/编辑弹窗 -->
		<el-dialog v-model="formVisible" :title="form.id ? '编辑品牌' : '新增品牌'" width="560px">
			<el-form :model="form" label-width="100px">
				<el-form-item label="品牌编码">
					<el-input v-model="form.code" placeholder="如 BR0012，留空自动生成" />
				</el-form-item>
				<el-form-item label="品牌名称" required>
					<el-input v-model="form.name" maxlength="100" placeholder="请输入品牌名称" />
				</el-form-item>
				<el-form-item label="关联分类">
					<el-select v-model="form.category_id" clearable placeholder="选择一级分类（可选）" style="width:100%">
						<el-option v-for="c in mainCategories" :key="c.id" :label="c.name" :value="c.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="排序">
					<el-input-number v-model="form.sort" :min="0" />
				</el-form-item>
				<el-form-item label="状态">
					<el-radio-group v-model="form.is_active">
						<el-radio :value="true">启用</el-radio>
						<el-radio :value="false">停用</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="form.remark" type="textarea" :rows="3" maxlength="500" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" @click="onSave">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import api from '@/api/business.js'

const router = useRouter()

const list = ref([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const pageSize = ref(20)
const selection = ref([])
const search = reactive({ keyword: '', is_active: null })
const formVisible = ref(false)
const form = ref({})
const mainCategories = ref([])

const load = async () => {
	loading.value = true
	try {
		const res = await api.brand.list.get({ ...search, page: page.value, page_size: pageSize.value })
		list.value = res.data?.list || []
		total.value = res.data?.total ?? 0
	} finally { loading.value = false }
}

const loadCategories = async () => {
	// 复用商品档案的分类接口（只取一级分类），避免为品牌单独开一个分类下拉端点
	const res = await api.product.category.list.get()
	mainCategories.value = (res.data || []).map((c) => ({ id: c.id, name: c.name }))
}

const resetSearch = () => { search.keyword = ''; search.is_active = null; page.value = 1; load() }
const onPageSize = (size) => { pageSize.value = size; page.value = 1; load() }
const onSelectionChange = (rows) => { selection.value = rows }

const openForm = (row) => {
	form.value = row
		? { ...row }
		: { name: '', code: '', category_id: null, sort: 0, is_active: true, remark: '' }
	formVisible.value = true
}

const onSave = async () => {
	if (!form.value.name) return ElMessage.warning('请填写品牌名称')
	if (form.value.id) {
		await api.brand.edit.put(form.value.id, form.value)
	} else {
		await api.brand.add.post(form.value)
	}
	ElMessage.success('已保存')
	formVisible.value = false
	load()
}

const onDelete = (row) => {
	ElMessageBox.confirm(`确认删除品牌「${row.name}」？`, '提示', { type: 'warning' })
		.then(async () => { await api.brand.delete.delete(row.id); ElMessage.success('已删除'); load() })
		.catch(() => {})
}

const batchStatus = async (active) => {
	await api.brand.batchUpdateStatus.post({ ids: selection.value.map((r) => r.id), is_active: active })
	ElMessage.success('已更新')
	load()
}

// 点击品牌名/商品数量 → 跳商品档案并按品牌筛选
const viewProducts = (row) => {
	router.push({ path: '/business/product', query: { brand_id: row.id } })
}

onMounted(() => { load(); loadCategories() })
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; padding: 0; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.filter { margin-bottom: 16px; }
.ops { margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
.pager { margin-top: 12px; justify-content: flex-end; }
.muted { color: #999; font-size: 12px; margin-left: 8px; }
.link { color: var(--el-color-primary); cursor: pointer; font-weight: 700; }
</style>
