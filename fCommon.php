<?php
// createSocketServer
// createSocketClient
// chkSocks

// chkGPSDpresent
// connectToGPSD($gpsdSock)

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
// makeWATCH

// wsDecode
// wsEncode

function createSocketServer($host,$port,$connections=2){
/* создаёт сокет, соединенный с $host,$port на своей машине, для приёма входящих соединений 
в Ubuntu $connections = 0 означает максимально возможное количество соединений, а в Raspbian (Debian?) действительно 0
*/
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$sock) {
	echo "Failed to create server socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	//return FALSE;
	exit('1');
}
for($i=0;$i<100;$i++) {
	$res = @socket_bind($sock, $host, $port);
	if(!$res) {
		echo "Failed to binding to $host:$port by: " . socket_strerror(socket_last_error($sock)) . ", waiting $i\r";
		sleep(1);
	}
	else break;
}
if(!$res) {
	echo "Failed to binding to $host:$port by: " . socket_strerror(socket_last_error($sock)) . "\n";
	//return FALSE;
	exit('1');
}
$res = socket_listen($sock,$connections); 	// 
if(!$res) {
	echo "Failed listennig by: " . socket_strerror(socket_last_error($sock)) . "\n";
	//return FALSE;
	exit('1');
}
//socket_set_nonblock($sock); 	// подразумевается, что изменений в сокете всегда ждём в socket_select
return $sock;
} // end function createSocketServer


function createSocketClient($host,$port){
/* создаёт сокет, соединенный с $host,$port на другом компьютере */
$sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$sock) {
	echo "Failed to create client socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
	//exit('1');
}
if(! @socket_connect($sock,$host,$port)){ 	// подключаемся к серверу
	echo "Failed to connect to remote server $host:$port by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
	//exit('1');
}
echo "Connected to $host:$port\n";
//$res = socket_write($socket, "\n");
return $sock;
} // end function createSocketClient

function chkSocks($socket) {
/**/
global $dataSourceConnectionObject, $masterSock, $sockets, $socksRead, $socksWrite, $socksError, $messages, $devicePresent,$dataSourceHost,$dataSourcePort,$dataSourceHumanName;
if($socket == $dataSourceConnectionObject){ 	// умерло соединение с  источником данных
	echo "\n$dataSourceHumanName socket die. Try to reconnect.\n";
	@socket_close($dataSourceConnectionObject); 	// он может быть уже закрыт
	$dataSourceConnectionObject = createSocketClient($dataSourceHost,$dataSourcePort); 	// Соединение с источником данных
	echo "Socket to $dataSourceHumanName reopen, do handshaking\n";
	$newDevices = dataSourceConnect($dataSourceConnectionObject);
	if($newDevices===FALSE) exit("Handshaking fail: $dataSourceHumanName not run, bye     \n");
	
	echo "Handshaked, will recieve data from $dataSourceHumanName\n";
	if(!$devicePresent) echo"but no required devices present     \n";
	$devicePresent = array_unique(array_merge($devicePresent,$newDevices));
	echo "New handshaking, will recieve data from $dataSourceHumanName\n";
}
elseif($socket == $masterSock){ 	// умерло входящее подключение
	echo "\nIncoming socket die. Try to recreate.\n";
	@socket_close($masterSock); 	// он может быть уже закрыт
	$masterSock = createSocketServer($gpsdProxyHost,$gpsdProxyPort,20); 	// Входное соединение
}
else {
	$n = array_search($socket,$sockets);	// 
	//echo "Close client socket #$n $socket type ".gettype($socket)." by error or by life                    \n";
	if($n !== FALSE){
		unset($sockets[$n]);
		unset($messages[$n]);
	}
	$n = array_search($socket,$socksRead);	// 
	if($n !== FALSE) unset($socksRead[$n]);
	$n = array_search($socket,$socksWrite);	// 
	if($n !== FALSE) unset($socksWrite[$n]);
	$n = array_search($socket,$socksError);	// 
	if($n !== FALSE) phpunset($socksError[$n]);
	@socket_close($socket); 	// он может быть уже закрыт
}
//echo "\nchkSocks sockets: "; print_r($sockets);
} // end function chkSocks

function chkGPSDpresent($host,$port){
/* Определение gpsd и gpsdPROXY */
$return = FALSE;
$socket = createSocketClient($host,$port);
if($socket){
	$res = @socket_write($socket, "\r\n", 2);	// gpsgPROXY не вернёт greeting, если не получит что-то. Ну, так получилось
	if($res !== FALSE) { 	
		$buf = @socket_read($socket, 2048, PHP_NORMAL_READ); 	// читаем
		if($buf !== FALSE){
			$buf = json_decode($buf, true);
			if(substr($buf["class"],0,7)=='VERSION') $return = TRUE;
		}
	}
}
@socket_close($socket);
return $return;
} // end function chkGPSDpresent

