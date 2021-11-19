<?php
/* Демон.
Кеширует данные TPV и AIS от gpsd, и отдаёт их по запросу ?POLL; протокола gpsd
При этом все устройства -- источники данных всё время работы демона остаются активными и потребляющими электричество.

Daemon
Caches TPV and AIS data from gpsd, and returns them on request ?POLL; of the gpsd protocol
As side: daemon keeps instruments alive and power consuming.  

Зачем это надо: 
Details:
https://lists.nongnu.org/archive/html/gpsd-users/2021-06/msg00017.html
https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00098.html

Call:
$ nc localhost 3838
$ cgps localhost:3838
$ telnet localhost 3838
*/
chdir(__DIR__); // задаем директорию выполнение скрипта

require('params.php'); 	// 

if(IRun()) { 	// Я ли?
	echo "I'm already running, exiting.\n"; 
	return;
}
// Self data
$greeting = '{"class":"VERSION","release":"gpsdPROXY_0","rev":"beta","proto_major":2,"proto_minor":2}';
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$gpsdProxydevice = array(
'class' => 'DEVICE',
'path' => 'gpsdPROXY',
'activated' => date('c'),
'flags' => $SEEN_GPS | $SEEN_AIS,
'stopbits' => 1
);
$gpsdData = array(); 	// собственно, собираемые / кешируемые данные
$noGPSDtimeout = 60; 	// 

if(!$gpsdProxyHost) $gpsdProxyHost='localhost'; 	// я сам. Хост/порт для обращения к gpsdProxy
if(!$gpsdProxyPort) $gpsdProxyPort=3838;
if(!$gpsdProxyGPSDhost) $gpsdProxyGPSDhost = 'localhost'; 	// источник данных для кеширования, обычно gpsd
if(!$gpsdProxyGPSDport) $gpsdProxyGPSDport = 2947;


$sockets = array(); 	// список функционирующих сокетов
$masterSock = createSocketServer($gpsdProxyHost,$gpsdProxyPort,20); 	// Соединение для приёма клиентов, входное соединение

$gpsdSock = createSocketClient($gpsdProxyGPSDhost,$gpsdProxyGPSDport); 	// Соединение с gpsd
// Подключимся к gpsd
echo "Socket to gpsd opened, do handshaking\n";
$devicePresent = connectToGPSD($gpsdSock);
if(!$devicePresent) exit("Handshaking fail: gpsd not run or no required devices present, bye     \n");
echo "Handshaked, will recieve data from gpsd\n";

$messages = array(); 	// массив сокет => сообщение номеров сокетов подключившихся клиентов

