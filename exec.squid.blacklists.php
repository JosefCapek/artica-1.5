<?php
$GLOBALS["FULL"]=false;
if(posix_getuid()<>0){die("Cannot be used in web server mode\n\n");}
include_once(dirname(__FILE__).'/ressources/class.templates.inc');
include_once(dirname(__FILE__).'/ressources/class.ldap.inc');
include_once(dirname(__FILE__).'/ressources/class.ini.inc');
include_once(dirname(__FILE__).'/ressources/class.mysql.inc');
include_once(dirname(__FILE__).'/ressources/class.ccurl.inc');
include_once(dirname(__FILE__).'/framework/class.unix.inc');
include_once(dirname(__FILE__).'/framework/frame.class.inc');
include_once(dirname(__FILE__).'/ressources/class.squidguard.inc');
$GLOBALS["working_directory"]="/opt/artica/proxy";
$GLOBALS["MAILLOG"]=array();
if(preg_match("#--verbose#",implode(" ",$argv))){$GLOBALS["VERBOSE"]=true;}
if(preg_match("#--force#",implode(" ",$argv))){$GLOBALS["FORCE"]=true;}

if($argv[1]=="--update"){update();die();}
if($argv[1]=="--downloads"){downloads();die();}
if($argv[1]=="--inject"){inject();die();}
if($argv[1]=="--reprocess-database"){inject_category($argv[2]);die();}
if($argv[1]=="--fullupdate"){fullupdate();die();}
if($argv[1]=="--schedule-maintenance"){schedulemaintenance();die();}



writelogs("unable to understand query !!!!!!!!!!!..." .@implode(",",$argv),"main()",__FILE__,__LINE__);

function fullupdate(){
	$GLOBALS["FULL"]=true;
	$pidfile="/etc/artica-postfix/pids/".basename(__FILE__).".".__FUNCTION__.".pid";
	$unix=new unix();
	$pid=@file_get_contents($pidfile);
	if($unix->process_exists($pid,__FILE__)){
		writelogs("Warning: Already running pid $pid",__FUNCTION__,__FILE__,__LINE__);
		return;
	}
	
	@file_put_contents($pidfile, getmypid());	
	
	update();downloads();inject();
	
}


function schedulemaintenance(){
	$pidfile="/etc/artica-postfix/pids/".basename(__FILE__).".".__FUNCTION__.".pid";
	$unix=new unix();
	$pid=@file_get_contents($pidfile);
	if($unix->process_exists($pid,__FILE__)){
		writelogs("Warning: Already running pid $pid",__FUNCTION__,__FILE__,__LINE__);
		return;
	}	
	$t1=time();
	$sql="SELECT avg( progress ) AS pourcent, categories FROM updates_categories WHERE filesize >0 GROUP BY categories HAVING pourcent<100  ORDER BY pourcent";
	$q=new mysql();
	$results=$q->QUERY_SQL($sql,"artica_backup");
	if(!$q->ok){$unix->send_email_events("Proxy:[BlacklistsDB] Fatal: mysql database error while initialize maintenance engine", $q->mysql_error."\n$sql", "proxy");}
	$num=mysql_num_rows($results);
	if($num==0){return;}
	
	while($ligne=@mysql_fetch_array($results,MYSQL_ASSOC)){$cat[]=$ligne["categories"];}
	if(count($cat)==0){return;}
	$unix->send_email_events("Proxy:[BlacklistsDB] Fatal: Schedule maintenance started on ".count($cat)." categories", @implode("\n", $cat), "proxy");
	while (list ($num, $category) = each ($cat) ){
		$tCTR=time();	
		inject_category($category);
		$log[]="Injected {$ligne["category"]} was {$ligne["pourcent"]}% server load:".getSystemLoad()." took : ".$unix->distanceOfTimeInWords($tCTR,time());
		$log[]=@implode("\n", $GLOBALS["MAILLOG"]);
	}

	
	$unix->send_email_events("Proxy:[BlacklistsDB] Maintenance done on $num categories ".$unix->distanceOfTimeInWords($t1,time()), @implode("\n", $log), "proxy");
	 
}



