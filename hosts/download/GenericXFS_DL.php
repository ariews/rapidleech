<?php
/*
@h@	/hosts/download/GenericXFS_DL.php

@h@	 GenericXFS_DL (alpha)
@h@	Written by Th3-822.
*/
if (!defined('RAPIDLEECH')) {
	require_once('index.html');
	exit();
}

class GenericXFS_DL extends DownloadClass {
	protected $page, $cookie, $scheme, $wwwDomain, $domain, $port, $host, $purl, $sslLogin, $cname, $form, $lpass, $fid, $enableDecoders = false, $embedDL = false, $unescaper = false, $customDecoder = false, $reverseForms = true, $cErrsFDL = array(), $DLregexp = '@https?://(?:[\w\-]+\.)+[\w\-]+(?:\:\d+)?/(?:files|dl?|cgi-bin/dl\.cgi)/[^\'\"\t<>\r\n\\\]+@i';
	private $classVer = 10;
	public $pluginVer, $pA;

	public function Download($link) {
		html_error('[GenericXFS_DL] This plugin can\'t be called directly.');
	}

	protected function Start($link, $cErrs = array(), $cErrReplace = true) {
		if ($this->pluginVer > $this->classVer) html_error('GenericXFS_DL class is outdated, please update it.');

		$this->cookie = empty($this->cookie) ? array('lang' => 'english') : array_merge($this->cookie, array('lang' => 'english'));
		$link = explode('|', str_ireplace('%7C', '|', $link), 2);
		if (count($link) > 1) $this->lpass = rawurldecode($link[1]);
		if (!preg_match('@https?://(?:[\w\-]+\.)+[\w\-]+(?:\:\d+)?/(\w{12})(?=(?:[/\.]|(?:\.html?))?)@i', str_ireplace('/embed-', '/', $link[0]), $url)) html_error('Invalid link?.');
		$this->fid = $url[1];
		$url = parse_url($url[0]);
		$url['scheme'] = strtolower($url['scheme']);
		$url['host'] = strtolower($url['host']);

		if ($this->wwwDomain && strpos($url['host'], 'www.') !== 0) $url['host'] = 'www.' . $url['host'];
		elseif (!$this->wwwDomain && strpos($url['host'], 'www.') === 0) $url['host'] = substr($url['host'], 4);

		$this->scheme = $url['scheme'];
		$this->domain = $url['host'];
		$this->port = (!empty($url['port']) && $url['port'] > 0 && $url['port'] < 65536) ? $url['port'] : 0;
		$this->host = $this->domain . (!empty($this->port) ? ':'.$this->port : '');
		$this->purl = $this->scheme.'://'.$this->host.'/';
		$this->link = $GLOBALS['Referer'] = rebuild_url($url);
		unset($url, $link);

		$this->enableDecoders = $this->embedDL || $this->unescaper || $this->customDecoder;

		if (empty($_POST['step']) || empty($_POST['captcha_type'])) {
			$this->page = $this->GetPage($this->link, $this->cookie);
			if (!empty($cErrs) && is_array($cErrs)) {
				foreach ($cErrs as $cErr) {
					if (is_array($cErr)) is_present($this->page, $cErr[0], $cErr[1]);
					else is_present($this->page, $cErr);
				}
				if ($cErrReplace) return $this->Login();
			}
			is_present($this->page, 'The file you were looking for could not be found');
			is_present($this->page, 'The file was removed by administrator');
			is_present($this->page, 'The file was deleted by its owner');
			is_present($this->page, 'The file was deleted by administration');
			is_present($this->page, 'No such file with this filename', 'Error: Invalid filename, check your link and try again.'); // With the regexp i removed the filename part of the link, this error shouldn't be showed
		}

		return $this->Login();
	}

