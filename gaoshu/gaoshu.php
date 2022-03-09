<?php
/*
	功能：模拟登陆“高术采编系统”并抓取稿件
	用法：更改$username和$passowrd为自己的帐户，将IP地址222.222.222.222改为高术系统的地址
*/
header('X-Accel-Buffering: no');
session_start();
require './vendor/autoload.php'; //加载php采集框架QueryList
use QL\QueryList;
$mysql = new DbControl();
$mysql->DB_conn();
ini_set("max_execution_time", 0);
$username = '用户名'; #高术系统帐户名
$password = '密码'; #高术系统帐户密码

$head = array(
	'Host: 222.222.222.222:81',
	'User-Agent: Mozilla/5.0 (Windows NT 5.1; rv:52.0) Gecko/20100101 Firefox/52.0',
	'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
	'Accept-Language: zh-CN,en-US;q=0.7,en;q=0.3',
	'Accept-Encoding: gzip, deflate',
	'Connection: keep-alive',
	'Upgrade-Insecure-Requests: 1',
);

$head1 = array(
	'Host: 222.222.222.222:8443',
	'User-Agent: Mozilla/5.0 (Windows NT 5.1; rv:52.0) Gecko/20100101 Firefox/52.0',
	'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
	'Accept-Language: zh-CN,en-US;q=0.7,en;q=0.3',
	'Accept-Encoding: gzip, deflate, br',
	'Connection: keep-alive',
	'Upgrade-Insecure-Requests: 1',
);

//if(!isset($_SESSION['gs_cookie'])){

	$cookie_jar = tempnam('./temp','cookie');//存放COOKIE的文件

	//第一步得到cookie和验证URL
	$url = "http://222.222.222.222:81/nrsc/desktop/index.jsp";
	$content = use_curl($url,'',1,$head,0,'','','','');
	//取重定向URL和cookie
	preg_match("/Location: ([^\r\n]*)/i", $content, $matches);
	$url = $matches[1];
	preg_match("/Set\-Cookie: ([^\r\n]*)/i", $content, $matches);
	$cookies = $matches[1];
	$_SESSION['gs_cookie'] = $cookies;

	//第二步　重定向URL得到cookie1
	$content = use_curl($url,'',1,$head1,0,'','','','');
	//获取上一次的cookie1和隐藏的lt值
	preg_match("/Set\-Cookie: ([^\r\n]*)/i", $content, $matches);
	$cookies1 = $matches[1];
	preg_match("/JSESSIONID=([^\r\n]*); Path/i", $cookies1, $matches);
	$cook='jsessionid='.$matches[1];
	$data = QueryList::Query($content,array('lt'=>array('input[name=lt]','value')))->data;
	$lt = $data[0]['lt'];

	//第三步：post表单，获取ticket
	$post_data = array('username'=>$username,'password'=>$password,'lt'=>$lt,'_eventId'=>'submit','x'=>'39','y'=>'19');
	$urlpost=str_replace('https://222.222.222.222:8443/cas/login','https://222.222.222.222:8443/cas/login;'.$cook,$url);

	// post登录并获取ticket
	$output = use_curl($urlpost,$url,1,$head1,1,$post_data,$cookies1,'',$cookie_jar);

	//第四步：带上第一次得到的cookie验证ticket
	preg_match('/Location: (.*?)-cas/i', $output, $matches);
	$url = trim($matches[1]).'-cas';

	$data = use_curl($url,'',1,$head,0,'',$cookies,'','');

	//第五步第一次目标页前去CAS服务器验证
	$url = 'http://222.222.222.222:81/nrsc';

	$data = use_curl($url,'',1,$head,0,'',$cookies,'','');

	//第六步获取返回页面
	$url = 'https://222.222.222.222:8443/cas/login?service=http%3A%2F%2F222.222.222.222%3A81%2Fnrsc%2Fj_acegi_cas_security_check';
	$data = use_curl($url,'',1,$head,0,'','',$cookie_jar,'');

	preg_match('/Location:(.*?)-cas/i', $data, $matches);
	$url = trim($matches[1]).'-cas';

	//第七步带上第一次的COOKIE访问目标页
	$data = use_curl($url,'',1,$head,0,'',$cookies,'','');

	//第八步进入后台页面显示30天稿件
	//$url='222.222.222.222:81/nrsc/desktop/index.jsp';
	//$data = use_curl($url,'',0,$head,0,'',$cookies,'','');
	@unlink($cookie_jar);
	//echo 'CAS登陆成功！';
