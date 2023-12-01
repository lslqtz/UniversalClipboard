<?php
define('Title', '通用剪切板');
define('Interval', 300);
define('InputTimeout', 200); // 输入延迟, 用于去除连续输出抖动造成的体验下降.
define('SessionName', 's'); // null 代表不使用.
define('Expiration', 3600); // Seconds, 0 意味着不过期.
define('VersionKey', 'DefaultVersionKey');
define('UseAuth', true);
define('VerifyClientVersionHash', true); // 校验客户端 Hash 可使 VersionKey 更改后废弃所有用户之前的剪切板, 可能可以防止 Session 模式下的剪切板跨账号访问 (但可能会导致内容清空).
define('AuthCookieName', 'UCLoginCredential');
define('AuthUserList', array('user1' => array('password' => ''), 'user2' => array('password' => '', 'sessionName' => null), 'user3' => array('password' => '0b7f849446d3383546d15a480966084442cd2193' /* user3 */, 'sessionName' => 'session'))); // SHA1 Encrypt
define('AnonymousUsername', 'Anonymous');
#function GetData(string $username): array|bool { // PHP 7 不支持联合类型.
function GetData(string $username) {
	global $sessionName;
	if ($sessionName !== null) {
		return $_SESSION;
	}
	$jsonData = false;
	if (is_file("UCJSON/{$username}.json")) {
		if (Expiration > 0 && ($jsonMTime = filemtime("UCJSON/{$username}.json")) !== false && (($jsonMTime + Expiration) < time())) {
			unlink("UCJSON/{$username}.json");
		} else {
			$jsonContent = file_get_contents("UCJSON/{$username}.json");
			$jsonData = ($jsonContent !== false ? json_decode($jsonContent, true) : false);
		}
	}
	return ($jsonData ?? array());
}
function SaveData(string $username, ?array $data): bool {
	global $sessionName;
	if ($sessionName !== null) {
		if ($data !== null) {
			$_SESSION = $data;
		}
		return true;
	}
	if (!is_dir('UCJSON') && !mkdir('UCJSON')) {
		return false;
	}
	if ($data === null) {
		return touch("UCJSON/{$username}.json");
	}
	$arrJSON = json_encode($data, JSON_NUMERIC_CHECK);
	if ($arrJSON === false) {
		return false;
	}
	return file_put_contents("UCJSON/{$username}.json", "{$arrJSON}\n");
}
#function CheckUser(): string|int { // PHP 7 不支持联合类型.
function CheckUser() {
	if (isset($_COOKIE[AuthCookieName])) {
		if (count(($userArr = explode(':', $_COOKIE[AuthCookieName], 2))) === 2 && (empty(AuthUserList[$username]['password']) || (!empty($userArr[1]) && AuthUserList[$userArr[0]]['password'] === $userArr[1]))) {
			return $userArr[0];
		} else {
			return -1;
		}
	}
	return -2;
}
function CheckLogin(string $username, string $password): bool {
	if (empty(AuthUserList[$username]['password']) || (!empty($password) && AuthUserList[$username]['password'] === sha1($password))) {
		return true;
	}
	return false;
}
$isPOST = (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST');
$username = (UseAuth ? CheckUser() : AnonymousUsername);
$sessionName = ((array_key_exists('sessionName', AuthUserList[$username])) ? AuthUserList[$username]['sessionName'] : SessionName);
$tryLogout = (isset($_GET['logout']) && $_GET['logout'] === '1');
if ($sessionName !== null) {
	ini_set('session.use_cookies', 0);
	ini_set('session.use_trans_sid', 1);
	ini_set('session.use_only_cookies', 0);
	if (Expiration > 0) {
		ini_set('session.gc_maxlifetime', Expiration);
	}
	if (!is_writable(session_save_path())) {
	    die('Session path ' . session_save_path() . " is not writable for PHP!\n"); 
	}
	session_name($sessionName);
	session_start();
	$sessionID = session_id();
	if (!isset($_GET[$sessionName]) || $_GET[$sessionName] !== $sessionID) {
		header("Location: {$_SERVER['SCRIPT_NAME']}?" . $sessionName . "={$sessionID}", true, 302);
		die();
	}
} else if (!$tryLogout && count($_GET) > 0 && !$isPOST) {
	header("Location: {$_SERVER['SCRIPT_NAME']}", true, 302);
	die();
}
$auth = (UseAuth ? false : true);
$tryLogin = (UseAuth && !empty($_POST['username']));
if ($tryLogout) {
	if (UseAuth) {
		setcookie(AuthCookieName, '', time() - 1, '/', '', false, true);
	}
	header("Location: {$_SERVER['SCRIPT_NAME']}" . (($sessionName !== null) ? ("?" . $sessionName . "={$sessionID}") : ''), true, 302);
	die();
} else {
	if ($username === -1) {
		if (!$tryLogin) {
			setcookie(AuthCookieName, '', time() - 1, '/', '', false, true);
		}
	} elseif ($username !== -2) {
		$auth = true;
	}
}
if ($tryLogin) {
	if ($auth) {
		$loginMessage = '您当前已登录!';
	} elseif (($auth = CheckLogin($_POST['username'], $_POST['password']))) {
		setcookie(AuthCookieName, $_POST['username'] . ':' . AuthUserList[$_POST['username']]['password'], time() + 2592000, '/', '', false, true);
		$username = $_POST['username'];
		$loginMessage = '登录成功!';
	} else {
		$loginMessage = '账号/密码不正确, 请检查后再试!';
	}
}
if (!$tryLogin && $isPOST) {
	header('Content-Type: application/json');
	if (!$auth) {
		die(json_encode(array('version' => -2)));
	}
	$clientJSON = json_decode(file_get_contents('php://input'), true);
	$clientVersion = $clientJSON['version'] ?? null;
	$clientVersionHash = $clientJSON['version_hash'] ?? null;
	$clientVersionHashCalc = (VerifyClientVersionHash ? ((($clientVersionHash !== null) ? sha1(VersionKey . ($auth ? $username : AnonymousUsername) . $clientVersion . VersionKey) : null)) : $clientVersionHash);
	if ($clientVersion !== -1) {
		if ($clientVersionHash !== null && $clientVersionHash === $clientVersionHashCalc) {
			$clientClipboard = isset($clientJSON['clipboard']) ? ($clientJSON['clipboard'] ?? null) : null;
			$serverData = GetData($username);
			$serverDataChanged = false;
			$clientVersionChanged = false;
		} else {
			$serverData = array();
		}
	} else {
		$serverData = GetData($username);
		$serverDataChanged = false;
		$clientVersionChanged = true;
	}
	if (!isset($serverData['version']) || !isset($serverData['clipboard'])) {
		$serverData['version'] = 0;
		$serverData['clipboard'] = '';
		$serverDataChanged = true;
		$clientVersionChanged = true;
	}
	$serverVersionHashCalc = ($clientVersion !== $serverData['version']) ? sha1(VersionKey . ($auth ? $username : AnonymousUsername) . $serverData['version'] . VersionKey) : $clientVersionHashCalc;
	if (!$clientVersionChanged) {
		if ($clientVersion === null || $clientVersion === -1 || $clientVersionHash === null || $clientVersion !== $serverData['version'] || $clientVersionHash !== $serverVersionHashCalc) { // 版本或 Hash 为空或不一致说明客户端在还没有更新内容前就落后或超前, 需要重新发回内容使客户端获得正确版本.
			$clientVersionChanged = true;
		} elseif ($clientClipboard !== null && $clientClipboard !== $serverData['clipboard']) { // 否则如果剪切板不为 null (允许空) 且内容被修改, 则进行更新.
			$serverDataChanged = true;
			$clientVersionChanged = true;
			if ($serverData['version'] < 23333 && !empty($clientClipboard)) {
				$serverData['version']++;
			} else {
				$serverData['version'] = 0;
			}
			$serverData['clipboard'] = $clientClipboard;
			$serverVersionHashCalc = sha1(VersionKey . ($auth ? $username : AnonymousUsername) . $serverData['version'] . VersionKey);
		}
	}
	// 将处理结果存储并重新发回给客户端, 且如果版本没有发生变化, 就不发送剪切板内容以节省带宽.
	SaveData($username, ($serverDataChanged ? $serverData : null));
	die(json_encode(array('version' => $serverData['version'], 'version_hash' => $serverVersionHashCalc, 'clipboard' => ($clientVersionChanged ? $serverData['clipboard'] : null))));
}
?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<link rel="stylesheet" href="dark.css">
		<link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
		<script src="https://cdn.bootcdn.net/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
		<script>
			function remakeQRCodeByColorScheme() {
				if (typeof qrcode !== 'undefined') {
					document.getElementById('qrcode').innerHTML = '';
				}
				l = window.matchMedia('(prefers-color-scheme: light)').matches;
				qrcode = new QRCode('qrcode', {'text': location.href, 'width': clientTextarea.scrollHeight, 'height': clientTextarea.scrollHeight, 'colorLight': (l ? '#fff' : '#666'), 'colorDark': '#000', 'correctLevel': QRCode.CorrectLevel.H});
			}
			function reqListener() {
				if (this.responseText == null || this.responseText.length <= 0) {
					isRequesting = false;
					return;
				}
				json = JSON.parse(this.responseText);
				if (json.version === undefined || json.version_hash === undefined || (json.version !== 0 && json.version < version)) {
					if (json.version === -2) {
						clearInterval(intervalTimer);
						clientStatus.innerText = '状态: 未登录.';
					}
				} else {
					version = json.version;
					version_hash = json.version_hash;
					clientTextarea.disabled = false;
					clientStatus.innerText = `状态: 正常, 客户端版本: ${version} (${version_hash}).`;
					if (!isInputting && json.clipboard !== undefined && json.clipboard !== null) {
						clientTextarea.value = json.clipboard;
					}
				}
				isRequesting = false;
			}
			function sync() {
				if (isInputting || isRequesting) {
					return;
				}
				isRequesting = true;
				req.open('POST', location.href, true);
				req.setRequestHeader('Content-Type', 'application/json');
				req.send(JSON.stringify({'version': version, 'version_hash': version_hash, 'clipboard': (version !== -1 ? clientTextarea.value : null)}));
			}
			window.onload = function () {
				isInputting = false;
				isRequesting = false;
				version = -1;
				version_hash = null;
				clientStatus = document.getElementById('status');
				clientTextarea = document.getElementsByTagName('textarea')[0];
				if (window.matchMedia) {
					remakeQRCodeByColorScheme();
					window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', remakeQRCodeByColorScheme);
				}
				req = new XMLHttpRequest();
				req.addEventListener('load', reqListener);
				req.timeout = <?php echo Interval; ?>;
				clientTextarea.addEventListener('input', function() {
					isInputting = true;
					if (typeof timeoutTimer !== 'undefined') {
						clearTimeout(timeoutTimer);
					}
					timeoutTimer = setTimeout(function () { isInputting = false; }, <?php echo InputTimeout; ?>);
				});
				intervalTimer = setInterval(sync, <?php echo Interval; ?>);
			}
		</script>
		<title><?php echo Title; ?></title>
	</head>
	<body>
		<h1><?php echo Title; ?></h1>
<?php
		if (!$auth) {
?>
		<form method="post" enctype="multipart/form-data">
			<div>
				<label for="username">账号: </label>
	    			<input type="text" id="username" name="username" required>
	    			<label for="password">密码: </label>
	    			<input type="password" id="password" name="password">
	    			<button>登录</button>
	    		</div>
	    	</form>
<?php
	    } else {
	    	echo "		<p>当前登录账号为: {$username}" . (UseAuth ? ", <a href=\"?logout=1\">登出</a>" : '.') . "</p>\n";
	    }
	    if (isset($loginMessage)) {
			echo "		<p>{$loginMessage}</p>\n";
		}
?>
		<p id="status">状态: 初始化...</p>
		<textarea spellcheck="false" autocomplete="off" style="width: calc(100% - 340px); min-width: 300px; height: 300px;" disabled="disabled"></textarea>
		<div id="qrcode" style="display: inline-block; vertical-align: top; height: 300px;"></div>
	</body>
</html>
