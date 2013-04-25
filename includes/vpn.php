<?php
/**
 * 获取 VPN 帐号的使用统计情况（vpn_accountstat=总计, vpn_accountstat_monthly=当月）
 * 
 * @return array('in', 'out', 'total');
 */
function vpn_accountstat($name) {
	$account = vpn_get($name);
	if ($account == false || $account['validto'] == NULL) {
		return false;
	}
	
	$validfrom = $account['validfrom'];
	$validto = $account['validto'];
	
	$qname = addslashes($name);
	
	$sql = "SELECT SUM(acctinputoctets) AS inband, SUM(acctoutputoctets) AS outband, SUM(acctsessiontime) AS sessiontime FROM radius.radacct WHERE username='$qname'
			AND UNIX_TIMESTAMP(acctstarttime)>=$validfrom AND UNIX_TIMESTAMP(acctstarttime)<=$validto";
	$res = db_query($sql);
	
	if ($res == false) {
		return false;
	}
	
	$arr = db_fetch_array($res);
	$arr['in'] = $arr['inband'];
	$arr['out'] = $arr['outband'];
	
	return $arr;
}

function vpn_accountstat_monthly($name) {
	$account = vpn_get($name);
	if ($account == false) {
		return false;
	}
	
	$datetime = getdate();
	$validfrom = mktime('0', '0', '0', $datetime['mon'], '1', $datetime['year']);
	
	$qname = addslashes($name);
	
	$sql = "SELECT SUM(acctinputoctets) AS inband, SUM(acctoutputoctets) AS outband, SUM(acctsessiontime) AS sessiontime FROM radius.radacct WHERE username='$qname'
			AND UNIX_TIMESTAMP(acctstarttime)>=$validfrom";
	$res = db_query($sql);
	
	if ($res == false) {
		return false;
	}
	
	$arr = db_fetch_array($res);
	$arr['in'] = $arr['inband'];
	$arr['out'] = $arr['outband'];
	
	return $arr;
}

/**
 * 获取 VPN 帐号信息
 * 
 * @return	成功返回 VPN 帐号信息数组，失败返回 false
 */
function vpn_get($name) {
	if ($name == '') {
		vpn_log('Invalid argument: $name == null');
		return false;
	}
	
	$qname = addslashes($name);
	
	$sql = "SELECT * FROM vpnaccount WHERE username='$qname'";
	$res = db_query($sql);
	
	if ($res == false || db_num_rows($res) == 0) {
		vpn_log('No such vpn account username');
		return false;
	}
	
	return db_fetch_array($res);
}

/**
 * 修改 VPN 帐号信息
 * 
 * @param $name	VPN 登录号
 * @param array()	一个数组，包含想要修改的信息：array(字段名 => 目标值)
 */
function vpn_mod($name, $param) {
	if ($name == '') {
		return false;
	}
	
	$qname = addslashes($name);
	
	foreach($param as $key => $value) {
		$qkey = addslashes($key);
		$qvalue = addslashes($qvalue);
		
		$sql = "UPDATE vpnaccount SET $qkey='$qvalue' WHERE username='$qname'";
		
		$res = db_query($sql);
		if ($res == false) {
			vpn_log("Can not modify VPN account $name with `$key' => `$valud'");
		}
	}
	
	return true;
}

/**
 * 新增 VPN 帐号
 * 
 * @param $name	VPN 登录名
 * @param $pass	VPN 登录密码
 * @param $uid	帐号所属的用户的用户编号
 * @param $serviceid	VPN 服务产品类型，当还没有购买服务的时候，此参数可忽略
 * @return	成功返回 true，失败返回错误信息
 */
function vpn_add($name, $pass, $uid, $serviceid = -1) {
	$qname = addslashes($name);
	$qpass = addslashes($qpass);
	$uid = (int)$uid;
	$ts = time();
	
	$sql = "SELECT * FROM vpnaccount WHERE username='$qname'";
	$res = db_query($sql);
	if (db_num_rows($res) > 0) {
		return _('The VPN login username is exists');
	}
	
	$sql = "INSERT INTO vpnaccount (uid, username, password, validfrom) VALUES($uid, '$qname', '$qpass', $ts)";
	$res = db_query($sql);
	if ($res == false) {
		return _('Error while updating database');
	}
	
	/// 设定账户配额
	if ($serviceid > 0) {
		$serviceid = (int)$serviceid;
		
		$service = db_quick_fetch('service', " WHERE id=$servicdid");
		if (count($service) <= 0) {
			vpn_log("No such service id $serviceid");
		}
		else {
			vpn_mod($name, array('trafficquota' => $service['trafficquota']));
		}
	}
	
	return true;
}

/**
 * 续费 VPN 帐号（即在 RADIUS 数据表中增加相应记录，同时更新 vpnaccount 表中的 validto 字段）
 * 
 * @param $name	VPN 登录名
 * @param $quatity	续费时长，单位（秒）
 * @return	成功返回 true，失败返回错误信息
 */
function vpn_renew($name, $quatity) {
	$quatity = (int)$quatity;
	$qname = addslashes($name);
	
	if ($name == '' || $quatity <= 0) {
		return false;
	}
	
	$sql = "SELECT * FROM vpnaccount WHERE username='$qname'";
	$res = db_query($sql);
	if ($res == false || db_num_rows($res) <= 0) {
		return false;
	}
	
	$account = db_fetch_array($res);
	
	$is_expire = false;
	if ($account['validto'] == NULL || $account['validto'] < time()) {
		$is_expire = true;
	}
	
	$modify = array();
	
	/// 如果 VPN 帐号是超时的，在续费的时候就应该重设有效起始期，避免重复计算无效期期间的使用情况
	if ($is_expire) {
		$modify['validfrom'] = time();
		$modify['validto'] = time() + $quatity;
	}
	else {
		$modify['validto'] = $account['validto'] + $quatity;
	}
	vpn_mod($name, $modify);
	
	vpn_log("Renew VPN account validate time to " . strftime('Y-n-j G:i:s', $modify['validfrom']) . ' ~ ' . strftime('Y-n-j G:i:s', $modify['validto']));
	
	return true;
}

/**
 * 获取 VPN 帐号列表
 * 
 * 若不传送 $uid 参数，则列出所有 VPN 账户。否则列出指定用户名下的 VPN 账户
 * 
 * @param $uid
 * @return array()	成功返回 VPN 列表数组，失败返回 false
 */
function vpn_list($uid = -1) {
	$uid = (int)$uid;
	
	$sql = 'SELECT * FROM vpnaccount';
	if ($uid >= 0) {
		$sql .= " WHERE uid=$uid";
	}
	echo $sql . "<hr>";
	$res = db_query($sql);
	if ($res == false) {
		return false;
	}
	
	$vpn = array();

	while (($arr = db_fetch_array($res)) != false) {
		array_push($vpn, $arr);
	}
	
	return $vpn;
}

/**
 * 计算用户现有账户是否足以支付此项服务，并返回如果购买了此项服务后账户的净余额
 * 
 * @return	足够支付返回非负数，不够支付返回负数
 */
function vpn_afford($serviceid, $email) {
	$serviceid = (int)$serviceid;
	
	$res = db_quick_fetch('service', 'WHERE id=$serviceid');
	if (count($res) <= 0) {
		vpn_log("No such service id $serviceid");
		return false;
	}
	
	$user = user_get($email);
	if ($user == false) {
		vpn_log("No such user `$email'");
		return false;
	}
	
	return ($user['balance'] + $user['credit'] >= $res['price']);
}
?>