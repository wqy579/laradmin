export default [
  {
    path: '/report/salesman-order',
    component: () => import('@/views/report/salesman-order/index.vue'),
    meta: {
      title: '业务员订单汇总',
      perm: 'rpt:salesman-order'
    }
  }
]