	protected function FindPost($formOp = 'download[1-3]') {
		//if (!preg_match_all('@<form(?:[\s\t][^\>]*)?\>(?:[^<]+(?!\<form)<[^<]+)*</form>@i', $this->page, $forms)) return false;
		if (!preg_match_all('@(?><form(?:\s[^\>]*)?\>)(?>.*?</form>)@is', $this->page, $forms)) return false;
		$forms = ($this->reverseForms ? array_reverse($forms[0]) : $forms[0]); // In some hosts the freedl form is before the "premium" form so $this->reverseForms must be on false on those hosts.
		$found = false;
		foreach ($forms as $form) {
			if (preg_match('@<input\s*[^>]*\sname="op"[^>]*\svalue="' . $formOp . '"@i', $form)) {
				$found = true;
				break;
			}
		}
		if (!$found) return false;

		// Remove html commented inputs.
		while ($comment = cut_str($form, '<!--', '-->')) $form = str_replace('<!--'.$comment.'-->', '', $form);

		$this->form = $form;
		unset($forms, $form, $ret);

		preg_match_all('@<input\s*[^>]*\stype="hidden"[^>]*\sname="(\w+)"[^>]*\svalue="([^"]*)"@i', $this->form, $inputs);
		$data = array_map('html_entity_decode', array_combine($inputs[1], $inputs[2]));

		if (($pos = stripos($this->form, '<textarea')) !== false && preg_match_all('@<textarea\s+(?:[^>]*\s)?name="(\w+)"[^>]*>([^<]*)</textarea>@i', substr($this->form, $pos), $inputs)) $data = array_merge($data, array_map('html_entity_decode', array_combine($inputs[1], $inputs[2])));

		if ((stripos($this->form, 'type="submit"') !== false || stripos($this->form, 'type="image"') !== false) && preg_match_all('@<input\s*[^>]*\stype="(?:submit|image)"[^>]*\sname="(\w+)"[^>]*\svalue="([^"]*)"@i', $this->form, $inputs)) {
			$data = array_merge($data, array_map('html_entity_decode', array_combine($inputs[1], $inputs[2])));
			if (!empty($data['method_free']) && !empty($data['method_premium'])) $data['method_premium'] = '';
		}

		$this->post = $data;
		return true;
	}

	// Custom page decoder placeholder
	protected function pageDecoder() {
		html_error('[GenericXFS_DL] $this->customDecoder is enabled but there is no pageDecoder() function.');
	}

	protected function runDecoders() {
		// Packed embedded video decoder
		if (!empty($this->embedDL) && preg_match_all('@eval\s*\(\s*function\s*\(p,a,c,k,e,d\)\s*\{.*\}\s*\(\s*\'([^\r|\n]*)\'\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*\'([^\']+)\'\.split\([\'|\"](.)[\'|\"]\)(?:,\d,\{\})?\)\)@', $this->page, $js)) {
			$cnt = count($js[0]);
			for ($i = 0; $i < count($js[0]); $i++) {
				$this->page = str_replace($js[0][$i], $this->XFSUnpacker($js[1][$i], $js[2][$i], $js[3][$i], $js[4][$i], $js[5][$i]), $this->page);
			}
		}
		// JS unescape decoder
		if (!empty($this->unescaper) && preg_match_all('@eval\s*\(unescape\s*\(\s*(\"|\')([%\da-fA-F]+)\1\s*\)\s*\)\s*;?@', $this->page, $js)) {
			$cnt = count($js[0]);
			for ($i = 0; $i < count($js[0]); $i++) {
				$this->page = str_replace($js[0][$i], urldecode($js[2][$i]), $this->page);
			}
		}
		// Custom decoder function
		if (!empty($this->customDecoder)) $this->pageDecoder();
	}

	// (Placeholder) Returns video title if available to use on stream video downloads.
	protected function getVideoTitle() {
		return false;
	}