$socksRead = array(); $socksWrite = array(); $socksError = array(); 	// массивы для изменивших состояние сокетов (с учётом, что они в socket_select() по ссылке, и NULL прямо указать нельзя)
echo "Ready to connection from $gpsdProxyHost:$gpsdProxyPort\n";
$msg = '';
do {
	//$startTime = microtime(TRUE);
	$socksRead = $sockets; 	// мы собираемся читать все сокеты
	$socksRead[] = $masterSock; 	// 
	$socksRead[] = $gpsdSock; 	// 
	// сокет всегда готов для чтения, есть там что-нибудь или нет, поэтому если в socksWrite что-то есть, socket_select никогда не ждёт, возвращая socksWrite неизменённым
	$socksWrite = array(); 	// очистим массив 
	foreach($messages as $n => $data){ 	// пишем только в сокеты, полученные от masterSock путём socket_accept
		if($data['output'])	$socksWrite[] = $sockets[$n]; 	// если есть, что писать -- добавим этот сокет в те, в которые будем писать
	}
	$socksError = $sockets; 	// 
	$socksError[] = $masterSock; 	// 
	$socksError[] = $gpsdSock; 	// 

	//echo "\n\nНачало. Ждём, пока что-нибудь произойдёт";
	/*
	$old_error_handler = set_error_handler(function ($severity, $message, $file, $line) {
		global $sockets,$socksRead,$socksWrite,$socksError;
		echo "\nError\n";
		echo "$severity, $message, $file, $line\n";
		//echo "socksRead\n"; print_r($socksRead);
		//echo "socksWrite\n"; print_r($socksWrite);
		//echo "socksError\n"; print_r($socksError);
		echo "sockets\n";
		foreach($sockets as $id => $sock){
			echo "$id ".gettype($sock)."\n";
		}
		
		echo "socksRead\n";
		foreach($socksRead as $id => $sock){
			echo "$id ".gettype($sock)."\n";
		}
		echo "socksWrite\n";
		foreach($socksWrite as $id => $sock){
			echo "$id ".gettype($sock)."\n";
		}
		echo "socksError\n";
		foreach($socksError as $id => $sock){
			echo "$id ".gettype($sock)."\n";
		}
		
		exit;
	});
	*/
	$num_changed_sockets = socket_select($socksRead, $socksWrite, $socksError, null); 	// должно ждать

	//restore_error_handler();

	// теперь в $socksRead только те сокеты, куда пришли данные, в $socksWrite -- те, откуда НЕ считали
	if (count($socksError)) { 	// Warning не перехватываются
		echo "socket_select: Error on sockets: " . socket_strerror(socket_last_error()) . "\n";
		foreach($socksError as $socket){
			chkSocks($socket);
		}
	}

	//echo "\nЧитаем из сокетов ".count($socksRead)."\n";
	foreach($socksRead as $socket){
		//if($socket == $gpsdSock) echo "Read: gpsd socket\n";
		if($socket == $masterSock) { 	// новое подключение
			$sock = socket_accept($socket); 	// новый сокет для подключившегося клиента
			if(!$sock or (get_resource_type($sock) != 'Socket')) {
				echo "Failed to accept incoming by: " . socket_strerror(socket_last_error($socket)) . "\n";
				chkSocks($socket); 	// recreate masterSock
				continue;
			}
			$sockets[] = $sock; 	// добавим новое входное подключение к имеющимся соединениям
			$n = array_search($sock,$sockets);	// Resource id не может быть ключём массива, поэтому используем порядковый номер. Что стрёмно.
			$messages[$n]['output'][] = $greeting; 	// 	
			//echo "New client connected!                                                        \n";
		    continue; 	// 
		}
		$buf = @socket_read($socket, 2048, PHP_NORMAL_READ); 	// читаем
		#echo "\nbuf=$buf|\n";
		
		if($buf === FALSE) { 	// клиент умер
			//echo "\n\nFailed to read data from socket by: " . socket_strerror(socket_last_error($socket)) . "\n"; 	// в общем-то -- обычное дело. Клиент закрывает соединение, сы об этом узнаём при попытке чтения
			chkSocks($socket);
		    continue;
		}
		
		// Собственно, содержательная часть
		//echo "\nПринято:$buf|\n"; 	// здесь что-то прочитали из какого-то сокета
		$sockKey = array_search($socket,$sockets); 	// 
		if(($socket == $gpsdSock) or ($messages[$sockKey]['PUT'] == TRUE)){ 	// прочитали из соединения с gpsd или это сокет, с которого шлют данные
			$inGpsdData = json_decode($buf,TRUE);
			// А оно надо? Здесь игнорируются устройства, не представленные на этапе установления соединения 
			// в ответ на WATCH. А вновь подключенные?
			/*
			if(!in_array($inGpsdData['device'],$devicePresent)) {  	// это не то устройство, которое потребовали
				continue;
			}
			*/
			//echo "\n inGpsdData\n"; print_r($inGpsdData);
			// Ok, мы получили требуемое
			//if($messages[$sockKey]['PUT'] == TRUE) {
			//	echo "\n Другой источник данных:	\n"; print_r($inGpsdData);
			//}
			updGPSDdata($inGpsdData);
			//echo "\n gpsdData\n"; print_r($gpsdData);
			//echo "\n gpsdData AIS\n"; print_r($gpsdData['AIS']);
		}
		else{ 	// прочитали из клиентского соединения
			$buf = trim($buf);
			//echo "\nПРИНЯТО ОТ КЛИЕНТА:\n$buf\n";
			if($buf[0]!='?'){ 	// это не команда
				continue;
			}
			// выделим команду и параметры
			list($command,$params) = explode('=',$buf);
			$command = trim(explode(';',substr($command,1))[0]); 	// не поддерживаем (пока?) нескольких команд за раз
			$params = trim($params);
			//echo "\nClient $sockKey| command=$command| params=$params|\n";
			if($params) $params = json_decode(substr($params,0,strrpos($params,'}')+1),TRUE);
			// Обработаем команду
			switch($command){
			case 'WATCH': 	// default: ?WATCH={"enable":true};
				if(!$params or count($params)!=1) continue 2; 	// мы понимаем только ?WATCH={"enable":true} и ?WATCH={"enable":false}
				if($params['enable'] == TRUE){
					$messages[$sockKey]['POLL'] = TRUE; 	//
					// вернуть DEVICES
					$msg = array('class' => 'DEVICES', 'devices' => array($gpsdProxydevice));
					$msg = json_encode($msg);
					// вернуть статус WATCH
					$msg .= "\n".'{"class":"WATCH","enable":"true","json":"true"}';
					$messages[$sockKey]['output'][] = $msg;
				}
				elseif($params['enable'] == FALSE){ 	// клиент сказал: всё
					socket_close($socket);
					unset($sockets[$sockKey]);
					unset($messages[$sockKey]);
				}
				break;
			case 'POLL':
				if($messages[$sockKey]['POLL'] !== TRUE) continue 2; 	// на POLL будем отзываться только после ?WATCH={"enable":true}
				$POLL = array(
					"class" => "POLL",
					"time" => time(),
					"active" => 0,
					"tpv" => array(),
					"sky" => array(),
					"ais" => array()
				);
				//echo "\n gpsdData\n"; print_r($gpsdData['TPV']);
				if($gpsdData['TPV']){
					foreach($gpsdData['TPV'] as $device => $data){
						$POLL["active"] ++;
						$POLL["tpv"][] = $data['data'];
					}
				}
				if($gpsdData['AIS']) {
					foreach($gpsdData['AIS'] as $vehicle => $data){
						$data['data']["class"] = "AIS";
						$POLL["ais"][$data['data']['mmsi']] = $data['data'];
					}
				}
				$messages[$sockKey]['output'][] = json_encode($POLL); 	// будем копить сообщения, вдруг клиент не готов их принять
				break;
			case 'CONNECT':
				//echo "\nrecieved CONNECT !\n";
				if(@$params['host'] and @$params['port']) { 	// указано подключиться туда
				}
				else { 	// данные будут из этого сокета
					//echo "\nby CONNECT, begin handshaking\n";
					$newDevices = connectToGPSD($socket);
					if(!$newDevices) break;
					$messages[$sockKey]['PUT'] = TRUE; 	//
					$devicePresent = array_unique(array_merge($devicePresent,$newDevices));
					//echo "\nCONNECT !\n";
				}
				break;
			}
		}
	}
	
	//echo "Пишем в сокеты ".count($socksWrite)."\n";
	// Здесь пишется в сокеты то, что попало в $messages на предыдущем обороте. Тогда соответствующие сокеты проверены на готовность, и готовые попали в $socksWrite. 
	foreach($socksWrite as $socket){
		$n = array_search($socket,$sockets);	// 
		foreach($messages[$n]['output'] as $msg) { 	// все накопленные сообщения
			$msg = $msg."\n";
			$res = socket_write($socket, $msg, strlen($msg));
			if($res === FALSE) { 	// клиент умер
				echo "\n\nFailed to write data to socket by: " . socket_strerror(socket_last_error($sock)) . "\n";
				chkSocks($socket);
				continue 2;
			}
		}
		$messages[$n]['output'] = array();
	}
	
	echo "Has ".(count($sockets))." client socks, and master and gpsd cocks. Ready ".count($socksRead)." read and ".count($socksWrite)." write socks\r";
	//echo "Connected ".(count($sockets))." clients. Ready ".count($socksRead)." read sockets          \r";
} while (true);