//}

	//取稿件列表
	$url='http://222.222.222.222:81/nrsc/nrjggjbj.do?method=list&gaojiaId=231377';
	$content = use_curl($url,'',0,$head,0,'',$_SESSION['gs_cookie'],'','');
	preg_match_all('/storyIDs\["(.*?)"\]/i',$content, $newsidlist);
	//print_r($newsidlist[1]);
	preg_match_all('/versionListJson \= \[(.*?)\];/i',$content, $newsinfo);
	preg_match_all('/\{(.*?)\}/i',$newsinfo[1][0], $matches);
	//print_r($matches[1]);
	for($i=0;$i<count($matches[1]);$i++ ){
		$ls1 = explode(',',$matches[1][$i]);
		
		for($j=0;$j<count($ls1);$j++ ){
			$ls1[$j] = str_replace('"','',$ls1[$j]);
			//$news = explode(':',$ls1[$j]);
			//$newslist[$i][$news[0]]=$news[1];
			$news = substr($ls1[$j],0,strpos($ls1[$j],':'));
			$newslist[$i][$news] = substr($ls1[$j],strpos($ls1[$j],':')+1);
		}
	}
	//print_r($newslist);

	//清空原有数据库中记录
	$sql = "TRUNCATE TABLE `news`";
	$mysql->sql_query($sql) or die("清空资源失败！<br>".mysql_error());

	//循环取稿件//////////////////////////////////////////////////////////////////
	for($i=0;$i<count($newslist);$i++ ){
		set_time_limit(0);	
		if($i<100){
				
			$title = $newslist[$i]['title'];
			/*
			if(stripos($title,'\r\n')){
				$title = substr($title,0,stripos($title,'\r\n'));
			}
			*/
			$addtime = $newslist[$i]['strcreateTime'];
			$uptime = $newslist[$i]['strmodifyTime'];
			$author = $newslist[$i]['authorName'];
			$from = $newslist[$i]['sourceFolderName'];
		
			//打开编辑页面取出branchId
			$url = "http://222.222.222.222:81/nrsc/storyBJForward.do?method=openWindow&gaojiaId=231377&storyId={$newsidlist[1][$i]}";
			$content = use_curl($url,'',0,$head,0,'',$_SESSION['gs_cookie'],'','');
			preg_match("/brnach0.id =(.*?);/i", $content, $matches);
			$branchId = trim($matches[1]);
			//带上branchId和mainStory用post方式获取图片地址
			$post_data = array('branchId'=>$branchId,'mainStory'=>$newsidlist[1][$i],'storyType'=>'ALL');
			$urlpost = 'http://222.222.222.222:81/nrsc/storyBJForward.do?method=showStoryList';
			$imglist = use_curl($urlpost,$url,0,$head,1,$post_data,$_SESSION['gs_cookie'],'','');
			//正则获取图片地址
			preg_match_all('/apachPath":"http:\/\/(.*?)"/i',$imglist, $imglist);
			//print_r($imglist[1]);
			
			//将图片地址串起来
			$imgstr = '';
			if(count($imglist[1])>0){
				foreach ($imglist[1] as $var){
					$imgstr .= '<img src="http://'.$var.'" /><br>';
				}
				$imgstr ='<p align="center">'.$imgstr.'</p>';
			}
		
			//获取稿件内容
			$url = "http://222.222.222.222:81/nrsc/storyBJForward.do?method=forwardTextEditor&outerSave=&isLocked=false&storyId={$newsidlist[1][$i]}&gaojiaId=231377";
			$content = use_curl($url,'',0,$head,0,'',$_SESSION['gs_cookie'],'','');
			$content = str_replace(chr(00),"",$content);//过滤特殊空格符
			$content = QueryList::Query($content,array('content'=>array('#div_formatText','html')))->data;
			$content = $content[0]['content'];

			//图片与稿件内容合并
			$content = $imgstr.$content;

			showinfo("-----------------------{$i}-------------------------<br>");

			//稿件入库
			$sql = "INSERT INTO `news` (`title`,`author`,`from`, `content`,`uptime`,`addtime`) VALUES ('$title', '$author', '$from', '$content', '$uptime', '$addtime')";
			$mysql->sql_query($sql) or die("添加资源失败！<br>".mysql_error());

			showinfo($newsidlist[1][$i].'.'.$title);
		}
	}


////////////////////////////////////////////////////////////////////////////////////
//$url：　　　　　　　请求的URL
//$referer：　　　　　referer地址
//$head　　　　　　　 是否返回head信息
//$httpheader　　　　 模拟请求的头部信息
//$post               1为post方式，0为get方式
//$postdata　　　　　 POST的数据
//$usecookie　　　　　使用变量cookie
//$usecookiefile　　　使用cookie文件
//$savecookiefile　　 cookie另存为文件
//////////////////////////////////////////////
function use_curl($url,$referer='',$head=1,$httpheader,$post=0,$postdata,$usecookie='',$usecookiefile='',$savecookiefile=''){
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	if(!empty($referer)){
		curl_setopt($ch, CURLOPT_REFERER, $url);
	}
	if(!empty($head)){
		curl_setopt($ch, CURLOPT_HEADER, $head);
	}
	if(!empty($httpheader)){
		curl_setopt($ch, CURLOPT_HTTPHEADER,$httpheader);//模似请求头
	}
	if($post!=0){
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));
	}
	if(!empty($usecookie)){
		curl_setopt($ch, CURLOPT_COOKIE, $usecookie);//使用cookie变量
	}
	if(!empty($usecookiefile)){
		curl_setopt($ch, CURLOPT_COOKIEFILE, $usecookiefile);//使用cookie文件
	}
	if(!empty($savecookiefile)){
		curl_setopt($ch, CURLOPT_COOKIEJAR, $savecookiefile);//存cookie到文件
	}
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//设为1则不直接显示
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	$output = curl_exec($ch); 
	curl_close($ch);
	return $output;
}
//数据库连接
////////////////////////////////////////////////////////////////////////////////////////
class DbControl
{
	// 建立数据库链接
	public function Db_conn()
	{
		global $cfg;
		$conn = @mysql_connect('localhost', 'root', '');
		if ($conn == false)
		{
			echo '数据库服务连接失败!';
			exit();
		}
		else
		{
			$dbsele = @mysql_select_db('gaoshu');
			if ($dbsele == false)
			{
				echo '数据库连接失败!';
				exit();
			}
		}
	}
	// 执行sql语句
	public function sql_query($sql)
	{
		mysql_query("set names 'utf8'");
		return mysql_query($sql);
	}

	public function sql_fetchrow($result)
	{
		return mysql_fetch_array($result);
	}

	public function sql_rows($result)
	{
		return mysql_num_rows($result);
	}
}
//消息实时显示
function showinfo($str){
	echo $str.'<br>';
	ob_flush();
	flush();
}
?>