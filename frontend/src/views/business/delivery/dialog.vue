<template>
    <el-dialog v-model="visible" :title="isEdit ? '编辑发货单' : '新建发货单'" width="800px" destroy-on-close>
        <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
            <el-row :gutter="20">
                <el-col :span="12">
                    <el-form-item label="关联订单" prop="order_id">
                        <el-select v-model="form.order_id" placeholder="选择订单" clearable filterable>
                            <el-option v-for="item in salesOrders" :key="item.id" :label="item.order_no + ' - ' + item.customer_name" :value="item.id" />
                        </el-select>
                    </el-form-item>
                </el-col>
                <el-col :span="12">
                    <el-form-item label="客户" prop="customer_id">
                        <el-select v-model="form.customer_id" placeholder="选择客户" filterable>
                            <el-option v-for="item in customers" :key="item.id" :label="item.name" :value="item.id" />
                        </el-select>
                    </el-form-item>
                </el-col>
            </el-row>
            <el-row :gutter="20">
                <el-col :span="12">
                    <el-form-item label="仓库" prop="warehouse_id">
                        <el-select v-model="form.warehouse_id" placeholder="选择仓库">
                            <el-option v-for="item in warehouses" :key="item.id" :label="item.name" :value="item.id" />
                        </el-select>
                    </el-form-item>
                </el-col>
                <el-col :span="12">
                    <el-form-item label="发货日期" prop="delivery_date">
                        <el-date-picker v-model="form.delivery_date" type="date" placeholder="选择日期" value-format="YYYY-MM-DD" />
                    </el-form-item>
                </el-col>
            </el-row>
            <el-row :gutter="20">
                <el-col :span="12">
                    <el-form-item label="车辆" prop="vehicle_id">
                        <el-select v-model="form.vehicle_id" placeholder="选择车辆" clearable filterable>
                            <el-option v-for="item in vehicles" :key="item.id" :label="item.license_plate" :value="item.id" />
                        </el-select>
                    </el-form-item>
                </el-col>
                <el-col :span="12">
                    <el-form-item label="司机" prop="driver_id">
                        <el-select v-model="form.driver_id" placeholder="选择司机" clearable filterable>
                            <el-option v-for="item in employees" :key="item.id" :label="item.name" :value="item.id" />
                        </el-select>
                    </el-form-item>
                </el-col>
            </el-row>
            <el-form-item label="路线" prop="route_id">
                <el-select v-model="form.route_id" placeholder="选择路线" clearable filterable>
                    <el-option v-for="item in routes" :key="item.id" :label="item.name" :value="item.id" />
                </el-select>
            </el-form-item>
            <el-form-item label="发货明细">
                <el-table :data="form.items" border size="small">
                    <el-table-column type="index" width="50" />
                    <el-table-column label="产品" min-width="150">
                        <template #default="{ row, $index }">
                            <el-select v-model="row.product_id" placeholder="选择产品" clearable filterable>
                                <el-option v-for="item in products" :key="item.id" :label="item.name + ' (' + item.sku + ')'" :value="item.id" />
                            </el-select>
                        </template>
                    </el-table-column>
                    <el-table-column label="数量" width="100">
                        <template #default="{ row }">
                            <el-input-number v-model="row.quantity" :min="0.01" :precision="2" size="small" />
                        </template>
                    </el-table-column>
                    <el-table-column label="单价" width="100">
                        <template #default="{ row }">
                            <el-input-number v-model="row.unit_price" :min="0" :precision="2" size="small" />
                        </template>
                    </el-table-column>
                    <el-table-column label="金额" width="100">
                        <template #default="{ row }">
                            {{ (row.quantity * row.unit_price).toFixed(2) }}
                        </template>
                    </el-table-column>
                    <el-table-column width="60">
                        <template #default="{ $index }">
                            <el-button type="danger" link @click="removeItem($index)">删除</el-button>
                        </template>
                    </el-table-column>
                </el-table>
                <el-button type="primary" link @click="addItem" style="margin-top: 10px">添加产品</el-button>
            </el-form-item>
            <el-form-item label="备注">
                <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="备注" />
            </el-form-item>
        </el-form>
        <template #footer>
            <el-button @click="visible = false">取消</el-button>
            <el-button type="primary" :loading="loading" @click="handleSubmit">确定</el-button>
        </template>
    </el-dialog>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({})
