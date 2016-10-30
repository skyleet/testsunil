<?php
error_reporting(0);
// ////////logging here////////////
// $time = date('g:i:s A, dS-M-Y,l.');
// $url = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
// $ip = $_SERVER['REMOTE_ADDR'];
// $uagent = $_SERVER['HTTP_USER_AGENT'];
// $ref = $_SERVER['HTTP_REFERER'];
// //$host = $_SERVER['SERVER_NAME'];
// $host = gethostbyaddr($_SERVER['REMOTE_ADDR']);
// $host2 = $_SERVER['REMOTE_HOST'];
// $rep = str_repeat("-",66);
// $logentry= "<tr><td>[$host] OR [$host2]</td><td>$time</td><td>$url</td><td>$ip</td><td>$uagent</td><td>$ref</td></tr>\n";
// $fp = @fopen("logs.php", "a");
// fwrite($fp, $logentry);
// @fclose($fp);
// /////////////////////////////////

$whitelistPatterns = array();
$forceCORS = true;
ob_start("ob_gzhandler");
if (!function_exists("curl_init")) die ("This proxy requires PHP's cURL extension. Please install/enable it on your server and try again.");
function getHostnamePattern($hostname) {
  $escapedHostname = str_replace(".", "\.", $hostname);
  return "@^https?://([a-z0-9-]+\.)*" . $escapedHostname . "@i";
}
function removeKeys(&$assoc, $keys2remove) {
  $keys = array_keys($assoc);
  $map = array();
  foreach ($keys as $key) {
     $map[strtolower($key)] = $key;
  }
  foreach ($keys2remove as $key) {
    $key = strtolower($key);
    if (isset($map[$key])) {
       unset($assoc[$map[$key]]);
    }
  }
}
if (!function_exists("getallheaders")) {
  function getallheaders() {
    $result = array();
    foreach($_SERVER as $key => $value) {
      if (substr($key, 0, 5) == "HTTP_") {
        $key = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
        $result[$key] = $value;
      }
    }
    return $result;
  }
}
define("PROXY_PREFIX", "http" . (isset($_SERVER['HTTPS']) ? "s" : "") . "://" . $_SERVER["SERVER_NAME"] . ($_SERVER["SERVER_PORT"] != 80 ? ":" . $_SERVER["SERVER_PORT"] : "") . $_SERVER["SCRIPT_NAME"] . "/");
function makeRequest($url) {
  $user_agent = $_SERVER["HTTP_USER_AGENT"];
  if (empty($user_agent)) {
    $user_agent = "Mozilla/5.0 (compatible; miniProxy)";
  }
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
  $browserRequestHeaders = getallheaders();
  removeKeys($browserRequestHeaders, array(
    "Host",
    "Content-Length",
    "Accept-Encoding"
  ));
  curl_setopt($ch, CURLOPT_ENCODING, "");
  $curlRequestHeaders = array();
  foreach ($browserRequestHeaders as $name => $value) {
    $curlRequestHeaders[] = $name . ": " . $value;
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $curlRequestHeaders);
  switch ($_SERVER["REQUEST_METHOD"]) {
    case "POST":
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents("php://input"));
    break;
    case "PUT":
      curl_setopt($ch, CURLOPT_PUT, true);
      curl_setopt($ch, CURLOPT_INFILE, fopen("php://input"));
    break;
  }
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt ($ch, CURLOPT_FAILONERROR, true);
  curl_setopt($ch, CURLOPT_URL, $url);
  $response = curl_exec($ch);
  $responseInfo = curl_getinfo($ch);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);
  $responseHeaders = substr($response, 0, $headerSize);
  $responseBody = substr($response, $headerSize);
  return array("headers" => $responseHeaders, "body" => $responseBody, "responseInfo" => $responseInfo);
}
function rel2abs($rel, $base) {
  if (empty($rel)) $rel = ".";
  if (parse_url($rel, PHP_URL_SCHEME) != "" || strpos($rel, "//") === 0) return $rel;
  if ($rel[0] == "#" || $rel[0] == "?") return $base.$rel;
  extract(parse_url($base));
  $path = isset($path) ? preg_replace('#/[^/]*$#', "", $path) : "/";
  if ($rel[0] == '/') $path = "";
  $port = isset($port) && $port != 80 ? ":" . $port : "";
  $auth = "";
  if (isset($user)) {
    $auth = $user;
    if (isset($pass)) {
      $auth .= ":" . $pass;
    }
    $auth .= "@";
  }
  $abs = "$auth$host$path$port/$rel";
  for ($n = 1; $n > 0; $abs = preg_replace(array("#(/\.?/)#", "#/(?!\.\.)[^/]+/\.\./#"), "/", $abs, -1, $n)) {} //Replace '//' or '/./' or '/foo/../' with '/'
  return $scheme . "://" . $abs;
}
function proxifyCSS($css, $baseURL) {
  return preg_replace_callback(
    '/url\((.*?)\)/i',
    function($matches) use ($baseURL) {
        $url = $matches[1];
        if (strpos($url, "'") === 0) {
          $url = trim($url, "'");
        }
        if (strpos($url, "\"") === 0) {
          $url = trim($url, "\"");
        }
        if (stripos($url, "data:") === 0) return "url(" . $url . ")";
        return "url(" . PROXY_PREFIX . rel2abs($url, $baseURL) . ")";
    },
    $css);
}
$url = substr($_SERVER["REQUEST_URI"], strlen($_SERVER["SCRIPT_NAME"]) + 1);
if (empty($url)) {
    die("<!DOCTYPE html><html><head><meta charset=\"UTF-8\" /><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><title>My Proxy | Unblock any website</title><meta name=\"description\" content=\"Hello World! Welcome to my proxy. Here you can Unblock any Website with my proxy. Simply enter url and press Enter.\" /><meta name=\"keywords\" content=\"Proxy, Unblock websites, No Sensorship, Very Fast proxy\" /><meta name=\"author\" content=\"yadavsunil4796@gmail\" /><style type=\"text/css\">body{margin: 0;background-color: #333333;}a{color: #f0f;}a:hover{color: #55f;}a:visited{color: #f90;}h1{color: #0f0;font-size: 250%;padding: 1%;}h3{color: #0f0;font-family: Papyrus, serif;font-size: 150%;word-wrap: break-word;padding: 0px 10px;}footer{color: #0ff;padding: 10px 5px;}form{background:linear-gradient(#555555,#151515);border:1px solid #333;border-radius:10px;box-shadow:inset 0 0 0 1px #272727;display:inline-block;position:relative;margin:30px auto 0;padding:25px;}input{background:linear-gradient(#333,#222);border:1px solid #444;border-radius:5px 5px 5px 5px;box-shadow:0 2px 0 #000;color:#0ff;display:block;float:left;font-family:helvetica,arial,sans-serif;font-size:20px;font-weight:500;height:40px;line-height:40px;text-shadow:0 1px 0 #000;width:500px;margin:0px 0px 9px 0px;padding:0 10px;}input:focus{animation:glow 800ms ease-out infinite alternate;background:linear-gradient(#333933,#222922);box-shadow:0 0 5px rgba(0,255,0,.1),inset 0 0 5px rgba(0,255,0,.1),0 2px 0 #000;color:#0ff;outline:none;border-color:#2f2;}button{background:linear-gradient(#555,#333);-webkit-box-sizing:content-box;-moz-box-sizing:content-box;-o-box-sizing:content-box;-ms-box-sizing:content-box;box-sizing:content-box;border:1px solid #55a;border-radius:5px 5px 5px 5px;box-shadow:0 2px 0 #000;color:#0cc;font-family:Cabin,helvetica,arial,sans-serif;font-size:13px;font-weight:500;height:40px;line-height:40px;text-shadow:0 2px 0 #000;width:80px;margin:0;padding:0;}button:hover,button:focus{background:linear-gradient(#777,#555);color:#5ff;outline:none;text-decoration: underline;font-style: bold;}button:active{background:linear-gradient(#888,#666);box-shadow:0 -1px 0 #222,inset 1px 0 1px #222;top: 1px;}@media screen and (min-width: 854px) {h1{font-size: 250%;}}@media screen and (min-width: 480px) and (max-width: 854px) {h1{font-size: 150%;}h3{font-size: 115%;}form{margin:10px auto 0;padding:25px;}input{width:300px;}}@media screen and (max-width: 480px) and (min-width: 240px) {h1{font-size: 115%;}h3{font-size: 85%;font-weight: 300;width: 95%;}footer{font-size: 80%;}form{margin:30px 5px 30px;padding:20px;}input{font-size: 15px;width:100%;padding: 0 0;}}@media screen and (max-width: 240px) {h1{font-size: 100%;}h3{font-size: 80%;font-weight: 300;width: 95%;} input{font-size: 10px;}footer{font-size: 70%;line-height: 150%}form{margin:10px 5px 10px;padding:10px;}input{font-size: 15px;width:100%;padding: 0 0;}}@keyframes glow {0% {border-color: #393;box-shadow: 0 0 5px rgba(0,255,0,.2), inset 0 0 5px rgba(0,255,0,.1), 0 2px 0 #000;} 100% {border-color: #6f6;box-shadow: 0 0 20px rgba(0,255,0,.6), inset 0 0 10px rgba(0,255,0,.5), 0 2px 0 #000;}}</style></head><body><center><section><h1>Welcome to My Proxy!</h1><h3>Proxy can be used like: <span style=\"text-decoration: underline;color: #0ff;\">" . PROXY_PREFIX . "http://skyleet.net/</span><br />Or, enter a URL below:</h3><form onsubmit=\"window.location.href='" . PROXY_PREFIX . "' + document.getElementById('URL').value; return false;\"><input id=\"URL\" type=\"text\" size=\"50\" placeholder=\"Enter URL here to Unblock Site...\" autofocus/><button type=\"submit\" value=\"Proxy It!\">Unblock It!</button></form><footer><p>Copyright &copy; 2016 <a href=\"http://skyleet.net\" target=\"_blank\">SUNIL KUMAR YADAV.</a>&nbsp;All Rights Reserved. Made with <span style=\"color: #F80365;font-size: 115%;\">❤</span> by me.</p></footer></section></center></body></html><!-- feel free to message me at yadavsunil4796@gmail.com-->");
} else if (strpos($url, "//") === 0) {
    $url = "http:" . $url;
} else if (strpos($url, ":/") !== strpos($url, "://")) {
    $pos = strpos($url, ":/");
    $url = substr_replace($url, "://", $pos, strlen(":/"));
} else if (!preg_match("@^.*://@", $url)) {
    $url = "http://" . $url;
}
$urlIsValid = count($whitelistPatterns) === 0;
foreach ($whitelistPatterns as $pattern) {
  if (preg_match($pattern, $url)) {
    $urlIsValid = true;
    break;
  }
}
if (!$urlIsValid) {
  die("Error: The requested URL was disallowed by the server administrator.");
}
$response = makeRequest($url);
$rawResponseHeaders = $response["headers"];
$responseBody = $response["body"];
$responseInfo = $response["responseInfo"];
$header_blacklist_pattern = "/^Content-Length|^Transfer-Encoding|^Content-Encoding.*gzip/i";
$responseHeaderBlocks = array_filter(explode("\r\n\r\n", $rawResponseHeaders));
$lastHeaderBlock = end($responseHeaderBlocks);
$headerLines = explode("\r\n", $lastHeaderBlock);
foreach ($headerLines as $header) {
  $header = trim($header);
  if (!preg_match($header_blacklist_pattern, $header)) {
    header($header);
  }
}
if ($forceCORS) {
  header("Access-Control-Allow-Origin: *", true);
  header("Access-Control-Allow-Credentials: true", true);
  if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
    if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"])) {
      header("Access-Control-Allow-Methods: GET, POST, OPTIONS", true);
    }
    if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"])) {
      header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}", true);
    }
    exit(0);
  }
}
$contentType = "";
if (isset($responseInfo["content_type"])) $contentType = $responseInfo["content_type"];
if (stripos($contentType, "text/html") !== false) {
  $detectedEncoding = mb_detect_encoding($responseBody, "UTF-8, ISO-8859-1");
  if ($detectedEncoding) {
    $responseBody = mb_convert_encoding($responseBody, "HTML-ENTITIES", $detectedEncoding);
  }
  $doc = new DomDocument();
  @$doc->loadHTML($responseBody);
  $xpath = new DOMXPath($doc);
  foreach($xpath->query('//form') as $form) {
    $method = $form->getAttribute("method");
    $action = $form->getAttribute("action");
    $action = empty($action) ? $url : rel2abs($action, $url);
    $form->setAttribute("action", PROXY_PREFIX . $action);
  }
  foreach($xpath->query('//style') as $style) {
    $style->nodeValue = proxifyCSS($style->nodeValue, $url);
  }
  foreach ($xpath->query('//*[@style]') as $element) {
    $element->setAttribute("style", proxifyCSS($element->getAttribute("style"), $url));
  }
  $proxifyAttributes = array("href", "src");
  foreach($proxifyAttributes as $attrName) {
    foreach($xpath->query('//*[@' . $attrName . ']') as $element) {
      $attrContent = $element->getAttribute($attrName);
      if ($attrName == "href" && (stripos($attrContent, "javascript:") === 0 || stripos($attrContent, "mailto:") === 0)) continue;
      $attrContent = rel2abs($attrContent, $url);
      $attrContent = PROXY_PREFIX . $attrContent;
      $element->setAttribute($attrName, $attrContent);
    }
  }
  $head = $xpath->query('//head')->item(0);
  $body = $xpath->query('//body')->item(0);
  $prependElem = $head != NULL ? $head : $body;
  if ($prependElem != NULL) {
    $scriptElem = $doc->createElement("script",
      '(function() {if (window.XMLHttpRequest) {function parseURI(url) {var m = String(url).replace(/^\s+|\s+$/g, "").match(/^([^:\/?#]+:)?(\/\/(?:[^:@]*(?::[^:@]*)?@)?(([^:\/?#]*)(?::(\d*))?))?([^?#]*)(\?[^#]*)?(#[\s\S]*)?/);// authority = "//" + user + ":" + pass "@" + hostname + ":" portreturn (m ? {href : m[0] || "",protocol : m[1] || "",authority: m[2] || "",host : m[3] || "",hostname : m[4] || "",port : m[5] || "",pathname : m[6] || "",search : m[7] || "",hash : m[8] || ""} : null);}function rel2abs(base, href) { // RFC 3986function removeDotSegments(input) {var output = [];input.replace(/^(\.\.?(\/|$))+/, "").replace(/\/(\.(\/|$))+/g, "/").replace(/\/\.\.$/, "/../").replace(/\/?[^\/]*/g, function (p) {if (p === "/..") {output.pop();} else {output.push(p);}});return output.join("").replace(/^\//, input.charAt(0) === "/" ? "/" : "");}href = parseURI(href || "");base = parseURI(base || "");return !href || !base ? null : (href.protocol || base.protocol) +(href.protocol || href.authority ? href.authority : base.authority) +removeDotSegments(href.protocol || href.authority || href.pathname.charAt(0) === "/" ? href.pathname : (href.pathname ? ((base.authority && !base.pathname ? "/" : "") + base.pathname.slice(0, base.pathname.lastIndexOf("/") + 1) + href.pathname) : base.pathname)) +(href.protocol || href.authority || href.pathname ? href.search : (href.search || base.search)) +href.hash;}var proxied = window.XMLHttpRequest.prototype.open;window.XMLHttpRequest.prototype.open = function() {if (arguments[1] !== null && arguments[1] !== undefined) {var url = arguments[1];url = rel2abs("' . $url . '", url);url = "' . PROXY_PREFIX . '" + url;arguments[1] = url;}return proxied.apply(this, [].slice.call(arguments));};}})();'
    );
    $scriptElem->setAttribute("type", "text/javascript");
    $prependElem->insertBefore($scriptElem, $prependElem->firstChild);
  }
  echo "<!-- Page served from skyleet.net proxy. -->\n" . $doc->saveHTML();
} else if (stripos($contentType, "text/css") !== false) {
  echo proxifyCSS($responseBody, $url);
} else {
  header("Content-Length: " . strlen($responseBody));
  echo $responseBody;
}
