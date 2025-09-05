<?php
// createSocketServer
// createSocketClient
// chkSocks

// chkGPSDpresent
// connectToGPSD($gpsdSock)
// GPSDlikeInstrumentsDataDecode($buf)

// realChkSignalKpresent
// chkSignalKpresent
// findSignalKinLAN

// realChkVenusOSpresent
// chkVenusOSpresent

// findSource

// updAndPrepare
// updInstrumentsData
// chkFreshOfData
// dataSourceSave() 
// makePOLL
// makeWATCH('TPV')
// makeALARM()
// makeWPT()

// navigationStatusEncode()


// wsDecode
// wsEncode

function createSocketServer($host,$port,$connections=1024){
/* создаёт сокет, соединенный с $host,$port на своей машине, для приёма входящих соединений 
в Ubuntu $connections = 0 означает максимально возможное количество соединений, а в Raspbian (Debian?) действительно 0
*/
if(substr_count($host,':')>1) {
	$sock = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
	$host = trim($host,'[]');
}
else $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$sock) {
	echo "Failed to create server socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
};
$res = socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);	// чтобы можно было освободить ранее занятый адрес, не дожидаясь, пока его освободит система
for($i=0;$i<100;$i++) {	// PHP Warning:  socket_bind(): Unable to bind address [98]: Address already in use
	$res = @socket_bind($sock, $host, $port);
	if(!$res) {
		echo "Failed to binding to $host:$port by: " . socket_strerror(socket_last_error($sock)) . ", waiting $i\r";
		sleep(1);
	}
	else break;
};
if(!$res) {
	echo "Failed to binding to $host:$port by: " . socket_strerror(socket_last_error($sock)) . "\n";
	return FALSE;
};
$res = socket_listen($sock,$connections); 	// 
if(!$res) {
	echo "Failed listennig by: " . socket_strerror(socket_last_error($sock)) . "\n";
	return FALSE;
};
//socket_set_nonblock($sock); 	// подразумевается, что изменений в сокете всегда ждём в socket_select
return $sock;
} // end function createSocketServer


function createSocketClient($host,$port){
/* создаёт сокет, соединенный с $host,$port на другом компьютере */
if(substr_count($host,':')>1) {
	$sock = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
	$host = trim($host,'[]');
}
else $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$sock) {
	echo "[createSocketClient] Failed to create client socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
	//exit('1');
}
if(! @socket_connect($sock,$host,$port)){ 	// подключаемся к серверу
	echo "[createSocketClient] Failed to connect to remote server $host:$port by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
	//exit('1');
}
echo "[createSocketClient] Connected to socket on $host:$port as client        \n";
//echo gettype($sock);
//$res = socket_write($socket, "\n");
return $sock;
} // end function createSocketClient

function chkSocks($socket) {
/**/
global $dataSourceConnectionObject, $masterSocks, $sockets, $socksRead, $socksWrite, $socksError, $messages, $devicePresent,$dataSourceHost,$dataSourcePort,$dataSourceHumanName,$gpsdProxyHosts;
if(($dataSourceConnectionObject !== NULL) and ($socket === $dataSourceConnectionObject)){ 	// умерло ранее бывшее соединение с  источником данных
	echo "[chkSocks] $dataSourceHumanName socket closed. Try to recreate.                      \n";
	//@socket_close($dataSourceConnectionObject); 	// он может быть уже закрыт
	dataSourceClose($dataSourceConnectionObject);	// правильно использовать специальную процедуру из конфигурации источника
	$dataSourceConnectionObject = createSocketClient($dataSourceHost,$dataSourcePort); 	// Соединение с источником данных
	if(!$dataSourceConnectionObject) {
		echo "[chkSocks] False open connection to $dataSourceHumanName\n";
		return;
	}
	echo "[chkSocks] Connection to $dataSourceHumanName reopen, do handshaking              \n";
	$newDevices = dataSourceConnect($dataSourceConnectionObject);
	if($newDevices===FALSE) {
		//exit("Handshaking fail: $dataSourceHumanName not run, bye     \n");
		echo "\n[chkSocks] Handshaking fail: $dataSourceHumanName nas no required devices or not run     \n";
		return;
	}
	
	echo "[chkSocks] Handshaked, will recieve data from $dataSourceHumanName\n";
	if(!$devicePresent) echo"but no required devices present     \n";
	$devicePresent = array_unique(array_merge($devicePresent,$newDevices));	// плоские массивы
	echo "[chkSocks] New handshaking, will recieve data from $dataSourceHumanName\n";
}
elseif(in_array($socket,$masterSocks,true)){ 	// умерло входное подключение
	echo "\n[chkSocks] Incoming socket die. Try to recreate.\n";
	@socket_close($masterSock); 	// он может быть уже закрыт
	foreach($gpsdProxyHosts as $i => $gpsdProxyHost){
		if($sock=createSocketServer($gpsdProxyHost[0],$gpsdProxyHost[1],20)) $masterSocks[] = $sock;
		else unset($gpsdProxyHosts[$i]);
	};
	if(!$masterSocks) exit("Unable to open inbound connections, died.\n");
}
else {	// один из входящих сокетов, или оно вообще не сокет
	$n = array_search($socket,$sockets);	// 
	echo "Close client socket #$n type ".gettype($socket)." by error or by life                    \n";
	if($n !== FALSE){
		unset($sockets[$n]);
		unset($messages[$n]);
	}
	$n = array_search($socket,$socksRead);	// 
	if($n !== FALSE) unset($socksRead[$n]);
	$n = array_search($socket,$socksWrite);	// 
	if($n !== FALSE) unset($socksWrite[$n]);
	$n = array_search($socket,$socksError);	// 
	if($n !== FALSE) unset($socksError[$n]);
	if($socket) socket_close($socket); 	// он может быть уже закрыт
}
//echo "\nchkSocks sockets: "; print_r($sockets);
} // end function chkSocks

function chkGPSDpresent($host,$port){
/* Определение gpsd и gpsdPROXY */
$return = FALSE;
$socket = createSocketClient($host,$port);
if($socket){
	socket_set_option($socket,SOL_SOCKET, SO_RCVTIMEO, array("sec"=>3, "usec"=>0));	// таймаут в sec
	$res = @socket_write($socket, "\r\n", 2);	// gpsgPROXY не вернёт greeting, если не получит что-то. Ну, так получилось
	if($res !== FALSE) { 	
		$buf = @socket_read($socket, 2048); 	// читаем, но если PHP_NORMAL_READ, то таймаут игнорируется, и оно будет висеть вечно
		if($buf !== FALSE){
			//echo "[chkGPSDpresent] buf=|$buf|\n";
			$buf = json_decode($buf, true);
			if(substr($buf["class"],0,7)=='VERSION') $return = TRUE;
		}
	}
}
if($socket) socket_close($socket);	// Мудацкий PHP8 падает с Fatal error, если $socket - не сокет. Казлы.
return $return;
} // end function chkGPSDpresent

function connectToGPSD($gpsdSock){
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$controlClasses = array('VERSION','DEVICES','DEVICE','WATCH');
$WATCHsend = FALSE;
$dataType = $SEEN_GPS | $SEEN_AIS; 	// данные от каких приборов будем принимать от gpsd
//echo "dataType=$dataType;\n";
//echo "\nBegin handshaking with gpsd to socket $gpsdSock\n";
// Похоже, выставить timeout для такого сокета невозможно?
// A Socket instance created with socket_create() or socket_accept(). 
// У нас же сокет создан socket_accept, должно работать? Но не работает.
//socket_set_nonblock($gpsdSock);
//echo "До SO_RCVTIMEO="; print_r(socket_get_option($gpsdSock,SOL_SOCKET,SO_RCVTIMEO)); echo ";      \n";
//socket_set_option($gpsdSock,SOL_SOCKET,SO_RCVTIMEO,array("sec"=>3,"usec"=>0));	// таймаут чтения для сокета
//socket_set_option($gpsdSock,SOL_SOCKET,SO_SNDTIMEO,array("sec"=>3,"usec"=>0));	// таймаут отправки для сокета
//echo "После SO_RCVTIMEO="; print_r(socket_get_option($gpsdSock,SOL_SOCKET,SO_RCVTIMEO)); echo ";      \n";
do { 	// при каскадном соединении нескольких gpsd заголовков может быть много
	$zeroCount = 0;	// счётчик пустых строк
	do {	// крутиться до принятия строки или до 10 пустых строк
		//echo "Ждём:          \n";
		$buf = socket_read($gpsdSock, 2048, PHP_NORMAL_READ); 	// читаем. Здесь 2048 байт достаточно: принимаются только короткие сообщения.
		//echo "\nfCommon.php [connectToGPSD] buf:$buf| \n$zeroCount\n";
		if($buf === FALSE) { 	// gpsd умер
			echo "\nFailed to read data from gpsd: " . socket_strerror(socket_last_error()) . "\n";
			chkSocks($gpsdSock);
			return FALSE;
		}
		$buf = trim($buf);
		if(!$buf) $zeroCount++;
	}while(!$buf and $zeroCount<10);
	if(!$buf) break;	// не склалось, облом
	$buf = json_decode($buf,TRUE);
	//echo "buf: "; print_r($buf);
	switch($buf['class']){
	case 'VERSION': 	// можно получить от slave gpsd после WATCH. А можно? Повторно передаются DEVICES и WATCH
		//echo "\nReceived VERSION\n";
		if(!$WATCHsend) { 	// команды WATCH ещё не посылали
			$params = array(
				"enable"=>TRUE,
				"json"=>TRUE,
				"scaled"=>TRUE, 	// преобразование единиц в gpsd. Возможно, это поможет с углом поворота, который я не декодирую
				"split24"=>TRUE 	// объединять части длинных сообщений
			);
			$msg = '?WATCH='.json_encode($params)."\n"; 	// велим gpsd включить устройства и посылать информацию
			$res = socket_write($gpsdSock, $msg, strlen($msg));
			if($res === FALSE) { 	// gpsd умер
				chkSocks($gpsdSock);
				echo "\n[connectToGPSD] Failed to send WATCH to gpsd: " . socket_strerror(socket_last_error()) . "\n";
				return FALSE;
			}
			$WATCHsend = TRUE;
			//echo "Sending TURN ON\n";
		}
		break;
	case 'DEVICES': 	// соберём подключенные устройства со всех gpsd, включая slave
		//echo "Received DEVICES\n"; //
		$devicePresent = array();
		foreach($buf["devices"] as $device) {
			//echo "\nChecked device with dataType $dataType:".($device['flags']&$dataType)."\n";
			if($device['flags']&$dataType) $devicePresent[] = $device['path']; 	// список требуемых среди обнаруженных и понятых устройств.
		}
		break;
	case 'DEVICE': 	// здесь информация о подключенных slave gpsd, т.е., общая часть path в имени устройства. Полезно для опроса конкретного устройства, но нам не надо. 
		//echo "Received about slave DEVICE<br>\n"; //
		break;
	case 'WATCH': 	// 
		//echo "Received WATCH\n\n"; //
		break 2; 	// приветствие завершилось
	}
	
}while($WATCHsend or @in_array($buf['class'],$controlClasses));
//echo "buf: "; print_r($buf);
if(!$devicePresent) {
	echo "\n[connectToGPSD] no required devices present\n";
	return FALSE;
}
$devicePresent = array_unique($devicePresent);
return $devicePresent;
} // end function connectToGPSD

function GPSDlikeInstrumentsDataDecode($buf){
/* Делает из полученного из сокета $buf данные в формате $instrumentsData, т.е. приводит их к формату 
массива ответов gpsd в режиме ?WATCH={"enable":true,"json":true};, когда оно передаёт поток отдельных сообщений, типа:
Array
(
    [class] => TPV
    [device] => tcp://localhost:2222
    [mode] => 3
    [lat] => 60.069966667
    [lon] => 23.522883333
    [altHAE] => 0
    [altMSL] => 0
    [alt] => 0
    [track] => 204.46
    [magtrack] => 204.76
    [magvar] => 8.7
    [speed] => 2.932
    [geoidSep] => 0
    [eph] => 0
)
*/
$buf = explode("\n",$buf);
array_walk($buf,function (&$oneBuf){$oneBuf=json_decode($oneBuf,TRUE);});
//echo "gpsd GPSDlikeInstrumentsDataDecode "; print_r($buf); echo "\n";
return $buf;
} // end function GPSDlikeInstrumentsDataDecode

function realChkSignalKpresent($host,$port){
/* Receive host,port of http interface of SignalK
Return array(host,port) of BSD socket of SignalK or FALSE 
*/
$return = FALSE;
//echo "realChkSignalKpresent: $host:$port;\n";
$buf = @file_get_contents("http://$host:$port/signalk");
//echo "buf: "; print_r($buf);
if($buf!==FALSE){
	$buf = json_decode($buf, true);
	if($buf!==NULL){
		//print_r($buf);
		$buf = parse_url($buf['endpoints']['v1']['signalk-tcp']);	// адрес нормального сокета
		//print_r($buf);
		if($buf!==FALSE) $return = array($buf['host'],$buf['port']);
	}
}
return $return;
} // end function realChkSignalKpresent

function chkSignalKpresent($host,$port){
/* Receive host,port of http interface of SignalK
Return array(host,port) of BSD socket of SignalK or FALSE 
*/
$return = realChkSignalKpresent($host,$port);
//print_r($return);
if(!$return) {
	$host = 'raspberrypi.local';
	$port = 3000;
	$return = realChkSignalKpresent($host,$port);
}
return $return;
} // end function chkSignalKpresent

