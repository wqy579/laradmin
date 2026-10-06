--- a/frontend/src/api/business.js
+++ b/frontend/src/api/business.js
@@ -181,6 +181,17 @@ const businessApi = {
 		warehouseProducts: { get: (params) => request.get('business/stocktaking/warehouse-products', { params }) },
 		ledger: { get: (params) => request.get('business/stocktaking/ledger', { params }) },
 	},
+
+	// 库存调整单
+	stockAdjust: {
+		list: { get: (params) => request.get('business/stock-adjust', { params }) },
+		detail: { get: (id) => request.get(`business/stock-adjust/${id}`) },
+		create: { post: (params) => request.post('business/stock-adjust', params) },
+		update: { put: (id, params) => request.put(`business/stock-adjust/${id}`, params) },
+		delete: { delete: (id) => request.delete(`business/stock-adjust/${id}`) },
+		submit: { post: (id) => request.post(`business/stock-adjust/${id}/submit`) },
+		approve: { post: (id, params) => request.post(`business/stock-adjust/${id}/approve`, params) },
+		reject: { post: (id, params) => request.post(`business/stock-adjust/${id}/reject`, params) },
+		cancel: { post: (id) => request.post(`business/stock-adjust/${id}/cancel`) },
+		warehouseProducts: { get: (params) => request.get('business/stock-adjust/warehouse-products', { params }) },
+	},