	protected function getFileName($url) {
		$fname = basename(parse_url($url, PHP_URL_PATH));
		if (preg_match("@^(?:v(?:ideo)?|{$this->fid})?\.(mp4|flv)$@i", $fname, $vExt)) { // Possible video/stream
			// Try to get original filename or title for renaming the file.
			if (!empty($this->post['fname'])) $newname = $this->post['fname'];
			else if (($title = $this->getVideoTitle())) $newname = $title;
			else $newname = false;

			// I always like to add a letter to mark it as a reconverted video stream and remove the original video .ext
			if (!empty($newname)) $fname = preg_replace('@\.(mp4|flv|mkv|webm|wmv|(m2)?ts|rm(vb)?|mpe?g?|vob|avi|[23]gp)$@i', '', basename($newname)) . '_S.' . strtolower($vExt[1]);
		}
		return $fname;
	}

	protected function testDL() {
		if (!empty($this->enableDecoders)) $this->runDecoders();

		if (preg_match($this->DLregexp, $this->page, $DL)) {
			$this->RedirectDownload($DL[0], basename($this->getFileName($DL[0])));
			return true;
		}
		return false;
	}

	protected function XFSUnpacker($p,$a,$c,$k,$ed) {
		$k = explode($ed, $k);
		while ($c--) if($k[$c]) $p = preg_replace('@\b'.base_convert($c, 10, $a).'\b@', $k[$c], $p);
		return $p;
	}

	protected function findCaptcha() {
		if (!empty($this->captcha)) return false;
		if (($pos = stripos($this->page, $this->scheme . '://' . $this->host . '/captchas/')) !== false && preg_match('@https?://(?:[\w\-]+\.)+[\w\-]+(?:\:\d+)?/captchas/[\w\-]+\.(?:jpe?g|png|gif)@i', substr($this->page, $pos), $gdCaptcha)) {
			// gd Captcha
			return array('type' => 1, 'url' => $gdCaptcha[0]);
		} elseif (substr_count($this->form, "<span style='position:absolute;padding-left:") > 3 && preg_match_all("@<span style='[^\'>]*padding-left\s*:\s*(\d+)[^\'>]*'[^>]*>((?:&#\w+;)|(?:\d))</span>@i", $this->form, $txtCaptcha)) {
			// Text Captcha (decodeable)
			$txtCaptcha = array_combine($txtCaptcha[1], $txtCaptcha[2]);
			ksort($txtCaptcha, SORT_NUMERIC);
			$txtCaptcha = trim(html_entity_decode(implode($txtCaptcha), ENT_QUOTES, 'UTF-8'));
			return array('type' => 2, 'key' => $txtCaptcha);
		} elseif ((stripos($this->page, 'google.com/recaptcha/api/') !== false || stripos($this->page, 'recaptcha.net/') !== false) && preg_match('@https?://(?:[\w\-]+\.)?(?:google\.com/recaptcha/api|recaptcha\.net)/(?:challenge|noscript)\?k=([\w\.\-]+)@i', $this->page, $reCaptcha)) {
			// Old reCAPTCHA
			return array('type' => 3, 'key' => $reCaptcha[1]);
		} elseif (($pos = stripos($this->page, '://api.solvemedia.com/')) !== false && preg_match('@https?://api\.solvemedia\.com/papi/challenge\.(?:no)?script\?k=([\w\.\-]+)@i', substr($this->page, $pos - 5), $smCaptcha)) {
			// SolveMedia Captcha
			return array('type' => 4, 'key' => $smCaptcha[1]);
		}
		return false;
	}