foreach($sockets as $socket) {
	socket_close($socket);
}
socket_close($masterSock);
socket_close($gpsdSock);









function IRun() {
/**/
global $phpCLIexec;
$pid = getmypid();
//echo "pid=$pid\n";
//echo "ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." -s$netAISserverURI'\n";
$toFind = pathinfo(__FILE__,PATHINFO_BASENAME);
exec("ps -A w | grep '$toFind'",$psList);
if(!$psList) exec("ps w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)."'",$psList); 	// for OpenWRT. For others -- let's hope so all run from one user
//print_r($psList); //
$run = FALSE;
foreach($psList as $str) {
	if(strpos($str,(string)$pid)!==FALSE) continue;
	if((strpos($str,"$phpCLIexec ")!==FALSE) and (strpos($str,$toFind)!==FALSE)){
		$run=TRUE;
		break;
	}
}
return $run;
}

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
		echo "Failed to binding to $host:$port by: " . socket_strerror(socket_last_error($sock)) . ", wait $i\r";
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
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$sock) {
	echo "Failed to create client socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	//return FALSE;
	exit('1');
}
if(! socket_connect($sock,$host,$port)){ 	// подключаемся к серверу
	echo "Failed to connect to remote server $host:$port by reason: " . socket_strerror(socket_last_error()) . "\n";
	//return FALSE;
	exit('1');
}
echo "Connected to $host:$port!\n";
//$res = socket_write($socket, "\n");
return $sock;
} // end function createSocketClient


function connectToGPSD($gpsdSock){
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$controlClasses = array('VERSION','DEVICES','DEVICE','WATCH');
$WATCHsend = FALSE;
$dataType = $SEEN_GPS | $SEEN_AIS; 	// данные от каких приборов будем принимать от gpsd
//echo "dataType=$dataType;\n";
//echo "\nBegin handshaking with gpsd\n";
do { 	// при каскадном соединении нескольких gpsd заголовков может быть много
	$buf = @socket_read($gpsdSock, 2048, PHP_NORMAL_READ); 	// читаем
	//echo "\nbuf:$buf|\n";
	if($buf === FALSE) { 	// gpsd умер
		//echo "\nFailed to read data from gpsd: " . socket_strerror(socket_last_error()) . "\n";
		chkSocks($gpsdSock);
		//exit();
		return FALSE;
	}
	if (!$buf = trim($buf)) {
		continue;
	}
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
				//exit();
				return FALSE;
			}
			$WATCHsend = TRUE;
			//echo "Sending TURN ON\n";
		}
		break;
	case 'DEVICES': 	// соберём подключенные устройства со всех gpsd, включая slave
		//echo "\nReceived DEVICES\n"; //
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
		//echo "Received WATCH\n"; //
		//print_r($gpsdWATCH); //
		break 2; 	// приветствие завершилось
	}
	
}while($WATCHsend or in_array(@$buf['class'],$controlClasses));
//echo "buf: "; print_r($buf);
if(!$devicePresent) {
	echo "\nno required devices present\n";
	//exit();
	return FALSE;
}
$devicePresent = array_unique($devicePresent);
return $devicePresent;
} // end function connectToGPSD


