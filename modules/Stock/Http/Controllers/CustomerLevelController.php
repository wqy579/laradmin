<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\CustomerLevel;

class CustomerLevelController extends Controller
{
    use ResponseTrait;

    public function index()
    {
        $list = CustomerLevel::orderBy('sort')->get()->map(function (CustomerLevel $l) {
            $l['customer_count'] = DB::table('customers')->where('level_id', $l->id)->count();

            return $l;
        });

        return $this->success(['list' => $list]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        if (CustomerLevel::where('code', $data['code'])->exists()) {
            return $this->error('等级编码已存在', 422);
        }

        $l = CustomerLevel::create(array_merge($data, ['is_system' => false]));

        return $this->created($l, '已创建');
    }

    public function update(Request $request, $id)
    {
        $l = CustomerLevel::find($id);

        if ($l === null) {
            return $this->notFound('等级不存在');
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:50',
            'code' => 'sometimes|required|string|max:20',
            'default_discount' => 'sometimes|required|numeric|min:0.1|max:9.9',
            'sort' => 'nullable|integer',
            'status' => 'nullable|boolean',
        ]);

        if (! empty($data['code']) && CustomerLevel::where('code', $data['code'])->where('id', '!=', $id)->exists()) {
            return $this->error('等级编码已存在', 422);
        }

        $l->update($data);

        return $this->success($l, '已更新');
    }

    public function destroy($id)
    {
        $l = CustomerLevel::find($id);

        if ($l === null) {
            return $this->notFound('等级不存在');
        }

        if ($l->is_system) {
            return $this->error('系统默认等级不可删除', 422);
        }

        if (DB::table('customers')->where('level_id', $id)->exists()) {
            return $this->error('该等级下有客户，不能删除');
        }

        $l->delete();

        return $this->success(null, '已删除');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'default_discount' => 'required|numeric|min:0.1|max:9.9',
            'sort' => 'nullable|integer',
            'status' => 'nullable|boolean',
        ]);
    }
}