	protected function showCaptcha($step) {
		if ($captcha = $this->findCaptcha()) {
			$data = $this->DefaultParamArr($this->link, (empty($this->cookie[$this->cname])) ? 0 : encrypt(CookiesToStr($this->cookie)));
			if (!empty($this->post)) foreach ($this->post as $k => $v) $data["T8gXFS[$k]"] = $v;
			$data['step'] = $step;
			$data['captcha_type'] = $captcha['type'];
			switch ($captcha['type']) {
				default: return html_error('Unknown captcha type.');
				case 1:
					list($headers, $imgBody) = explode("\r\n\r\n", $this->GetPage($captcha['url']), 2);
					if (substr($headers, 9, 3) != '200') html_error('[1] Error downloading CAPTCHA img.');
					$mimetype = (preg_match('@image/[\w+]+@', $headers, $mimetype) ? $mimetype[0] : 'image/jpg');
					return $this->EnterCaptcha("data:$mimetype;base64,".base64_encode($imgBody), $data);
				case 2:
					$this->captcha = 2;
					$this->post['code'] = $captcha['key'];
					// postCaptcha won't be needed on this case.
					return true;
				case 3: return $this->reCAPTCHA($captcha['key'], $data);
				case 4: return $this->SolveMedia($captcha['key'], $data);
			}
		}
		return false;
	}

	protected function postCaptcha(&$step) {
		if (empty($_POST['step']) || empty($_POST['captcha_type'])) return false;
		if (!empty($_POST['cookie'])) {
			$this->cookie = StrToCookies(decrypt(urldecode($_POST['cookie'])));
			$this->cookie['lang'] = 'english';
			$_POST['cookie'] = false;
		}
		$post = (!empty($_POST['T8gXFS']) && is_array($_POST['T8gXFS']) ? $_POST['T8gXFS'] : array());
		switch ($_POST['captcha_type']) {
			default: 
				return html_error('Invalid captcha type.');
			case '1': // Image (gd) Captcha
				$this->captcha = 1;
				if (empty($_POST['captcha'])) html_error('[1] You didn\'t enter the image verification code.');
				$post['code'] = urlencode($_POST['captcha']);
				break;
			case '3': // Old reCAPTCHA
				$this->captcha = 3;
				if (empty($_POST['recaptcha_response_field'])) html_error('[3] You didn\'t enter the image verification code.');
				if (empty($_POST['recaptcha_challenge_field'])) html_error('[3] Empty reCAPTCHA challenge.');
				$post['recaptcha_challenge_field'] = urlencode($_POST['recaptcha_challenge_field']);
				$post['recaptcha_response_field'] = urlencode($_POST['recaptcha_response_field']);
				break;
			case '4': // Solvemedia
				$this->captcha = 4;
				$post = array_merge($post, $this->verifySolveMedia());
				break;
		}
		$step = (int)$_POST['step'];
		$_POST['step'] = $_POST['captcha_type'] = false;
		$this->page = $this->GetPage($this->link, $this->cookie, $post);
		$this->cookie = GetCookiesArr($this->page, $this->cookie);
		return true;
	}

	// Finds FreeDL countdown on $this->page and calls $this->CountDown(X) for it.
	// return true if there is a countdown, false otherwise.
	protected function findCountdown() {
		if (preg_match('@<span[^>]*>(?>.*?<span\s+id=[\'"][\w\-]+[\'"][^>]*>)(\d+)</span>(?>.*?</span>)@sim', $this->page, $count)) {
			if ($count[1] > 0) $this->CountDown($count[1] + 2);
			return true;
		}
		return false;
	}

	protected function checkCaptcha($step) {
		if (preg_match('@>\s*Wrong captcha\s*<@i', $this->page)) {
			if (empty($this->captcha)) html_error("Error: Unknown captcha. [$step]");
			else if ($this->captcha == '2') html_error("Error: Error Decoding Captcha. [$step]");
			else html_error("Error: Wrong Captcha Entered. [$step]");
		}
	}

