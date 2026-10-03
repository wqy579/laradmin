import config from '../config'

const systemRoutes = [
	{
		path: '/login',
		name: 'Login',
		component: () => import('../views/login/index.vue'),
		meta: {
			title: 'login',
			hidden: true,
		},
	},
	{
		path: '/register',
		name: 'Register',
		component: () => import('../views/login/user-register.vue'),
		meta: {
			title: 'register',
			hidden: true,
		},
	},
	{
		path: '/reset-password',
		name: 'ResetPassword',
		component: () => import('../views/login/reset-password.vue'),
		meta: {
			title: 'resetPassword',
			hidden: true,
		},
	},
	{
		path: '/404',
		name: 'NotFound',
		component: () => import('../layouts/other/404.vue'),
		meta: {
			title: '404',
			hidden: true,
		},
	},
	{
		path: '/',
		name: 'Layout',
		component: () => import('../layouts/index.vue'),
		redirect: config.DASHBOARD_URL,
		children: [
			{
				path: '/dashboard',
				name: 'Dashboard',
				component: () => import('../views/home/index.vue'),
				meta: { title: 'dashboard' },
			},
			{
				path: '/business/product',
				name: 'Product',
				component: () => import('../views/business/product/index.vue'),
				meta: { title: 'product' },
			},
			{
				path: '/business/cost-price',
				name: 'CostPrice',
				component: () => import('../views/business/cost-prices/index.vue'),
				meta: { title: 'costPrice' },
			},
			{
				path: '/business/customers',
				name: 'Customer',
				component: () => import('../views/business/customer/index.vue'),
				meta: { title: 'customer' },
			},
			{
				path: '/business/supplier',
				name: 'Supplier',
				component: () => import('../views/business/supplier/index.vue'),
				meta: { title: 'supplier' },
			},
			{
				path: '/business/warehouse',
				name: 'Warehouse',
				component: () => import('../views/business/warehouse/index.vue'),
				meta: { title: 'warehouse' },
			},
			{
				path: '/business/vehicle',
				name: 'Vehicle',
				component: () => import('../views/business/vehicle/index.vue'),
				meta: { title: 'vehicle' },
			},
			{
				path: '/business/route',
				name: 'Route',
				component: () => import('../views/business/route/index.vue'),
				meta: { title: 'route' },
			},
			{
				path: '/business/employee',
				name: 'Employee',
				component: () => import('../views/business/employee/index.vue'),
				meta: { title: 'employee' },
			},
			{
				path: '/business/sales-order',
				name: 'SalesOrder',
				component: () => import('../views/business/sales-order/index.vue'),
				meta: { title: 'salesOrder' },
			},
			{
				path: '/business/stock-history',
				name: 'StockHistory',
				component: () => import('../views/business/stock-history/index.vue'),
				meta: { title: 'stockHistory' },
			},
			{
				path: '/business/stock-in',
				name: 'StockIn',
				component: () => import('../views/business/stock-in/index.vue'),
				meta: { title: 'stockIn' },
			},
			{
				path: '/business/stock-out',
				name: 'StockOut',
				component: () => import('../views/business/stock-out/index.vue'),
				meta: { title: 'stockOut' },
			},
			{
				path: '/business/stock',
				name: 'Stock',
				component: () => import('../views/business/stock/index.vue'),
				meta: { title: 'stock' },
			},
			{
				path: '/business/stock-monitor',
				name: 'StockMonitor',
				component: () => import('../views/business/stock-monitor/index.vue'),
				meta: { title: 'stockMonitor' },
			},
			{
				path: '/business/stock-check',
				name: 'StockCheck',
				component: () => import('../views/business/stock-check/index.vue'),
				meta: { title: 'stockCheck' },
			},
			{
				path: '/business/return',
				name: 'Return',
				component: () => import('../views/business/return/index.vue'),
				meta: { title: 'return' },
			},
			{
				path: '/business/transfer',
				name: 'Transfer',
				component: () => import('../views/business/transfer/index.vue'),
				meta: { title: 'transfer' },
			},
			{
				path: '/business/delivery',
				name: 'Delivery',
				component: () => import('../views/business/delivery/index.vue'),
				meta: { title: 'delivery' },
			},
			{
				path: '/business/dispatch',
				name: 'Dispatch',
				component: () => import('../views/business/dispatch/index.vue'),
				meta: { title: 'dispatch' },
			},
			{
				path: '/business/attendance',
				name: 'Attendance',
				component: () => import('../views/business/attendance/index.vue'),
				meta: { title: 'attendance' },
			},
			{
				path: '/business/visit/logs',
				name: 'VisitLog',
				component: () => import('../views/business/visit/index.vue'),
				meta: { title: 'visit' },
			},
			// 财务管理
			{
				path: '/business/receive',
				name: 'Receive',
				component: () => import('../views/business/receive/index.vue'),
				meta: { title: 'receive' },
			},
			{
				path: '/business/pay',
				name: 'Pay',
				component: () => import('../views/business/pay/index.vue'),
				meta: { title: 'pay' },
			},
			{
				path: '/business/expense',
				name: 'Expense',
				component: () => import('../views/business/expense/index.vue'),
				meta: { title: 'expense' },
			},
			{
				path: '/business/receivable',
				name: 'Receivable',
				component: () => import('../views/business/receivable/index.vue'),
				meta: { title: 'receivable' },
			},
			{
				path: '/business/payable',
				name: 'Payable',
				component: () => import('../views/business/payable/index.vue'),
				meta: { title: 'payable' },
			},
			{
				path: '/business/cash-flow',
				name: 'CashFlow',
				component: () => import('../views/business/cash-flow/index.vue'),
				meta: { title: 'cashFlow' },
			},
			{
				path: '/business/profit',
				name: 'Profit',
				component: () => import('../views/business/profit/index.vue'),
				meta: { title: 'profit' },
			},
			{
				path: '/business/history',
				name: 'FinanceHistory',
				component: () => import('../views/business/history/index.vue'),
				meta: { title: 'financeHistory' },
			},
			{
				path: '/business/other-incomes',
				name: 'OtherIncome',
				component: () => import('../views/business/other-income/index.vue'),
				meta: { title: 'otherIncome' },
			},
			{
				path: '/business/general-expense',
				name: 'GeneralExpense',
				component: () => import('../views/business/general-expense/index.vue'),
				meta: { title: 'generalExpense' },
			},
			{
				path: '/business/statement',
				name: 'Statement',
				component: () => import('../views/business/statement/index.vue'),
				meta: { title: 'statement' },
			},
			// 权限管理
			{
				path: '/auth/permission',
				name: 'Permission',
				component: () => import('../views/auth/permission/index.vue'),
				meta: { title: 'permission' },
			},
			{
				path: '/auth/role',
				name: 'Role',
				component: () => import('../views/auth/role/index.vue'),
				meta: { title: 'role' },
			},
			{
				path: '/auth/user',
				name: 'User',
				component: () => import('../views/auth/user/index.vue'),
				meta: { title: 'user' },
			},
			{
				path: '/auth/department',
				name: 'Department',
				component: () => import('../views/auth/department/index.vue'),
				meta: { title: 'department' },
			},
		],
	},
]

export default systemRoutes