function connectToGPSD($gpsdSock){
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$controlClasses = array('VERSION','DEVICES','DEVICE','WATCH');
$WATCHsend = FALSE;
$dataType = $SEEN_GPS | $SEEN_AIS; 	// данные от каких приборов будем принимать от gpsd
//echo "dataType=$dataType;\n";
//echo "\nBegin handshaking with gpsd to socket $gpsdSock\n";
do { 	// при каскадном соединении нескольких gpsd заголовков может быть много
	$zeroCount = 0;	// счётчик пустых строк
	do {	// крутиться до принятия строки или до 10 пустых строк
		$buf = @socket_read($gpsdSock, 2048, PHP_NORMAL_READ); 	// читаем
		//echo "\nbuf:$buf| \n$zeroCount\n";
		if($buf === FALSE) { 	// gpsd умер
			//echo "\nFailed to read data from gpsd: " . socket_strerror(socket_last_error()) . "\n";
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
	case 'VERSION': 	// можно получить от slave gpsd после WATCH
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
				echo "\nFailed to send WATCH to gpsd: " . socket_strerror(socket_last_error()) . "\n";
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
	echo "\nno required devices present\n";
	return FALSE;
}
$devicePresent = array_unique($devicePresent);
return $devicePresent;
} // end function connectToGPSD

function realChkSignalKpresent($host,$port){
/* Receive host,port of http interface of SignalK
Return array(host,port) of BSD socket of SignalK or FALSE 
*/
$return = FALSE;
//echo "realChkSignalKpresent: $host:$port;\n";
$buf = @file_get_contents("http://$host:$port/signalk");
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
if($mqtt->connect(true, NULL, $username, $password)) {
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
	$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
	if($res){
		if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
		$requireFile = 'venusos.php';
		echo "Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
	}
	else { 	// попробуем Signal K
		$res = findSignalKinLAN();	// спросим у Avahi
		if($res) {
			list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт веб-интерфейса
			$res = realChkSignalKpresent($host,$port);
			print_r($res);
			if($res) {
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
				$requireFile = 'signalk.php';
				echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
			}
			else { echo "Найденные адреса кривые\n";
				$dataSourceHost = '127.0.0.1';	
				$dataSourcePort = 3000;	
				$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
				if($res){
					list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
					$requireFile = 'signalk.php';
					echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
				}
			}
		}
		else {
			$dataSourceHost = '127.0.0.1';	
			$dataSourcePort = 3000;	
			$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
			if($res){
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
				$requireFile = 'signalk.php';
				echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
			}
		}		
	}
	break;
case 'signalk':
	if(!$dataSourceHost) $dataSourceHost = '127.0.0.1';	
	if(!$dataSourcePort) $dataSourcePort = 3000;	
	$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
	if($res){
		list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
		$requireFile = 'signalk.php';
		echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
	}
	else {
		$res = findSignalKinLAN();	// спросим у Avahi
		if($res) {	//echo "Avahi что-то нашёл\n";
			list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт веб-интерфейса
			//echo "$dataSourceHost:$dataSourcePort\n";
			$res = realChkSignalKpresent($dataSourceHost,$dataSourcePort);
			if($res) {
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
				$requireFile = 'signalk.php';
				echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
			}
		}
		else {	// попробуем VenusOS
			$dataSourceHost = '127.0.0.1';	
			$dataSourcePort = 1883;	
			$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
			if($res){
				if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
				$requireFile = 'venusos.php';
				echo "Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
			}
		}
	}
	break;
default:	// gpsd
	if(!$dataSourceHost) $dataSourceHost = '127.0.0.1';	
	if(!$dataSourcePort) $dataSourcePort = 2947;	
	if(chkGPSDpresent($dataSourceHost,$dataSourcePort)) {
		$requireFile = 'gpsd.php';
		echo "Found gpsd on $dataSourceHost:$dataSourcePort\n";
	}
	else { 	 //echo "попробуем Signal K\n";
		$res = findSignalKinLAN();	// спросим у Avahi
		if($res) {	//echo "Avahi что-то нашёл\n";
			list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт веб-интерфейса
			//echo "$dataSourceHost:$dataSourcePort\n";
			$res = realChkSignalKpresent($dataSourceHost,$dataSourcePort);
			if($res) {
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
				$requireFile = 'signalk.php';
				echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
			}
			else { echo "Найденные адреса кривые\n";
				$dataSourceHost = '127.0.0.1';	
				$dataSourcePort = 3000;	
				$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
				if($res){
					list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
					$requireFile = 'signalk.php';
					echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
				}
				else {	// попробуем VenusOS
					$dataSourceHost = '127.0.0.1';	
					$dataSourcePort = 1883;	
					$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
					if($res){
						if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
						$requireFile = 'venusos.php';
						echo "Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
					}
				}
			}
		}
		else {
			$dataSourceHost = '127.0.0.1';	
			$dataSourcePort = 3000;	
			$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
			if($res){
				list($dataSourceHost,$dataSourcePort) = $res;	// хост и порт нормального сокета
				$requireFile = 'signalk.php';
				echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
			}
			else {	// попробуем VenusOS
				$dataSourceHost = '127.0.0.1';	
				$dataSourcePort = 1883;	
				$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
				if($res){
					if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
					$requireFile = 'venusos.php';
					echo "Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
				}
			}
		}		
	}
}

if($requireFile) return array($dataSourceHost,$dataSourcePort,$requireFile);
else return FALSE;
} // end function findSource

function updAndPrepare($inInstrumentsData=array(),$sockKey=null){
/* Обновляет кеш данных и готовит к отправке, если надо, данные для режима WATCH, 
так, что на следующем обороте они будут отправлены 
$inInstrumentsData -- масиив ответов gpsd в режиме ?WATCH={"enable":true,"json":true};
*/
global $messages, $pollWatchExist, $instrumentsData;

//echo "[updAndPrepare] sockKey=$sockKey;                   \n";
//print_r($inInstrumentsData);
$instrumentsDataUpdated = array();
if($inInstrumentsData) {
	foreach($inInstrumentsData as $inInstrument){
		$instrumentsDataUpdated = array_merge($instrumentsDataUpdated,updInstrumentsData($inInstrument,$sockKey));
		//echo "merged instrumentsDataUpdated "; print_r($instrumentsDataUpdated);
	}
}
else $instrumentsDataUpdated = updInstrumentsData(array(),$sockKey);	// вызвали для проверки протухших данных и отправке, если
//echo "Что изменилось, instrumentsDataUpdated: "; print_r($instrumentsDataUpdated);
dataSourceSave(); 	// сохраним в файл, если пора
//echo "\npollWatchExist=$pollWatchExist;"; print_r($inInstrumentsData);
if($pollWatchExist){	// есть режим WATCH, надо подготовить данные. От gpsd (или что там вместо) может прийти пустое или непонятное
	// Не надо ли что-нибудь сразу отправить?
	$WATCH = null; $ais = null; $MOB = null;
	$pollWatchExist = FALSE;	// 
	$now = microtime(true);
	foreach($messages as $n => $sockData){
		if($sockData['POLL'] === 'WATCH'){	// для соответствующего сокета указано посылать непрерывно. === потому что $data['POLL'] на момент сравнения может иметь тип boolean, и при == произойдёт приведение 'WATCH' к boolean;
			$pollWatchExist = TRUE;	// отметим, что есть сокет с режимом WATCH
			if(($now - @$sockData['lastSend'])<floatval(@$sockData['minPeriod'])) continue;	// частота отсылки данных
			$messages[$n]['lastSend'] = $now;

			//echo "n=$n; sockData:"; print_r($sockData);
			if((@$sockData['subscribe']=="TPV") and $instrumentsDataUpdated["TPV"]){
				if(!$WATCH) $WATCH = makeWATCH();
				$messages[$n]['output'][] = json_encode($WATCH)."\r\n\r\n";
			}
			elseif((@$sockData['subscribe']=="AIS") and $instrumentsDataUpdated["AIS"]){
				if(!$ais) $ais = makeAIS();
				$out = array('class' => 'AIS');	// это не вполне правильный класс, но ничему не противоречит
				$out['ais'] = $ais;
				$messages[$n]['output'][] = json_encode($out)."\r\n\r\n";
				unset($out);
			}
			elseif(!@$sockData['subscribe']){	// не указали подписку, шлём всё
				if($instrumentsDataUpdated["TPV"]){
					if(!$WATCH) $WATCH = makeWATCH();
					$messages[$n]['output'][] = json_encode($WATCH)."\r\n\r\n";
				}
				if($instrumentsDataUpdated["AIS"]){	// 
					if(!$ais) $ais = makeAIS();
					$out = array('class' => 'AIS');
					$out['ais'] = $ais;
					$messages[$n]['output'][] = json_encode($out)."\r\n\r\n";
					unset($out);
				}
			}
			//echo "gpsdDataUpdated[MOB]={$instrumentsDataUpdated["MOB"]};        \n";			
			if(isset($instrumentsDataUpdated["MOB"]) and $instrumentsDataUpdated["MOB"]!==$n){	// не тот сокет, который прислал данные. Если вернуть данные тому же, то он может их снова прислать из каких-то своих соображений, и так бесконечно.
			//if(isset($instrumentsDataUpdated["MOB"]) and $instrumentsDataUpdated["MOB"]===$n){	// тот сокет, который прислал данные, для тестовых целей
				//echo "Prepare to send MOB data to WACH'ed socket #$n;                      \n";
				//print_r($instrumentsData["MOB"]);
				$messages[$n]['output'][] = json_encode($instrumentsData["MOB"])."\r\n\r\n";
			}
			
		}
	}
}
} // end function updAndPrepare

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
global $instrumentsData,$gpsdProxyTimeouts,$collisionDistance;
$instrumentsDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.
$now = time();
//echo "\ninInstrumentsData="; print_r($inInstrumentsData);echo"\n";
switch(@$inInstrumentsData['class']) {	// Notice if $inInstrumentsData empty
case 'SKY':
	break;
case 'TPV':
	// собирает данные по устройствам, в том числе и однородные
	$dataTime = $now;
	foreach($inInstrumentsData as $type => $value){ 	// обновим данные
		$instrumentsData['TPV'][$inInstrumentsData['device']]['data'][$type] = $value; 	// php создаёт вложенную структуру, это не python
		if($type == 'time') { // надеемся, что время прислали до содержательных данных
			$dataTime = strtotime($value);
			//echo "\nПрисланное время: |$value|$dataTime, восстановленное: |".date(DATE_ATOM,$dataTime)."|".strtotime(date(DATE_ATOM,$dataTime))." \n";
			if(!$dataTime) $dataTime = $now;
		}
		//echo "\ngpsdProxyTimeouts['TPV'][$type]={$gpsdProxyTimeouts['TPV'][$type]};\n";
		//echo "\ninInstrumentsData['device']={$inInstrumentsData['device']};\n";
		// Записываем время кеширования всех, потому что оно используется в makeWATCH для собирания самых свежих значений от разных устройств
		$instrumentsData['TPV'][$inInstrumentsData['device']]['cachedTime'][$type] = $dataTime;
		/*
		if($instrumentsData['TPV'][$inInstrumentsData['device']]['cachedTime'][$type] != $now){
			//echo "type=$type; "; print_r($instrumentsData['TPV'][$inInstrumentsData['device']]['cachedTime']);
			echo "\nДля $type время не совпадает на ".($instrumentsData['TPV'][$inInstrumentsData['device']]['cachedTime'][$type] - $now)." сек. \n";
			echo "Применяется время: $dataTime, ".date(DATE_ATOM,$dataTime).", сейчас ".date(DATE_ATOM,$now)." \n";
		};
		*/
		
		$instrumentsDataUpdated['TPV'] = TRUE;
	}
	break;
case 'netAIS':
	//echo "JSON netAIS Data: "; print_r($inInstrumentsData); echo "\n";
	foreach($inInstrumentsData['data'] as $vehicle => $data){
		$timestamp = $data['timestamp'];
		if(!$timestamp) $timestamp = $now;
		$instrumentsData['AIS'][$vehicle]['timestamp'] = $timestamp;
		foreach($data as $type => $value){
			$instrumentsData['AIS'][$vehicle]['data'][$type] = $value; 	// 
			$instrumentsData['AIS'][$vehicle]['cachedTime'][$type] = $timestamp;
			$instrumentsDataUpdated['AIS'] = true;
		}
	}
	break;
case 'AIS':
	//echo "JSON AIS Data: "; print_r($inInstrumentsData); echo "\n";
	$vehicle = trim((string)$inInstrumentsData['mmsi']);	//
	$instrumentsData['AIS'][$vehicle]['data']['mmsi'] = $vehicle;
	if($inInstrumentsData['netAIS']) $instrumentsData['AIS'][$vehicle]['data']['netAIS'] = TRUE; 	// 
	//echo "\n AIS sentence type ".$inInstrumentsData['type']."\n";
	switch($inInstrumentsData['type']) {
	case 27:
	case 18:
	case 19:
	case 1:
	case 2:
	case 3:		// http://www.e-navigation.nl/content/position-report
		if(isset($inInstrumentsData['status'])) {
			$instrumentsData['AIS'][$vehicle]['data']['status'] = (int)filter_var($inInstrumentsData['status'],FILTER_SANITIZE_NUMBER_INT); 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
			$instrumentsData['AIS'][$vehicle]['cachedTime']['status'] = $now;
		}
		if(isset($inInstrumentsData['status_text'])) $instrumentsData['AIS'][$vehicle]['data']['status_text'] = filter_var($inInstrumentsData['status_text'],FILTER_SANITIZE_STRING);
		if(isset($inInstrumentsData['accuracy'])) {
			$instrumentsData['AIS'][$vehicle]['data']['accuracy'] = (int)filter_var($inInstrumentsData['accuracy'],FILTER_SANITIZE_NUMBER_INT); 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
			$instrumentsData['AIS'][$vehicle]['cachedTime']['accuracy'] = $now;
		}
		if(isset($inInstrumentsData['turn'])){
			if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
				//$instrumentsData['AIS'][$vehicle]['data']['turn'] = $inInstrumentsData['turn']; 	// градусы в минуту со знаком или строка? one of the strings "fastright" or "fastleft" if it is out of the AIS encoding range; otherwise it is quadratically mapped back to the turn sensor number in degrees per minute
			}
			else {
				//$instrumentsData['AIS'][$vehicle]['data']['turn'] = (int)filter_var($inInstrumentsData['turn'],FILTER_SANITIZE_NUMBER_INT); 	// тут чёта сложное...  Rate of turn ROTAIS 0 to +126 = turning right at up to 708° per min or higher 0 to –126 = turning left at up to 708° per min or higher Values between 0 and 708° per min coded by ROTAIS = 4.733 SQRT(ROTsensor) degrees per min where  ROTsensor is the Rate of Turn as input by an external Rate of Turn Indicator (TI). ROTAIS is rounded to the nearest integer value. +127 = turning right at more than 5° per 30 s (No TI available) –127 = turning left at more than 5° per 30 s (No TI available) –128 (80 hex) indicates no turn information available (default). ROT data should not be derived from COG information.
			}
			$instrumentsData['AIS'][$vehicle]['cachedTime']['turn'] = $now;
		}
		if($inInstrumentsData['type'] == 27) { 	// оказывается, там координаты в 1/10 минуты и скорость в узлах!!!
			if(isset($inInstrumentsData['lon']) or isset($inInstrumentsData['lat'])){
				if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
					$instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)$inInstrumentsData['lon']; 	// 
					$instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)$inInstrumentsData['lat'];
				}
				else {
					if($inInstrumentsData['lon']==181) $instrumentsData['AIS'][$vehicle]['data']['lon'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)filter_var($inInstrumentsData['lon'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10*60); 	// Longitude in degrees	( 1/10 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
					if($inInstrumentsData['lat']==91) $instrumentsData['AIS'][$vehicle]['data']['lat'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)filter_var($inInstrumentsData['lat'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10*60); 	// Latitude in degrees (1/10 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
				}
				$instrumentsData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
				$instrumentsData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
			}
			if(isset($inInstrumentsData['speed'])){
				if($inInstrumentsData['speed']==63) $instrumentsData['AIS'][$vehicle]['data']['speed'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['speed'] = (float)filter_var($inInstrumentsData['speed'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)*1852/3600; 	// м/сек SOG Speed over ground in m/sec 	Knots (0-62); 63 = not available = default
				$instrumentsData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
			}
			if(isset($inInstrumentsData['course'])){
				if($inInstrumentsData['course']==511) $instrumentsData['AIS'][$vehicle]['data']['course'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['course'] = (float)filter_var($inInstrumentsData['course'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Путевой угол. COG Course over ground in degrees Degrees (0-359); 511 = not available = default
				$instrumentsData['AIS'][$vehicle]['cachedTime']['course'] = $now;
			}
		}
		else {
			if(isset($inInstrumentsData['lon']) or isset($inInstrumentsData['lat'])){
				if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
					$instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)$inInstrumentsData['lon']; 	// 
					$instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)$inInstrumentsData['lat'];
				}
				else {
					if($inInstrumentsData['lon']==181) $instrumentsData['AIS'][$vehicle]['data']['lon'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)filter_var($inInstrumentsData['lon'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10000*60); 	// Longitude in degrees	( 1/10 000 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
					if($inInstrumentsData['lat']==91) $instrumentsData['AIS'][$vehicle]['data']['lat'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)filter_var($inInstrumentsData['lat'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10000*60); 	// Latitude in degrees (1/10 000 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
				}
				$instrumentsData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
				$instrumentsData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
			}
			if(isset($inInstrumentsData['speed'])){
				if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
					$instrumentsData['AIS'][$vehicle]['data']['speed'] = ((int)$inInstrumentsData['speed']*1852)/(60*60); 	// SOG Speed over ground in m/sec 	
				}
				else {
					if($inInstrumentsData['speed']>1022) $instrumentsData['AIS'][$vehicle]['data']['speed'] = NULL;
					else $instrumentsData['AIS'][$vehicle]['data']['speed'] = (float)filter_var($inInstrumentsData['speed'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)*185.2/3600; 	// SOG Speed over ground in m/sec 	(in 1/10 knot steps (0-102.2 knots) 1 023 = not available, 1 022 = 102.2 knots or higher)
				}
				$instrumentsData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
			}
			if(isset($inInstrumentsData['course'])){
				if($inInstrumentsData['course']==3600) $instrumentsData['AIS'][$vehicle]['data']['course'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['course'] = (float)filter_var($inInstrumentsData['course'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/10; 	// Путевой угол. COG Course over ground in degrees ( 1/10 = (0-3599). 3600 (E10h) = not available = default. 3601-4095 should not be used)
				$instrumentsData['AIS'][$vehicle]['cachedTime']['course'] = $now;
			}
		}
		if(isset($inInstrumentsData['heading'])){
			if($inInstrumentsData['heading']==511) $instrumentsData['AIS'][$vehicle]['data']['heading'] = NULL;
			else $instrumentsData['AIS'][$vehicle]['data']['heading'] = (float)filter_var($inInstrumentsData['heading'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Истинный курс. True heading Degrees (0-359) (511 indicates not available = default)
			$instrumentsData['AIS'][$vehicle]['cachedTime']['heading'] = $now;
		}
		if($inInstrumentsData['second']>63) $instrumentsData['AIS'][$vehicle]['timestamp'] = (int)filter_var($inInstrumentsData['second'],FILTER_SANITIZE_NUMBER_INT);	// Ну так же проще! Будем считать, что если там большая цифра -- то это unix timestamp. Так будем принимать метку времени от SignalK
		elseif($inInstrumentsData['second']>59) $instrumentsData['AIS'][$vehicle]['timestamp'] = time();
		else $instrumentsData['AIS'][$vehicle]['timestamp'] = time() - (int)filter_var($inInstrumentsData['second'],FILTER_SANITIZE_NUMBER_INT); 	// Unis timestamp. Time stamp UTC second when the report was generated by the electronic position system (EPFS) (0-59, or 60 if time stamp is not available, which should also be the default value, or 61 if positioning system is in manual input mode, or 62 if electronic position fixing system operates in estimated (dead reckoning) mode, or 63 if the positioning system is inoperative)
		if(isset($inInstrumentsData['maneuver'])){
			$instrumentsData['AIS'][$vehicle]['data']['maneuver'] = (int)filter_var($inInstrumentsData['maneuver'],FILTER_SANITIZE_NUMBER_INT); 	// Special manoeuvre indicator 0 = not available = default 1 = not engaged in special manoeuvre 2 = engaged in special manoeuvre (i.e. regional passing arrangement on Inland Waterway)
			$instrumentsData['AIS'][$vehicle]['cachedTime']['maneuver'] = $now;
		}
		if(isset($inInstrumentsData['raim'])) $instrumentsData['AIS'][$vehicle]['data']['raim'] = (int)filter_var($inInstrumentsData['raim'],FILTER_SANITIZE_NUMBER_INT); 	// RAIM-flag Receiver autonomous integrity monitoring (RAIM) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use. See Table 50
		if(isset($inInstrumentsData['radio'])) $instrumentsData['AIS'][$vehicle]['data']['radio'] = (string)$inInstrumentsData['radio']; 	// Communication state
		$instrumentsDataUpdated['AIS'] = TRUE;
		//break; 	//comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1. Но gpsdAISd не имеет дела с netAIS?
	case 5: 	// http://www.e-navigation.nl/content/ship-static-and-voyage-related-data
	case 24: 	// Vendor ID не поддерживается http://www.e-navigation.nl/content/static-data-report
		//echo "JSON inInstrumentsData: \n"; print_r($inInstrumentsData); echo "\n";
		if(isset($inInstrumentsData['imo'])) $instrumentsData['AIS'][$vehicle]['data']['imo'] = (string)$inInstrumentsData['imo']; 	// IMO number 0 = not available = default – Not applicable to SAR aircraft 0000000001-0000999999 not used 0001000000-0009999999 = valid IMO number; 0010000000-1073741823 = official flag state number.
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
		if(isset($inInstrumentsData['shiptype_text'])) $instrumentsData['AIS'][$vehicle]['data']['shiptype_text'] = filter_var($inInstrumentsData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
		if(isset($inInstrumentsData['to_bow'])) $instrumentsData['AIS'][$vehicle]['data']['to_bow'] = (float)filter_var($inInstrumentsData['to_bow'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position. Also indicates the dimension of ship (m) (see Fig. 42 and § 3.3.3) For SAR aircraft, the use of this field may be decided by the responsible administration. If used it should indicate the maximum dimensions of the craft. As default should A = B = C = D be set to “0”
		if(isset($inInstrumentsData['to_stern'])) $instrumentsData['AIS'][$vehicle]['data']['to_stern'] = (float)filter_var($inInstrumentsData['to_stern'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
		if(isset($inInstrumentsData['to_port'])) $instrumentsData['AIS'][$vehicle]['data']['to_port'] = (float)filter_var($inInstrumentsData['to_port'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
		if(isset($inInstrumentsData['to_starboard'])) $instrumentsData['AIS'][$vehicle]['data']['to_starboard'] = (float)filter_var($inInstrumentsData['to_starboard'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
		if(isset($inInstrumentsData['epfd'])) $instrumentsData['AIS'][$vehicle]['data']['epfd'] = (int)filter_var($inInstrumentsData['epfd'],FILTER_SANITIZE_NUMBER_INT); 	// Type of electronic position fixing device. 0 = undefined (default) 1 = GPS 2 = GLONASS 3 = combined GPS/GLONASS 4 = Loran-C 5 = Chayka 6 = integrated navigation system 7 = surveyed 8 = Galileo, 9-14 = not used 15 = internal GNSS
		if(isset($inInstrumentsData['epfd_text'])) $instrumentsData['AIS'][$vehicle]['data']['epfd_text'] = (string)$inInstrumentsData['epfd_text']; 	// 
		if(isset($inInstrumentsData['eta'])) $instrumentsData['AIS'][$vehicle]['data']['eta'] = (string)$inInstrumentsData['eta']; 	// ETA Estimated time of arrival; MMDDHHMM UTC Bits 19-16: month; 1-12; 0 = not available = default  Bits 15-11: day; 1-31; 0 = not available = default Bits 10-6: hour; 0-23; 24 = not available = default Bits 5-0: minute; 0-59; 60 = not available = default For SAR aircraft, the use of this field may be decided by the responsible administration
		if(isset($inInstrumentsData['draught'])){
		 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
			if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['draught'] = (float)$inInstrumentsData['draught']; 	// в метрах
			else $instrumentsData['AIS'][$vehicle]['data']['draught'] = (float)filter_var($inInstrumentsData['draught'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Maximum present static draught In m ( 1/10 m, 255 = draught 25.5 m or greater, 0 = not available = default; in accordance with IMO Resolution A.851 Not applicable to SAR aircraft, should be set to 0)
		}
		if(isset($inInstrumentsData['destination'])){
			$instrumentsData['AIS'][$vehicle]['data']['destination'] = filter_var($inInstrumentsData['destination'],FILTER_SANITIZE_STRING); 	// Destination Maximum 20 characters using 6-bit ASCII; @@@@@@@@@@@@@@@@@@@@ = not available For SAR aircraft, the use of this field may be decided by the responsible administration
			$instrumentsData['AIS'][$vehicle]['cachedTime']['destination'] = $now;
		}
		if(isset($inInstrumentsData['dte'])) $instrumentsData['AIS'][$vehicle]['data']['dte'] = (int)filter_var($inInstrumentsData['dte'],FILTER_SANITIZE_NUMBER_INT); 	// DTE Data terminal equipment (DTE) ready (0 = available, 1 = not available = default) (see § 3.3.1)
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
		if(isset($inInstrumentsData['length'])) $instrumentsData['AIS'][$vehicle]['data']['length'] = (float)filter_var($inInstrumentsData['length'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Length of ship in m
		if(isset($inInstrumentsData['beam'])) $instrumentsData['AIS'][$vehicle]['data']['beam'] = (float)filter_var($inInstrumentsData['beam'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Beam of ship in m ширина, длина бимса.
		if(isset($inInstrumentsData['shiptype']) and !$instrumentsData['AIS'][$vehicle]['data']['shiptype']) $instrumentsData['AIS'][$vehicle]['data']['shiptype'] = (string)$inInstrumentsData['shiptype']; 	// Ship/combination type ERI Classification В какой из посылок тип правильный - неизвестно, поэтому будем брать только из одной
		if(isset($inInstrumentsData['shiptype_text']) and !$instrumentsData['AIS'][$vehicle]['data']['shiptype_text'])$instrumentsData['AIS'][$vehicle]['data']['shiptype_text'] = filter_var($inInstrumentsData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
		if(isset($inInstrumentsData['hazard'])) $instrumentsData['AIS'][$vehicle]['data']['hazard'] = (int)filter_var($inInstrumentsData['hazard'],FILTER_SANITIZE_NUMBER_INT); 	// Hazardous cargo | 0 | 0 blue cones/lights | 1 | 1 blue cone/light | 2 | 2 blue cones/lights | 3 | 3 blue cones/lights | 4 | 4 B-Flag | 5 | Unknown (default)
		if(isset($inInstrumentsData['hazard_text'])) $instrumentsData['AIS'][$vehicle]['data']['hazard_text'] = filter_var($inInstrumentsData['hazard_text'],FILTER_SANITIZE_STRING); 	// 
		if(isset($inInstrumentsData['draught']) and !$instrumentsData['AIS'][$vehicle]['data']['draught']) $instrumentsData['AIS'][$vehicle]['data']['draught'] = (float)filter_var($inInstrumentsData['draught'],FILTER_SANITIZE_NUMBER_INT)/100; 	// Draught in m ( 1-200 * 0.01m, default 0)
		if(isset($inInstrumentsData['loaded'])) $instrumentsData['AIS'][$vehicle]['data']['loaded'] = (int)filter_var($inInstrumentsData['loaded'],FILTER_SANITIZE_NUMBER_INT); 	// Loaded/Unloaded | 0 | N/A (default) | 1 | Unloaded | 2 | Loaded
		if(isset($inInstrumentsData['loaded_text'])) $instrumentsData['AIS'][$vehicle]['data']['loaded_text'] = filter_var($inInstrumentsData['loaded_text'],FILTER_SANITIZE_STRING); 	// 
		if(isset($inInstrumentsData['speed_q'])) $instrumentsData['AIS'][$vehicle]['data']['speed_q'] = (int)filter_var($inInstrumentsData['speed_q'],FILTER_SANITIZE_NUMBER_INT); 	// Speed inf. quality 0 = low/GNSS (default) 1 = high
		if(isset($inInstrumentsData['course_q'])) $instrumentsData['AIS'][$vehicle]['data']['course_q'] = (int)filter_var($inInstrumentsData['course_q'],FILTER_SANITIZE_NUMBER_INT); 	// Course inf. quality 0 = low/GNSS (default) 1 = high
		if(isset($inInstrumentsData['heading_q'])) $instrumentsData['AIS'][$vehicle]['data']['heading_q'] = (int)filter_var($inInstrumentsData['heading_q'],FILTER_SANITIZE_NUMBER_INT); 	// Heading inf. quality 0 = low/GNSS (default) 1 = high
		$instrumentsDataUpdated['AIS'] = TRUE;
		//echo "\n instrumentsData[AIS][$vehicle]['data']:\n"; print_r($instrumentsData['AIS'][$vehicle]['data']);
		break;
	}
	/*
	// Посчитаем данные для контроля столкновений:
	list($instrumentsData['AIS'][$vehicle]['collisionArea'],$instrumentsData['AIS'][$vehicle]['squareArea']) = updCollisionArea($instrumentsData['AIS'][$vehicle]['data'],$collisionDistance);	// fCollisions.php
	echo "\n Calculated collision areas for $vehicle \n";
	*/
	break;
case 'MOB':
	$instrumentsData['MOB']['class'] = 'MOB';
	$instrumentsData['MOB']['status'] = $inInstrumentsData['status'];
	$instrumentsData['MOB']['points'] = $inInstrumentsData['points'];
	$instrumentsDataUpdated['MOB'] = $sockKey;
	//echo "MOB: "; print_r($instrumentsData['MOB']);
	break;
}

// Проверим актуальность всех данных
$instrumentsDataUpdated = array_merge($instrumentsDataUpdated,chkFreshOfData());	
/*
// Проверим опасность столкновений
$instrumentsDataUpdated = array_merge($instrumentsDataUpdated,chkCollisions());	
*/
//echo "\n gpsdDataUpdated\n"; print_r($instrumentsDataUpdated);
//echo "\n instrumentsData\n"; print_r($instrumentsData);
//echo "\n instrumentsData AIS\n"; print_r($instrumentsData['AIS']);
return $instrumentsDataUpdated;
} // end function updInstrumentsData

function chkFreshOfData(){
/* Проверим актуальность всех данных */
global $instrumentsData,$gpsdProxyTimeouts,$noVehicleTimeout,$boatInfo;
$instrumentsDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.
$TPVtimeoutMultiplexor = 30;	// через сколько таймаутов свойство удаляется совсем
// TPV
//print_r($instrumentsData);
$now = time();
if($instrumentsData['TPV']){
	foreach($instrumentsData['TPV'] as $device => $data){
		foreach($instrumentsData['TPV'][$device]['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
			//echo "type=$type; data['data'][$type]={$data['data'][$type]}; gpsdProxyTimeouts['TPV'][$type]={$gpsdProxyTimeouts['TPV'][$type]}; now=$now; cachedTime=$cachedTime;\n";
			if((!is_null($data['data'][$type])) and $gpsdProxyTimeouts['TPV'][$type] and (($now - $cachedTime) > $gpsdProxyTimeouts['TPV'][$type])) {	// Notice if on $gpsdProxyTimeouts not have this $type
				$instrumentsData['TPV'][$device]['data'][$type] = null;
				/* // Это не нужно, потому что collision area для себя считается каждый раз непосредственно перед употреблением
				if(in_array($type,array('lat','lon','track','speed'))){	// удалим данные для контроля столкновений, если протухли исходные
					unset($boatInfo['collisionArea']);
					unset($boatInfo['squareArea']);
					echo "\n Removed self collision area \n";
				}
				*/
				$instrumentsDataUpdated['TPV'] = TRUE;
				//echo "Данные ".$type." от устройства ".$device." протухли на ".($now - $cachedTime)." сек            \n";
			}
			elseif((is_null($data['data'][$type])) and $gpsdProxyTimeouts['TPV'][$type] and (($now - $cachedTime) > ($TPVtimeoutMultiplexor*$gpsdProxyTimeouts['TPV'][$type]))) {	// Notice if on $gpsdProxyTimeouts not have this $type
				unset($instrumentsData['TPV'][$device]['data'][$type]);
				unset($instrumentsData['TPV'][$device]['cachedTime'][$type]);
				$instrumentsDataUpdated['TPV'] = TRUE;
				//echo "Данные ".$type." от устройства ".$device." совсем протухли на ".($now - $cachedTime)." сек   \n";
			}
		}
		//echo "instrumentsData['TPV'][$device] после очистки:"; print_r($instrumentsData['TPV'][$device]['data']);
		if($instrumentsData['TPV'][$device]['cachedTime']) {
			// Удалим все данные устройства, которое давно ничего не давало из контролируемых на протухание параметров
			$toDel = TRUE;
			foreach($instrumentsData['TPV'][$device]['cachedTime'] as $type => $cachedTime){	// поищем, есть ли среди кешированных контролируемые параметры
				if($gpsdProxyTimeouts['TPV'][$type]) {
					$toDel = FALSE;
					break;
				}
			}
			if($toDel) {	// 
				unset($instrumentsData['TPV'][$device]); 	// 
				$instrumentsDataUpdated['TPV'] = TRUE;
				//echo "All TPV data of device $device purged by the long silence.                        \n";
			}
		}
	}
}
// AIS
if($instrumentsData['AIS']) {	// IF быстрей, чем обработка Warning?
	foreach($instrumentsData['AIS'] as $id => $vehicle){
		if(($now - $vehicle['timestamp'])>$noVehicleTimeout) {
			unset($instrumentsData['AIS'][$id]); 	// удалим цель, последний раз обновлявшуюся давно
			$instrumentsDataUpdated['AIS'] = TRUE;
			//echo "Данные AIS для судна ".$id." протухли на ".($now - $vehicle['timestamp'])." сек при норме $noVehicleTimeout       \n";
			continue;	// к следующей цели AIS
		}
		if($instrumentsData['AIS'][$id]['cachedTime']){ 	// поищем, не протухло ли чего
			foreach($instrumentsData['AIS'][$id]['cachedTime'] as $type => $cachedTime){
				if(!is_null($vehicle['data'][$type]) and $gpsdProxyTimeouts['AIS'][$type] and (($now - $cachedTime) > $gpsdProxyTimeouts['AIS'][$type])) {
					$instrumentsData['AIS'][$id]['data'][$type] = null;
					/*
					if(in_array($type,array('lat','lon','course','speed'))){	// удалим данные для контроля столкновений, если протухли исходные
						//unset($instrumentsData['AIS'][$id]['collisionArea']);
						//unset($instrumentsData['AIS'][$id]['squareArea']);
						//echo "\n Removed collision area for $id \n";
						list($instrumentsData['AIS'][$id]['collisionArea'],$instrumentsData['AIS'][$id]['squareArea']) = updCollisionArea($instrumentsData['AIS'][$id]['data'],$collisionDistance);	// fCollisions.php
						echo "\n Re-calculate collision area for $id \n";
					}
					*/
					$instrumentsDataUpdated['AIS'] = TRUE;
					//echo "Данные AIS ".$type." для судна ".$id." протухли на ".($now - $cachedTime)." сек                     \n";
				}
				elseif(is_null($vehicle['data'][$type]) and $gpsdProxyTimeouts['AIS'][$type] and (($now - $cachedTime) > (2*$gpsdProxyTimeouts['AIS'][$type]))) {
					unset($instrumentsData['AIS'][$id]['data'][$type]);
					unset($instrumentsData['AIS'][$id]['cachedTime'][$type]);
					$instrumentsDataUpdated['AIS'] = TRUE;
					//echo "Данные AIS ".$type." для судна ".$id." протухли на ".($now - $cachedTime)." сек                     \n";
				}
			}
		}
	}
}
return $instrumentsDataUpdated;
} // end function chkFreshOfData


function dataSourceSave(){
/**/
global $instrumentsData,$backupFileName,$backupTimeout,$lastBackupSaved;

if((time()-$lastBackupSaved)>$backupTimeout){
	file_put_contents($backupFileName,json_encode($instrumentsData));
}
} // end function savepsdData

function makeAIS(){
/* делает массив ais */
global $instrumentsData;

$ais = array();
if($instrumentsData['AIS']) {
	foreach($instrumentsData['AIS'] as $vehicle => $data){
		//$data['data']["class"] = "AIS"; 	// вроде бы, тут не надо?...
		$data['data']["timestamp"] = $data["timestamp"];		
		$ais[$data['data']['mmsi']] = $data['data'];
	}
}
return $ais;
} // end function makeAIS

function makePOLL(){
/* Из глобального $instrumentsData формирует массив ответа на ?POLL протокола gpsd
*/
global $instrumentsData;

$POLL = array(	// данные для передачи клиенту как POLL, в формате gpsd
	"class" => "POLL",
	"time" => time(),
	"active" => 0,
	"tpv" => array(),
	"sky" => array(),	// обязательно по спецификации, пусто
);
//echo "\n instrumentsData\n"; print_r($instrumentsData['TPV']);
if($instrumentsData['TPV']){
	foreach($instrumentsData['TPV'] as $device => $data){
		$POLL["active"] ++;
		$POLL["tpv"][] = $data['data'];
	}
}
$POLL["ais"] = makeAIS();
if($instrumentsData["MOB"]){
	$POLL["mob"] = $instrumentsData["MOB"];
}
return $POLL;
} // end function makePOLL

function makeWATCH(){
/* Из глобального $instrumentsData формирует массив ответа потока ?WATCH протокола gpsd
*/
global $instrumentsData;
//echo "instrumentsData: "; print_r($instrumentsData);

// нужно собрать свежие данные от всех устройств в одно "устройство". 
// При этом окажется, что координаты от одного приёмника ГПС, а ошибка этих координат -- от другого, если первый не прислал ошибку
$WATCH = array();
$lasts = array(); $times = array();
if($instrumentsData['TPV']){
	foreach($instrumentsData['TPV'] as $device => $data){
		foreach($data['data'] as $type => $value){
			if($type=='device') continue;	// необязательный параметр. Указать своё устройство?
			if($data['cachedTime'][$type]<=@$lasts[$type]) continue;	// что лучше -- старый 3D fix, или свежий 2d fix?
			if($type=='lat' or $type=='lon' or $type=='time') $times[] = $data['cachedTime'][$type];
			// присвоим только свежие значения
			//if($type=='lat' or $type=='lon') continue;
			$WATCH[$type] = $value;
			$lasts[$type] = $data['cachedTime'][$type];
		}
	}
}
//print_r($times);
if($times) $WATCH['time'] = date(DATE_ATOM,min($times));	// могут быть присланы левые значения времени, или не присланы совсем
else $WATCH['time'] = date(DATE_ATOM,time());
//print_r($WATCH);
return $WATCH;
} // end function makeWATCH

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
}

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
}

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
	$tail = mb_substr($data,$dataLength,'8bit');

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
	}
}

return array($decodedData,$type,$FIN,$tail);
} // end function wsDecode

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

    $frameHead = array_merge($frameHead, $mask);
}
$frame = implode('', $frameHead);

// append payload to frame:
for ($i = 0; $i < $payloadLength; $i++) {
    $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
}

return $frame;
} // end function wsEncode
?>
