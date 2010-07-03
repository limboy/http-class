<?php
class Http {

	/**
	 * 默认设置
	 *
	 * target: 目标地址
	 * method: 方法[GET/POST/PUT/DELETE]，默认为GET
	 * params: 参数
	 * cookies: cookies
	 * timeout: 过期时间，默认为3
	 * referer: 引用地址
	 * cookiePath: cookie存放路径，curl会用到，默认为cookie.txt
	 * useCookie: 是否使用cookie，默认为true
	 * saveCookie: 是否保存服务端发送过来的cookie,默认为true
	 * username: Basic Auth的用户名
	 * password: Basic Auth的密码
	 * maxRedirect: 最大重定向数
	 * redirect: 是否启用重定向
	 * debug: 是否进行调试，暂未实现
	 * userAgent: 模拟浏览器
	 *
	 * @var array
	 */
	protected $_config = array(
		'target' => '',
		'method' => 'GET',
		'params' => array(),
		'cookies' => array(),
		'timeout' => 3,
		'referer' => '',
		'cookiePath' => 'cookie.txt',
		'useCookie' => true,
		'saveCookie' => true,
		'username' => '',
		'password' => '',
		'maxRedirect' => 5,
		'redirect' => true,
		'debug' => false,
		'userAgent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.9',
		'useCurl' => true,
	);

	protected $_host;
	protected $_port = 80;
	protected $_path = '';
	protected $_schema = 'http';
	protected $_headers;
	protected $_status = 0;
	protected $_result;
	protected $_cookies = array();
	protected $_curRedirect = 0;
	protected $_error;
	protected $_nextToken;
	protected $_method = array('GET', 'POST', 'PUT', 'DELETE');

	public function __construct($config = null) {
		if(!empty($config)) {
			$this->setConfigs($config);
		}
	}

	public function __get($name) {
		if (isset($this->_config[$name])) {
			return $this->_config[$name];
		}
	}

	public function __set($name, $value) {
		if (isset($this->_config[$name])) {
			$this->_config[$name] = $value;
			$setter = '_setConfig'.ucfirst($name);
			method_exists($this, $setter) && $this->$setter($value);
		}
	}

	public function setConfigs(array $configs) {
		$this->_config = $configs + $this->_config;

		foreach($this->_config as $key=>$value) {
			$setMethod = '_setConfig'.ucfirst($key);
			if (method_exists($this, $setMethod)) {
				$this->$setMethod();
			}
		}
	}

	protected function _setConfigMethod() {
		if(!in_array($this->_config['method'], $this->_method, true)) {
			throw new Exception('无效方法'.$this->_config['method']);
		}
	}

	/*
	protected function _domainMatch($requestHost, $cookieDomain) {
		if ('.' != $cookieDomain[0]) {
			return $requestHost == $cookieDomain;
		} elseif (substr_count($cookieDomain, '.') < 2) {
			return false;
		} else {
			return substr('.'.$requestHost, - strlen($cookieDomain)) == $cookieDomain;
		}
	}
	//*/

	protected function _parseCookie() {
		if (is_array($this->_headers['set-cookie'])) {
			$cookieHeaders = $this->_headers['set-cookie'];
		}
		else {
			$cookieHeaders = array($this->_headers['set-cookie']);
		}

		foreach ($cookieHeaders as $cookie) {
			$cookieArray = explode(';', $cookie);
			foreach ($cookieArray as $cKey => $cVal) {
				$name = $this->_encodeCookie($cKey, true);
				$value = $this->_encodeCookie($cVal, false);
				$this->_cookies[$name] = $value;
			}
		}
	}
	