function update(){
	$myDate=GetLastUpdateDate();
	echo "BLACKLISTS: Last update on $myDate\n";
	$unix=new unix();
	$curl=new ccurl("http://www.artica.fr/blacklist/update.ini");
	if(!$curl->GetFile("/tmp/update.ini")){
		echo "BLACKLISTS: Failed to retreive http://www.artica.fr/blacklist/update.ini ($curl->error)\n";
		$unix->send_email_events("Proxy:[BlacklistsDB] unable to download blacklist index file", $curl->error, "proxy");
		return;
	}
	
	$ini=new Bs_IniHandler("/tmp/update.ini");
	$date=$ini->_params["settings"]["date"];
	echo "BLACKLISTS: Pattern update $date\n";
	if(!$GLOBALS["FORCE"]){
		if($date==$myDate){
			echo "BLACKLISTS: No new updates\n";
			return;
		}
	}
	
	while (list ($category, $array) = each ($ini->_params) ){
		echo "Saving $category\n";
		while (list ($filename, $size) = each ($array) ){	
			if(!is_numeric($size)){$size=0;}
			echo "Saving $filename for $category\n";
			if(!INITCategory($category,$date,$filename,$size)){
				echo "Fatal error $category $date $filename $size\n";
				return;
			}
		}
	}
	
	
}

function GetLastUpdateDate(){
	$q=new mysql();
	$sql="SELECT zDate FROM updates_categories WHERE categories='settings'";
	$ligne=mysql_fetch_array($q->QUERY_SQL($sql,"artica_backup"));
	return $ligne["zDate"];
}

function INITCategory($category,$zDate,$filename,$filesize){
	$unix=new unix();
	$q=new mysql();
	$md5=md5($category.$filename);
	$sql="SELECT zDate FROM updates_categories WHERE categories='$category' and filename='$filename'";
	$ligne=mysql_fetch_array($q->QUERY_SQL($sql,"artica_backup"));
	if($ligne["zDate"]==null){
		$sql="INSERT INTO updates_categories (zmd5,filename,categories,filesize,zDate,subject) VALUES('$md5','$filename','$category','$filesize','$zDate','{scheduled}')";
	}else{
		$sql="UPDATE `updates_categories` SET zmd5='$md5',filesize='$filesize',zDate='$zDate',finish=0,progress=0,subject='{scheduled}' WHERE filename='$filename'";
	}
	
	$q=new mysql();
	if($GLOBALS["VERBOSE"]){echo $sql."\n";}
	$q->QUERY_SQL($sql,"artica_backup");
	if(!$q->ok){
		$unix->send_email_events("Proxy:[BlacklistsDB] Fatal: mysql database error while initialize update engine", $q->mysql_error, "proxy");
		return false;
	}
	return true;
}

function UpdateCategories($filename,$progress,$subject,$finish=0){
	$sql="UPDATE `updates_categories` SET finish=$finish,progress=$progress,subject='$subject' WHERE filename='$filename'";
	$q=new mysql();
	$q->QUERY_SQL($sql,"artica_backup");
}


function downloads(){
	$working_dir=$GLOBALS["working_directory"];
	$unix=new unix();
	$sql="SELECT * FROM updates_categories WHERE filesize>0";
	$q=new mysql();
	$results=$q->QUERY_SQL($sql,"artica_backup");
	if(!$q->ok){
		echo "Fatal error $sql\n";
		$unix->send_email_events("Proxy:[BlacklistsDB] Fatal: mysql database error while retreive update list", $q->mysql_error, "proxy");
		return;
	}
	$num=mysql_num_rows($results);
	echo "$num files to check\n";

	
	while($ligne=@mysql_fetch_array($results,MYSQL_ASSOC)){
		$filename=$ligne["filename"];
		$targetfile=$working_dir."/".$filename;
		
		if(!is_dir(dirname($targetfile))){
			echo "Creating directory ".dirname($targetfile)."\n";
			@mkdir(dirname($targetfile),0755,true);
		}		
		
		if(CheckTargetFile($targetfile,$ligne["filesize"])){
			echo "$filename skipped...\n";
			continue;
		}
		
		UpdateCategories($filename,10,"{downloading}",0);
		
		$curl=new ccurl("http://www.artica.fr/$filename");
		echo "Downloading http://www.artica.fr/$filename\n";
		if(!$curl->GetFile($targetfile)){
			echo "Fatal error downloading http://www.artica.fr/$filename\n";
			$unix->send_email_events("Proxy:[BlacklistsDB] Fatal: unable to download $filename", "", "proxy");
			UpdateCategories($filename,0,"{error}",0);
			continue;
		}
		
		if(CheckTargetFile($targetfile,$ligne["filesize"])){
			UpdateCategories($filename,20,"{downloaded}",0);
		}
		
		echo "$filename success...\n";
		
		
	}
	
	
}