const emit = defineEmits(['success'])

const visible = ref(false)
const loading = ref(false)
const isEdit = ref(false)
const formRef = ref(null)

const form = reactive({
    order_id: null,
    warehouse_id: null,
    vehicle_id: null,
    driver_id: null,
    route_id: null,
    customer_id: null,
    delivery_date: null,
    remark: '',
    items: [],
})

const rules = {
    customer_id: [{ required: true, message: '请选择客户', trigger: 'change' }],
    warehouse_id: [{ required: true, message: '请选择仓库', trigger: 'change' }],
}

const customers = ref([])
const warehouses = ref([])
const vehicles = ref([])
const employees = ref([])
const routes = ref([])
const products = ref([])
const salesOrders = ref([])

onMounted(async () => {
    await Promise.all([
        fetchCustomers(),
        fetchWarehouses(),
        fetchVehicles(),
        fetchEmployees(),
        fetchRoutes(),
        fetchProducts(),
        fetchSalesOrders(),
    ])
})

async function fetchCustomers() {
    const res = await businessApi.customer.list.get({ per_page: 1000 })
    customers.value = res.data?.list || []
}

async function fetchWarehouses() {
    const res = await businessApi.warehouse.list.get({ per_page: 1000 })
    warehouses.value = res.data?.list || []
}

async function fetchVehicles() {
    const res = await businessApi.vehicle.list.get({ per_page: 1000 })
    vehicles.value = res.data?.list || []
}

async function fetchEmployees() {
    const res = await businessApi.employee.list.get({ per_page: 1000 })
    employees.value = res.data?.list || []
}

async function fetchRoutes() {
    const res = await businessApi.route.list.get({ per_page: 1000 })
    routes.value = res.data?.list || []
}

async function fetchProducts() {
    const res = await businessApi.product.list.get({ per_page: 1000 })
    products.value = res.data?.list || []
}

async function fetchSalesOrders() {
    const res = await businessApi.salesOrder.list.get({ per_page: 1000, status: 2 })
    salesOrders.value = res.data?.list || []
}

function open(id) {
    visible.value = true
    isEdit.value = !!id
    if (id) {
        businessApi.delivery.detail.get(id).then(res => {
            const data = res.data
            Object.assign(form, {
                order_id: data.order_id,
                warehouse_id: data.warehouse_id,
                vehicle_id: data.vehicle_id,
                driver_id: data.driver_id,
                route_id: data.route_id,
                customer_id: data.customer_id,
                delivery_date: data.delivery_date,
                remark: data.remark,
                items: data.items || [],
            })
        })
    } else {
        resetForm()
    }
}

function resetForm() {
    Object.assign(form, {
        order_id: null,
        warehouse_id: null,
        vehicle_id: null,
        driver_id: null,
        route_id: null,
        customer_id: null,
        delivery_date: null,
        remark: '',
        items: [],
    })
}

function addItem() {
    form.items.push({ product_id: null, quantity: 1, unit_price: 0 })
}

function removeItem(index) {
    form.items.splice(index, 1)
}

function handleSubmit() {
    formRef.value?.validate(async valid => {
        if (!valid) return
        loading.value = true
        try {
            if (isEdit.value) {
                await businessApi.delivery.edit.put(form.id, form)
                ElMessage.success('更新成功')
            } else {
                await businessApi.delivery.add.post(form)
                ElMessage.success('创建成功')
            }
            visible.value = false
            emit('success')
        } catch (e) {
            console.error(e)
        } finally {
            loading.value = false
        }
    })
}

defineExpose({ open })
</script>