	protected function _parseHeaders($responseHeader)
	{
		$headers = explode("\r\n", $responseHeader);
		$this->_headers = array();

		if ($this->_status === 0) {
			preg_match('/^http\/[0-9]\.[0-9][ \t]+([0-9]+)[ \t]*/i', $headers[0], $matches);
			if (!empty($matches)) {
				$this->_status = $matches[1];
			}
			else {
				throw new Exception('未知的HTTP STATUS');
			}
			unset($headers[0]);
		}

		foreach ($headers as $header) {
			$headerArray = explode(':', $header, 2);
			if (count($headerArray) == 2) {
				$headerName  = strtolower($headerArray[0]);
				$headerValue = trim($headerArray[1], "\r\n");

				if(isset($this->_headers[$headerName])) {
					if(is_string($this->_headers[$headerName])) {
						$this->_headers[$headerName] = array($this->_headers[$headerName]);
					}
					$this->_headers[$headerName][] = $headerValue;
				}
				else {
					$this->_headers[$headerName] = $headerValue;
				}
			}
		}

		if ($this->_config['saveCookie'] && isset($this->_headers['set-cookie'])) {
			$this->_parseCookie();
		}
	}

	protected function _encodeCookie($value, $isName) {
		return($isName ? str_replace("=", "%25", $value) : str_replace(";", "%3B", $value));
	}

	/**
	 * 添加参数
	 *
	 * @param array $params 要添加的参数
	 * @return void
	 */
	public function addParams(array $params) {
		foreach($params as $key => $val) {
			$this->_config['params'][$key] = $val;
		}
	}

	/**
	 * 添加cookie
	 *
	 * @param array $cookies 要添加的cookies
	 * @return void
	 */
	public function addCookies(array $cookies) {
		foreach($cookies as $key => $val) {
			$this->_config['cookies'][$key] = $val;
		}
	}

