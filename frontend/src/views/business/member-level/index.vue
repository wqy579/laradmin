<template>
	<div class="page">
		<div class="header">
			<span class="title">会员等级</span>
			<el-button type="primary" @click="handleAdd">新增等级</el-button>
		</div>
		<el-card shadow="never" class="card">
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="level_no" label="等级编号" width="110" align="center" />
				<el-table-column prop="name" label="等级名称" width="140" />
				<el-table-column prop="upgrade_amount" label="升级门槛(元)" width="120" align="right" />
				<el-table-column label="折扣率" width="100" align="center">
					<template #default="{ row }">
						<span class="discount">{{ row.discount_rate }} 折</span>
					</template>
				</el-table-column>
				<el-table-column label="颜色" width="130">
					<template #default="{ row }">
						<span class="dot" :style="{ background: row.color || '#ccc' }"></span>
						<span style="margin-left:6px">{{ row.color }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="member_count" label="会员数" width="90" align="center" />
				<el-table-column prop="balance_total" label="储值合计" width="110" align="right" />
				<el-table-column prop="points_total" label="积分合计" width="100" align="right" />
				<el-table-column prop="sort" label="排序" width="80" align="center" />
				<el-table-column label="状态" width="80" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
							{{ row.status === 1 ? '启用' : '停用' }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="200" align="center" fixed="right">
					<template #default="{ row }">
						<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
						<el-button v-if="row.status === 1" type="warning" link size="small" @click="handleDisable(row)">停用</el-button>
						<el-button v-else type="success" link size="small" @click="handleEnable(row)">启用</el-button>
						<el-popconfirm v-if="!row.is_system" title="确定删除该等级？" @confirm="handleDelete(row)">
							<template #reference>
								<el-button type="danger" link size="small">删除</el-button>
							</template>
						</el-popconfirm>
					</template>
				</el-table-column>
			</el-table>
		</el-card>
	</div>

	<LevelDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="current" @success="load" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import LevelDialog from '../components/member-level-dialog.vue'

const list = ref([])
const loading = ref(false)

const load = async () => {
	loading.value = true
	try {
		const res = await businessApi.memberLevel.list.get()
		list.value = res.data || []
	} finally {
		loading.value = false
	}
}

const dialog = reactive({ visible: false })
const current = ref(null)

const handleAdd = () => { current.value = null; dialog.visible = true }
const handleEdit = (row) => { current.value = row; dialog.visible = true }
const handleDelete = async (row) => {
	const res = await businessApi.memberLevel.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已删除'); load() }
}
const handleEnable = async (row) => {
	const res = await businessApi.memberLevel.enable.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已启用'); load() }
}
const handleDisable = async (row) => {
	const res = await businessApi.memberLevel.disable.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已停用'); load() }
}

onMounted(load)
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; padding: 0; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.discount { color: var(--el-color-primary); font-weight: 700; }
.dot { display: inline-block; width: 14px; height: 14px; border-radius: 3px; vertical-align: middle; }
</style>
