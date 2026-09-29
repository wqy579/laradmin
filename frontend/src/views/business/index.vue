<template>
	<div class="biz-dashboard">
		<h2>业务管理</h2>
		<div class="cards">
			<router-link to="/business/product" class="card">
				<div class="card-icon" style="background:#e8f5e9">[REDACTED_SK_KEY]</div>
				<div class="card-title">产品管理</div>
				<div class="card-desc">管理产品目录、分类、价格</div>
			</router-link>
			<router-link to="/business/customer" class="card">
				<div class="card-icon" style="background:#e3f2fd">[REDACTED_SK_KEY]</div>
				<div class="card-title">客户管理</div>
				<div class="card-desc">管理客户信息和联系资料</div>
			</router-link>
			<router-link to="/business/supplier" class="card">
				<div class="card-icon" style="background:#fff3e0">[REDACTED_SK_KEY]</div>
				<div class="card-title">供应商管理</div>
				<div class="card-desc">管理供应商信息和采购关系</div>
			</router-link>
			<router-link to="/business/warehouse" class="card">
				<div class="card-icon" style="background:#fce4ec">[REDACTED_SK_KEY]</div>
				<div class="card-title">仓库管理</div>
				<div class="card-desc">管理仓库信息和库存位置</div>
			</router-link>
			<router-link to="/business/vehicle" class="card">
				<div class="card-icon" style="background:#e8eaf6">[REDACTED_SK_KEY]</div>
				<div class="card-title">车辆管理</div>
				<div class="card-desc">管理车辆信息和司机资料</div>
			</router-link>
			<router-link to="/business/route" class="card">
				<div class="card-icon" style="background:#e0f2f1">[REDACTED_SK_KEY]</div>
				<div class="card-title">路线管理</div>
				<div class="card-desc">管理配送路线和配送范围</div>
			</router-link>
			<router-link to="/business/sales-order" class="card">
				<div class="card-icon" style="background:#fff8e1">[REDACTED_SK_KEY]</div>
				<div class="card-title">销售订单</div>
				<div class="card-desc">管理销售订单和审批流程</div>
			</router-link>
			<router-link to="/business/stock-history" class="card">
				<div class="card-icon" style="background:#f3e5f5">[REDACTED_SK_KEY]</div>
				<div class="card-title">库存流水</div>
				<div class="card-desc">记录每次库存变化</div>
			</router-link>
			<router-link to="/business/stock" class="card">
				<div class="card-icon" style="background:#e1fbee">[REDACTED_SK_KEY]</div>
				<div class="card-title">库存管理</div>
				<div class="card-desc">查看库存数量和成本</div>
			</router-link>
			<router-link to="/business/employee" class="card">
				<div class="card-icon" style="background:#e7f4ff">[REDACTED_SK_KEY]</div>
				<div class="card-title">员工管理</div>
				<div class="card-desc">管理员工档案和权限</div>
			</router-link>
			<router-link to="/business/attendance" class="card">
				<div class="card-icon" style="background:#fff1e6">[REDACTED_SK_KEY]</div>
				<div class="card-title">考勤管理</div>
				<div class="card-desc">管理员工签到和出勤</div>
			</router-link>
		</div>
	</div>
</template>

<script setup>
</script>

<style scoped>
.biz-dashboard { padding: 24px; }
h2 { margin: 0 0 20px 0; font-size: 20px; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }
.card {
	display: flex; flex-direction: column; align-items: center; padding: 24px 16px;
	border-radius: 12px; text-align: center; text-decoration: none; color: inherit;
	border: 1px solid var(--el-border-color); transition: all 0.2s;
}
.card:hover { border-color: var(--el-color-primary); box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
.card-icon { font-size: 32px; margin-bottom: 12px; width: 56px; height: 56px; display: flex; align-items: center; justify-content: center; border-radius: 12px; }
.card-title { font-size: 15px; font-weight: 600; margin-bottom: 6px; }
.card-desc { font-size: 12px; color: var(--el-text-color-secondary); }
</style>
