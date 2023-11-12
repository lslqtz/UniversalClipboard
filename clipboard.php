<?php
define('Title', '通用剪切板');
define('Interval', 1000);
define('InputTimeout', 200); // 输入延迟, 用于去除连续输出抖动造成的体验下降.
define('SessionName', 's'); // null 代表不使用.
define('VersionKey', 'DefaultVersionKey');
define('UseAuth', true);
define('AuthCookieName', 'UCLoginCredential');
define('AuthUserList', array('user1' => '')); // SHA1 Encrypt
#function GetData(string $username): array|bool { // PHP 7 不支持联合类型.
function GetData(string $username) {
	if (SessionName !== null) {
		return $_SESSION;
	}
	$jsonContent = (is_file("UCJSON/{$username}.json") ? file_get_contents("UCJSON/{$username}.json") : false);
	$jsonData = ($jsonContent !== false ? json_decode($jsonContent, true) : false);
	return ($jsonData ?? array());
}
function SaveData(string $username, array $data): bool {
	if (SessionName !== null) {
		$_SESSION = $data;
		return true;
	}
	if (!is_dir('UCJSON') && !mkdir('UCJSON')) {
		return false;
	}
	$arrJSON = json_encode($data, JSON_NUMERIC_CHECK);
	if ($arrJSON === false) {
		return false;
	}
	return file_put_contents("UCJSON/{$username}.json", "{$arrJSON}\n");
}
#function CheckUser(): string|bool { // PHP 7 不支持联合类型.
function CheckUser() {
	if (isset($_COOKIE[AuthCookieName]) && count(($userArr = explode(':', $_COOKIE[AuthCookieName], 2))) === 2 && isset(AuthUserList[$userArr[0]]) && AuthUserList[$userArr[0]] === $userArr[1]) {
		return $userArr[0];
	}
	return false;
}
function CheckLogin(string $username, string $password): bool {
	if (isset(AuthUserList[$username]) && (empty(AuthUserList[$username]) || (!empty($password) && AuthUserList[$username] === sha1($password)))) {
		return true;
	}
	return false;
}
if (SessionName !== null) {
	ini_set('session.use_cookies', 0);
	ini_set('session.use_trans_sid', 1);
	ini_set('session.use_only_cookies', 0);
	ini_set('session.gc_maxlifetime', 3600);
	if (!is_writable(session_save_path())) {
	    die('Session path ' . session_save_path() . " is not writable for PHP!\n"); 
	}
	session_name(SessionName);
	session_start();
	$sessionID = session_id();
	if (!isset($_GET[SessionName]) || $_GET[SessionName] !== $sessionID) {
		header("Location: {$_SERVER['SCRIPT_NAME']}?" . SessionName . "={$sessionID}", true, 302);
		die();
	}
}
$auth = (UseAuth ? false : true);
$username = (UseAuth ? CheckUser() : 'Anonymous');
$tryLogin = (UseAuth && !empty($_POST['username']));
if ($username !== false) {
	$auth = true;
	if (isset($_GET['logout']) && $_GET['logout'] === '1') {
		if (UseAuth) {
			setcookie(AuthCookieName, '', time() - 1, '/', '', false, true);
		}
		header("Location: {$_SERVER['SCRIPT_NAME']}" . ((SessionName !== null) ? ("?" . SessionName . "={$sessionID}") : ''), true, 302);
		die();
	}
} elseif (!$tryLogin) {
	setcookie(AuthCookieName, '', time() - 1, '/', '', false, true);
}
if ($tryLogin) {
	if ($auth) {
		$loginMessage = '您当前已登录!';
	} elseif (($auth = CheckLogin($_POST['username'], $_POST['password']))) {
		setcookie(AuthCookieName, $_POST['username'] . ':' . AuthUserList[$_POST['username']], time() + 2592000, '/', '', false, true);
		$username = $_POST['username'];
		$loginMessage = '登录成功!';
	} else {
		$loginMessage = '账号/密码不正确, 请检查后再试!';
	}
}
if (!$tryLogin && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
	header('Content-Type: application/json');
	if (!$auth) {
		die(json_encode(array('version' => -2)));
	}
	$clientJSON = json_decode(file_get_contents('php://input'), true);
	$clientVersion = $clientJSON['version'] ?? null;
	$clientVersionHash = $clientJSON['version_hash'] ?? null;
	$clientClipboard = isset($clientJSON['clipboard']) ? ($clientJSON['clipboard'] ?? null) : null;
	$serverData = GetData($username);
	if (!isset($serverData['version']) || !isset($serverData['clipboard'])) {
		$serverData['version'] = 0;
		$serverData['clipboard'] = '';
	}
	$serverVersionHash = sha1(VersionKey . ($auth ? $username : '') . $serverData['version'] . VersionKey);
	$versionChanged = false;
	if ($clientVersion === null || $clientVersion === -1 || $clientVersionHash === null || $clientVersion !== $serverData['version'] || $clientVersionHash !== $serverVersionHash) { // 版本或 Hash 为空或不一致说明客户端在还没有更新内容前就落后或超前, 需要重新发回内容使客户端获得正确版本.
		$versionChanged = true;
	} elseif ($clientClipboard !== null && $clientClipboard !== $serverData['clipboard']) { // 否则如果剪切板不为 null (允许空) 且内容被修改, 则进行更新.
		$versionChanged = true;
		if ($serverData['version'] < 23333 && !empty($clientClipboard)) {
			$serverData['version']++;
		} else {
			$serverData['version'] = 0;
		}
		$serverData['clipboard'] = $clientClipboard;
		$serverVersionHash = sha1(VersionKey . ($auth ? $username : '') . $serverData['version'] . VersionKey);
	}
	// 将处理结果存储并重新发回给客户端, 且如果版本没有发生变化, 就不发送剪切板内容以节省带宽.
	SaveData($username, $serverData);
	die(json_encode(array('version' => $serverData['version'], 'version_hash' => $serverVersionHash, 'clipboard' => ($versionChanged ? $serverData['clipboard'] : null))));
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
	    			<input type="password" id="password" name="password" required>
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
