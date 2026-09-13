<template>
    <div class="delivery-list">
        <sTable
            ref="table"
            :columns="columns"
            :api-data="apiData"
            :search-data="searchData"
            @search-change="handleSearch"
            @search-reset="handleReset"
        >
            <template #btnAdd>
                <el-button type="primary" @click="handleAdd">新建发货单</el-button>
            </template>
            <template #column_status="{ row }">
                <el-tag :type="getStatusType(row.status)">
                    {{ getStatusText(row.status) }}
                </el-tag>
            </template>
        </sTable>

        <delivery-dialog ref="dialog" @success="handleSuccess" />
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import sTable from '@/components/sTable/index.vue'
import deliveryDialog from './dialog.vue'
import businessApi from '@/api/business'

const table = ref(null)
const dialog = ref(null)
const searchData = ref({})
const columns = ref([
    { field: 'delivery_no', title: '发货单号', width: 150 },
    { field: 'order_no', title: '关联订单', width: 120 },
    { field: 'customer_name', title: '客户', width: 120 },
    { field: 'warehouse_name', title: '仓库', width: 100 },
    { field: 'driver_name', title: '司机', width: 100 },
    { field: 'total_amount', title: '总金额', width: 120 },
    { field: 'paid_amount', title: '已收款', width: 120 },
    { field: 'delivery_date', title: '发货日期', width: 120 },
    { field: 'status', title: '状态', width: 100 },
    { field: 'remark', title: '备注', ellipsis: true },
    { title: '操作', width: 220, fixed: 'right',
        buttons: [
            { type: 'view', title: '查看' },
            { type: 'edit', title: '编辑', condition: (row) => row.status === 0 },
            { type: 'custom', title: '发货', condition: (row) => row.status === 0, handler: (row) => handleDispatch(row) },
            { type: 'custom', title: '完成', condition: (row) => row.status === 1, handler: (row) => handleComplete(row) },
            { type: 'delete', title: '删除', condition: (row) => row.status === 0 },
        ]
    },
])

const apiData = () => businessApi.delivery.list.get(searchData.value)

function handleSearch(data) {
    searchData.value = data
    table.value?.reload()
}

function handleReset() {
    searchData.value = {}
    table.value?.reload()
}

function handleAdd() {
    dialog.value?.open()
}

function handleEdit(row) {
    dialog.value?.open(row.id)
}

function handleDelete(row) {
    ElMessage.success('删除成功')
    table.value?.reload()
}

function handleDispatch(row) {
    businessApi.delivery.dispatch.post(row.id).then(() => {
        ElMessage.success('发货成功')
        table.value?.reload()
    })
}

function handleComplete(row) {
    businessApi.delivery.complete.post(row.id).then(() => {
        ElMessage.success('完成成功')
        table.value?.reload()
    })
}

function handleSuccess() {
    table.value?.reload()
}

function getStatusType(status) {
    const map = { 0: 'warning', 1: 'primary', 2: 'success', 3: 'danger' }
    return map[status] || ''
}

function getStatusText(status) {
    const map = { 0: '待发货', 1: '已发货', 2: '已完成', 3: '已取消' }
    return map[status] || '未知'
}
</script>