function findSignalKinLAN(){
/**/
$avahiDiscovery = array();
$ret = exec('avahi-browse --terminate --resolve --parsable --no-db-lookup _signalk-http._tcp',$avahiDiscovery);
//echo "ret:$ret;"; print_r($avahiDiscovery);
if($ret) {
	$findServers = array();
	foreach($avahiDiscovery as $line){
		if($line[0] != '=') continue;
		//echo "$line\n\n";
		$line = explode(';',$line);
		//print_r($line);
		if($line[2] == 'IPv6') continue;	// оно действительно позиционно?
		//print_r($line);
		$info = explode('" "',trim($line[9],'"'));
		//print_r($info);
		$realInfo = array();
		foreach($info as $value){	// array_walk не может изменить ключи, только значения
			list($key,$value)=explode('=',$value);
			$realInfo[$key] = $value;
		}
		unset($info);
		//print_r($realInfo);
		if((strpos($realInfo['roles'],'master')!==FALSE) and (strpos($realInfo['roles'],'main')!==FALSE)) {	// главный сервер. На нём всё?
			//$selfID = $realInfo['vuuid'];	// id главного сервера
			$findServers[$line[7]] = $line[8];	// адрес = порт
		}
	}
	//echo "self=$selfID;"; print_r($findServers);
	if(count($findServers)==2) unset($findServers['127.0.0.1']);
	elseif(count($findServers)>2) echo "Found more than one SignalK main service. It's bad.";	
	//echo "self=$selfID;"; print_r($findServers);
	// Через всё вот это от Avahi получен веб-адрес и id главного сервера SignalK, на котором должно быть всё
	$selfHost = key($findServers);	// первый элемент, потому что указатель не двигали array_key_first у нас ещё нет :-)
	$selfPort = $findServers[$selfHost];
	unset($findServers);
	//echo "self=$selfID;\nselfHost=$selfHost; selfPort=$selfPort;\n";
	return array($selfHost,$selfPort);
}
return FALSE;
} // end function findSignalKinLAN

function realChkVenusOSpresent($host,$port){
/* return VenusOS system serial if found */
require_once('phpMQTT.php');
$return = FALSE;
$client_id = uniqid(); // make sure this is unique for connecting to sever - you could use uniqid()
$mqtt = new Bluerhinos\phpMQTT($host, $port, $client_id);
//if($mqtt->connect(true, NULL, $username, $password)) {
if($mqtt->connect(true)) {
	$payload = $mqtt->subscribeAndWaitForMessage('N/#', 0);
	if($payload){
		$return = explode('/',array_key_first($payload))[1];	// VenusOS system serial
	}
}
$mqtt->close();
return $return;
} // end function realChkVenusOSpresent

function chkVenusOSpresent($host,$port){
$return = realChkVenusOSpresent($host,$port);
if($return) $GLOBALS['VenusOSsystemSerial'] = $return;
else {
	$host = 'venus.local';
	$port = 1883;
	$return = realChkVenusOSpresent($host,$port);
	if($return) {
		$GLOBALS['VenusOSsystemSerial'] = $return;
		$return = array($host,$port);
	}
}
return $return;
} // end function chkVenusOSpresent

function findSource($dataSourceType,$dataSourceHost=null,$dataSourcePort=null){
/* Check getted source or find other */
switch($dataSourceType){
case 'venusos':
	if(!$dataSourceHost) $dataSourceHost = '127.0.0.1';	
	if(!$dataSourcePort) $dataSourcePort = 1883;
	echo "[findSource] Try VenusOS on $dataSourceHost:$dataSourcePort\n";
	$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
	if($res){
		if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
		$requireFile = 'venusos.php';
		echo "Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
	}
	else { 	// попробуем Signal K
		echo "[findSource] VenusOS not found. Try SignalK by Avahi\n";
		$res = findSignalKinLAN();	// спросим у Avahi
		if($res) {
			list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт веб-интерфейса
			$res = realChkSignalKpresent($host,$port);
			if($res) {
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
				$requireFile = 'signalk.php';
				echo "[findSource] Found Signal K on $dataSourceHost:$dataSourcePort\n";
			}
			else { 
				$dataSourceHost = '127.0.0.1';	
				$dataSourcePort = 3000;	
				echo "[findSource] Avahi return bad. Try SignalK on $dataSourceHost:$dataSourcePort\n";
				$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
				if($res){
					list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
					$requireFile = 'signalk.php';
					echo "[findSource] Found Signal K on $dataSourceHost:$dataSourcePort\n";
				}
			}
		}
		else {
			$dataSourceHost = '127.0.0.1';	
			$dataSourcePort = 3000;	
			echo "[findSource] Avahi return no. Try SignalK on $dataSourceHost:$dataSourcePort\n";
			$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
			if($res){
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
				$requireFile = 'signalk.php';
				echo "[findSource] Found Signal K on $dataSourceHost:$dataSourcePort\n";
			}
		}		
	}
	break;
case 'signalk':
	if(!$dataSourceHost) $dataSourceHost = '127.0.0.1';	
	if(!$dataSourcePort) $dataSourcePort = 3000;	
	echo "[findSource] Try SignalK on $dataSourceHost:$dataSourcePort\n";
	$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
	if($res){
		list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
		$requireFile = 'signalk.php';
		echo "[findSource] Found Signal K on $dataSourceHost:$dataSourcePort\n";
	}
	else {
		echo "[findSource] SignalK not found. Try SignalK by Avahi\n";
		$res = findSignalKinLAN();	// спросим у Avahi
		if($res) {	//echo "Avahi что-то нашёл\n";
			list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт веб-интерфейса
			//echo "$dataSourceHost:$dataSourcePort\n";
			$res = realChkSignalKpresent($dataSourceHost,$dataSourcePort);
			if($res) {
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
				$requireFile = 'signalk.php';
				echo "[findSource] Found Signal K on $dataSourceHost:$dataSourcePort\n";
			}
		}
		else {	// попробуем VenusOS
			$dataSourceHost = '127.0.0.1';	
			$dataSourcePort = 1883;	
			echo "[findSource] Avahi return no. Try VenusOS on $dataSourceHost:$dataSourcePort\n";
			$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
			if($res){
				if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
				$requireFile = 'venusos.php';
				echo "[findSource] Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
			}
		}
	}
	break;
default:	// gpsd
	if(!$dataSourceHost) $dataSourceHost = '127.0.0.1';	
	if(!$dataSourcePort) $dataSourcePort = 2947;	
	echo "[findSource] Try gpsd on $dataSourceHost:$dataSourcePort\n";
	if(chkGPSDpresent($dataSourceHost,$dataSourcePort)) {
		$requireFile = 'gpsd.php';
		echo "[findSource] Found gpsd on $dataSourceHost:$dataSourcePort\n";
	}
	else {
		echo "[findSource] gpsd not found. Try SignalK on $dataSourceHost:$dataSourcePort\n";
		$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
		if($res){
			list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
			$requireFile = 'signalk.php';
			echo "[findSource] Found Signal K on $dataSourceHost:$dataSourcePort\n";
		}
		else {
			echo "[findSource] SignalK not found. Try SignalK by Avahi\n";
			$res = findSignalKinLAN();	// спросим у Avahi
			if($res) {	//echo "Avahi что-то нашёл\n";
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт веб-интерфейса
				//echo "$dataSourceHost:$dataSourcePort\n";
				$res = realChkSignalKpresent($dataSourceHost,$dataSourcePort);
				if($res) {
					list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
					$requireFile = 'signalk.php';
					echo "[findSource] Found Signal K on $dataSourceHost:$dataSourcePort\n";
				}
				else {
					$dataSourceHost = '127.0.0.1';	
					$dataSourcePort = 3000;	
					echo "[findSource] Avahi return bad. Try SignalK on $dataSourceHost:$dataSourcePort\n";
					$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
					if($res){
						list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
						$requireFile = 'signalk.php';
						echo "[findSource] Found SignalK on $dataSourceHost:$dataSourcePort\n";
					}
					else {	// попробуем VenusOS
						$dataSourceHost = '127.0.0.1';	
						$dataSourcePort = 1883;	
						echo "[findSource] SignalK not found. Try VenusOS on $dataSourceHost:$dataSourcePort\n";
						$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
						if($res){
							if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
							$requireFile = 'venusos.php';
							echo "[findSource] Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
						}
					}
				}
			}
			else {
				$dataSourceHost = '127.0.0.1';	
				$dataSourcePort = 3000;	
				echo "[findSource] Avahi return no. Try SignalK on $dataSourceHost:$dataSourcePort\n";
				$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
				if($res){
					list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
					$requireFile = 'signalk.php';
					echo "[findSource] Found Signal K on $dataSourceHost:$dataSourcePort\n";
				}
				else {	// попробуем VenusOS
					$dataSourceHost = '127.0.0.1';	
					$dataSourcePort = 1883;	
					echo "[findSource] SignalK not found. Try VenusOS on $dataSourceHost:$dataSourcePort\n";
					$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
					if($res){
						if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
						$requireFile = 'venusos.php';
						echo "[findSource] Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
					};
				};
			};
		};
	};
};

if(@$requireFile) return array($dataSourceHost,$dataSourcePort,$requireFile);
else return FALSE;
} // end function findSource