function CheckTargetFile($filename,$requiredsize){
	if(!is_file($filename)){return false;}
	$size=filesize($filename);
	if($size<>$requiredsize){return false;}
	return true;
}

function inject_category($categories){
	
	
	$pidfile="/etc/artica-postfix/pids/".basename(__FILE__).".".__FUNCTION__.".".md5($categories).".pid";
	$unix=new unix();
	$pid=@file_get_contents($pidfile);
	if($unix->process_exists($pid,__FILE__)){
		writelogs("Warning: Already running pid $pid for $categories",__FUNCTION__,__FILE__,__LINE__);
		return;
	}
	
	$unix->send_email_events("Proxy:[BlacklistsDB] processing injecting category $categories", "Pid number $pid", "proxy");
	@file_put_contents($pidfile, getmypid());
	$t1=time();
	
	$working_dir=$GLOBALS["working_directory"];
	$sql="SELECT * FROM updates_categories WHERE categories='$categories' AND progress<100 AND filesize>0 ORDER BY progress";
	$q=new mysql();
	$results=$q->QUERY_SQL($sql,"artica_backup");
	if(!$q->ok){
		echo "Fatal error $sql\n";
		$unix->send_email_events("Proxy:[BlacklistsDB] Fatal: inject() mysql database error while retreive update list", $q->mysql_error, "proxy");
		return;
	}
	$num=mysql_num_rows($results);
	$GLOBALS["MAILLOG"][]=__LINE__.")  Pid number ".getmypid();
	
	writelogs("$sql",__FUNCTION__,__FILE__,__LINE__);
	writelogs("$num files to check for $categories",__FUNCTION__,__FILE__,__LINE__);
	$GLOBALS["MAILLOG"][]=__LINE__.")  $num files to check for $categories";
	$c=0;
	while($ligne=@mysql_fetch_array($results,MYSQL_ASSOC)){	
		$filename=$ligne["filename"];
		$targetfile=$working_dir."/".$filename;
		$targetfileUncompress=$working_dir."/".$filename.".ext";
		UpdateCategories($filename,30,"{uncompress}",0);
		if(!extractGZ($targetfile,$targetfileUncompress)){
			$unix->send_email_events("Proxy:[BlacklistsDB] Fatal: unable to extract $targetfile", "", "proxy");
			UpdateCategories($filename,30,"{failed_uncompress}",0);
			continue;
		}
		$c++;
		$GLOBALS["MAILLOG"][]=__LINE__.")  $filename done (system load ".getSystemLoad().")";
		inject_sql($filename,$targetfileUncompress);

	}
	$distanceOfTimeInWords=$unix->distanceOfTimeInWords($t1,time());
	$GLOBALS["MAILLOG"][]=__LINE__.")  Files processed: $c\nduration:$distanceOfTimeInWords\n";
	
	$unix->send_email_events("Proxy:[BlacklistsDB] processing injecting category $categories done",
	@implode("\n", $GLOBALS["MAILLOG"]) , "proxy");
	$GLOBALS["MAILLOG"]=array();
	CategoriesCountCache();
	
}

function inject(){
	
	
	$pidfile="/etc/artica-postfix/pids/".basename(__FILE__).".".__FUNCTION__.".pid";
	$unix=new unix();
	if(!$GLOBALS["FULL"]){
		$pid=@file_get_contents($pidfile);
		if($unix->process_exists($pid,__FILE__)){
			writelogs("Warning: Already running pid $pid",__FUNCTION__,__FILE__,__LINE__);
			return;
		}
	}
	
	@file_put_contents($pidfile, getmypid());
		
	
	$working_dir=$GLOBALS["working_directory"];
	$unix=new unix();
	$sql="SELECT * FROM updates_categories WHERE finish=0 and progress>0 AND filesize>0 ORDER BY categories";
	$q=new mysql();
	$results=$q->QUERY_SQL($sql,"artica_backup");
	if(!$q->ok){
		echo "Fatal error $sql\n";
		$unix->send_email_events("Proxy: Fatal:[BlacklistsDB] inject() mysql database error while retreive update list", $q->mysql_error, "proxy");
		return;
	}
	$num=mysql_num_rows($results);
	echo "$num files to check\n";

	
	while($ligne=@mysql_fetch_array($results,MYSQL_ASSOC)){	
		$filename=$ligne["filename"];
		$targetfile=$working_dir."/".$filename;
		$targetfileUncompress=$working_dir."/".$filename.".ext";
		UpdateCategories($filename,30,"{uncompress}",0);
		if(!extractGZ($targetfile,$targetfileUncompress)){
			$unix->send_email_events("Proxy:[BlacklistsDB] Fatal: unable to extract $targetfile", "", "proxy");
			UpdateCategories($filename,30,"{failed_uncompress}",0);
			continue;
		}
		
		inject_sql($filename,$targetfileUncompress);
		//return;
	}
	
	CategoriesCountCache();
}

