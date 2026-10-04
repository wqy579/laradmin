<template>
	<div>
		<el-button style="margin-bottom:10px" :type="type==='special'?'danger':'primary'" plain @click="$emit('add')">+ 添加促销商品</el-button>
		<el-table :data="items" size="small" border height="280">
			<el-table-column prop="product_code" label="编码" width="110" />
			<el-table-column prop="product_name" label="商品名称" min-width="150" show-overflow-tooltip />
			<el-table-column prop="spec" label="规格" width="80" />
			<el-table-column prop="unit" label="单位" width="60" align="center" />
			<el-table-column label="原价" width="90" align="right">
				<template #default="{row}"><span :class="{line: type==='special'}">¥{{ row.original_price }}</span></template>
			</el-table-column>
			<el-table-column v-if="type==='discount'" label="折扣率" width="120" align="center">
				<template #default="{row}"><el-input-number v-model="row.discount_rate" :min="0.1" :max="9.9" :step="0.1" :precision="1" size="small" style="width:90px" /></template>
			</el-table-column>
			<el-table-column v-if="type==='special'" label="特价" width="120" align="center">
				<template #default="{row}"><el-input-number v-model="row.special_price" :min="0" :precision="2" size="small" style="width:100px" /></template>
			</el-table-column>
			<el-table-column v-if="type==='discount'" label="促销价" width="100" align="right">
				<template #default="{row}"><span class="red">¥{{ calcDiscount(row) }}</span></template>
			</el-table-column>
			<el-table-column v-if="type==='special'" label="优惠" width="100" align="right">
				<template #default="{row}"><span class="green">-¥{{ diff(row) }}</span></template>
			</el-table-column>
			<el-table-column label="操作" width="70" align="center">
				<template #default="{ $index }"><el-button link type="danger" @click="$emit('remove', $index)">删除</el-button></template>
			</el-table-column>
		</el-table>
	</div>
</template>

<script setup>
defineProps({ items: { type: Array, default: () => [] }, type: { type: String, default: 'discount' } })
defineEmits(['add', 'remove'])
function calcDiscount(r) { return ((Number(r.original_price)||0) * (Number(r.discount_rate)||0) / 10).toFixed(2) }
function diff(r) { return Math.max(0, (Number(r.original_price)||0) - (Number(r.special_price)||0)).toFixed(2) }
</script>

<style scoped>
.red { color: #f5222d; font-weight: bold; }
.green { color: #52c41a; font-weight: bold; }
.line { text-decoration: line-through; color: #999; }
</style>