function updGPSDdata($inGpsdData) {
/**/
global $gpsdData,$gpsdProxyTimeouts,$noVehicleTimeout;
$now = time();
switch($inGpsdData['class']) {
case 'SKY':
	break;
case 'TPV':
	foreach($inGpsdData as $type => $value){ 	// обновим данные
		$gpsdData['TPV'][$inGpsdData['device']]['data'][$type] = $value; 	// php создаёт вложенную структуру, это не python
		$gpsdData['TPV'][$inGpsdData['device']]['cachedTime'][$type] = $now;
	}
	// Проверим актуальность всех данных
	foreach($gpsdData['TPV'][$inGpsdData['device']]['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
		if(($gpsdData['TPV'][$inGpsdData['device']]['data'][$type] !== NULL) and $gpsdProxyTimeouts['TPV'][$type] and (($now - $cachedTime) > $gpsdProxyTimeouts['TPV'][$type])) {
			//echo "\n gpsdData\n"; print_r($gpsdData['TPV'][$inGpsdData['device']]['data']);
			//$gpsdData['TPV'][$inGpsdData['device']]['data'][$type] = NULL;
			unset($gpsdData['TPV'][$inGpsdData['device']]['data'][$type]);
			//echo "Данные ".$type." от устройства ".$inGpsdData['device']." протухли.                     \n";
		}
	}
	break;
case 'netAIS':
	foreach($inGpsdData['data'] as $vehicle => $data){
		$timestamp = $data['timestamp'];
		if(!$timestamp) $timestamp = $now;
		$gpsdData['AIS'][$vehicle]['timestamp'] = $timestamp;
		foreach($data as $type => $value){
			$gpsdData['AIS'][$vehicle]['data'][$type] = $value; 	// 
			$gpsdData['AIS'][$vehicle]['cachedTime'][$type] = $timestamp;
		}
		foreach($gpsdData['AIS'][$vehicle]['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
			if(($gpsdData['AIS'][$vehicle]['data'][$type] !== NULL) and $gpsdProxyTimeouts['AIS'][$type] and (($now - $cachedTime) > $gpsdProxyTimeouts['AIS'][$type])) {
				//echo "\n gpsdData\n"; print_r($gpsdData$gpsdData['AIS'][$vehicle]['data']);
				//$gpsdData['AIS'][$vehicle]['data'][$type] = NULL;
				unset($gpsdData['AIS'][$vehicle]['data'][$type]);
				//echo "Данные AIS".$type." для судна ".$vehicle." протухли.                                       \n";
			}
		}
	}
	break;
case 'AIS':
	//echo "JSON AIS Data:\n"; print_r($inGpsdData); echo "\n";
	$vehicle = trim((string)$inGpsdData['mmsi']);
	$aisVehicles[] = $vehicle;
	$gpsdData['AIS'][$vehicle]['data']['mmsi'] = $vehicle;
	if($inGpsdData['netAIS']) $gpsdData['AIS'][$vehicle]['netAIS'] = 1; 	// 
	//echo "\n AIS sentence type ".$inGpsdData['type']."\n";
	switch($inGpsdData['type']) {
	case 27:
	case 18:
	case 19:
	case 1:
	case 2:
	case 3:		// http://www.e-navigation.nl/content/position-report
		$gpsdData['AIS'][$vehicle]['data']['status'] = (int)filter_var($inGpsdData['status'],FILTER_SANITIZE_NUMBER_INT); 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
		$gpsdData['AIS'][$vehicle]['data']['status_text'] = filter_var($inGpsdData['status_text'],FILTER_SANITIZE_STRING);
		$gpsdData['AIS'][$vehicle]['cachedTime']['status'] = $now;
		$gpsdData['AIS'][$vehicle]['data']['accuracy'] = (int)filter_var($inGpsdData['accuracy'],FILTER_SANITIZE_NUMBER_INT); 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
		$gpsdData['AIS'][$vehicle]['cachedTime']['accuracy'] = $now;
		if($inGpsdData['turn']){
			if($inGpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
				//$gpsdData['AIS'][$vehicle]['data']['turn'] = $inGpsdData['turn']; 	// градусы в минуту со знаком или строка? one of the strings "fastright" or "fastleft" if it is out of the AIS encoding range; otherwise it is quadratically mapped back to the turn sensor number in degrees per minute
			}
			else {
				//$gpsdData['AIS'][$vehicle]['data']['turn'] = (int)filter_var($inGpsdData['turn'],FILTER_SANITIZE_NUMBER_INT); 	// тут чёта сложное...  Rate of turn ROTAIS 0 to +126 = turning right at up to 708° per min or higher 0 to –126 = turning left at up to 708° per min or higher Values between 0 and 708° per min coded by ROTAIS = 4.733 SQRT(ROTsensor) degrees per min where  ROTsensor is the Rate of Turn as input by an external Rate of Turn Indicator (TI). ROTAIS is rounded to the nearest integer value. +127 = turning right at more than 5° per 30 s (No TI available) –127 = turning left at more than 5° per 30 s (No TI available) –128 (80 hex) indicates no turn information available (default). ROT data should not be derived from COG information.
			}
			$gpsdData['AIS'][$vehicle]['cachedTime']['turn'] = $now;
		}
		if($inGpsdData['type'] == 27) { 	// оказывается, там координаты в 1/10 минуты и скорость в узлах!!!
			if($inGpsdData['lon'] or $inGpsdData['lat']){
				if($inGpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
					$gpsdData['AIS'][$vehicle]['data']['lon'] = (float)$inGpsdData['lon']; 	// 
					$gpsdData['AIS'][$vehicle]['data']['lat'] = (float)$inGpsdData['lat'];
				}
				else {
					if($inGpsdData['lon']==181) $gpsdData['AIS'][$vehicle]['data']['lon'] = NULL;
					else $gpsdData['AIS'][$vehicle]['data']['lon'] = (float)filter_var($inGpsdData['lon'],FILTER_SANITIZE_NUMBER_FLOAT)/(10*60); 	// Longitude in degrees	( 1/10 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
					if($inGpsdData['lat']==91) $gpsdData['AIS'][$vehicle]['data']['lat'] = NULL;
					else $gpsdData['AIS'][$vehicle]['data']['lat'] = (float)filter_var($inGpsdData['lat'],FILTER_SANITIZE_NUMBER_FLOAT)/(10*60); 	// Latitude in degrees (1/10 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
				}
				$gpsdData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
				$gpsdData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
			}
			if($inGpsdData['speed']==63) $gpsdData['AIS'][$vehicle]['data']['speed'] = NULL;
			else $gpsdData['AIS'][$vehicle]['data']['speed'] = (float)filter_var($inGpsdData['speed'],FILTER_SANITIZE_NUMBER_FLOAT)*1852/3600; 	// SOG Speed over ground in m/sec 	Knots (0-62); 63 = not available = default
			$gpsdData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
			if($inGpsdData['course']==511) $gpsdData['AIS'][$vehicle]['data']['course'] = NULL;
			else $gpsdData['AIS'][$vehicle]['data']['course'] = (float)filter_var($inGpsdData['course'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Путевой угол. COG Course over ground in degrees Degrees (0-359); 511 = not available = default
			$gpsdData['AIS'][$vehicle]['cachedTime']['course'] = $now;
		}
		else {
			if($inGpsdData['lon'] or $inGpsdData['lat']){
				if($inGpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
					$gpsdData['AIS'][$vehicle]['data']['lon'] = (float)$inGpsdData['lon']; 	// 
					$gpsdData['AIS'][$vehicle]['data']['lat'] = (float)$inGpsdData['lat'];
				}
				else {
					if($inGpsdData['lon']==181) $gpsdData['AIS'][$vehicle]['data']['lon'] = NULL;
					else $gpsdData['AIS'][$vehicle]['data']['lon'] = (float)filter_var($inGpsdData['lon'],FILTER_SANITIZE_NUMBER_FLOAT)/(10000*60); 	// Longitude in degrees	( 1/10 000 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
					if($inGpsdData['lat']==91) $gpsdData['AIS'][$vehicle]['data']['lat'] = NULL;
					else $gpsdData['AIS'][$vehicle]['data']['lat'] = (float)filter_var($inGpsdData['lat'],FILTER_SANITIZE_NUMBER_FLOAT)/(10000*60); 	// Latitude in degrees (1/10 000 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
				}
				$gpsdData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
				$gpsdData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
			}
			if($inGpsdData['speed']){
				if($inGpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
					$gpsdData['AIS'][$vehicle]['data']['speed'] = ((int)$inGpsdData['speed']*1852)/(60*60); 	// SOG Speed over ground in m/sec 	
				}
				else {
					if($inGpsdData['speed']>1022) $gpsdData['AIS'][$vehicle]['data']['speed'] = NULL;
					else $gpsdData['AIS'][$vehicle]['data']['speed'] = (float)filter_var($inGpsdData['speed'],FILTER_SANITIZE_NUMBER_FLOAT)*185.2/3600; 	// SOG Speed over ground in m/sec 	(in 1/10 knot steps (0-102.2 knots) 1 023 = not available, 1 022 = 102.2 knots or higher)
				}
				$gpsdData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
			}
			if($inGpsdData['course']==3600) $gpsdData['AIS'][$vehicle]['data']['course'] = NULL;
			else $gpsdData['AIS'][$vehicle]['data']['course'] = (float)filter_var($inGpsdData['course'],FILTER_SANITIZE_NUMBER_FLOAT)/10; 	// Путевой угол. COG Course over ground in degrees ( 1/10 = (0-3599). 3600 (E10h) = not available = default. 3601-4095 should not be used)
			$gpsdData['AIS'][$vehicle]['cachedTime']['course'] = $now;
		}
		if($inGpsdData['heading']==511) $gpsdData['AIS'][$vehicle]['data']['heading'] = NULL;
		else $gpsdData['AIS'][$vehicle]['data']['heading'] = (float)filter_var($inGpsdData['heading'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Истинный курс. True heading Degrees (0-359) (511 indicates not available = default)
		$gpsdData['AIS'][$vehicle]['cachedTime']['heading'] = $now;
		if($inGpsdData['second']>59) $gpsdData['AIS'][$vehicle]['timestamp'] = time();
		else $gpsdData['AIS'][$vehicle]['timestamp'] = time() - (int)filter_var($inGpsdData['second'],FILTER_SANITIZE_NUMBER_INT); 	// Unis timestamp. Time stamp UTC second when the report was generated by the electronic position system (EPFS) (0-59, or 60 if time stamp is not available, which should also be the default value, or 61 if positioning system is in manual input mode, or 62 if electronic position fixing system operates in estimated (dead reckoning) mode, or 63 if the positioning system is inoperative)
		$gpsdData['AIS'][$vehicle]['data']['maneuver'] = (int)filter_var($inGpsdData['maneuver'],FILTER_SANITIZE_NUMBER_INT); 	// Special manoeuvre indicator 0 = not available = default 1 = not engaged in special manoeuvre 2 = engaged in special manoeuvre (i.e. regional passing arrangement on Inland Waterway)
		$gpsdData['AIS'][$vehicle]['cachedTime']['maneuver'] = $now;
		$gpsdData['AIS'][$vehicle]['data']['raim'] = (int)filter_var($inGpsdData['raim'],FILTER_SANITIZE_NUMBER_INT); 	// RAIM-flag Receiver autonomous integrity monitoring (RAIM) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use. See Table 50
		$gpsdData['AIS'][$vehicle]['data']['radio'] = (string)$inGpsdData['radio']; 	// Communication state
		//break; 	//comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1. Но gpsdAISd не имеет дела с netAIS?
	case 5: 	// http://www.e-navigation.nl/content/ship-static-and-voyage-related-data
	case 24: 	// Vendor ID не поддерживается http://www.e-navigation.nl/content/static-data-report
		//echo "JSON inGpsdData: \n"; print_r($inGpsdData); echo "\n";
		if($inGpsdData['imo']) $gpsdData['AIS'][$vehicle]['data']['imo'] = (string)$inGpsdData['imo']; 	// IMO number 0 = not available = default – Not applicable to SAR aircraft 0000000001-0000999999 not used 0001000000-0009999999 = valid IMO number; 0010000000-1073741823 = official flag state number.
		if($inGpsdData['ais_version']) $gpsdData['AIS'][$vehicle]['data']['ais_version'] = (int)filter_var($inGpsdData['ais_version'],FILTER_SANITIZE_NUMBER_INT); 	// AIS version indicator 0 = station compliant with Recommendation ITU-R M.1371-1; 1 = station compliant with Recommendation ITU-R M.1371-3 (or later); 2 = station compliant with Recommendation ITU-R M.1371-5 (or later); 3 = station compliant with future editions
		if($inGpsdData['callsign']=='@@@@@@@') $gpsdData['AIS'][$vehicle]['data']['callsign'] = NULL;
		elseif($inGpsdData['callsign']) $gpsdData['AIS'][$vehicle]['data']['callsign'] = (string)$inGpsdData['callsign']; 	// Call sign 7 x 6 bit ASCII characters, @@@@@@@ = not available = default. Craft associated with a parent vessel, should use “A” followed by the last 6 digits of the MMSI of the parent vessel. Examples of these craft include towed vessels, rescue boats, tenders, lifeboats and liferafts.
		if($inGpsdData['shipname']=='@@@@@@@@@@@@@@@@@@@@') $gpsdData['AIS'][$vehicle]['data']['shipname'] = NULL;
		elseif($inGpsdData['shipname']) $gpsdData['AIS'][$vehicle]['data']['shipname'] = filter_var($inGpsdData['shipname'],FILTER_SANITIZE_STRING); 	// Maximum 20 characters 6 bit ASCII, as defined in Table 47 “@@@@@@@@@@@@@@@@@@@@” = not available = default. The Name should be as shown on the station radio license. For SAR aircraft, it should be set to “SAR AIRCRAFT NNNNNNN” where NNNNNNN equals the aircraft registration number.
		if($inGpsdData['shiptype']) $gpsdData['AIS'][$vehicle]['data']['shiptype'] = (int)filter_var($inGpsdData['shiptype'],FILTER_SANITIZE_NUMBER_INT); 	// Type of ship and cargo type 0 = not available or no ship = default 1-99 = as defined in § 3.3.2 100-199 = reserved, for regional use 200-255 = reserved, for future use Not applicable to SAR aircraft
		if($inGpsdData['shiptype_text']) $gpsdData['AIS'][$vehicle]['data']['shiptype_text'] = filter_var($inGpsdData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
		if($inGpsdData['to_bow']) $gpsdData['AIS'][$vehicle]['data']['to_bow'] = (float)filter_var($inGpsdData['to_bow'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position. Also indicates the dimension of ship (m) (see Fig. 42 and § 3.3.3) For SAR aircraft, the use of this field may be decided by the responsible administration. If used it should indicate the maximum dimensions of the craft. As default should A = B = C = D be set to “0”
		if($inGpsdData['to_stern']) $gpsdData['AIS'][$vehicle]['data']['to_stern'] = (float)filter_var($inGpsdData['to_stern'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
		if($inGpsdData['to_port']) $gpsdData['AIS'][$vehicle]['data']['to_port'] = (float)filter_var($inGpsdData['to_port'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
		if($inGpsdData['to_starboard']) $gpsdData['AIS'][$vehicle]['data']['to_starboard'] = (float)filter_var($inGpsdData['to_starboard'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
		$gpsdData['AIS'][$vehicle]['data']['epfd'] = (int)filter_var($inGpsdData['epfd'],FILTER_SANITIZE_NUMBER_INT); 	// Type of electronic position fixing device. 0 = undefined (default) 1 = GPS 2 = GLONASS 3 = combined GPS/GLONASS 4 = Loran-C 5 = Chayka 6 = integrated navigation system 7 = surveyed 8 = Galileo, 9-14 = not used 15 = internal GNSS
		$gpsdData['AIS'][$vehicle]['data']['epfd_text'] = (string)$inGpsdData['epfd_text']; 	// 
		$gpsdData['AIS'][$vehicle]['data']['eta'] = (string)$inGpsdData['eta']; 	// ETA Estimated time of arrival; MMDDHHMM UTC Bits 19-16: month; 1-12; 0 = not available = default  Bits 15-11: day; 1-31; 0 = not available = default Bits 10-6: hour; 0-23; 24 = not available = default Bits 5-0: minute; 0-59; 60 = not available = default For SAR aircraft, the use of this field may be decided by the responsible administration
		if($inGpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
			if($inGpsdData['draught']) $gpsdData['AIS'][$vehicle]['data']['draught'] = (float)$inGpsdData['draught']; 	// в метрах
		}
		else {
			if($inGpsdData['draught']) $gpsdData['AIS'][$vehicle]['data']['draught'] = (float)filter_var($inGpsdData['draught'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Maximum present static draught In m ( 1/10 m, 255 = draught 25.5 m or greater, 0 = not available = default; in accordance with IMO Resolution A.851 Not applicable to SAR aircraft, should be set to 0)
		}
		$gpsdData['AIS'][$vehicle]['data']['destination'] = filter_var($inGpsdData['destination'],FILTER_SANITIZE_STRING); 	// Destination Maximum 20 characters using 6-bit ASCII; @@@@@@@@@@@@@@@@@@@@ = not available For SAR aircraft, the use of this field may be decided by the responsible administration
		$gpsdData['AIS'][$vehicle]['data']['dte'] = (int)filter_var($inGpsdData['dte'],FILTER_SANITIZE_NUMBER_INT); 	// DTE Data terminal equipment (DTE) ready (0 = available, 1 = not available = default) (see § 3.3.1)
		//break; 	// comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1
	case 6: 	// http://www.e-navigation.nl/asm  http://192.168.10.10/gpsd/AIVDM.adoc
	case 8: 	// 
		//echo "JSON inGpsdData:\n"; print_r($inGpsdData); echo "\n";
		$gpsdData['AIS'][$vehicle]['data']['dac'] = (string)$inGpsdData['dac']; 	// Designated Area Code
		$gpsdData['AIS'][$vehicle]['data']['fid'] = (string)$inGpsdData['fid']; 	// Functional ID
		if($inGpsdData['vin']) $gpsdData['AIS'][$vehicle]['data']['vin'] = (string)$inGpsdData['vin']; 	// European Vessel ID
		if($inGpsdData['length']) $gpsdData['AIS'][$vehicle]['data']['length'] = (float)filter_var($inGpsdData['length'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Length of ship in m
		if($inGpsdData['beam']) $gpsdData['AIS'][$vehicle]['data']['beam'] = (float)filter_var($inGpsdData['beam'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Beam of ship in m
		if(!$gpsdData['AIS'][$vehicle]['data']['shiptype']) $gpsdData['AIS'][$vehicle]['data']['shiptype'] = (string)$inGpsdData['shiptype']; 	// Ship/combination type ERI Classification В какой из посылок тип правильный - неизвестно, поэтому будем брать только из одной
		if(!$gpsdData['AIS'][$vehicle]['data']['shiptype_text'])$gpsdData['AIS'][$vehicle]['data']['shiptype_text'] = filter_var($inGpsdData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
		$gpsdData['AIS'][$vehicle]['data']['hazard'] = (int)filter_var($inGpsdData['hazard'],FILTER_SANITIZE_NUMBER_INT); 	// Hazardous cargo | 0 | 0 blue cones/lights | 1 | 1 blue cone/light | 2 | 2 blue cones/lights | 3 | 3 blue cones/lights | 4 | 4 B-Flag | 5 | Unknown (default)
		$gpsdData['AIS'][$vehicle]['data']['hazard_text'] = filter_var($inGpsdData['hazard_text'],FILTER_SANITIZE_STRING); 	// 
		if(!$gpsdData['AIS'][$vehicle]['data']['draught']) $gpsdData['AIS'][$vehicle]['data']['draught'] = (float)filter_var($inGpsdData['draught'],FILTER_SANITIZE_NUMBER_INT)/100; 	// Draught in m ( 1-200 * 0.01m, default 0)
		$gpsdData['AIS'][$vehicle]['data']['loaded'] = (int)filter_var($inGpsdData['loaded'],FILTER_SANITIZE_NUMBER_INT); 	// Loaded/Unloaded | 0 | N/A (default) | 1 | Unloaded | 2 | Loaded
		$gpsdData['AIS'][$vehicle]['data']['loaded_text'] = filter_var($inGpsdData['loaded_text'],FILTER_SANITIZE_STRING); 	// 
		$gpsdData['AIS'][$vehicle]['data']['speed_q'] = (int)filter_var($inGpsdData['speed_q'],FILTER_SANITIZE_NUMBER_INT); 	// Speed inf. quality 0 = low/GNSS (default) 1 = high
		$gpsdData['AIS'][$vehicle]['data']['course_q'] = (int)filter_var($inGpsdData['course_q'],FILTER_SANITIZE_NUMBER_INT); 	// Course inf. quality 0 = low/GNSS (default) 1 = high
		$gpsdData['AIS'][$vehicle]['data']['heading_q'] = (int)filter_var($inGpsdData['heading_q'],FILTER_SANITIZE_NUMBER_INT); 	// Heading inf. quality 0 = low/GNSS (default) 1 = high
		break;
	}

	if($gpsdData['AIS'][$vehicle]['cachedTime']){
		foreach($gpsdData['AIS'][$vehicle]['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
			if(($gpsdData['AIS'][$vehicle]['data'][$type] !== NULL) and $gpsdProxyTimeouts['AIS'][$type] and (($now - $cachedTime) > $gpsdProxyTimeouts['AIS'][$type])) {
				//echo "\n gpsdData\n"; print_r($gpsdData$gpsdData['AIS'][$vehicle]['data']);
				//$gpsdData['AIS'][$vehicle]['data'][$type] = NULL;
				unset($gpsdData['AIS'][$vehicle]['data'][$type]);
				//echo "Данные AIS".$type." для судна ".$vehicle." протухли.                                       \n";
			}
		}
	}
}
// if AIS target present?
if($gpsdData['AIS']) { 	// может не быть
	foreach($gpsdData['AIS'] as $id => $vehicle){
		if(($now - $vehicle['timestamp'])>$noVehicleTimeout) unset($gpsdData['AIS'][$id]); 	// удалим цель, последний раз обновлявшуюся давно
		/*
		// удалим цель AIS, все контролируемые параметры которой протухли.
		// но юзер может добавить вечный параметр?
		$noInfo = TRUE;
		foreach($gpsdProxyTimeouts['AIS'] as $type){
			if($vehicle['data'][$type]){
				$noInfo = FALSE;
				break;
			}
		}
		if($noInfo) unset($gpsdData['AIS'][$vehicle]); 	
		*/
	}
}
//echo "\n gpsdData\n"; print_r($gpsdData);
//echo "\n gpsdData AIS\n"; print_r($gpsdData['AIS']);
} // end function updGPSDdata


function chkSocks($socket) {
/**/
global $gpsdSock, $masterSock, $sockets, $socksRead, $socksWrite, $socksError, $messages, $devicePresent;
if($socket == $gpsdSock){ 	// умерло соединение с gpsd
	echo "\nGPSD socket die. Try to reconnect.\n";
	socket_close($gpsdSock);
	$gpsdSock = createSocketClient($gpsdProxyGPSDhost,$gpsdProxyGPSDport); 	// Соединение с gpsd
	echo "Socket to gpsd reopen, do handshaking\n";
	$newDevices = connectToGPSD($gpsdSock);
	if(!$newDevices) exit("gpsd not run or no required devices present, bye       \n");
	$devicePresent = array_unique(array_merge($devicePresent,$newDevices));
	echo "New handshaking, will recieve data from gpsd\n";
}
elseif($socket == $masterSock){ 	// умерло входящее подключение
	echo "\nIncoming socket die. Try to recreate.\n";
	socket_close($masterSock);
	$masterSock = createSocketServer($gpsdProxyHost,$gpsdProxyPort,20); 	// Входное соединение
}
else {
	$n = array_search($socket,$sockets);	// 
	//echo "\nError in socket with # $n ant type ".gettype($socket)."\n";
	unset($sockets[$n]);
	unset($messages[$n]);
	$n = array_search($socket,$socksRead);	// 
	unset($socksRead[$n]);
	$n = array_search($socket,$socksWrite);	// 
	unset($socksWrite[$n]);
	$n = array_search($socket,$socksError);	// 
	unset($socksError[$n]);
	socket_close($socket);
}
//echo "\nchkSocks sockets: "; print_r($sockets);
} // end function chkSocks
?>