	/**
	 * 执行操作，返回结果
	 *
	 * @return string 
	 */
	public function execute() {
		$target =& $this->_config['target'];
		$queryString = '';
		empty($this->_config['params']) || $queryString = http_build_query($this->_config['params']);

		$useCurl = function_exists('curl_init') && $this->_config['useCurl'];

		if($this->_config['method'] == 'GET' && !empty($queryString)) {
			$target = $target . "?" . $queryString;
		}

		$urlParsed = parse_url($target);

		if ($urlParsed['scheme'] == 'https') {
			$this->_host = 'ssl://'.$urlParsed['host'];
			$this->_port = ($this->_port != 0)?$this->_port:443;
		} else {
			$this->_host = $urlParsed['host'];
			$this->_port = ($this->_port != 0) ? $this->_port : 80;
		}

		$this->_path   = (isset($urlParsed['path'])?$urlParsed['path']:'/').
			(isset($urlParsed['query'])
			?'?' . $urlParsed['query']
			:'');
		$this->_schema = $urlParsed['scheme'];

		if(!empty($this->_config['cookies'])) {
			$tempString = array();
			foreach ($this->_config['cookies'] as $key => $value) {
				if(trim($value) !== '') {
					$tempString[] = $key."=".urlencode($value);
				}
			}
			$cookieString = join('&', $tempString);
		}

		if ($useCurl) {
			$ch = curl_init();

			if ($this->_config['method'] == 'GET') {
				curl_setopt ($ch, CURLOPT_HTTPGET, true); 
				curl_setopt ($ch, CURLOPT_POST, false); 
			} elseif ($this->_config['method'] == 'PUT') {
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); 
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Length: ' . strlen($queryString)));
				curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString); 
			} elseif ($this->_config['method'] == 'DELETE') {
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'); 
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Length: ' . strlen($queryString)));
				curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString); 
			} else {
				curl_setopt ($ch, CURLOPT_POSTFIELDS, $queryString);
				curl_setopt ($ch, CURLOPT_POST, true); 
				curl_setopt ($ch, CURLOPT_HTTPGET, false); 
			}

			if ($this->_config['username'] && $this->_config['password']) {
				curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
			}

			if ($this->_config['useCookie'] && isset($cookieString))
			{
				curl_setopt($ch, CURLOPT_COOKIE, $cookieString);
			}

			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_NOBODY, false);
			curl_setopt($ch, CURLOPT_COOKIEJAR, $this->_config['cookiePath']);
			curl_setopt($ch, CURLOPT_TIMEOUT, $this->_config['timeout']);
			curl_setopt($ch, CURLOPT_USERAGENT, $this->_config['userAgent']);
			curl_setopt($ch, CURLOPT_URL, $target);
			curl_setopt($ch, CURLOPT_REFERER, $this->_config['referer']);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $this->_config['redirect']);
			curl_setopt($ch, CURLOPT_MAXREDIRS, $this->_config['maxRedirect']);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			$content = curl_exec($ch);
			$contentArray = explode("\r\n\r\n", $content, 2);
			$this->_result = $contentArray[count($contentArray) - 1]; 
			$this->_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$this->_parseHeaders($contentArray[count($contentArray) - 2]);
			if ($curlError = curl_error($ch)) {
				throw new Exception($ch);
			}
			curl_close($ch);
		} else {
			$filePointer = fsockopen($this->_host, $this->_port, $errorNumber, $errorString, $this->_config['timeout']);

			if (!$filePointer)
			{
				throw new Exception('无法创建http socket连接'.$errorString.' ( '.$errorNumber.' )');
			}

			$requestHeader  = $this->_config['method']." ".$this->_path."  HTTP/1.1\r\n";
			$requestHeader .= "Host: ".$urlParsed['host']."\r\n";
			$requestHeader .= "User-Agent: ".$this->_config['userAgent']."\r\n";
			$requestHeader .= "Content-Type: application/x-www-form-urlencoded\r\n";

			if ($this->_config['useCookie'] && isset($cookieString)) {
				$requestHeader .= "Cookie: ".$cookieString."\r\n";
			}

			if ($this->_config['method'] == "POST") {
				$requestHeader .= "Content-Length: ".strlen($queryString)."\r\n";
			}

			if ($this->_config['referer'] != '') {
				$requestHeader .= "Referer: ".$this->_config['referer']."\r\n";
			}

			if ($this->_config['username'] && $this->_config['password']) {
				$requestHeader .= "Authorization: Basic ".base64_encode($this->_config['username'].':'.$this->_config['password'])."\r\n";
			}

			$requestHeader .= "Connection: close\r\n\r\n";

			if ($this->_config['method'] == "POST" && !empty($queryString)) {
				$requestHeader .= $queryString;
			}           
			
			fwrite($filePointer, $requestHeader);

			$result = '';
			do {
				$result .= fread($filePointer, 1);
			}
			while (!feof($filePointer));
			list($responseHeader, $responseContent) = explode("\r\n\r\n", $result, 2);
			$this->_parseHeaders($responseHeader);

			if (($this->_status == '301' || $this->_status == '302') && $this->_config['redirect'] == true) {
				if ($this->_curRedirect < $this->_config['maxRedirect']) {
					$newUrlParsed = parse_url($this->_headers['location']);
					if ($newUrlParsed['host']) {
						$newTarget = $this->_headers['location'];    
					} else {
						$newTarget = $this->_schema . '://' . $this->_host . '/' . $this->_headers['location'];
					}
					$newTarget = trim($newTarget);
					$this->_port   = 0;
					$this->_status = 0;
					$this->_config['params'] = array();
					$this->_config['method'] = 'GET';
					$this->_config['referer'] = $target;
					$this->_curRedirect++;

					$this->_config['target'] = $newTarget;
					$this->_result = $this->execute($newTarget);
				} else {
					throw new Component_Exception('Too many redirects');
				}
			} else {
				//http 1.1会添加一行字段标示message内容的长度，去掉
				$responseContent = explode("\r\n", $responseContent, 2);
				$responseContent = $responseContent[1];

				$this->_result = trim($responseContent);
			}
		}

		return $this->_result;
	}

	/**
	 * 获取执行结果
	 *
	 * @return string 执行结果
	 */
	public function getResult() {
		return $this->_result;
	}

	/**
	 * 获取headers
	 *
	 * @return array headers
	 */
	public function getHeaders() {
		return $this->_headers;
	}

	/**
	 * 获取状态
	 *
	 * @return int status
	 */
	public function getStatus() {
		return $this->_status;
	}

}
