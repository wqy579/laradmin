/**
 * 业务员订单汇总表 — Mock 数据
 *
 * TODO(api):
 *   GET /api/report/salesman-order/items
 *   GET /api/report/salesman-order/customer-item
 *   GET /api/report/salesman-order/salesman
 *   GET /api/report/salesman-order/export
 */
import config from '../config'

const NOW = new Date()
const ONE_YEAR_AGO = new Date(NOW.getFullYear() - 1, NOW.getMonth(), 1)

// ── 商品明细：30+ 行，覆盖多业务员/客户/商品 ──
const SALESMANS = ['张三', '李四', '王五', '赵六', '刘七']
const CUSTOMERS = ['客户 A', '客户 B', '客户 C', '客户 D', '客户 E']
const PRODUCTS = ['产品 101', '产品 102', '产品 103', '产品 104', '产品 105']

const items = [
  { seq: 1, apply_date: '2026-01-10', salesman: '张三', customer: '客户 A', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 150, unit_price: 8.5, amount: 1275, status: '已完成成功' },
  { seq: 2, apply_date: '2026-01-15', salesman: '李四', customer: '客户 A', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '已完成成功' },
  { seq: 3, apply_date: '2026-01-20', salesman: '张三', customer: '客户 B', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 200, unit_price: 12, amount: 2400, status: '已完成成功' },
  { seq: 4, apply_date: '2026-01-25', salesman: '李四', customer: '客户 B', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 150, unit_price: 12, amount: 1800, status: '待审核' },
  { seq: 5, apply_date: '2026-02-01', salesman: '王五', customer: '客户 A', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 80, unit_price: 25, amount: 2000, status: '已完成成功' },
  { seq: 6, apply_date: '2026-02-05', salesman: '张三', customer: '客户 C', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 120, unit_price: 18, amount: 2160, status: '已完成成功' },
  { seq: 7, apply_date: '2026-02-10', salesman: '李四', customer: '客户 C', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 100, unit_price: 18, amount: 1800, status: '待审核' },
  { seq: 8, apply_date: '2026-02-15', salesman: '王五', customer: '客户 D', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 200, unit_price: 30, amount: 6000, status: '已完成成功' },
  { seq: 9, apply_date: '2026-02-20', salesman: '赵六', customer: '客户 A', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '已完成成功' },
  { seq: 10, apply_date: '2026-03-01', salesman: '刘七', customer: '客户 B', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 120, unit_price: 12, amount: 1440, status: '待审核' },
  { seq: 11, apply_date: '2026-03-05', salesman: '张三', customer: '客户 C', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 80, unit_price: 25, amount: 2000, status: '已完成成功' },
  { seq: 12, apply_date: '2026-03-10', salesman: '李四', customer: '客户 D', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 50, unit_price: 30, amount: 1500, status: '已完成成功' },
  { seq: 13, apply_date: '2026-03-15', salesman: '王五', customer: '客户 E', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '已完成成功' },
  { seq: 14, apply_date: '2026-03-20', salesman: '赵六', customer: '客户 A', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 100, unit_price: 18, amount: 1800, status: '待审核' },
  { seq: 15, apply_date: '2026-03-25', salesman: '刘七', customer: '客户 B', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 100, unit_price: 30, amount: 3000, status: '已完成成功' },
  { seq: 16, apply_date: '2026-04-01', salesman: '张三', customer: '客户 C', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 120, unit_price: 8.5, amount: 1020, status: '已完成成功' },
  { seq: 17, apply_date: '2026-04-05', salesman: '李四', customer: '客户 D', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 80, unit_price: 18, amount: 1440, status: '待审核' },
  { seq: 18, apply_date: '2026-04-10', salesman: '王五', customer: '客户 E', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 100, unit_price: 12, amount: 1200, status: '已完成成功' },
  { seq: 19, apply_date: '2026-04-15', salesman: '赵六', customer: '客户 C', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 80, unit_price: 30, amount: 2400, status: '已完成成功' },
  { seq: 20, apply_date: '2026-04-20', salesman: '刘七', customer: '客户 A', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 100, unit_price: 25, amount: 2500, status: '已完成成功' },
  { seq: 21, apply_date: '2026-05-01', salesman: '张三', customer: '客户 B', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 150, unit_price: 8.5, amount: 1275, status: '已完成成功' },
  { seq: 22, apply_date: '2026-05-05', salesman: '李四', customer: '客户 C', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 120, unit_price: 12, amount: 1440, status: '待审核' },
  { seq: 23, apply_date: '2026-05-10', salesman: '王五', customer: '客户 D', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 200, unit_price: 30, amount: 6000, status: '已完成成功' },
  { seq: 24, apply_date: '2026-05-15', salesman: '赵六', customer: '客户 A', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 80, unit_price: 25, amount: 2000, status: '待审核' },
  { seq: 25, apply_date: '2026-05-20', salesman: '刘七', customer: '客户 E', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '已完成成功' },
  { seq: 26, apply_date: '2026-06-01', salesman: '张三', customer: '客户 B', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 80, unit_price: 18, amount: 1440, status: '已完成成功' },
  { seq: 27, apply_date: '2026-06-05', salesman: '李四', customer: '客户 C', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 100, unit_price: 30, amount: 3000, status: '待审核' },
  { seq: 28, apply_date: '2026-06-10', salesman: '王五', customer: '客户 A', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 150, unit_price: 12, amount: 1800, status: '已完成成功' },
  { seq: 29, apply_date: '2026-06-15', salesman: '赵六', customer: '客户 D', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '待审核' },
  { seq: 30, apply_date: '2026-06-20', salesman: '刘七', customer: '客户 E', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 80, unit_price: 25, amount: 2000, status: '已完成成功' },
]

