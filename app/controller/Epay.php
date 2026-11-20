<?php
namespace app\controller;

use app\BaseController;
use think\facade\View;
use think\facade\Config;
use app\model\Order;
use app\model\Pays;
use app\model\Users;
use think\facade\Db;

require_once(__DIR__ . "/../../extend/Epay/epay_notify.class.php");

class Epay extends Common
{


    private $userInfo;

    private $epay_config;

    private $authController = array(
        'vip_Notify',
        'pie_Notify',
        'limit2_Notify',
    );
    public function __construct()
    {
        parent:: __construct();
        $this->epay_config = [
            'partner' => Config::get('web.epay_id'),
            'key' => Config::get('web.epay_key'),
            'sign_type' => strtoupper('MD5'),
            'input_charset' => strtolower('utf-8'),
            'transport' => 'http',
            'apiurl' => Config::get('web.epay_url')
        ];
    }

    public function submit()
    {
        $data = input('get.');
        if (!isset($data['type'])) {
			View::assign('alert', 'NO TYPE!');
            View::assign('url', '/console#/');
            exit(View::fetch('common/error_no_console'));
        } elseif (!$order = Pays::where('orderid', '=', $data['orderid'])->find()) {
            View::assign('alert', '订单号不存在');
            View::assign('url', '/console#/');
            exit(View::fetch('common/error_no_console'));
        } else {
            exit(Pay($order['orderid'], $order['name'], $order['money'], $order['type'], $order['shop'], $order['shopid']));
        }
    }