function updAndPrepare($inInstrumentsData=array(),$sockKey=null,$instrumentsDataUpdated=array()){
/* Обновляет кеш данных и готовит к отправке, если надо, данные для режима WATCH, 
так, что на следующем обороте они будут отправлены 
$inInstrumentsData -- масиив ответов gpsd в режиме ?WATCH={"enable":true,"json":true};
Однако, обычно (всегда?) там только один ответ gpsd, т.е., одно сообщение одного класса
от одного устройства. Даже при каскадном соединении gpsd?
Опять же однако - для SignalK обновление каждого path, т.е., одного параметра - это одно сообщение,
плюс каждая цель AIS - одно сообщение, поэтому при получении данных 
от SignalK $inInstrumentsData - да, массив. Когда как от gpsd - это массив из одного элемента.
*/
global $messages, $pollWatchExist, $instrumentsData;

//echo "\n[updAndPrepare] sockKey=$sockKey;                   \n";
//print_r($inInstrumentsData);
//if($instrumentsData['AIS']['538008208']) {echo "mmsi: Princess Margo\n"; print_r($instrumentsData['AIS']['538008208']['data']); echo "\n";};

if($inInstrumentsData) {
	foreach($inInstrumentsData as $inInstrument){
		//echo "\n[updAndPrepare] inInstrument "; print_r($inInstrument);
		$instrumentsDataUpdated = array_merge($instrumentsDataUpdated,updInstrumentsData($inInstrument,$sockKey));	// массивы со строковыми ключами
		//echo "[updAndPrepare] merged instrumentsDataUpdated "; print_r($instrumentsDataUpdated);
	};
}
else $instrumentsDataUpdated = array_merge($instrumentsDataUpdated,updInstrumentsData(array(),$sockKey));	// вызвали для проверки протухших данных и отправке, если
//echo "Что изменилось, instrumentsDataUpdated: "; print_r($instrumentsDataUpdated);
if(!$instrumentsDataUpdated) return;	// если ничего не изменилось - нечего и посылать.

dataSourceSave(); 	// сохраним в файл, если пора

// Подготовим к отправке каждому подписчику данные в соответствии с подпиской.
//echo "\npollWatchExist:"; print_r($pollWatchExist);
if($pollWatchExist){	// есть режим WATCH, надо подготовить данные. От gpsd (или что там вместо) может прийти пустое или непонятное
	// чтобы для всех подключенных клиентов создать данные один раз
	$WATCH = null; $ais = null; $ALARM = null;	
	$updatedTypes = array_intersect_key($instrumentsDataUpdated,$pollWatchExist);	// те обновленные типы данных, на которые есть подписка
	//if((count($updatedTypes)>1) or (!array_key_exists("TPV",$updatedTypes))) {echo "\n [updAndPrepare] updatedTypes:"; print_r($updatedTypes);echo "\n instrumentsDataUpdated:"; print_r($instrumentsDataUpdated);};
	if(!$updatedTypes) return;	// нет ничего нового
	foreach($updatedTypes as $updatedType => $v){
		//echo "updatedType=$updatedType; v=$v;         \n";
		if(!$v) continue;	// там всё же должно быть true
		switch($updatedType){
		case "TPV":
			$WATCH = json_encode(makeWATCH("TPV"),JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";	// нельзя JSON_NUMERIC_CHECK, потому что оно превратит mmsi в число, хотя оно строка. Тогда бессмысленно JSON_PRESERVE_ZERO_FRACTION
			break;
		case "ATT":
			$ATT = json_encode(makeWATCH("ATT"),JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";	// нельзя JSON_NUMERIC_CHECK, потому что оно превратит mmsi в число, хотя оно строка. Тогда бессмысленно JSON_PRESERVE_ZERO_FRACTION
			break;
		case "AIS":
			$ais = json_encode(makeAIS(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";
			//echo "\n [updAndPrepare] prepare AIS data to send=$ais";
			break;
		case "ALARM":
			$ALARM = json_encode(makeALARM(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";
			//echo "\n [updAndPrepare] prepare ALARM data to send=$ALARM";
			break;
		case "WPT":
			$WPT = json_encode(makeWPT(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";
			//echo "\n [updAndPrepare] prepare WPT data to send=$WPT";
			break;
		};
	};
	$pollWatchExist = array();	// нет сокетов с режимом WATCH
	$now = microtime(true);
	foreach($messages as $socket => $sockData){	// для каждого сокета
		if($sockData['POLL'] !== 'WATCH') continue;
		// для соответствующего сокета указано посылать непрерывно.
		// === потому что $data['POLL'] на момент сравнения может иметь тип boolean, и при == произойдёт приведение 'WATCH' к boolean;
		$pollWatchExist = array_merge($pollWatchExist,$sockData['subscribe']);	// отметим, что есть сокет с режимом WATCH и некоторой подпиской // массивы со строковыми ключами
		if(($now - @$sockData['lastSend'])<floatval(@$sockData['minPeriod'])) continue;	// частота отсылки данных
		$messages[$socket]['lastSend'] = $now;

		// Здесь каждому клиенту записывается своя копия данных
		// причём для AIS -- все данные всех целей, даже если что-то изменилось только у одной.
		//echo "socket=$socket; sockData:"; print_r($sockData);
		$clientMessagesCount = count($messages[$socket]['output']);
		//echo "для клиента $socket уже есть $clientMessagesCount сообщений, если их не уменьшится ещё {$messages[$socket]['outputSkip']} оборотов - начнём пропускать\n";
		foreach($sockData['subscribe'] as $subscribe=>$v){
			if(!@$updatedTypes[$subscribe]) continue;	
			// по этой подписке есть свежие данные
			switch($subscribe){
			case "TPV":
				$messages[$socket]['output'][] = &$WATCH;	// строго говоря, &$WATCH, но в PHP ленивое присваивание....
				//echo "[updAndPrepare] write to send TPV to socket #$socket: |$WATCH|                     \n"; print_r($messages[$socket]['output']); echo "\n";
				break;
			case "ATT":
				$messages[$socket]['output'][] = &$ATT;	// строго говоря, &$ATT, но в PHP ленивое присваивание....
				//echo "sending ATT=$ATT                     \n";
				break;
			case "AIS":
				if($clientMessagesCount){
					//echo "очередь слишком большая - вообще не шлём AIS  \n";
					// Но этого не может быть, потому что в разделе записи пишутся в сокет все
					// имеющиеся сообщения, а если он умрёт - то они просто уничтожатся.
					// Т.е., этот механизмик не имеет смысла и никогда не работает.
					continue 2;	// вообще не будем слать AIS
				}
				$messages[$socket]['output'][] = $ais;
				//echo "sending AIS to socket=$socket;                     \n";
				break;
			case "ALARM":
				$messages[$socket]['output'][] = $ALARM;
				break;
			case "WPT":
				$messages[$socket]['output'][] = $WPT;
				break;
			};
		};
	};
};
}; // end function updAndPrepare


function updInstrumentsData($inInstrumentsData=array(),$sockKey=null) {
/* Обновляет глобальный кеш $instrumentsData отдельными сообщениями $inInstrumentsData
$inInstrumentsData -- один ответ gpsd в режиме ?WATCH={"enable":true,"json":true};, 
когда оно передаёт поток отдельных сообщений, типа:
Array
(
    [class] => TPV
    [device] => tcp://localhost:2222
    [mode] => 3
    [lat] => 60.069966667
    [lon] => 23.522883333
    [altHAE] => 0
    [altMSL] => 0
    [alt] => 0
    [track] => 204.46
    [magtrack] => 204.76
    [magvar] => 8.7
    [speed] => 2.932
    [geoidSep] => 0
    [eph] => 0
)
{"class":"AIS","device":"tcp://localhost:2222","type":1,"repeat":0,"mmsi":244660492,"scaled":false,"status":0,"status_text":"Under way using engine","turn":-128,"speed":0,"accuracy":true,"lon":3424893,"lat":31703105,"course":0,"heading":511,"second":25,"maneuver":0,"raim":true,"radio":81955}

*/
global $instrumentsData,$gpsdProxyTimeouts,$collisionDistance,$dataUpdated,$boatInfo;
$instrumentsDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.
$now = time();
//echo "\ninInstrumentsData="; print_r($inInstrumentsData);echo"\n";
switch(@$inInstrumentsData['class']) {	// Notice if $inInstrumentsData empty
case 'SKY':
	break;
case 'IMU':	// The IMU object is asynchronous to the GNSS epoch. It is reported with arbitrary, even out of order, time scales. The ATT and IMU objects have the same fields, but IMU objects are output as soon as possible.
	$inInstrumentsData['class'] = 'ATT';
case 'ATT':	// An ATT object is a vehicle-attitude report.
case 'TPV':	// A TPV object is a time-position-velocity report.
	// собирает данные по устройствам, в том числе и однородные
	//echo "\ninInstrumentsData="; print_r($inInstrumentsData);echo"\n";
	//echo "recieve TPV                     \n";
	$dataTime = $now;
	foreach($inInstrumentsData as $type => $value){ 	// обновим данные
		if($type == 'time') { // надеемся, что время прислали до содержательных данных
			$dataTime = strtotime($value);
			//echo "\nПрисланное время: |$value|$dataTime, восстановленное: |".date(DATE_ATOM,$dataTime)."|".strtotime(date(DATE_ATOM,$dataTime))." \n";
			if(!$dataTime) $dataTime = $now;
		};
		if(is_numeric($value)){
			// int or float. нет способа привести к целому или вещественному без явной проверки, 
			// кроме как вот через такую задницу. 
			// Однако, оказывается, что числа уже всегда? И чё теперь? Ибо (int)0 !== (float)0
			//echo "\ntype=$type; value=$value; is_int:".(is_int($value))."; is_float:".(is_float($value))."; \n";
			$value = 0+$value; 	// в результает получается целое или вещественное число
			// Записываем время кеширования всех, потому что оно используется в makeWATCH для собирания самых свежих значений от разных устройств
			// но если значение float, и равно предыдущему - считаем, что это предыдущее значение
			// и время кеширования не обновляем. 
			// Что стрёмно, на самом деле, ибо у нас часто (всегда?) значеия float, даже когда они
			// int, особенно 0. Почему?
			if(is_float($value)){
				 if($value !== @$instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['data'][$type]){	// Кстати, такой фокус не пройдёт в JavaScript, потому что переменной $instrumentsData['TPV'][$inInstrumentsData['device']]['data'][$type] в начале не существует.
					// php создаёт вложенную структуру, это не python и не javascript
					$instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['cachedTime'][$type] = $dataTime;
				};
			}
			else{
				$instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['cachedTime'][$type] = $dataTime;
			};

			$instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['data'][$type] = $value; 	// int or float
			// Поправки
			switch($type){
			// Это всё будет как в TPV, как оно в gpsd, так и в ATT, где оно быть должно было бы.
			case 'depth': 
				if(isset($boatInfo['to_echosounder'])) $instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['data'][$type] += $boatInfo['to_echosounder'];
			case 'temp': 
			case 'wanglem': 
			case 'wangler': 
			case 'wanglet': 
			case 'wspeedr': 
			case 'wspeedt': 
			case 'wtemp': 
				if($inInstrumentsData['class']=='TPV'){	// это всё от TPV, а не от ATT, как должно было бы быть
					$instrumentsData['ATT'][$inInstrumentsData['device']]['data']['class'] = 'ATT';
					$instrumentsData['ATT'][$inInstrumentsData['device']]['data']['device'] = $inInstrumentsData['device'];
					$instrumentsData['ATT'][$inInstrumentsData['device']]['data']['time'] = date(DATE_ATOM,$dataTime);
					$instrumentsData['ATT'][$inInstrumentsData['device']]['data'][$type] = $value; 	// то же устройство будет и в TPV и в ATT
					$instrumentsData['ATT'][$inInstrumentsData['device']]['cachedTime']['class'] = $instrumentsData['TPV'][$inInstrumentsData['device']]['cachedTime'][$type];
					$instrumentsData['ATT'][$inInstrumentsData['device']]['cachedTime']['device'] = $instrumentsData['TPV'][$inInstrumentsData['device']]['cachedTime'][$type];
					$instrumentsData['ATT'][$inInstrumentsData['device']]['cachedTime'][$type] = $instrumentsData['TPV'][$inInstrumentsData['device']]['cachedTime'][$type];
					$instrumentsDataUpdated['ATT'] = TRUE;
					//echo "[updInstrumentsData] instrumentsData['ATT']:    "; print_r($instrumentsData['ATT']); echo "\n";
				};
				break;
			case 'mheading': 
				if(isset($instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['data']['magdev'])) $instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['data'][$type] += $instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['data']['magdev'];
				elseif(isset($boatInfo['magdev'])) $instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['data'][$type] += $boatInfo['magdev'];
				break;
			};
		}
		else{
			$instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['data'][$type] = (string)$value; 	// string
			// Записываем время кеширования всех, потому что оно используется в makeWATCH для собирания самых свежих значений от разных устройств
			$instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['cachedTime'][$type] = $dataTime;
		};
	
		//echo "\ngpsdProxyTimeouts[$inInstrumentsData['class']][$type]={$gpsdProxyTimeouts[$inInstrumentsData['class']][$type]};\n";
		//echo "\ninInstrumentsData['device']={$inInstrumentsData['device']};\n";
		/*
		if($instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['cachedTime'][$type] != $now){
			//echo "type=$type; "; print_r($instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['cachedTime']);
			echo "\nДля $type время не совпадает на ".($instrumentsData[$inInstrumentsData['class']][$inInstrumentsData['device']]['cachedTime'][$type] - $now)." сек. \n";
			echo "Применяется время: $dataTime, ".date(DATE_ATOM,$dataTime).", сейчас ".date(DATE_ATOM,$now)." \n";
		};
		*/
		
		$instrumentsDataUpdated[$inInstrumentsData['class']] = TRUE;

		$instrumentsDataUpdated = array_merge($instrumentsDataUpdated,chkWPT());	// обновление путевой точки
	}
	break;
case 'netAIS':
	//echo "\n[updInstrumentsData] netAIS Data: "; print_r($inInstrumentsData); echo "\n";
	foreach($inInstrumentsData['data'] as $vehicle => $data){
		if($data['class'] == 'MOB'){	// сообщение MOB
			//echo "\n[updInstrumentsData] netAIS MOB Data: "; print_r($data); echo "\n";
			if($data['status']){	//echo "в пришедших данных есть статус MOB          \n";
				if($instrumentsData['ALARM']['MOB']['status']){	 //echo "[updInstrumentsData] netAIS: режим MOB есть, в пришедших данных есть.\n";// echo "instrumentsData['ALARM']['MOB']:"; print_r($instrumentsData['ALARM']['MOB']);echo "\n";
					// Если имеюшиеся данные моложе пришедших - игнорируем
					//echo "[updInstrumentsData] {$instrumentsData['ALARM']['MOB']['timestamp']} >= {$data['timestamp']}\n";
					if($instrumentsData['ALARM']['MOB']['timestamp'] >= $data['timestamp']) break;
					//echo "[updInstrumentsData] netAIS: режим MOB есть, в пришедших данных есть свежее.   \n"; echo "instrumentsData['ALARM']['MOB']:"; print_r($instrumentsData['ALARM']['MOB']);echo "\n";
					// Обновим / добавим точки
					// Пришедшие точки там уже могут быть, причём от одного mmsi - сколько хочешь точек.
					// Поэтому нужно взять в пришедшем все точки от одного mmsi, удалить
					// из нашего MOB все точки от этого mmsi, а потом добавить в наш MOB
					// точки из пришедшего с этим mmsi.
					$yetDeleted = array();	// список mmsi, точки от которых уже удалены
					$current = null;	// mmsi текущей точки в нашем объекте MOB
					foreach($instrumentsData['ALARM']['MOB']['points'] as $point){	// удалим точки с этим mmsi
						if($point['current']) {
							$current = $point['mmsi'];
							break;
						};
					};
					//echo "[updInstrumentsData] current=$current;\n";
					foreach($data['points'] as $inPoint){
						if($inPoint['mmsi'] == $boatInfo['mmsi']) continue;	// игнорируем информацию о себе, пришедшую со стороны
						if(!in_array($inPoint['mmsi'],$yetDeleted)){	// если точки с mmsi этой точки ещё не удаляли
							$yetDeleted[] = $inPoint['mmsi'];
							foreach($instrumentsData['ALARM']['MOB']['points'] as $i => $isPoint){	// удалим точки с этим mmsi
								if($inPoint['mmsi'] != $isPoint['mmsi']) continue;
								//echo "такая точка есть в нашем MOB\n";
								unset($instrumentsData['ALARM']['MOB']['points'][$i]);	
							};
						};
						//echo "[updInstrumentsData] current=$current;\n";
						if($current and ($current != $inPoint['mmsi'])) $inPoint['current'] = false;	// если у нас уже есть текущая точка, сведения о чужой текущей игнорируем
						$instrumentsData['ALARM']['MOB']['points'][] = $inPoint;
						$instrumentsData['ALARM']['MOB']['timestamp'] = $data['timestamp'];
						$instrumentsDataUpdated['ALARM'] = true;
					};
					$instrumentsData['ALARM']['MOB']['points'] = array_values($instrumentsData['ALARM']['MOB']['points']);	// переиндексируем массив, потому что, если было удаление точек, то теперь с точки зрения json это объект
				}
				else{	 //echo "режима MOB нет                 \n";
					// Однако, если у нас есть завершённый режим MOB, который был
					// завершён позже метки времени пришедшего - игнорируем пришедший.
					// Таким образом, получив чужой MOB, а потом выключив свой, поднятый на основании чужого,
					// мы сможем игнорировать чужой MOB до тех пор, пока тот не изменится.
					//echo "[updInstrumentsData] {$instrumentsData['ALARM']['MOB']['timestamp']} >= {$data['timestamp']}\n";
					if($instrumentsData['ALARM']['MOB']['timestamp'] >= $data['timestamp']) break;
					//echo " 	поднимем тревогу   \n";
					$instrumentsData['ALARM']['MOB'] = $data;
					$instrumentsData['ALARM']['MOB']['timestamp'] = $data['timestamp'];
					$instrumentsDataUpdated['ALARM'] = true;
				};
			}
			else { 	 //echo "\n иначе - в пришедших данных нет статуса MOB\n";
				if($instrumentsData['ALARM']['MOB']['status']){	 //echo "netAIS: режим MOB есть, в пришедших данных нет.\n";// echo "instrumentsData['ALARM']['MOB']:"; print_r($instrumentsData['ALARM']['MOB']);echo "\n";
					// В пришедших данных должны быть точки, в отношении которых кто-то выключил режим MOB.
					// Тогда мы удаляем все точки от тех mmsi, которые имеются в выключенных точках,
					// из своего MOB.
					$yetDeleted = array();	// список mmsi, точки от которых уже удалены
					foreach($data['points'] as $inPoint){
						//echo "inPoint:"; print_r($inPoint); echo "\n";
						if($inPoint['mmsi'] == $boatInfo['mmsi']) continue;	// игнорируем информацию о себе, пришедшую со стороны
						if(!in_array($inPoint['mmsi'],$yetDeleted)){	// если точки с mmsi этой точки ещё не удаляли
							$yetDeleted[] = $inPoint['mmsi'];
							foreach($instrumentsData['ALARM']['MOB']['points'] as $i => $isPoint){
								//echo "isPoint:"; print_r($isPoint); echo "\n";
								if($inPoint['mmsi'] != $isPoint['mmsi']) continue;
									//echo "такая точка есть в нашем MOB\n";
									unset($instrumentsData['ALARM']['MOB']['points'][$i]);
									$instrumentsData['ALARM']['MOB']['timestamp'] = $data['timestamp'];
									$instrumentsDataUpdated['ALARM'] = true;
							};
						};
					};
					$instrumentsData['ALARM']['MOB']['points'] = array_values($instrumentsData['ALARM']['MOB']['points']);	// переиндексируем массив, потому что, если было удаление точек, то теперь с точки зрения json это объект
					if(count($instrumentsData['ALARM']['MOB']['points']) == 0){	// точек в нашем объекте MOB не осталось.
						$instrumentsData['ALARM']['MOB']['status'] = false;
					};
				}
				else {	 //echo "режима MOB нет                          \n";
				};
			};
			//echo "[updInstrumentsData] instrumentsData['ALARM']['MOB'] after:"; print_r($instrumentsData['ALARM']['MOB']);echo "\n";
		}
		else {	// netAIS vessel
			$vehicle = (string)$vehicle;	// mmsi должна быть строкой
			$timestamp = $data['timestamp'];
			if(!$timestamp) $timestamp = $now;
			$instrumentsData['AIS'][$vehicle]['timestamp'] = $timestamp;
			foreach($data as $type => $value){
				$instrumentsData['AIS'][$vehicle]['data'][$type] = $value; 	// 
				$instrumentsData['AIS'][$vehicle]['cachedTime'][$type] = $timestamp;
				$instrumentsDataUpdated['AIS'] = true;
			}
			// Посчитаем данные для контроля столкновений:
			list($instrumentsData['AIS'][$vehicle]['collisionArea'],$instrumentsData['AIS'][$vehicle]['squareArea']) = updCollisionArea($instrumentsData['AIS'][$vehicle]['data'],$collisionDistance);	// fCollisions.php
		};
	}
	break;
case 'AIS':
	//echo "\nJSON AIS Data: "; print_r($inInstrumentsData); echo "\n";
	$vehicle = trim((string)$inInstrumentsData['mmsi']);	//
	$instrumentsData['AIS'][$vehicle]['data']['mmsi'] = $vehicle;	// ВНИМАНИЕ! Ключ -- строка, представимая как число. Любые действия в массивом, затрагивающие ключи -- сделают эту строку числом
	if($inInstrumentsData['netAIS']) $instrumentsData['AIS'][$vehicle]['data']['netAIS'] = TRUE; 	// 
	//echo "\nmmsi $vehicle AIS sentence type ".$inInstrumentsData['type']."\n";
	//if($vehicle=='538008208') {echo "mmsi: Princess Margo\n"; print_r($instrumentsData['AIS'][$vehicle]['data']); echo "\n";};
	switch($inInstrumentsData['type']) {
	case 27:
	case 18:
	case 19:
	case 1:
	case 2:
	case 3:		// http://www.e-navigation.nl/content/position-report
		// Для начала определим timestamp полученного сообщения, и,
		// если оно не моложе имеющегося - сообщение проигнорируем
		$inInstrumentsData['second'] = (int)filter_var($inInstrumentsData['second'],FILTER_SANITIZE_NUMBER_INT);
		if($inInstrumentsData['second']>63) $timestamp = $inInstrumentsData['second'];	// Ну так же проще! Будем считать, что если там большая цифра -- то это unix timestamp. Так будем принимать метку времени от SignalK
		elseif($inInstrumentsData['second']>59) $timestamp = $now;	// т.е., никакого разумного времени передано не было, только условные.
		else $timestamp = $now - $inInstrumentsData['second']; 	// Unis timestamp. Time stamp UTC second when the report was generated by the electronic position system (EPFS) (0-59, or 60 if time stamp is not available, which should also be the default value, or 61 if positioning system is in manual input mode, or 62 if electronic position fixing system operates in estimated (dead reckoning) mode, or 63 if the positioning system is inoperative)
		if($instrumentsData['AIS'][$vehicle]['timestamp'] and ($timestamp<=$instrumentsData['AIS'][$vehicle]['timestamp'])) {
			//echo "\nПолучено старое сообщение AIS № 1 для mmsi=$vehicle, игнорируем.\n";
			break;
		}
		$instrumentsData['AIS'][$vehicle]['timestamp'] = $timestamp;
		//echo "\nПолучено сообщение AIS № 1 для mmsi=$vehicle, timestamp=$timestamp; now=$now;\n";

		if(isset($inInstrumentsData['status'])) {
			if(is_string($inInstrumentsData['status'])){	// костыль к горбатому gpsd, который для 27 предложения пишет в status status_text.
				//$instrumentsData['AIS'][$vehicle]['data']['status_text'] = filter_var($inInstrumentsData['status'],FILTER_SANITIZE_STRING);	// оно не надо, ибо интернационализация и всё такое. И, кстати: для американцев нет других языков, да.
				$instrumentsData['AIS'][$vehicle]['data']['status'] = navigationStatusEncode($instrumentsData['AIS'][$vehicle]['data']['status_text']);
			}
			else $instrumentsData['AIS'][$vehicle]['data']['status'] = (int)filter_var($inInstrumentsData['status'],FILTER_SANITIZE_NUMBER_INT); 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
			if($instrumentsData['AIS'][$vehicle]['data']['status'] === 0 and (!@$instrumentsData['AIS'][$vehicle]['data']['speed'])) $instrumentsData['AIS'][$vehicle]['data']['status'] = null;	// они сплошь и рядом ставят статус 0 для не движущегося судна
			if($instrumentsData['AIS'][$vehicle]['data']['status'] == 15) $instrumentsData['AIS'][$vehicle]['data']['status'] = null;
			$instrumentsData['AIS'][$vehicle]['cachedTime']['status'] = $now;
			//echo "inInstrumentsData['status']={$inInstrumentsData['status']}; status={$instrumentsData['AIS'][$vehicle]['data']['status']};\n";
		}
		//if(isset($inInstrumentsData['status_text'])) $instrumentsData['AIS'][$vehicle]['data']['status_text'] = filter_var($inInstrumentsData['status_text'],FILTER_SANITIZE_STRING);
		//echo "inInstrumentsData['status_text']={$inInstrumentsData['status_text']}; status_text={$instrumentsData['AIS'][$vehicle]['data']['status_text']};\n";
		if(isset($inInstrumentsData['accuracy'])) {
			if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['accuracy'] = $inInstrumentsData['accuracy']; 	// данные уже приведены к человеческому виду
			else $instrumentsData['AIS'][$vehicle]['data']['accuracy'] = (bool)filter_var($inInstrumentsData['accuracy'],FILTER_SANITIZE_NUMBER_INT); 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
			$instrumentsData['AIS'][$vehicle]['cachedTime']['accuracy'] = $now;
		}
		if(isset($inInstrumentsData['turn'])){
			if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду
				if($inInstrumentsData['turn'] == 'nan') $instrumentsData['AIS'][$vehicle]['data']['turn'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['turn'] = $inInstrumentsData['turn']; 	// градусы в минуту со знаком или строка? one of the strings "fastright" or "fastleft" if it is out of the AIS encoding range; otherwise it is quadratically mapped back to the turn sensor number in degrees per minute
			}
			else {
				$instrumentsData['AIS'][$vehicle]['data']['turn'] = (int)filter_var($inInstrumentsData['turn'],FILTER_SANITIZE_NUMBER_INT); 	// тут чёта сложное...  Rate of turn ROTAIS 0 to +126 = turning right at up to 708° per min or higher 0 to –126 = turning left at up to 708° per min or higher Values between 0 and 708° per min coded by ROTAIS = 4.733 SQRT(ROTsensor) degrees per min where  ROTsensor is the Rate of Turn as input by an external Rate of Turn Indicator (TI). ROTAIS is rounded to the nearest integer value. +127 = turning right at more than 5° per 30 s (No TI available) –127 = turning left at more than 5° per 30 s (No TI available) –128 (80 hex) indicates no turn information available (default). ROT data should not be derived from COG information.
			}
			if($instrumentsData['AIS'][$vehicle]['data']['turn'] == -128) $instrumentsData['AIS'][$vehicle]['data']['turn'] = null;	// -128 ?
			$instrumentsData['AIS'][$vehicle]['cachedTime']['turn'] = $now;
			//echo "$vehicle inInstrumentsData['turn']={$inInstrumentsData['turn']}; turn={$instrumentsData['AIS'][$vehicle]['data']['turn']};                 \n";
		}
		if(isset($inInstrumentsData['lon']) and isset($inInstrumentsData['lat'])){
			if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду
				if($inInstrumentsData['type'] == 27){
					if( !($instrumentsData['AIS'][$vehicle]['data']['lon'] and $instrumentsData['AIS'][$vehicle]['data']['lat'])) {	// костыль к багу gpsd, когда он округляет эти координаты до первого знака после запятой
						$instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)$inInstrumentsData['lon']; 	// 
						$instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)$inInstrumentsData['lat'];
						$instrumentsData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
						$instrumentsData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
					}
				}
				else {
					$instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)$inInstrumentsData['lon']; 	// 
					$instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)$inInstrumentsData['lat'];
					$instrumentsData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
					$instrumentsData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
				}
			}
			else {
				if($inInstrumentsData['type'] == 27) { 	// оказывается, там координаты в 1/10 минуты и скорость в узлах!!!
					if($inInstrumentsData['lon']==181*60*10) $instrumentsData['AIS'][$vehicle]['data']['lon'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)filter_var($inInstrumentsData['lon'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10*60); 	// Longitude in degrees	( 1/10 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
					if($inInstrumentsData['lat']==91*60*10) $instrumentsData['AIS'][$vehicle]['data']['lat'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)filter_var($inInstrumentsData['lat'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10*60); 	// Latitude in degrees (1/10 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
				}
				else {
					if($inInstrumentsData['lon']==181*60*10000) $instrumentsData['AIS'][$vehicle]['data']['lon'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)filter_var($inInstrumentsData['lon'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10000*60); 	// Longitude in degrees	( 1/10 000 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
					if($inInstrumentsData['lat']==91*60*10000) $instrumentsData['AIS'][$vehicle]['data']['lat'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)filter_var($inInstrumentsData['lat'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10000*60); 	// Latitude in degrees (1/10 000 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
				}
				$instrumentsData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
				$instrumentsData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
			}
		}
		if(isset($inInstrumentsData['speed'])){
			if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
				if($inInstrumentsData['speed']=='nan') $instrumentsData['AIS'][$vehicle]['data']['speed'] = NULL;
				else {
					if($inInstrumentsData['type'] == 27){
						if( !$instrumentsData['AIS'][$vehicle]['data']['speed']){	// не будем принимать данные из сообщения 27 из-за меньшей точности
							$instrumentsData['AIS'][$vehicle]['data']['speed'] = $inInstrumentsData['speed']*1852/(60*60); 	// SOG Speed over ground in m/sec 	
							$instrumentsData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
						}
					}
					else {
						$instrumentsData['AIS'][$vehicle]['data']['speed'] = $inInstrumentsData['speed']*1852/(60*60); 	// SOG Speed over ground in m/sec 	
						$instrumentsData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
					}
				}
			}
			else {
				if($inInstrumentsData['type'] == 27) { 	// оказывается, там координаты в 1/10 минуты и скорость в узлах!!!
					if($inInstrumentsData['speed']==63) $instrumentsData['AIS'][$vehicle]['data']['speed'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['speed'] = (float)filter_var($inInstrumentsData['speed'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)*1852/3600; 	// м/сек SOG Speed over ground in m/sec 	Knots (0-62); 63 = not available = default
				}
				else {
					if($inInstrumentsData['speed']>1022) $instrumentsData['AIS'][$vehicle]['data']['speed'] = NULL;	
					else $instrumentsData['AIS'][$vehicle]['data']['speed'] = (float)filter_var($inInstrumentsData['speed'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)*185.2/3600; 	// SOG Speed over ground in m/sec 	(in 1/10 knot steps (0-102.2 knots) 1 023 = not available, 1 022 = 102.2 knots or higher)
				}
				$instrumentsData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
			}
		}
		if(isset($inInstrumentsData['course'])){
			if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду
				if($inInstrumentsData['course']==360) $instrumentsData['AIS'][$vehicle]['data']['course'] = NULL;
				else {
					if($inInstrumentsData['type'] == 27){
						if( !$instrumentsData['AIS'][$vehicle]['data']['course']){	// не будем принимать данные из сообщения 27 из-за меньшей точности
							$instrumentsData['AIS'][$vehicle]['data']['course'] = $inInstrumentsData['course']; 	// Путевой угол.
							$instrumentsData['AIS'][$vehicle]['cachedTime']['course'] = $now;
						}
					}
					else {
						$instrumentsData['AIS'][$vehicle]['data']['course'] = $inInstrumentsData['course']; 	// Путевой угол.
						$instrumentsData['AIS'][$vehicle]['cachedTime']['course'] = $now;
					}
				}
			}
			else{
				if($inInstrumentsData['type'] == 27) { 	// оказывается, там путевой угол в градусах
					if($inInstrumentsData['course']==511) $instrumentsData['AIS'][$vehicle]['data']['course'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['course'] = (float)filter_var($inInstrumentsData['course'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Путевой угол. COG Course over ground in degrees Degrees (0-359); 511 = not available = default
				}
				else {
					if($inInstrumentsData['course']==3600) $instrumentsData['AIS'][$vehicle]['data']['course'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['course'] = (float)filter_var($inInstrumentsData['course'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/10; 	// Путевой угол. COG Course over ground in degrees ( 1/10 = (0-3599). 3600 (E10h) = not available = default. 3601-4095 should not be used)
				};
				$instrumentsData['AIS'][$vehicle]['cachedTime']['course'] = $now;
			};
			//echo "inInstrumentsData['scaled']={$inInstrumentsData['scaled']}\n";
			//if($vehicle=='230985490') echo "inInstrumentsData['course']={$inInstrumentsData['course']}; course={$instrumentsData['AIS'][$vehicle]['data']['course']};\n";
		};
		if(isset($inInstrumentsData['heading'])){
			if($inInstrumentsData['scaled']) {
				if($inInstrumentsData['heading']==511) $instrumentsData['AIS'][$vehicle]['data']['heading'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['heading'] = $inInstrumentsData['heading']; 	// 
			}
			else {
				if($inInstrumentsData['heading']==511) $instrumentsData['AIS'][$vehicle]['data']['heading'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['heading'] = (float)filter_var($inInstrumentsData['heading'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Истинный курс. True heading Degrees (0-359) (511 indicates not available = default)
			};
			$instrumentsData['AIS'][$vehicle]['cachedTime']['heading'] = $now;
			//if($vehicle=='230985490') echo "inInstrumentsData['heading']={$inInstrumentsData['heading']}; heading={$instrumentsData['AIS'][$vehicle]['data']['heading']};\n\n";
		};
		if(isset($inInstrumentsData['maneuver'])){
			if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['maneuver'] = $inInstrumentsData['maneuver']; 	// данные уже приведены к человеческому виду
			else $instrumentsData['AIS'][$vehicle]['data']['maneuver'] = (int)filter_var($inInstrumentsData['maneuver'],FILTER_SANITIZE_NUMBER_INT); 	// Special manoeuvre indicator 0 = not available = default 1 = not engaged in special manoeuvre 2 = engaged in special manoeuvre (i.e. regional passing arrangement on Inland Waterway)
			if($instrumentsData['AIS'][$vehicle]['data']['maneuver'] === 0) $instrumentsData['AIS'][$vehicle]['data']['maneuver'] = NULL;
			$instrumentsData['AIS'][$vehicle]['cachedTime']['maneuver'] = $now;
		};
		if(isset($inInstrumentsData['raim'])) $instrumentsData['AIS'][$vehicle]['data']['raim'] = (bool)filter_var($inInstrumentsData['raim'],FILTER_SANITIZE_NUMBER_INT); 	// RAIM-flag Receiver autonomous integrity monitoring (RAIM) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use. See Table 50
		if(isset($inInstrumentsData['radio'])) $instrumentsData['AIS'][$vehicle]['data']['radio'] = (string)$inInstrumentsData['radio']; 	// Communication state
		$instrumentsDataUpdated['AIS'] = TRUE;
		//break; 	//comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1. Но gpsdAISd не имеет дела с netAIS?
	case 5: 	// http://www.e-navigation.nl/content/ship-static-and-voyage-related-data
	case 24: 	// Vendor ID не поддерживается http://www.e-navigation.nl/content/static-data-report
		//echo "JSON inInstrumentsData: \n"; print_r($inInstrumentsData); echo "\n";
		if(isset($inInstrumentsData['imo'])) {
			$instrumentsData['AIS'][$vehicle]['data']['imo'] = (string)$inInstrumentsData['imo']; 	// IMO number 0 = not available = default – Not applicable to SAR aircraft 0000000001-0000999999 not used 0001000000-0009999999 = valid IMO number; 0010000000-1073741823 = official flag state number.
			if($instrumentsData['AIS'][$vehicle]['data']['imo'] === '0') $instrumentsData['AIS'][$vehicle]['data']['imo'] = NULL;
		}
		if(isset($inInstrumentsData['ais_version'])) $instrumentsData['AIS'][$vehicle]['data']['ais_version'] = (int)filter_var($inInstrumentsData['ais_version'],FILTER_SANITIZE_NUMBER_INT); 	// AIS version indicator 0 = station compliant with Recommendation ITU-R M.1371-1; 1 = station compliant with Recommendation ITU-R M.1371-3 (or later); 2 = station compliant with Recommendation ITU-R M.1371-5 (or later); 3 = station compliant with future editions
		if(isset($inInstrumentsData['callsign'])){
			if($inInstrumentsData['callsign']=='@@@@@@@') $instrumentsData['AIS'][$vehicle]['data']['callsign'] = NULL;
			elseif($inInstrumentsData['callsign']) $instrumentsData['AIS'][$vehicle]['data']['callsign'] = (string)$inInstrumentsData['callsign']; 	// Call sign 7 x 6 bit ASCII characters, @@@@@@@ = not available = default. Craft associated with a parent vessel, should use “A” followed by the last 6 digits of the MMSI of the parent vessel. Examples of these craft include towed vessels, rescue boats, tenders, lifeboats and liferafts.
		}
		if(isset($inInstrumentsData['shipname'])){
			if($inInstrumentsData['shipname']=='@@@@@@@@@@@@@@@@@@@@') $instrumentsData['AIS'][$vehicle]['data']['shipname'] = NULL;
			elseif($inInstrumentsData['shipname']) $instrumentsData['AIS'][$vehicle]['data']['shipname'] = filter_var($inInstrumentsData['shipname'],FILTER_SANITIZE_STRING); 	// Maximum 20 characters 6 bit ASCII, as defined in Table 47 “@@@@@@@@@@@@@@@@@@@@” = not available = default. The Name should be as shown on the station radio license. For SAR aircraft, it should be set to “SAR AIRCRAFT NNNNNNN” where NNNNNNN equals the aircraft registration number.
		}
		if(isset($inInstrumentsData['shiptype'])) $instrumentsData['AIS'][$vehicle]['data']['shiptype'] = (int)filter_var($inInstrumentsData['shiptype'],FILTER_SANITIZE_NUMBER_INT); 	// Type of ship and cargo type 0 = not available or no ship = default 1-99 = as defined in § 3.3.2 100-199 = reserved, for regional use 200-255 = reserved, for future use Not applicable to SAR aircraft
		//echo "inInstrumentsData['shiptype']={$inInstrumentsData['shiptype']}; shiptype={$instrumentsData['AIS'][$vehicle]['data']['shiptype']};\n\n";
		//if(isset($inInstrumentsData['shiptype_text'])) $instrumentsData['AIS'][$vehicle]['data']['shiptype_text'] = filter_var($inInstrumentsData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
		//echo "inInstrumentsData['shiptype_text']={$inInstrumentsData['shiptype_text']}; shiptype_text={$instrumentsData['AIS'][$vehicle]['data']['shiptype_text']};\n\n";
		if(isset($inInstrumentsData['to_bow'])) $instrumentsData['AIS'][$vehicle]['data']['to_bow'] = (float)filter_var($inInstrumentsData['to_bow'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position. Also indicates the dimension of ship (m) (see Fig. 42 and § 3.3.3) For SAR aircraft, the use of this field may be decided by the responsible administration. If used it should indicate the maximum dimensions of the craft. As default should A = B = C = D be set to “0”
		if(isset($inInstrumentsData['to_stern'])) $instrumentsData['AIS'][$vehicle]['data']['to_stern'] = (float)filter_var($inInstrumentsData['to_stern'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
		if(isset($inInstrumentsData['to_port'])) $instrumentsData['AIS'][$vehicle]['data']['to_port'] = (float)filter_var($inInstrumentsData['to_port'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
		if(isset($inInstrumentsData['to_starboard'])) $instrumentsData['AIS'][$vehicle]['data']['to_starboard'] = (float)filter_var($inInstrumentsData['to_starboard'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
		if($instrumentsData['AIS'][$vehicle]['data']['to_bow']===0 and $instrumentsData['AIS'][$vehicle]['data']['to_stern']===0 and $instrumentsData['AIS'][$vehicle]['data']['to_port']===0 and $instrumentsData['AIS'][$vehicle]['data']['to_starboard']===0){
			$instrumentsData['AIS'][$vehicle]['data']['to_bow'] = null;
			$instrumentsData['AIS'][$vehicle]['data']['to_stern'] = null;
			$instrumentsData['AIS'][$vehicle]['data']['to_port'] = null;
			$instrumentsData['AIS'][$vehicle]['data']['to_starboard'] = null;
		}
		if(isset($inInstrumentsData['epfd'])) {
			$instrumentsData['AIS'][$vehicle]['data']['epfd'] = (int)filter_var($inInstrumentsData['epfd'],FILTER_SANITIZE_NUMBER_INT); 	// Type of electronic position fixing device. 0 = undefined (default) 1 = GPS 2 = GLONASS 3 = combined GPS/GLONASS 4 = Loran-C 5 = Chayka 6 = integrated navigation system 7 = surveyed 8 = Galileo, 9-14 = not used 15 = internal GNSS
			if($instrumentsData['AIS'][$vehicle]['data']['epfd'] == 0) $instrumentsData['AIS'][$vehicle]['data']['epfd'] = null;
		}
		//if(isset($inInstrumentsData['epfd_text'])) $instrumentsData['AIS'][$vehicle]['data']['epfd_text'] = (string)$inInstrumentsData['epfd_text']; 	// 
		if(isset($inInstrumentsData['eta'])) {
			$instrumentsData['AIS'][$vehicle]['data']['eta'] = (string)$inInstrumentsData['eta']; 	// ETA Estimated time of arrival; MMDDHHMM UTC Bits 19-16: month; 1-12; 0 = not available = default  Bits 15-11: day; 1-31; 0 = not available = default Bits 10-6: hour; 0-23; 24 = not available = default Bits 5-0: minute; 0-59; 60 = not available = default For SAR aircraft, the use of this field may be decided by the responsible administration
			if($instrumentsData['AIS'][$vehicle]['data']['eta'] === '0') $instrumentsData['AIS'][$vehicle]['data']['eta'] = null;
		}
		if($inInstrumentsData['draught']){	// осадка не может быть 0
		 	// данные уже приведены к человеческому виду
			if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['draught'] = $inInstrumentsData['draught']; 	// осадка в метрах
			else $instrumentsData['AIS'][$vehicle]['data']['draught'] = (float)filter_var($inInstrumentsData['draught'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Maximum present static draught In m ( 1/10 m, 255 = draught 25.5 m or greater, 0 = not available = default; in accordance with IMO Resolution A.851 Not applicable to SAR aircraft, should be set to 0)
			if($instrumentsData['AIS'][$vehicle]['data']['draught'] == 0) $instrumentsData['AIS'][$vehicle]['data']['draught'] = null;
			//echo "inInstrumentsData['draught']={$inInstrumentsData['draught']}; draught={$instrumentsData['AIS'][$vehicle]['data']['draught']};\n\n";
		}
		if(isset($inInstrumentsData['destination'])){
			$instrumentsData['AIS'][$vehicle]['data']['destination'] = filter_var($inInstrumentsData['destination'],FILTER_SANITIZE_STRING); 	// Destination Maximum 20 characters using 6-bit ASCII; @@@@@@@@@@@@@@@@@@@@ = not available For SAR aircraft, the use of this field may be decided by the responsible administration
			if($instrumentsData['AIS'][$vehicle]['data']['destination'] == '@@@@@@@@@@@@@@@@@@@@') $instrumentsData['AIS'][$vehicle]['data']['destination'] = null;
			$instrumentsData['AIS'][$vehicle]['cachedTime']['destination'] = $now;
		}
		if(isset($inInstrumentsData['dte'])) {
			$instrumentsData['AIS'][$vehicle]['data']['dte'] = (int)filter_var($inInstrumentsData['dte'],FILTER_SANITIZE_NUMBER_INT); 	// DTE Data terminal equipment (DTE) ready (0 = available, 1 = not available = default) (see § 3.3.1)
			if($instrumentsData['AIS'][$vehicle]['data']['dte'] == 1) $instrumentsData['AIS'][$vehicle]['data']['dte'] = null;
		}
		$instrumentsDataUpdated['AIS'] = TRUE;
		//break; 	// comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1
	case 6: 	// http://www.e-navigation.nl/asm  http://192.168.10.10/gpsd/AIVDM.adoc
	case 8: 	// 
		//echo "JSON inInstrumentsData:\n"; print_r($inInstrumentsData); echo "\n";
		if(isset($inInstrumentsData['dac'])){
			$instrumentsData['AIS'][$vehicle]['data']['dac'] = (string)$inInstrumentsData['dac']; 	// Designated Area Code
			$instrumentsData['AIS'][$vehicle]['cachedTime']['dac'] = $now;
		}
		if(isset($inInstrumentsData['fid'])) $instrumentsData['AIS'][$vehicle]['data']['fid'] = (string)$inInstrumentsData['fid']; 	// Functional ID
		if(isset($inInstrumentsData['vin'])) $instrumentsData['AIS'][$vehicle]['data']['vin'] = (string)$inInstrumentsData['vin']; 	// European Vessel ID
		if(isset($inInstrumentsData['length'])){
			if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['length'] = $inInstrumentsData['length']/10;	// Длина всё равно в дециметрах!!!
			else $instrumentsData['AIS'][$vehicle]['data']['length'] = (float)filter_var($inInstrumentsData['length'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Length of ship in m
			if(!$instrumentsData['AIS'][$vehicle]['data']['length']) $instrumentsData['AIS'][$vehicle]['data']['length'] = null;
		}
		if(isset($inInstrumentsData['beam'])) {
			if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['beam'] = $inInstrumentsData['beam']/10;	// Ширина всё равно в дециметрах!!!
			else $instrumentsData['AIS'][$vehicle]['data']['beam'] = (float)filter_var($inInstrumentsData['beam'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Beam of ship in m ширина, длина бимса.
			if(!$instrumentsData['AIS'][$vehicle]['data']['beam']) $instrumentsData['AIS'][$vehicle]['data']['beam'] = null;
		}
		if(isset($inInstrumentsData['shiptype']) and !$instrumentsData['AIS'][$vehicle]['data']['shiptype']) $instrumentsData['AIS'][$vehicle]['data']['shiptype'] = (string)$inInstrumentsData['shiptype']; 	// Ship/combination type ERI Classification В какой из посылок тип правильный - неизвестно, поэтому будем брать только из одной
		//if(isset($inInstrumentsData['shiptype_text']) and !$instrumentsData['AIS'][$vehicle]['data']['shiptype_text'])$instrumentsData['AIS'][$vehicle]['data']['shiptype_text'] = filter_var($inInstrumentsData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
		if(isset($inInstrumentsData['hazard'])) $instrumentsData['AIS'][$vehicle]['data']['hazard'] = (int)filter_var($inInstrumentsData['hazard'],FILTER_SANITIZE_NUMBER_INT); 	// Hazardous cargo | 0 | 0 blue cones/lights | 1 | 1 blue cone/light | 2 | 2 blue cones/lights | 3 | 3 blue cones/lights | 4 | 4 B-Flag | 5 | Unknown (default)
		//if(isset($inInstrumentsData['hazard_text'])) $instrumentsData['AIS'][$vehicle]['data']['hazard_text'] = filter_var($inInstrumentsData['hazard_text'],FILTER_SANITIZE_STRING); 	// 
		if($inInstrumentsData['draught'] and !$instrumentsData['AIS'][$vehicle]['data']['draught']) {
			if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['draught'] = $inInstrumentsData['draught']/100;	// Осадка всё равно в сантиметрах!!!
			else $instrumentsData['AIS'][$vehicle]['data']['draught'] = (float)filter_var($inInstrumentsData['draught'],FILTER_SANITIZE_NUMBER_INT)/100; 	// Draught in m ( 1-200 * 0.01m, default 0) осадка
			if(!$instrumentsData['AIS'][$vehicle]['data']['draught']) $instrumentsData['AIS'][$vehicle]['data']['draught'] = null;
		}
		if(isset($inInstrumentsData['loaded'])) {
			$instrumentsData['AIS'][$vehicle]['data']['loaded'] = (int)filter_var($inInstrumentsData['loaded'],FILTER_SANITIZE_NUMBER_INT); 	// Loaded/Unloaded | 0 | N/A (default) | 1 | Unloaded | 2 | Loaded
			if(!$instrumentsData['AIS'][$vehicle]['data']['loaded']) $instrumentsData['AIS'][$vehicle]['data']['loaded'] = null;
		}
		//if(isset($inInstrumentsData['loaded_text'])) $instrumentsData['AIS'][$vehicle]['data']['loaded_text'] = filter_var($inInstrumentsData['loaded_text'],FILTER_SANITIZE_STRING); 	// 
		if(isset($inInstrumentsData['speed_q'])) $instrumentsData['AIS'][$vehicle]['data']['speed_q'] = (int)filter_var($inInstrumentsData['speed_q'],FILTER_SANITIZE_NUMBER_INT); 	// Speed inf. quality 0 = low/GNSS (default) 1 = high
		if(isset($inInstrumentsData['course_q'])) $instrumentsData['AIS'][$vehicle]['data']['course_q'] = (int)filter_var($inInstrumentsData['course_q'],FILTER_SANITIZE_NUMBER_INT); 	// Course inf. quality 0 = low/GNSS (default) 1 = high
		if(isset($inInstrumentsData['heading_q'])) $instrumentsData['AIS'][$vehicle]['data']['heading_q'] = (int)filter_var($inInstrumentsData['heading_q'],FILTER_SANITIZE_NUMBER_INT); 	// Heading inf. quality 0 = low/GNSS (default) 1 = high
		$instrumentsDataUpdated['AIS'] = TRUE;
		//break; 	// comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1
	case 14:	// Safety related broadcast message https://www.e-navigation.nl/content/safety-related-broadcast-message
		if(isset($inInstrumentsData['text'])) $instrumentsData['AIS'][$vehicle]['data']['safety_related_text'] = filter_var($inInstrumentsData['text'],FILTER_SANITIZE_STRING); 	// 
		//$instrumentsDataUpdated['AIS'] = TRUE;	// это сообщение приходит только вместе с сообщением 1, поэтому не будем по его приёме указывать на этот факт. Тогда содержание этого сообщения будет отослано клиентам только тогда, когда будет отослано (следующее) сообщение 1
		break;
	}; // end switch($inInstrumentsData['type'])
	
	if(!$instrumentsData['AIS'][$vehicle]['data']['length'] and $instrumentsData['AIS'][$vehicle]['data']['to_bow'] and $instrumentsData['AIS'][$vehicle]['data']['to_stern']){
		$instrumentsData['AIS'][$vehicle]['data']['length'] = $instrumentsData['AIS'][$vehicle]['data']['to_bow'] + $instrumentsData['AIS'][$vehicle]['data']['to_stern'];
	};
	if(!$instrumentsData['AIS'][$vehicle]['data']['beam'] and $instrumentsData['AIS'][$vehicle]['data']['to_port'] and $instrumentsData['AIS'][$vehicle]['data']['to_starboard']){
		$instrumentsData['AIS'][$vehicle]['data']['beam'] = $instrumentsData['AIS'][$vehicle]['data']['to_port'] + $instrumentsData['AIS'][$vehicle]['data']['to_starboard'];
	};
	//echo "\n instrumentsData[AIS][$vehicle]['data']:\n"; print_r($instrumentsData['AIS'][$vehicle]['data']);echo "\n";

	if(substr($vehicle,0,2)=='97'){
		//echo "\n instrumentsData[AIS][$vehicle]['data']:\n"; print_r($instrumentsData['AIS'][$vehicle]['data']);echo "\n";
		switch(substr($vehicle,0,3)){
		case '970':	// AIS SART
		case '972':	// AIS MOB
		case '974':	// AIS EPIRB
			//echo "vehicle=$vehicle не должен быть отослан как AIS. instrumentsData:\n"; //print_r($instrumentsData['AIS'][$vehicle]['data']);echo "\n";
			
			if($instrumentsData['ALARM']['MOB']['status']){	//echo "режим MOB есть. instrumentsData['ALARM']['MOB']:\n";// print_r($instrumentsData['ALARM']['MOB']);echo "\n";
				// Если эта цель AIS уже есть как точка в имеющемся объекте MOB - игнорируем.
				// По AIS приходит только одна точка с данным mmsi, так что она идентифицируется этим mmsi
				// А удалять из $instrumentsData['AIS'] не надо?
				$i = null; $maxi = count($instrumentsData['ALARM']['MOB']['points']);
				for($i=0; $i<$maxi; $i++){
					if(isset($instrumentsData['ALARM']['MOB']['points'][$i]['mmsi'])
					 and $instrumentsData['ALARM']['MOB']['points'][$i]['mmsi']==$instrumentsData['AIS'][$vehicle]['data']['mmsi']) break;
				};
				//echo "i=$i; maxi=$maxi;\n";
				// Признак изменения передаётся, только если изменились координаты или текст
				// а если они не меняются, то тот, кто пропустил сообщение - больше его не получит.
				// Правильно ли это? Не зря по AIS оно передаётся каждую минуту?
				// Но локальные клиенты получат сообщение, потому что здесь MOB есть,
				// а у них - нет. А вот netAIS - больше не получит.
				// netAIS получит, потому что получает данные по POLL, и они ему отдаются все в любом случае.
				if($i<$maxi){	//echo "такая точка №$i с mmsi={$instrumentsData['AIS'][$vehicle]['data']['mmsi']}; уже есть, обновим.   \n"; 
					$instrumentsData['ALARM']['MOB']['status'] = true;
					// Обновлять будем, если координаты старой и новой не совпадают.
					if(isset($instrumentsData['AIS'][$vehicle]['data']['lat']) and ($instrumentsData['AIS'][$vehicle]['data']['lat'] !== $instrumentsData['ALARM']['MOB']['points'][$i]['coordinates'][1])
					 and isset($instrumentsData['AIS'][$vehicle]['data']['lon']) and ($instrumentsData['AIS'][$vehicle]['data']['lon'] !== $instrumentsData['ALARM']['MOB']['points'][$i]['coordinates'][0])){
						$instrumentsData['ALARM']['MOB']['points'][$i]['coordinates']=array($instrumentsData['AIS'][$vehicle]['data']['lon'],$instrumentsData['AIS'][$vehicle]['data']['lat']);
						$instrumentsData['ALARM']['MOB']['timestamp'] = $instrumentsData['AIS'][$vehicle]['timestamp'];
					};
					if(isset($instrumentsData['AIS'][$vehicle]['data']['safety_related_text']) and ($instrumentsData['AIS'][$vehicle]['data']['safety_related_text'] !== $instrumentsData['ALARM']['MOB']['points'][$i]['safety_related_text'])){
						$instrumentsData['ALARM']['MOB']['points'][$i]['safety_related_text']=$instrumentsData['AIS'][$vehicle]['data']['safety_related_text'];
						$instrumentsDataUpdated['ALARM'] = true;	// timestamp там нет
					};
				}
				else{	//echo "такой точки с mmsi={$instrumentsData['AIS'][$vehicle]['data']['mmsi']}; ещё нет, создадим и поднимем тревогу    \n";
					if(!isset($instrumentsData['AIS'][$vehicle]['data']['lat']) or !isset($instrumentsData['AIS'][$vehicle]['data']['lon'])){
						// AIS MOB может посылать сигнал до того, как получит положение от ГПС.
						// Поэтому дадим такой точке свои координаты
						$curr_tpv = makeWATCH("TPV");
						if($curr_tpv["lat"] and $curr_tpv["lon"]){
							$instrumentsData['AIS'][$vehicle]['data']['lon'] = $curr_tpv["lon"];
							$instrumentsData['AIS'][$vehicle]['data']['lat'] = $curr_tpv["lat"];
						}
						else {
							$instrumentsData['AIS'][$vehicle]['data']['lon'] = null;
							$instrumentsData['AIS'][$vehicle]['data']['lat'] = null;
						};
					};
					$instrumentsData['ALARM']['MOB']['points'][] = array(
						'coordinates'=> array($instrumentsData['AIS'][$vehicle]['data']['lon'],$instrumentsData['AIS'][$vehicle]['data']['lat']),	// "The first two elements are longitude and latitude" https://datatracker.ietf.org/doc/html/rfc7946#section-3.1.1
						'mmsi'=>$instrumentsData['AIS'][$vehicle]['data']['mmsi'],
						'safety_related_text'=>$instrumentsData['AIS'][$vehicle]['data']['safety_related_text']
					);
					$instrumentsData['ALARM']['MOB']['status'] = true;
					$instrumentsData['ALARM']['MOB']['timestamp'] = $instrumentsData['AIS'][$vehicle]['timestamp'];
					$instrumentsDataUpdated['ALARM'] = true;
				};
				unset($instrumentsData['AIS'][$vehicle]);	// удалим этот mmsi из данных AIS
			}
			else{	//echo "режима MOB нет, поднимем тревогу для точки с mmsi={$instrumentsData['AIS'][$vehicle]['data']['mmsi']};           \n";
				if(!isset($instrumentsData['AIS'][$vehicle]['data']['lat']) or !isset($instrumentsData['AIS'][$vehicle]['data']['lon'])){
					// AIS MOB может посылать сигнал до того, как получит положение от ГПС.
					// Поэтому дадим такой точке свои координаты
					$curr_tpv = makeWATCH("TPV");
					if($curr_tpv["lat"] and $curr_tpv["lon"]){
						$instrumentsData['AIS'][$vehicle]['data']['lon'] = $curr_tpv["lon"];
						$instrumentsData['AIS'][$vehicle]['data']['lat'] = $curr_tpv["lat"];
					}
					else {
						$instrumentsData['AIS'][$vehicle]['data']['lon'] = null;
						$instrumentsData['AIS'][$vehicle]['data']['lat'] = null;
					};
				};
				$instrumentsData['ALARM']['MOB'] = array(
					'class'=>'MOB',
					'status'=>true,
					'points'=> array(
						array(
							'coordinates'=> array($instrumentsData['AIS'][$vehicle]['data']['lon'],$instrumentsData['AIS'][$vehicle]['data']['lat']),	// "The first two elements are longitude and latitude" https://datatracker.ietf.org/doc/html/rfc7946#section-3.1.1
							'mmsi'=>$instrumentsData['AIS'][$vehicle]['data']['mmsi'],
							'safety_related_text'=>$instrumentsData['AIS'][$vehicle]['data']['safety_related_text']
						)
					),
					'timestamp'=>$instrumentsData['AIS'][$vehicle]['timestamp']
				);
				// Если удалить этот vehacle из $instrumentsData['AIS'], то потеряется его timestamp
				// и старые сообщения будут обрабатываться как новые.
				// с другой стороны, а откуда там старые сообщения? А кто их знает? Реальных данных нет.
				// Но если не удалять - будет пурга с порядком прихода сообщений, и вместе с MOB
				// будет показываться кораблик.
				unset($instrumentsData['AIS'][$vehicle]);	// удалим этот mmsi из данных AIS
				$instrumentsDataUpdated['ALARM'] = true;
			};
			
			//echo "vehicle=$vehicle; instrumentsDataUpdated['ALARM']={$instrumentsDataUpdated['ALARM']};         \n";
			//if($instrumentsDataUpdated['ALARM']) echo "vehicle=$vehicle; instrumentsDataUpdated['ALARM']={$instrumentsDataUpdated['ALARM']};         \n";
			return $instrumentsDataUpdated;	// вообще больше никакая обработка не нужна? Можно протормозить с контролем столкновений и актуальностью данных?
			//break 2;	// потому что именно для этого case не нужно считать контроль столкновений, когда как для всего остального - нужно
		default:
		};
	};

	// Посчитаем данные для контроля столкновений:
	list($instrumentsData['AIS'][$vehicle]['collisionArea'],$instrumentsData['AIS'][$vehicle]['squareArea']) = updCollisionArea($instrumentsData['AIS'][$vehicle]['data'],$collisionDistance);	// fCollisions.php
	//echo "\n Calculated collision areas for $vehicle \n";
	break;
case 'MOB':	// есть один объект MOB в $instrumentsData['ALARM']
	if($inInstrumentsData['timestamp']<=$instrumentsData['ALARM']['MOB']['timestamp']) break;
	$instrumentsData['ALARM']['MOB']['class'] = 'MOB';
	$instrumentsData['ALARM']['MOB']['status'] = $inInstrumentsData['status'];
	foreach($inInstrumentsData['points'] as &$point){
		if(!$point['mmsi']) $point['mmsi'] = $boatInfo['mmsi'];	// если там точки без mmsi - то это наши точки
		// А что делать, есди там нет координат? Думаю, ничего: такая точка не покажется, но все её атрибуты будут.
		// Если же MOB включается, но координат нет, а точка от себя, то укажем свои координаты
		// На самом деле - это пустое, потому что скорее всего, если клиент прислал MOB без
		// координат, то и у меня координат уже нет.
		// Но может быть, что у клиента не было связи с сервером, когда нажали кнопку MOB.
		// Тогда у меня координаты есть, а у него - протухли.
		//echo "[updInstrumentsData] MOB point:   ";print_r($point);echo "\n";
		if(($inInstrumentsData['status'] and $point['mmsi'] == $boatInfo['mmsi']) and (!$point['coordinates'] or !isset($point['coordinates'][0]) or !isset($point['coordinates'][1]))){
			$last = 0;
			foreach($instrumentsData['TPV'] as $device => $data){
				if(@$data['cachedTime']['lon']<=$last) continue;	// что лучше -- старый 3D fix, или свежий 2d fix?
				if(isset($data['lon']) and isset($data['lat'])){
					$point['coordinates'] = array();
					$point['coordinates'][] = $data['lon'];
					$point['coordinates'][] = $data['lat'];
				};
				$last = @$data['cachedTime']['lon'];
			};
			//echo "[updInstrumentsData] MOB point with self coordinates:   ";print_r($point);echo "\n";
		};
	};
	$instrumentsData['ALARM']['MOB']['points'] = $inInstrumentsData['points'];
	$instrumentsData['ALARM']['MOB']['timestamp'] = $inInstrumentsData['timestamp'];
	$instrumentsData['ALARM']['MOB']['source'] = $inInstrumentsData['source'];
	if(!$instrumentsData['ALARM']['MOB']['source']) $instrumentsData['ALARM']['MOB']['source'] = '972'.substr($boatInfo['mmsi'],3);
	$instrumentsDataUpdated['ALARM'] = true;
	//echo "instrumentsDataUpdated['ALARM']={$instrumentsDataUpdated['ALARM']};             \n";
	//echo "recieved new MOB data:            "; print_r($instrumentsData['ALARM']['MOB']);
	break;
};

// Проверим актуальность всех данных
$instrumentsDataUpdated = array_merge($instrumentsDataUpdated,chkFreshOfData());	// плоские массивы

// Проверим опасность столкновений
// при каждом поступлении любых данных? Проверять надо при поступлении TPV, netAIS и AIS. Т.е., в общем-то, всех.
$instrumentsDataUpdated = array_merge($instrumentsDataUpdated,chkCollisions());	// плоские массивы

$dataUpdated = time();	// Обозначим когда данные были обновлены

//echo "\n Data Updated: "; print_r($instrumentsDataUpdated);
//echo "\n instrumentsData\n"; print_r($instrumentsData['ALARM']);
//echo "\n instrumentsDataUpdated AIS:"; print_r($instrumentsDataUpdated['AIS']); echo "\n";
//echo "instrumentsDataUpdated['ALARM']={$instrumentsDataUpdated['ALARM']};\n";
return $instrumentsDataUpdated;
} // end function updInstrumentsData

function chkFreshOfData(){
/* Проверим актуальность всех данных */
global $instrumentsData,$gpsdProxyTimeouts,$boatInfo;
$instrumentsDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.
$TPVtimeoutMultiplexor = 30;	// через сколько таймаутов свойство удаляется совсем
$dataLongTimeOutFlag = false;
//print_r($instrumentsData);
$now = time();
foreach($instrumentsData as $class => $devices){
	switch($class){
	case 'TPV':
	case 'ATT':
		foreach($devices as $device => $data){
			foreach($data['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
				//echo "type=$type; data['data'][$type]={$data['data'][$type]}; gpsdProxyTimeouts[$class][$type]={$gpsdProxyTimeouts[$class][$type]}; now=$now; cachedTime=$cachedTime;\n";
				if((!is_null(@$data['data'][$type])) and @$gpsdProxyTimeouts[$class][$type] and (($now - $cachedTime) > @$gpsdProxyTimeouts[$class][$type])) {	// Notice if on $gpsdProxyTimeouts not have this $type
					$instrumentsData[$class][$device]['data'][$type] = null;
					/* // Это не нужно, потому что collision area для себя считается каждый раз непосредственно перед употреблением
					if(in_array($type,array('lat','lon','track','speed'))){	// удалим данные для контроля столкновений, если протухли исходные
						unset($boatInfo['collisionArea']);
						unset($boatInfo['squareArea']);
						echo "\n Removed self collision area \n";
					}
					*/
					$instrumentsDataUpdated[$class] = TRUE;
					//echo "Данные $class:$type от устройства $device протухли на ".($now - $cachedTime)." сек            \n";
				}
				elseif((is_null($data['data'][$type])) and $gpsdProxyTimeouts[$class][$type] and (($now - $cachedTime) > ($TPVtimeoutMultiplexor*$gpsdProxyTimeouts[$class][$type]))) {	// Notice if on $gpsdProxyTimeouts not have this $type
					unset($instrumentsData[$class][$device]['data'][$type]);
					unset($instrumentsData[$class][$device]['cachedTime'][$type]);
					$dataLongTimeOutFlag = true;
					$instrumentsDataUpdated[$class] = TRUE;
					//echo "Данные $class:$type от устройства $device совсем протухли на ".($now - $cachedTime)." сек   \n";
				};
			};
			//echo "instrumentsData[$class][$device] после очистки:"; print_r($instrumentsData[$class][$device]['data']);
			// Удалим все данные устройства, которое давно ничего не давало из контролируемых на протухание параметров
			if($dataLongTimeOutFlag and $instrumentsData[$class][$device]['cachedTime']) {
				$toDel = TRUE;
				// поищем, есть ли среди кешированных контролируемые параметры
				// Если нет, это значит, что все контролируемые параметры были удалены выше
				// как "совсем протухли", и остались только неконтролируемые.
				// Что позволяет считать, что это устройства "давно ничего не давало".
				// Однако, их может не быть ещё, а не уже, поэтому нужен флаг
				foreach($instrumentsData[$class][$device]['cachedTime'] as $type => $cachedTime){	
					if(@$gpsdProxyTimeouts[$class][$type]) {
						$toDel = FALSE;
						break;
					};
				};
				if($toDel) {	// 
					unset($instrumentsData[$class][$device]); 	// 
					$instrumentsDataUpdated[$class] = TRUE;
					//echo "All $class data of device $device purged by the long silence.                        \n";
				};
			};
		};
		break;
	case 'AIS':
		foreach($instrumentsData['AIS'] as $id => $vehicle){
			//echo "[chkFreshOfData] AIS id=$id;\n";
				if(isset($gpsdProxyTimeouts['AIS']['noVehicle']) and isset($vehicle['timestamp']) and (($now - $vehicle['timestamp'])>$gpsdProxyTimeouts['AIS']['noVehicle'])) {
				unset($instrumentsData['AIS'][$id]); 	// удалим цель, последний раз обновлявшуюся давно
				$instrumentsDataUpdated['AIS'] = TRUE;
				//echo "Данные AIS для судна ".$id." протухли на ".($now - $vehicle['timestamp'])." сек при норме {$gpsdProxyTimeouts['AIS']['noVehicle']}       \n";
				continue;	// к следующей цели AIS
			};
			if($instrumentsData['AIS'][$id]['cachedTime']){ 	// поищем, не протухло ли чего
				foreach($instrumentsData['AIS'][$id]['cachedTime'] as $type => $cachedTime){
					if(!is_null($vehicle['data'][$type]) and $gpsdProxyTimeouts['AIS'][$type] and (($now - $cachedTime) > $gpsdProxyTimeouts['AIS'][$type])) {
						$instrumentsData['AIS'][$id]['data'][$type] = null;
						if(in_array($type,array('lat','lon','course','heading','speed'))){	// удалим данные для контроля столкновений, если протухли исходные
							list($instrumentsData['AIS'][$id]['collisionArea'],$instrumentsData['AIS'][$id]['squareArea']) = updCollisionArea($instrumentsData['AIS'][$id]['data'],$collisionDistance);	// fCollisions.php
							//echo "\n Re-calculate collision area for $id \n";
						}
						$instrumentsDataUpdated['AIS'] = TRUE;
						//echo "Данные AIS ".$type." для судна ".$id." протухли на ".($now - $cachedTime)." сек                     \n";
					}
					elseif(is_null($vehicle['data'][$type]) and $gpsdProxyTimeouts['AIS'][$type] and (($now - $cachedTime) > (2*$gpsdProxyTimeouts['AIS'][$type]))) {
						unset($instrumentsData['AIS'][$id]['data'][$type]);
						unset($instrumentsData['AIS'][$id]['cachedTime'][$type]);
						if(in_array($type,array('lat','lon','course','heading','speed'))){	// удалим данные для контроля столкновений, если протухли исходные
							list($instrumentsData['AIS'][$id]['collisionArea'],$instrumentsData['AIS'][$id]['squareArea']) = updCollisionArea($instrumentsData['AIS'][$id]['data'],$collisionDistance);	// fCollisions.php
							//echo "\n Re-calculate collision area for $id \n";
						}
						$instrumentsDataUpdated['AIS'] = TRUE;
						//echo "Данные AIS ".$type." для судна ".$id." совсем протухли на ".($now - $cachedTime)." сек                     \n";
					};
				};
			};
		};
		break;
	};
};
return $instrumentsDataUpdated;
} // end function chkFreshOfData


function dataSourceSave(){
/**/
global $instrumentsData,$backupFileName,$backupTimeout,$lastBackupSaved;

if((time()-$lastBackupSaved)>$backupTimeout){
	file_put_contents($backupFileName,json_encode($instrumentsData,JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
	$lastBackupSaved = time();
}
} // end function savepsdData

function makeAIS(){
/* делает объект ais */
$ais = array('class' => 'AIS');	// это не вполне правильный класс, но ничему не противоречит. Теперь уже правильный. Когда изменилось?
$ais['ais'] = makeAISlist();
return $ais;
} // end function makeAIS

function makeSELF(){
/* делает объект self - данные о своём судне */
global $boatInfo;
$self = $boatInfo;
unset($self['collisionArea']);
unset($self['squareArea']);
$self['class'] = 'SELF';
return $self;
} // end function makeSELF


function makeAISlist(){
/* делает массив ais */
global $instrumentsData;

$ais = array();
if($instrumentsData['AIS']) {
	foreach($instrumentsData['AIS'] as $vehicle => $data){
		$ais[$vehicle] = $data['data'];
		$ais[$vehicle]["timestamp"] = $data["timestamp"];		
		//$ais[$vehicle]['collisionArea'] = $data['collisionArea'];	///////// for collision test purpose /////////
		//if($data['data']['mmsi'] === '230108610') echo "lon={$ais[$data['data']['mmsi']]['lon']}; lat={$ais[$data['data']['mmsi']]['lat']};\n\n";
		//if($data['data']['mmsi'] === '230985490') echo "course={$ais[$data['data']['mmsi']]['course']}; heading={$ais[$data['data']['mmsi']]['heading']};\n\n";
	};
};
return $ais;
} // end function makeAISlist

function makePOLL($subscribes=array()){
/* Из глобального $instrumentsData формирует массив ответа на ?POLL протокола gpsd
*/
global $instrumentsData,$dataUpdated,$minSocketTimeout;

//echo "\n[makePOLL] subscribes:"; print_r($subscribes); echo "\n";
$POLL = array(	// данные для передачи клиенту как POLL, в формате gpsd
	"class" => "POLL",
	"time" => time(),
	"active" => 0,
	"tpv" => array(),
	"att" => array(),
	"sky" => array(),	// обязательно по спецификации, пусто
);
if((time()-$dataUpdated)>=$minSocketTimeout){	// давно не получали данных
	//echo "POLL: данные были получены ".(time()-$dataUpdated)." сек. назад.                   \n";
	updAndPrepare();	// проверим кеш на предмет протухших данных
}
//echo "\n [makePOLL] instrumentsData\n"; print_r($instrumentsData['TPV']);
foreach($subscribes as $subscribe=>$v){
	switch($subscribe){
	case "TPV":
		if($instrumentsData['TPV']){
			foreach($instrumentsData['TPV'] as $device => $data){
				$POLL["active"] ++;
				$POLL["tpv"][] = $data['data'];
			}
		}
		break;
	case "ATT":
		if($instrumentsData['ATT']){
			foreach($instrumentsData['ATT'] as $device => $data){
				$POLL["active"] ++;
				$POLL["att"][] = $data['data'];
			}
		}
		break;
	case "AIS":
		if($instrumentsData['AIS'] and (strpos($subscribe,"AIS")!==false or !$subscribe)){
			$POLL["ais"] = makeAISlist();
		}
		break;
	case "ALARM":
		// Не правильней было бы $POLL["alarm"]["mob"]? Но тогда разборщики (какие?)
		// заточенные на два уровня, обломятся.
		if($instrumentsData['ALARM']["MOB"]){
			$POLL["mob"] = $instrumentsData['ALARM']["MOB"];
		}
		if($instrumentsData['ALARM']["collisions"]){
			$POLL["collisions"] = $instrumentsData['ALARM']["collisions"];
		}
		break;
	case "SELF":
		$POLL["self"] = makeSELF();
		break;
	case "WPT":
		$POLL["WPT"] = makeWPT();
		break;
	};
};
//echo "\n [makePOLL] подготовленный POLL:"; print_r($POLL); echo "\n";
return $POLL;
}; // end function makePOLL


function makeWATCH($class='TPV'){
/* Из глобального $instrumentsData формирует массив ответа потока ?WATCH протокола gpsd
*/
global $instrumentsData;
//echo "instrumentsData: "; print_r($instrumentsData);

// нужно собрать свежие данные от всех устройств в одно "устройство". 
// При этом окажется, что координаты от одного приёмника ГПС, а ошибка этих координат -- от другого, если первый не прислал ошибку
$WATCH = array();
$lasts = array(); $times = array();
if($instrumentsData[$class]){
	foreach($instrumentsData[$class] as $device => $data){
		foreach($data['data'] as $type => $value){
			if($type=='device') continue;	// необязательный параметр. Указать своё устройство?
			if(@$data['cachedTime'][$type]<=@$lasts[$type]) continue;	// что лучше -- старый 3D fix, или свежий 2d fix?
			if($type=='lat' or $type=='lon' or $type=='time') $times[] = $data['cachedTime'][$type];
			// присвоим только свежие значения
			//if($type=='lat' or $type=='lon') continue;
			$WATCH[$type] = $value;
			$lasts[$type] = $data['cachedTime'][$type];
		};
	};
	/*//////// for collision test purpose /////////
	global $boatInfo;
	$WATCH['collisionArea'] = $boatInfo['collisionArea'];	
	$WATCH['collisionSegments'] = $instrumentsData['ALARM']['collisionSegments'];	
	///////// for collision test purpose ////////*/
}
//print_r($times);
if($times) $WATCH['time'] = date(DATE_ATOM,min($times));	// могут быть присланы левые значения времени, или не присланы совсем
else $WATCH['time'] = date(DATE_ATOM,time());
//echo "[makeWATCH] WATCH:      "; print_r($WATCH); echo "\n";;
//if($class == 'ATT'){echo "[makeWATCH] WATCH:      "; print_r($WATCH); echo "\n";};
return $WATCH;
}; // end function makeWATCH


function makeALARM(){
/**/
global $instrumentsData;
$ret = '';
//echo "\n instrumentsData[ALARM]:"; print_r($instrumentsData["ALARM"]);
if(!$instrumentsData["ALARM"]) return $ret;
$ret = array('class'=>'ALARM');
$ret['alarms'] = $instrumentsData["ALARM"];
return $ret;
}; // end function makeALARM


function makeWPT(){
global $instrumentsData;
$ret = '';
//echo "\n instrumentsData[WPT]:"; print_r($instrumentsData["WPT"]);
if(!isset($instrumentsData["WPT"])) return $ret;
$ret = $instrumentsData["WPT"];
$ret['class'] = 'WPT';
return $ret;
}; // end function makeWPT


function navigationStatusEncode($statusText){
$statusText = strtolower($statusText);
$encode = array(
'under way using engine'=>0,
'at anchor'=>1,
'not under command'=>2,
'restricted maneuverability'=>3,
'restricted manoeuverability'=>3,
'constrained by her draught'=>4,
'moored'=>5,
'aground'=>6,
'engaged in fishing'=>7,
'under way sailing'=>8,
'power-driven vessel towing astern'=>11,
'power-driven vessel pushing ahead or towing alongside'=>12,
'AIS-SART is active'=>14
);
$statusCode = $encode[$statusText];
if($statusCode === null)	$statusCode = 15;
return $statusCode;
} // end function navigationStatusEncode


function wsDecode($data){
/* Возвращает:
$decodedData данные или null если фрейм принят не полностью и нечего декодировать, или 
false -- что-то пошло не так, непонятно, что делать
$type тип данных или null, если данные в нескольких фреймах, и это не первый фрейм
$FIN признак последнего фрейма (TRUE) или FALSE, если фрейм не последний
$tail один или несколько склееных фреймов, оставшихся после выделения первого
*/
$decodedData = null; $tail = null; $FIN = null;

// estimate frame type:
$firstByteBinary = sprintf('%08b', ord($data[0])); 	// преобразование первого байта в битовую строку
$secondByteBinary = sprintf('%08b', ord($data[1])); 	// преобразование второго байта в битовую строку
$opcode = bindec(mb_substr($firstByteBinary, 4, 4,'8bit'));	// последние четыре бита первого байта -- в десятичное число из текста
$payloadLength = ord($data[1]) & 127;	// берём как число последние семь бит второго байта

$isMasked = $secondByteBinary[0] == '1';	// первый бит второго байта -- из текстового представления.
if($firstByteBinary[0] == '1') $FIN = 'messageComplete';


switch ($opcode) {
case 1:	// text frame:
	$type = 'text';
	break;
case 2:
	$type = 'binary';
	break;
case 8:	// connection close frame
	$type = 'close';
	break;
case 9:	// ping frame
	$type = 'ping';
	break;
case 10:	// pong frame
	$type = 'pong';
	break;
default:
	$type = null;
};

if ($payloadLength === 126) {
	if (mb_strlen($data,'8bit') < 4) return false;
	$mask = mb_substr($data, 4, 4,'8bit');
	$payloadOffset = 8;
	$dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
} 
elseif ($payloadLength === 127) {
	if (mb_strlen($data,'8bit') < 10) return false;
	$mask = mb_substr($data, 10, 4,'8bit');
	$payloadOffset = 14;
	$tmp = '';
	for ($i = 0; $i < 8; $i++) {
		$tmp .= sprintf('%08b', ord($data[$i + 2]));
	}
	$dataLength = bindec($tmp) + $payloadOffset;
	unset($tmp);
} 
else {
	$mask = mb_substr($data, 2, 4,'8bit');
	$payloadOffset = 6;
	$dataLength = $payloadLength + $payloadOffset;
};

/**
 * We have to check for large frames here. socket_recv cuts at 1024 (65536 65550?) bytes
 * so if websocket-frame is > 1024 bytes we have to wait until whole
 * data is transferd.
 */
//echo "mb_strlen(data)=".mb_strlen($data,'8bit')."; dataLength=$dataLength;\n";
if (mb_strlen($data,'8bit') < $dataLength) {
	//echo "\nwsDecode: recievd ".mb_strlen($data,'8bit')." byte, but frame length $dataLength byte.\n";
	$FIN = 'partFrame';
	$tail = $data;
}
else {
	$tail = mb_substr($data,$dataLength,null,'8bit');

	if($isMasked) {
		//echo "wsDecode: unmasking \n";
		$unmaskedPayload = ''; 
		for ($i = $payloadOffset; $i < $dataLength; $i++) {
			$j = $i - $payloadOffset;
			if (isset($data[$i])) {
				$unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
			}
		}
		$decodedData = $unmaskedPayload;
	} 
	else {
		$payloadOffset = $payloadOffset - 4;
		$decodedData = mb_substr($data, $payloadOffset,'8bit');
	};
};

return array($decodedData,$type,$FIN,$tail);
}; // end function wsDecode


function wsEncode($payload, $type = 'text', $masked = false){
/* https://habr.com/ru/post/209864/ 
Кодирует $payload как один фрейм
*/
if(!$type) $type = 'text';
$frameHead = array();
$payloadLength = mb_strlen($payload,'8bit');

switch ($type) {
case 'text':    // first byte indicates FIN, Text-Frame (10000001):
    $frameHead[0] = 129;
    break;
case 'close':    // first byte indicates FIN, Close Frame(10001000):
    $frameHead[0] = 136;
    break;
case 'ping':    // first byte indicates FIN, Ping frame (10001001):
    $frameHead[0] = 137;
    break;
case 'pong':    // first byte indicates FIN, Pong frame (10001010):
    $frameHead[0] = 138;
    break;
}

// set mask and payload length (using 1, 3 or 9 bytes)
if ($payloadLength > 65535) {
    $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
    $frameHead[1] = ($masked === true) ? 255 : 127;
    for ($i = 0; $i < 8; $i++) {
        $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
    }
    // most significant bit MUST be 0
    if ($frameHead[2] > 127) {
        return array('type' => '', 'payload' => '', 'error' => 'frame too large (1004)');
    }
} 
elseif ($payloadLength > 125) {
    $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
    $frameHead[1] = ($masked === true) ? 254 : 126;
    $frameHead[2] = bindec($payloadLengthBin[0]);
    $frameHead[3] = bindec($payloadLengthBin[1]);
} 
else {
    $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
}

// convert frame-head to string:
foreach (array_keys($frameHead) as $i) {
    $frameHead[$i] = chr($frameHead[$i]);
}
if ($masked === true) {
    // generate a random mask:
    $mask = array();
    for ($i = 0; $i < 4; $i++) {
        $mask[$i] = chr(rand(0, 255));
    }

    $frameHead = array_merge($frameHead, $mask);	// плоские массивы
}
$frame = implode('', $frameHead);

// append payload to frame:
for ($i = 0; $i < $payloadLength; $i++) {
    $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
}

return $frame;
} // end function wsEncode


?>
