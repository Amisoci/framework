<?php
	try {
		session_start();
		include "global.php";
		speed();
		include "settings.php";
		setup();
		if($_SERVER["HTTPS"]!="on"){
			header("Location: https://".$_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"]);
			exit;
		}
		if(!in_array($_SERVER["SERVER_NAME"],settings("OPEN_URLS"))){
			if(!isSelf()){
				header("Location: https://".settings("MAIN_SITE_URL").$_SERVER["REQUEST_URI"]);
				exit;
			}
		}
		$http_accept = explode(",",$_SERVER["HTTP_ACCEPT"])[0];
		// Do not output personal things above these lines as they could be viewable by anyone, on development and live sites
		$data=array("request"=>array("get"=>$_GET,"post"=>$_POST,"uri"=>array("url"=>$_SERVER["REQUEST_URI"],"query"=>$_SERVER["QUERY_STRING"])));
		$version_db_list=sqlGetAll("versions");
		$version_list = array();
		foreach($version_db_list as $version){
			$version_list[$version["type"]]=$version["version"];
		}
		$data["setup"]=array("handler"=>$file,"view"=>$view,"versions"=>$version_list);
		$data["user"]=getLoggedInUser();
		$content = array();
		$data["content"]=$content;
		$query_pos= strpos($_SERVER["REQUEST_URI"],"?");
		if($query_pos){
			$location = substr($_SERVER["REQUEST_URI"],0,$query_pos);
			if($location=="/"){
				$location = "/index";
			}
		} elseif($_SERVER["REQUEST_URI"]=="/"){
			$location = "/index";
		} else {
			$location = substr($_SERVER["REQUEST_URI"],0,strlen($_SERVER["REQUEST_URI"]));
		}
		$redirects = sqlGetAll("redirects");
		foreach($redirects as $redirect){
			preg_match($redirect["url"],$location,$matches);
			if(!$matches){
				continue;
			}
			unset($matches[0]);
			$id=0;
			preg_match("/\?([\s\S]+)$/",$redirect["redirect"],$query_string);
			$query_tmp = explode("&",$query_string[1]);
			$query = array();
			foreach($query_tmp as $query_string){
				$tmp_array = explode("=",$query_string);
				$query[$tmp_array[0]] = $tmp_array[1];
			}
			foreach($query as $item=>$index){
				preg_match("/{([\d]+)}/",$index,$index_id);
				$index_id = $index_id[1];
				$_GET[$item] = $matches[$index_id];
			}
			$location = substr($redirect["redirect"], 0,strpos($redirect["redirect"],"?"));
		}
		if(in_array($location,array("/login/login","/signup/signup","/verify_email_code","/assign_google_captcha_score"))){
			$http_accept="text/plain";
		}
		if($http_accept=="*/*"&&preg_match("/^\/?media/", $location)){
			$http_accept = "media";
		}
		switch($http_accept){
			case "text/html":
				$_SESSION["current_google_captcha_score"]=1;
				header("Content-type:text/html");
				$file = "handlers".$location.".php";
				$view = "view".$location.".coch";
				break;
			case "application/json":
				header("Content-type:application/json");
			case "text/plain":
				$file = "services".$location.".php";
				$data["setup"]["handler"] = $file;
				if((checkCsrfToken()||$location=="/verify_email_code")&&checkGoogleCaptchaScore()){
					include $file;
				} else {
					if(checkCsrfToken()){
						echo "<h1>Failed Google Captcha, please try again: ".$_SESSION["current_google_captcha_score"]."</h1>";
					} else {
						echo "<h1>CSRF TOKEN ERROR</h1>";
					}
				}
				exit;
			case "text/css":
				header("Content-type:text/css");
				echo display("css".$location);
				exit;
			case "image/webp":
			case "image/avif":
			case "image/apng":
			case "image/svg+xml":
			case "image/*":
				preg_match("/\/?([\s\S]+)\.([\s\S]+)/",$location,$image_details);
				$image_type = $image_details[2];
				$location = "img/".$image_details[1].".".$image_type;
				switch($image_type){
					case "svg":
						header('Content-type: image/svg+xml');
						echo display($location);
						exit;
					case "png":
						$header = "image/png";
					case "jpeg":
					case "jpg":
						$header =$header??"image/jpg";
					case "gif":
						$header = $header??"image/gif";
						header("Content-Type: image/gif");
						readfile($location);
						exit;
				}
				exit;
			case "media":
				preg_match("/^\/?media\/([^\/]+)\/([^\/]+)\.([a-zA-Z0-9]+)/",$location,$media_details);
				$location = "media/".$media_details[1]."/".$media_details[2].".".$media_details[3];
				readfile($location);
				exit;
			default:
				header("Content-type:text/javascript");
				$request = $_SERVER["REQUEST_URI"];
				echo display("js".substr($request,0,strpos($request,"?")));
				exit;
		}
		if((settings("REQUIRE_LOGIN")=="true"&&(!in_array($_SERVER["REQUEST_URI"], settings("LOGIN_NOT_REQUIRED"))&&!isset($_COOKIE["login_id"])))&&!strpos($_SERVER["REQUEST_URI"],"/ref/")===false){
			if(!isset($_SESSION["return_url"])||!$_SESSION["return_url"]){
				$_SESSION["return_url"] = $_SERVER["REQUEST_URI"];
			}
			header("Location: ".settings("LOGIN_REDIRECT"));
			exit;
		}
		if(file_exists($file)||isset($render_view)||file_exists($view)){
			if(file_exists($file)){
				include $file;
			}
			if(isset($render_view)||file_exists($view)) {
				$data["content"]=$content;
				if(getHtmlLayout()){
					$csrf_token = generateRandomString(50);
					$_SESSION["csrf_token"] = $csrf_token;
					$data["setup"]["csrf_token"] = $csrf_token;
					$html.=display("head.coch",$data);
					if($data["user"]){
						include "handlers/friend_list.php";
						$data["content"]=$content;
						$html.=display("friend_list.coch",$data);
					}
				}
				if(isset($render_view)){
					$html.=display($render_view,$data);
				} elseif(file_exists($view)){
					$html.=display($view,$data);
				}
				if(isset($_GET["test"])&&isSelf()){
					$html.="<div style='position:fixed;bottom:0;margin:10px auto;z-index:10;background-color:red;'>".$_SESSION["test_data"]??"no data"."</div>";
				}
				if(getHtmlLayout()){
					$html.=display("foot.coch");
				}
			}
		} else {
			if(file_exists("scripts".$location.".php")&&isSelf()){
				include "scripts".$location.".php";
				exit;
			} else {
				$html.=display("head.coch",$data);
				$html.="<h1>Error 404 Page Not Found</h1>";
				sqlInsert("error_log",array("error_code"=>$error_code,"message"=>$location));
				$html.=display("foot.coch");
			}
		}
		if(isset($_GET["coch"])&&$_GET["coch"]!="false"&&isSelf()){
			dump($data);
			exit;
		}
		if(isset($_REQUEST["preload"])&&$_REQUEST["preload"]){
			if(!isset($_SESSION["preload_url_list"])){
				$_SESSION["preload_url_list"] = array();
			}
			if(!isset($_SESSION["preload_url_list"][$_SERVER["REQUEST_URI"]])){
				$_SESSION["preload_url_list"][$_SERVER["REQUEST_URI"]] = $html;
			}
		} else {
			echo $html;
		}

	} catch(Throwable $e){
		if(isSelf()){
			echo "<h1>Error</h1><br/>".$e->getMessage()." at ".$e->getFile()." on Line: ".$e->getLine();
		} elseif(settings("DB_SERVER_NAME")) {
			$message = $e->getMessage()." at ".$e->getFile()." on Line: ".$e->getLine();
			$message = str_replace("'","\'",$message);
			$previous = sqlGetBy("error_log",array("message"=>str_replace("'","\'",$message)));
			if(count($previous)){
				$error_code = $previous[0]["error_code"];
			} else {
				$error_code = generateRandomString(30);
				sqlInsert("error_log",array("error_code"=>$error_code,"message"=>$message));
			}
			echo "<h1>An error has occured</h1><br/><h2>Please contact ".settings("ERROR_NAME")." using error code: ".$error_code." for assistance</h2>";
		} else {
			echo "<h1>An error has occured</h1><br/><h2>Please contact ".settings("ERROR_NAME")." describing how you got this error.";
		}
		exit;
	}
?>