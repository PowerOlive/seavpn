<?php
/**
 * 请求一笔 PayPal 付款
 * 
 * @param $orderid	唯一的订单号
 * @param $amount	金额，单位（元）
 * @return	PayPal 返回的 NVP，失败返回 false
 */
function paypal_new_payment($orderid, $amount) {
	$amtstr = '';
	
	if ($orderid == '') {
		vpn_log('Invalid argument: $orderid == ""');
		return false;
	}
	
	$user = user_isonline();
	if ($user === false) {
		vpn_log('User must be logined before payment');
		return false;
	}
	
	/// 发送请求
	$amtstr = sprintf('%0.2f', $amount);
	
	$arr = paypal_nvp_request(array('method' => 'SetExpressCheckout',
									'paymentrequest_0_amt' => $amtstr,
									'paymentrequest_n_currencycode' => CURRENCY_CODE,
									'reqconfirmshipping' => '0',
									'noshipping' => '1',
									'allownote' => '0',
									'returnurl' => PAYPAL_RETURNURL . '?orderid=' . $orderid,
									'cancelurl' => SITE_BASE . "order_preview.php?id=$orderid"));
	
	if ($res === false) {
		return false;
	}
	
	$ack = strtolower($arr['ack']);
	if ($ack != 'success' && $ack != 'successwithwarning') {
		vpn_log('Fail to open a new order with PayPal: ' . print_r($arr, true));
		return false;
	}
	
	$token = $arr['token'];
	if ($token == '') {
		vpn_log('PayPal return empty token while opening a new order');
		return false;
	}
	
	return $arr;
}

/**
 * 确认并完成收款
 * 
 * @param $amount	金额，单位（元）
 * @param $token	PayPal 的支付 token
 * @return	失败返回 false，成功返回 PayPal 在 DoExpressCheckoutPayment 时返回的 NVP
 */
function paypal_do_payment($token, $amount) {
	if ($token == '') {
		vpn_log('Invalid argument: token == ""');
		return false;
	}
	
	/// 获取付款信息
	
	$arr = paypal_nvp_request(array('method' => 'GetExpressCheckoutDetails', 
									'token' => $token));
									
	if ($arr == false) {
		vpn_log('Fail doing PayPal NVP request');
		return false;
	}
	
	$ack = strtolower($arr['ack']);
	if ($ack != 'success' && $ack != 'successwithwarning') {
		vpn_log('PayPal return error while do payment: ' . print_r($arr, true));
		return false;
	}
	
	$payerid = $arr['payerid'];
	
	/// 确认付款信息
	
	$amtstr = '';
	$amtstr = sprintf('%0.2f', $amount);
	
	$arr = paypal_nvp_request(array('method' => 'DoExpressCheckoutPayment',
									'paymentinfo_0_paymentaction' => 'Sale',
									'paymentrequest_0_amt' => $amtstr,
									'paymentrequest_n_currencycode' => CURRENCY_CODE,
									'payerid' => $payerid,
									'token' => $token));
									
	if ($arr === false) {
		vpn_log('Fail while request PayPal NVP on do payment');
		return false;
	}
	
	$ack = strtolower($arr['ack']);
	if ($ack != 'success' && $ack != 'successwithwarning') {
		vpn_log('PayPal return error while done payment: ' . print_r($arr, true));
		return false;
	}
	
	return $arr;
}


/**
 * 将用户重定向到 PayPal 付款页面以便让用户进行付款。
 * 注意，该函数将直接向用户浏览器发送 Location 头，用户将离开本站
 * 
 * @param $oid	订单编号
 * @return	失败返回 false，成功则跳转到 PayPal 付款页面，并返回 true
 */
function paypal_redirect($oid) {
	global $smarty;
	
	$oid = (int)$oid;
	
	$payments = db_quick_fetch('payment', "WHERE orderid=$oid");
	if ($payments === false || count($payments) <= 0) {
		vpn_log("No such order id $oid in payment table");
		return false;
	}
	$payment = $payments[0];
	
	$url = sprintf(PAYPAL_REDIRECTURL, $payment['token']);
	
	header("Location: $url");
	
	$smarty->assign('tip_title', _('Redirect'));
	$smarty->assign('tip_msg', _('Redirecting you to PayPal'));
	$smarty->assign('redirect_url', $url);
	$smarty->assign('redirect_delay', 3000);
	$smarty->display('tip.html');
	
	return true;
}

/**
 * 发送一个 PayPal NVP 请求，并获取返回结果
 * @param array	Name Value Pair
 * @return array	返回的结果，N 已经转换成小写
 */
function paypal_nvp_request($nvp) {
	$getstr = '?';
	
	$getstr .= 'signature=' . urlencode(PAYPAL_APISIGN);
	$getstr .= '&user=' . urlencode(PAYPAL_APIUSER);
	$getstr .= '&pwd=' . urlencode(PAYPAL_APIPASS);
	$getstr .= '&version=84.0';
	
		
	foreach ($nvp as $k => $v) {
		$getstr .= '&' . urlencode($k) . '=' . urlencode($v);
	}
	
	$ch = curl_init(PAYPAL_APIURL . $getstr);
	
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	
	vpn_log('Request PayPal ==> ' . PAYPAL_APIURL . $getstr);
	
	$ret = curl_exec($ch);
	if ($ret === false) {
		vpn_log('curl_exec() fail: ' . curl_error());
		return false;
	}

	$arr = paypal_getstr2array($ret);
	
	return $arr;
}

/**
 * 参见 getstr2array，不过本函数还会将 key 全部转换成小写以便判断
 */
function paypal_getstr2array($str) {
	$res = getstr2array($str);
	$ret = array();
	
	foreach ($res as $key => $value) {
		$ret[strtolower($key)] = $value;
	}
	
	return $ret;
}
?>