function extractGZ($srcName, $dstName){
    $sfp = gzopen($srcName, "rb");
    $fp = fopen($dstName, "w");

    while ($string = gzread($sfp, 4096)) {
        fwrite($fp, $string, strlen($string));
    }
    gzclose($sfp);
    fclose($fp);
    $size=@filesize($dstName);
    if($size>0){return true;}
    return false;
}

function getSystemLoad(){
	$array_load=sys_getloadavg();
	return $array_load[0];
	
}

function inject_sql($srcfilename,$filename){
	
	$datas=explode("\n",@file_get_contents($filename));
	echo "Processing $filename ". count($datas)." rows\n";
	if(!is_array($datas)){
		$GLOBALS["MAILLOG"][]=__LINE__.")  $filename no elements";
		UpdateCategories($srcfilename,30,"{corrupted}",0);
	}
	
	$c=0;
	$d=0;
	$t1=time();
	$unix=new unix();
	$count=count($datas);
	$q=new mysql();
	$prefix="INSERT IGNORE INTO dansguardian_community_categories (zmd5,zDate,category,pattern,uuid,sended) VALUES";
	$suffixR=array();
	while (list ($index, $row) = each ($datas) ){
		if(trim($row)==null){continue;}
		$ligne=unserialize($row);
		if(strlen($ligne["category"])==1){continue;}
		$suffixR[]="('{$ligne["zmd5"]}','{$ligne["zDate"]}','{$ligne["category"]}','{$ligne["pattern"]}','{$ligne["uuid"]}',1)";
		$c++;

		if(count($suffixR)>50){
			$pourc=round(($c/$count)*100);
			if($pourc>30){
				UpdateCategories($srcfilename,$pourc,"{importing}",0);
				if(system_is_overloaded(basename(__FILE__))){
					echo "Overloaded, waiting 30s\n";
					$ldao=getSystemLoad();
					$GLOBALS["MAILLOG"][]=__LINE__.")  Overloaded ($ldao),waiting 30s...";
					sleep(30);
				}
				
				if(system_is_overloaded(basename(__FILE__))){
					UpdateCategories($srcfilename,$pourc,"{overloaded}",0);
					echo "Overloaded, die...\n";
					$ldao=getSystemLoad();
					$GLOBALS["MAILLOG"][]=__LINE__.")  Overloaded,$ldao die...";
					$unix->send_email_events("Proxy:[BlacklistsDB] processing black list $srcfilename database injection aborted", "
					System is overloaded ($ldao), the processing will be aborted and restart in next cycle
					Task stopped line $c/$count rows\n
					", "proxy");
					die();
				}
				
			}
			$suffix=@implode(",", $suffixR);
			$sql="$prefix $suffix";
			$suffixR=array();
			$q=new mysql();
			$q->QUERY_SQL($sql,"artica_backup");
			if(!$q->ok){
				UpdateCategories($srcfilename,30,"{sql_error}",0);
				return;
			}
			
			usleep(500000);
		}
	}

	if(count($suffixR)>0){
		$suffix=@implode(",", $suffixR);
		$sql="$prefix $suffix";
		$q=new mysql();
		$q->QUERY_SQL($sql,"artica_backup");		
	}
	
	$GLOBALS["MAILLOG"][]=__LINE__.") Success importing $c elements in" .$unix->distanceOfTimeInWords($t1,time());
	UpdateCategories($srcfilename,100,"{success}",1);
	
}

function CategoriesCountCache(){
	$q=new mysql();
	$sql="SELECT count(zmd5) as tcount,category FROM dansguardian_community_categories group by category";
	$results=$q->QUERY_SQL($sql,"artica_backup");
	while($ligne=@mysql_fetch_array($results,MYSQL_ASSOC)){	
		$array[$ligne["category"]]=$tcount;
	}
	
	@file_put_contents("/usr/share/artica-postfix/ressources/squid.categories.count.cache", serialize($array));
	shell_exec("/bin/chmod 777 /usr/share/artica-postfix/ressources/squid.categories.count.cache");
}