	protected function FreeDL($step = 1) {
		if (!$this->postCaptcha($step) && $step == 1 && !empty($this->cookie[(!empty($this->cname) ? $this->cname : 'xfss')])) {
			// Member DL: We need to reload the page with the user's cookies.
			$this->page = $this->GetPage($this->link, $this->cookie);
			$this->cookie = GetCookiesArr($this->page, $this->cookie);
		}
		if (($pos = stripos($this->page, 'You have to wait')) !== false && preg_match('@You have to wait[\W\S]?(?:(?:\s*|\s*<br\s*/?\s*>\s*)?\d+ \w+,\s){0,2}\d+ \w+(?:\s*|\s*<br\s*/?\s*>\s*)?(?:un)?till? (?:the )?next download@i', substr($this->page, $pos), $err)) html_error('Error: '.strip_tags($err[0]));
		if (($pos = stripos($this->page, 'You can download files up to ')) !== false && preg_match('@You can download files up to \d+ [KMG]b only.@i', substr($this->page, $pos), $err)) html_error('Error: '.$err[0]);
		if (($pos = stripos($this->page, 'You have reached the download')) !== false && preg_match('@You have reached the download[- ]limit(?: of|:) \d+ [KMGT]b for(?: the)? last \d+ days?@i', substr($this->page, $pos), $err)) html_error('Error: '.$err[0]);
		if ($this->testDL()) return true;
		if (!$this->FindPost()) {
			is_present($this->page, 'Downloads are disabled for your country:', 'Downloads are disabled for your server\'s country.');
			is_present($this->page, 'This server is in maintenance mode. Refresh this page in some minutes.', 'File is not available at this moment, try again later.');
			is_present($this->page, 'This file is available for Premium Users only.');
			is_present($this->page, 'This file reached max downloads limit', 'Error: This file reached max downloads limit.');
			if (!empty($this->cErrsFDL) && is_array($this->cErrsFDL)) {
				foreach ($this->cErrsFDL as $cErr) {
					if (is_array($cErr)) is_present($this->page, $cErr[0], $cErr[1]);
					else is_present($this->page, $cErr);
				}
			}
			return html_error('Non aceptable form found.');
		}
		is_present($this->page, '>Skipped countdown', "Error: Skipped countdown? [$step].");
		$this->checkCaptcha($step);
		switch ($this->post['op']) {
			default: html_error('Unknown form op.');
			case 'download1':
				$fstep = 1;
				break;
			case 'download2':
				$fstep = 2;
				break;
		}
		if ($step > $fstep) html_error("Loop Detected [$fstep]");
		is_present($this->page, '>Expired session<', "Error: Expired Download Session. [$fstep]");
		$this->findCountdown();
		$this->showCaptcha($fstep);
		$this->page = $this->GetPage($this->link, $this->cookie, $this->post);
		$this->cookie = GetCookiesArr($this->page, $this->cookie);
		return $this->FreeDL($fstep + 1);
	}

	protected function PremiumDL() {
		$this->page = $this->GetPage($this->link, $this->cookie);
		if (($pos = stripos($this->page, 'You have reached the download')) !== false && preg_match('@You have reached the download[- ]limit(?: of|:) \d+ [TGMK]b for(?: the)? last \d+ days?@i', substr($this->page, $pos), $err)) html_error('Error: '.$err[0]);

		if (!$this->testDL()) {
			if (!$this->FindPost()) {
				is_present($this->page, 'Downloads are disabled for your country:', 'Downloads are disabled for your server\'s country.');
				is_present($this->page, 'This server is in maintenance mode. Refresh this page in some minutes.', 'File is not available at this moment, try again later.');
				html_error('[PremiumDL] Non aceptable form found.');
			}
			if (!isset($this->post['method_premium']) || $this->post['method_premium'] === '') $this->post['method_premium'] = 1;
			sleep(1); // This should avoid errors at massive usage.
			$this->page = $this->GetPage($this->link, $this->cookie, $this->post);
			if (($pos = stripos($this->page, 'You have reached the download')) !== false && preg_match('@You have reached the download[- ]limit(?: of|:) \d+ [TGMK]b for(?: the)? last \d+ days?@i', substr($this->page, $pos), $err)) html_error('Error: '.$err[0]);
			if (!$this->testDL()) html_error('Error: Download-link not found.');
		} else return true;
	}