    public function vip_Return()
    {
        $data = input('get.');
        $this->checkLogin();
		$userInfo = Users::getLoginUser();
        $alipayNotify = new \AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
            ];
            if ($data['trade_status'] == 'TRADE_FINISHED' || $data['trade_status'] == 'TRADE_SUCCESS') {
                if ($pay_order['status'] == 0) {
                    Order::add($order);
                    Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                    Users::where('uid', '=', $userInfo['uid'])
                        ->update([
                            'power' => 1,
                        ]);
                } else {
                    View::assign('alert', '开通付费版成功,感谢您的购买');
					View::assign('url', '/console#/');
					exit(View::fetch('common/shop_no_console'));
                }
                View::assign('alert', '开通付费版成功,感谢您的购买');
				View::assign('url', '/console#/');
				exit(View::fetch('common/shop_no_console'));
            } else {
                return "trade_status=" . $data['trade_status'];
            }
        } else {
            View::assign('alert', '订单效验失败');
			View::assign('url', '/console#/');
			exit(View::fetch('common/shop_no_console'));
        }
    }

    public function vip_Notify()
    {
        $data = input('get.');
        // 未配置支付密钥时一律拒绝，避免空密钥导致签名可被伪造
        if (empty($this->epay_config['key'])) {
            return 'fail';
        }
        $alipayNotify = new \AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            if (!$pay_order) {
                // 订单不存在：无单可发，直接确认，避免 null==0 误判发货、也避免网关无限重试
                return 'success';
            }
            $order = [
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
            ];
            // 幂等：同一 trade_no 只记一次（gui_order.trade_no 是主键，重复插入会 500）
            if (!\think\facade\Db::name('order')->where('trade_no', '=', $data['trade_no'])->find()) {
                Order::add($order);
            }
            if ($data['trade_status'] == 'TRADE_FINISHED') {
            } else if ($data['trade_status'] == 'TRADE_SUCCESS' && $pay_order['status'] == 0) {
                Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                Users::where('uid', '=', $pay_order['uid'])
                    ->update([
                        'power' => 1
                    ]);
            }
            return 'success';
        } else {
            return 'full';
        }
    }

    public function pie_Return()
    {
        $data = input('get.');
        $this->checkLogin();
		$userInfo = Users::getLoginUser();
        $alipayNotify = new \AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
            ];
            if ($data['trade_status'] == 'TRADE_FINISHED' || $data['trade_status'] == 'TRADE_SUCCESS') {
                if ($pay_order['status'] == 0) {
                    Order::add($order);
                    Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                    $peie_Num = 1;
                    $now_Num = $userInfo['pie'] + $peie_Num;
                    Users::where('uid', '=', $userInfo['uid'])
                        ->update([
                            'pie' => $now_Num,
                        ]);
                } else {
					View::assign('alert', '购买' . $pay_order['name'] . '成功,感谢您的购买');
					View::assign('url', '/console#/');
					exit(View::fetch('common/shop_no_console'));
                }
                View::assign('alert', '购买' . $pay_order['name'] . '成功,感谢您的购买');
				View::assign('url', '/console#/');
				exit(View::fetch('common/shop_no_console'));
            } else {
                return "trade_status=" . $data['trade_status'];
            }
        } else {
            View::assign('alert', '订单效验失败');
			View::assign('url', '/console#/');
			exit(View::fetch('common/shop_no_console'));
        }
    }

    public function pie_Notify()
    {
        $data = input('get.');
        // 未配置支付密钥时一律拒绝，避免空密钥导致签名可被伪造
        if (empty($this->epay_config['key'])) {
            return 'fail';
        }
        $alipayNotify = new \AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            if (!$pay_order) {
                // 订单不存在：无单可发，直接确认，避免 null==0 误判发货、也避免网关无限重试
                return 'success';
            }
            $order = [
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
            ];
            // 幂等：同一 trade_no 只记一次（gui_order.trade_no 是主键，重复插入会 500）
            if (!\think\facade\Db::name('order')->where('trade_no', '=', $data['trade_no'])->find()) {
                Order::add($order);
            }
            if ($data['trade_status'] == 'TRADE_FINISHED') {
            } else if ($data['trade_status'] == 'TRADE_SUCCESS' && $pay_order['status'] == 0) {
                Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                $peie_Num = 1;
                $u = Users::where('uid', '=', $pay_order['uid'])->find();
                $now_Num = ($u ? $u['pie'] : 0) + $peie_Num;
                Users::where('uid', '=', $pay_order['uid'])
                    ->update([
                        'pie' => $now_Num,
                    ]);
            }
            return 'success';
        } else {
            return 'full';
        }
    }
    
    public function limit2_Return()
    {
        $data = input('get.');
        $this->checkLogin();
		$userInfo = Users::getLoginUser();
        $alipayNotify = new \AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
            ];
            if ($data['trade_status'] == 'TRADE_FINISHED' || $data['trade_status'] == 'TRADE_SUCCESS') {
                if ($pay_order['status'] == 0) {
                    Order::add($order);
                    Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                    $peie_Num = 5000;
                    $now_Num = $userInfo['limit2'] + $peie_Num;
                    Users::where('uid', '=', $userInfo['uid'])
                        ->update([
                            'limit2' => $now_Num,
                        ]);
                } else {
					View::assign('alert', '购买' . $pay_order['name'] . '成功,感谢您的购买');
					View::assign('url', '/console#/');
					exit(View::fetch('common/shop_no_console'));
                }
                View::assign('alert', '购买' . $pay_order['name'] . '成功,感谢您的购买');
				View::assign('url', '/console#/');
				exit(View::fetch('common/shop_no_console'));
            } else {
                return "trade_status=" . $data['trade_status'];
            }
        } else {
            View::assign('alert', '订单效验失败');
			View::assign('url', '/console#/');
			exit(View::fetch('common/shop_no_console'));
        }
    }

    public function limit2_Notify()
    {
        $data = input('get.');
        // 未配置支付密钥时一律拒绝，避免空密钥导致签名可被伪造
        if (empty($this->epay_config['key'])) {
            return 'fail';
        }
        $alipayNotify = new \AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            if (!$pay_order) {
                // 订单不存在：无单可发，直接确认，避免 null==0 误判发货、也避免网关无限重试
                return 'success';
            }
            $order = [
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
            ];
            // 幂等：同一 trade_no 只记一次（gui_order.trade_no 是主键，重复插入会 500）
            if (!\think\facade\Db::name('order')->where('trade_no', '=', $data['trade_no'])->find()) {
                Order::add($order);
            }
            if ($data['trade_status'] == 'TRADE_FINISHED') {
            } else if ($data['trade_status'] == 'TRADE_SUCCESS' && $pay_order['status'] == 0) {
                Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                $peie_Num = 5000;
                $u = Users::where('uid', '=', $pay_order['uid'])->find();
                $now_Num = ($u ? $u['limit2'] : 0) + $peie_Num;
                Users::where('uid', '=', $pay_order['uid'])
                    ->update([
                        'limit2' => $now_Num,
                    ]);
            }
            return 'success';
        } else {
            return 'full';
        }
    }

    protected
    function _initialize()
    {
        parent::_initialize();
        $this->checkLogin();
		
    }
	
	private
    function checkLogin()
    {
        $check_Login = Users::checkLogin();
        if ($check_Login != null) {
            View::assign('alert', $check_Login['msg']);
            View::assign('url', $check_Login['url']);
            exit(View::fetch('common/error_no_console'));
        } else {
            $this->userInfo = Users::getLoginUser();
            View::assign('userInfo', $this->userInfo);
        }
    }
}