// 过滤期间 [2026-01-01, 2026-07-01]
function filterByPeriod(item) {
  if (item.apply_date) {
    const d = new Date(item.apply_date + 'T00:00:00')
    return d >= new Date(ONE_YEAR_AGO)
  }
  return true
}
const itemsData = items.filter(filterByPeriod)

// ── 客户商品汇总：业务员 × 客户 × 商品 ──
const customerItem = [
  { salesman: '张三', customer: '客户 A', product_name: '产品 101', qty: 250, amount: 2125 },
  { salesman: '张三', customer: '客户 A', product_name: '产品 102', qty: 150, amount: 1800 },
  { salesman: '李四', customer: '客户 A', product_name: '产品 101', qty: 250, amount: 2125 },
  { salesman: '李四', customer: '客户 A', product_name: '产品 102', qty: 150, amount: 1800 },
  { salesman: '王五', customer: '客户 A', product_name: '产品 101', qty: 100, amount: 850 },
  { salesman: '王五', customer: '客户 A', product_name: '产品 102', qty: 150, amount: 1800 },
  { salesman: '王五', customer: '客户 A', product_name: '产品 103', qty: 80, amount: 2000 },
  { salesman: '赵六', customer: '客户 A', product_name: '产品 101', qty: 100, amount: 850 },
  { salesman: '刘七', customer: '客户 A', product_name: '产品 103', qty: 100, amount: 2500 },
  { salesman: '赵六', customer: '客户 B', product_name: '产品 101', qty: 250, amount: 2125 },
  { salesman: '张三', customer: '客户 B', product_name: '产品 102', qty: 200, amount: 2400 },
  { salesman: '张三', customer: '客户 B', product_name: '产品 103', qty: 80, amount: 2000 },
  { salesman: '李四', customer: '客户 B', product_name: '产品 102', qty: 150, amount: 1800 },
  { salesman: '李四', customer: '客户 B', product_name: '产品 103', qty: 100, amount: 2000 },
  { salesman: '王五', customer: '客户 B', product_name: '产品 102', qty: 100, amount: 1200 },
  { salesman: '赵六', customer: '客户 B', product_name: '产品 104', qty: 100, amount: 1800 },
  { salesman: '刘七', customer: '客户 B', product_name: '产品 104', qty: 120, amount: 2160 },
]

// ── 业务员订单汇总（聚合后） ──
const salesmanAgg = [
  { sales_name: '张三', order_count: 9, goods_item_count: 16, order_amount: 16675, revenue: 16675, rank: 1, sales_item_count: 8 },
  { sales_name: '李四', order_count: 8, goods_item_count: 13, order_amount: 13125, revenue: 13125, rank: 2, sales_item_count: 7 },
  { sales_name: '王五', order_count: 7, goods_item_count: 10, order_amount: 13600, revenue: 13600, rank: 3, sales_item_count: 6 },
  { sales_name: '赵六', order_count: 6, goods_item_count: 8, order_amount: 12800, revenue: 12800, rank: 4, sales_item_count: 4 },
  { sales_name: '刘七', order_count: 5, goods_item_count: 8, order_amount: 13200, revenue: 13200, rank: 5, sales_item_count: 3 },
]

export default {
  items: itemsData,
  customerItem: customerItem,
  salesman: salesmanAgg,
}