	// Allow Custom Login Post. (UTB)
	protected function sendLogin($post) {
		$page = $this->GetPage((!empty($this->sslLogin) ? 'https://'.$this->host.'/' : $this->purl) . '?op=login', $this->cookie, $post, $this->purl);
		return $page;
	}

	protected function Login() {
		$this->pA = (empty($_REQUEST['premium_user']) || empty($_REQUEST['premium_pass']) ? false : true);
		$pkey = str_ireplace(array('www.', '.'), array('', '_'), $this->domain);
		if (($_REQUEST['premium_acc'] != 'on' || (!$this->pA && (empty($GLOBALS['premium_acc'][$pkey]['user']) || empty($GLOBALS['premium_acc'][$pkey]['pass']))))) return $this->FreeDL();
	
		$user = ($this->pA ? $_REQUEST['premium_user'] : $GLOBALS['premium_acc'][$pkey]['user']);
		$pass = ($this->pA ? $_REQUEST['premium_pass'] : $GLOBALS['premium_acc'][$pkey]['pass']);
		if ($this->pA && !empty($_POST['pA_encrypted'])) {
			$user = decrypt(urldecode($user));
			$pass = decrypt(urldecode($pass));
			unset($_POST['pA_encrypted']);
		}

		if (empty($user) || empty($pass)) html_error('Login Failed: User or Password is empty. Please check login data.');
		$post = array();
		$post['op'] = 'login';
		$post['redirect'] = '';
		$post['login'] = urlencode($user);
		$post['password'] = urlencode($pass);

		$page = $this->sendLogin($post);

		if (!$this->checkLogin($page)) html_error('Login Error: checkLogin() returned false.');

		$this->cookie = GetCookiesArr($page);
		if (empty($this->cookie[(!empty($this->cname) ? $this->cname : 'xfss')])) html_error('Login Error: Cannot find session cookie.');
		$this->cookie['lang'] = 'english';

		$page = $this->isLoggedIn();
		if (!$page) html_error('Login Error: isLoggedIn() returned false.');

		if ($page === true) $page = $this->GetPage($this->purl.'?op=my_account', $this->cookie, 0, $this->purl);
		return $this->checkAccount($page);
	}

	// Checks For Login Errors on $page and Calls html_error() For Them.
	// return true if there are no login errors, false otherwise.
	protected function checkLogin($page) {
		is_present($page, 'op=resend_activation', 'Login failed: Your account isn\'t confirmed yet.');
		is_present($page, 'Your account was banned by administrator.', 'Login failed: Account is Banned.');
		is_present($page, 'Your IP is banned', 'Login Error: IP banned for too many wrong logins.');
		if (preg_match('@Incorrect (Username|Login) or Password@i', $page)) html_error('Login failed: User/Password incorrect.');
		return true;
	}

	// Checks if account is logged in.
	// return $page - If it's logged in and the $page loaded is usable too for checkAccount(), if not, return true or false
	protected function isLoggedIn() {
		$page = $this->GetPage($this->purl.'?op=my_account', $this->cookie, 0, $this->purl);
		if (stripos($page, '/?op=logout') === false && stripos($page, '/logout') === false) return false;
		return $page;
	}

	// A simpler function for check if account is premium in $page contents, easier to override on plugins for specific hosts.
	// return true if user is premium, false otherwise.
	protected function isAccPremium($page) {
		if (stripos($page, 'Premium account expire') !== false || stripos($page, 'Premium-account expire') !== false || stripos($page, 'Premium Expires') !== false) return true;
		return false;
	}

	protected function checkAccount($page) {
		is_present($page, 'Your account was banned by administrator.', '[checkAccount] Account is Banned.');
		if ($this->isAccPremium($page)) return $this->PremiumDL();

		// FreeDL() shouldn't have issues using it with a premium account... But PremiumDL() uses less checks.
		$this->changeMesg('<br /><b>Account isn\'t premium?</b>', true);
		return $this->FreeDL();
	}

}

// GenericXFS_DL (alpha)
// Written by Th3-822.

?>