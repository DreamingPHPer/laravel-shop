<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Exceptions\InvalidRequestException;
use App\Models\Installment;
use App\Models\InstallmentItem;
use App\Models\Order;
use Carbon\Carbon;
use Endroid\QrCode\QrCode;
use Illuminate\Http\Request;

class InstallmentsController extends Controller
{
    /**
     * 分期付款列表
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        $installments = Installment::query()
            ->where('user_id', $request->user()->id)
            ->paginate(10);

        return view('installments.index', ['installments' => $installments]);
    }

    /**
     * 分期还款详情
     *
     * @param Installment $installment
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(Installment $installment)
    {
        // 校验订单是否属于当前用户
        $this->authorize('own', $installment);

        // 取出当前分期付款的所有的还款计划，并按还款顺序排序
        $items = $installment->items()->orderBy('sequence')->get();

        return view(
            'installments.show',
            [
                'installment' => $installment,
                'items' => $items,
                // 下一个未完成还款的还款计划
                'nextItem' => $items->where('paid_at', null)->first(),
            ]
        );
    }

    /**
     * 支付宝支付
     *
     * @param Installment $installment
     * @return mixed
     * @throws InvalidRequestException
     */
    public function payByAlipay(Installment $installment)
    {
        if ($installment->order->closed) {
            throw new InvalidRequestException('对应的商品订单已被关闭');
        }
        if ($installment->status === Installment::STATUS_FINISHED) {
            throw new InvalidRequestException('该分期订单已结清');
        }
        // 获取当前分期付款最近一个未支付的还款计划
        if (!$nextItem = $installment->items()->whereNull('paid_at')->orderBy('sequence')->first()) {
            // 如果没有未支付的还款，原则上不可能，因为如果分期已结清则在上一个判断就退出了
            throw new InvalidRequestException('该分期订单已结清');
        }

        // 调用支付宝的网页支付
        return app('alipay')->web(
            [
                // 支付订单号使用分期流水号+还款计划编号
                'out_trade_no' => $installment->no.'_'.$nextItem->sequence,
                'total_amount' => $nextItem->total,
                'subject' => '支付'.config('app.name').'的分期订单：'.$installment->no,
                // 这里的 notify_url 和 return_url 可以覆盖掉在 AppServiceProvider 设置的回调地址
                'notify_url' => ngrok_url('installments.alipay.notify'),
                'return_url' => route('installments.alipay.return'),
            ]
        );
    }

    /**
     * 支付宝前端回调页面
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function alipayReturn()
    {
        try {
            app('alipay')->verify();
        } catch (\Exception $exception) {
            return view('pages.error', ['message' => '数据不正确']);
        }

        return view('pages.success', ['message' => '付款成功']);
    }

    /**
     * 支付宝服务器端回调
     *
     * @return string
     */
    public function alipayNotify()
    {
        // 校验支付宝回调参数是否正确
        $data = app('alipay')->verify();
        // 如果订单状态不是成功或者结束，则不走后续的逻辑
        if (!in_array($data->trade_status, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            return app('alipay')->success();
        }
        if ($this->paid($data->out_trade_no, 'alipay', $data->trade_no)) {
            return app('alipay')->success();
        }

        return 'fail';
    }

    /**
     * 微信支付
     *
     * @param Installment $installment
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @throws InvalidRequestException
     */
    public function payByWechatPay(Installment $installment)
    {
        if ($installment->order->closed) {
            throw new InvalidRequestException('对应的商品订单已被关闭');
        }
        if ($installment->status === Installment::STATUS_FINISHED) {
            throw new InvalidRequestException('该分期订单已结清');
        }
        if (!$nextItem = $installment->items()->whereNull('paid_at')->orderBy('sequence')->first()) {
            throw new InvalidRequestException('该分期订单已结清');
        }

        $wechatOrder = app('wechatpay')->scan(
            [
                'out_trade_no' => $installment->no.'_'.$nextItem->sequence,
                'total_fee' => $nextItem->total->multipliedBy(100)->toScale(0),
                'body' => '支付'.config('app.name').'的分期订单：'.$installment->no,
                'notify_url' => ngrok_url('installments.wechat.notify'),
            ]
        );
        // 把要转换的字符串作为 QrCode 的构造函数参数
        $qrCode = new QrCode($wechatOrder->code_url);

        // 将生成的二维码图片数据以字符串形式输出，并带上相应的响应类型
        return response($qrCode->writeString(), 200, ['Content-Type' => $qrCode->getContentType()]);
    }

    /**
     * 微信支付前端回调页面
     *
     * @return string
     */
    public function wechatNotify()
    {
        $data = app('wechatpay')->verify();
        if ($this->paid($data->out_trade_no, 'wechat', $data->transaction_id)) {
            return app('wechatpay')->success();
        }

        return 'fail';
    }

    /**
     * 微信退款回调
     *
     * @param Request $request
     * @return string
     */
    public function wechatRefundNotify(Request $request)
    {
        // 给微信的失败响应
        $failXml = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[FAIL]]></return_msg></xml>';
        // 校验微信回调参数
        $data = app('wechatpay')->verify(null, true);
        // 根据单号拆解出对应的商品退款单号及对应的还款计划序号
        list($no, $sequence) = explode('_', $data['out_refund_no']);

        $item = InstallmentItem::query()
            ->whereHas(
                'installment',
                function ($query) use ($no) {
                    $query->whereHas(
                        'order',
                        function ($query) use ($no) {
                            $query->where('refund_no', $no); // 根据订单表的退款流水号找到对应还款计划
                        }
                    );
                }
            )
            ->where('sequence', $sequence)
            ->first();
        // 没有找到对应的订单，原则上不可能发生，保证代码健壮性
        if (!$item) {
            return $failXml;
        }

        // 如果退款成功
        if ($data['refund_status'] === 'SUCCESS') {
            // 将还款计划退款状态改成退款成功
            $item->update(
                [
                    'refund_status' => InstallmentItem::REFUND_STATUS_SUCCESS,
                ]
            );

            $item->installment->refreshRefundStatus();
        } else {
            // 否则将对应还款计划的退款状态改为退款失败
            $item->update(
                [
                    'refund_status' => InstallmentItem::REFUND_STATUS_FAILED,
                ]
            );
        }

        return app('wechatpay')->success();
    }

    /**
     * 支付回调逻辑（支付宝、微信）
     *
     * @param $outTradeNo
     * @param $paymentMethod
     * @param $paymentNo
     * @return bool
     */
    protected function paid($outTradeNo, $paymentMethod, $paymentNo)
    {
        list($no, $sequence) = explode('_', $outTradeNo);
        if (!$installment = Installment::where('no', $no)->first()) {
            return false;
        }
        if (!$item = $installment->items()->where('sequence', $sequence)->first()) {
            return false;
        }
        if ($item->paid_at) {
            return true;
        }
        \DB::transaction(
            function () use ($paymentNo, $paymentMethod, $no, $installment, $item) {
                $item->update(
                    [
                        'paid_at' => Carbon::now(),
                        'payment_method' => $paymentMethod,
                        'payment_no' => $paymentNo,
                    ]
                );
                if ($item->sequence === 0) {
                    $installment->update(['status' => Installment::STATUS_REPAYING]);
                    $installment->order->update(
                        [
                            'paid_at' => Carbon::now(),
                            'payment_method' => 'installment',
                            'payment_no' => $no,
                        ]
                    );
                    event(new OrderPaid($installment->order));
                }
                if ($item->sequence === $installment->count - 1) {
                    $installment->update(['status' => Installment::STATUS_FINISHED]);
                }
            }
        );

        return true;
    }
}
