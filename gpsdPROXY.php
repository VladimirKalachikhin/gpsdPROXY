<?php
/* Демон.
Кеширует данные TPV и AIS от gpsd, и отдаёт их по запросу ?POLL; протокола gpsd

Кроме того, можно обратиться к демону с запросом ?WATCH={“enable”:true,“json”:true} и получить поток. Можно
обратиться по протоколу websocket -- скорее всего, будет работать.

Daemon
Caches TPV and AIS data from gpsd, and returns them on request ?POLL; of the gpsd protocol
As side: daemon keeps instruments alive and power consuming.  

The ?WATCH={“enable”:true,“json”:true} mode also available: via cocket or websocket. 
The websocket is partially implemented but mostly work.

Зачем это надо: 
Details:
https://lists.nongnu.org/archive/html/gpsd-users/2021-06/msg00017.html
https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00098.html

Call:
$ nc localhost 3838
$ cgps localhost:3838
$ telnet localhost 3838
*/
/*
Version 0.5.0

0.5.0	rewritten to module structure and add VenusOS data source. Used https://github.com/bluerhinos/phpMQTT with big changes.
0.4.1	remove lat lon from WATCH flow if mode < 2 (no fix). On POLL stay as received.
*/
chdir(__DIR__); // задаем директорию выполнение скрипта

require('fCommon.php'); 	// 
require('params.php'); 	// 

if(IRun()) { 	// Я ли?
	echo "I'm already running, exiting.\n"; 
	return;
}

// Self data
// собственно, собираемые / кешируемые данные
@mkdir(pathinfo($backupFileName, PATHINFO_DIRNAME));
if(@filemtime($backupFileName)<(time()-86400)) @unlink($backupFileName);	// файл был обновлён более суток назад
else $instrumentsData = @json_decode(@file_get_contents($backupFileName), true);
if(!$instrumentsData) $instrumentsData = array(); 	
$lastBackupSaved = 0;	// время последнего сохранения кеша
$lastClientExchange = 0;	// время последней коммуникации какого-нибудь клиента

$greeting = '{"class":"VERSION","release":"gpsdPROXY_0","rev":"beta","proto_major":3,"proto_minor":0}';
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$gpsdProxydevice = array(
'class' => 'DEVICE',
'path' => 'gpsdPROXY',
'activated' => date('c'),
'flags' => $SEEN_GPS | $SEEN_AIS,
'stopbits' => 1
);
if(!$gpsdProxyHost) $gpsdProxyHost='localhost'; 	// я сам. Хост/порт для обращения к gpsdProxy
if(!$gpsdProxyPort) $gpsdProxyPort=3838;
$pollWatchExist = FALSE;	// флаг, что есть сокеты в режиме WATCH, когда данные им посылаются непрерывно
$minSocketTimeout = 86400;	// сек., сутки
// определим, какое минимальное время протухания величины указано в конфиге
array_walk_recursive($gpsdProxyTimeouts, function($val){
											global $minSocketTimeout;
											if(is_numeric($val) and ($val<$minSocketTimeout)) $minSocketTimeout = $val;
										});
//echo "minSocketTimeout=$minSocketTimeout;\n";





// Поехали
$dataSock = NULL; 	// Сокет к источнику данных, может не быть, как оно в VenusOS. Определяется в require
// Определим, к кому подключаться для получения данных
switch($dataSourceType){
case 'venusos':
	if(!$dataSourceHost) $dataSourceHost = 'localhost';	
	if(!$dataSourcePort) $dataSourcePort = 1883;	
	$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
	if($res){
		if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
		echo "Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
		require('venusos.php');
	}
	break;
/*
case 'signalk':
	if(!$dataSourceHost) $dataSourceHost = 'localhost';	
	if(!$dataSourcePort) $dataSourcePort = 3000;	
	if($res = chkGPSDpresent($dataSourceHost,$dataSourcePort)){
		if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
		echo "Found Signal K\n";
		require('signalk.php');
	}
	break;
*/
default:	// gpsd
	if(!$dataSourceHost) $dataSourceHost = 'localhost';	
	if(!$dataSourcePort) $dataSourcePort = 2947;	
	if(chkGPSDpresent($dataSourceHost,$dataSourcePort)) {
		echo "Found gpsd on $dataSourceHost:$dataSourcePort\n";
		require('gpsd.php');
	}
	else { 	// попробуем Signal K
		$dataSourceHost = 'localhost';	
		$dataSourcePort = 3000;	
		$res = chkSignalKpresent($dataSourceHost,$dataSourcePort);
		if($res){
			if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
			echo "Found Signal K on $dataSourceHost:$dataSourcePort\n";
			require('signalk.php');
		}
		else {	// попробуем VenusOS
			$dataSourceHost = 'localhost';	
			$dataSourcePort = 1883;	
			$res = chkVenusOSpresent($dataSourceHost,$dataSourcePort);
			if($res){
				if(is_array($res)) list($dataSourceHost,$dataSourcePort) = $res;
				echo "Found VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort\n";
				require('venusos.php');
			}
		}
	}
}
if(!$dataSourceHumanName) {	// обязательно должна быть в файле require
	echo "No any data source found, exiting.\n"; 
	return;
}
$sockets = array(); 	// список функционирующих сокетов
$masterSock = createSocketServer($gpsdProxyHost,$gpsdProxyPort,20); 	// Соединение для приёма клиентов, входное соединение
//echo "masterSock=$masterSock; gpsdSock=$dataSock;\n";

// Подключимся к источнику данных
echo "Socket to $dataSourceHumanName opened, do handshaking                                   \n";
$devicePresent = dataSourceConnect($dataSourceConnectionObject);	// реально $devicePresent нигде не используются, кроме как ниже. Можно использовать как-нибудь?
//var_dump($devicePresent);
if($devicePresent===FALSE) exit("Handshaking fail: MQTT server in $dataSourceHumanName on $dataSourceHost:$dataSourcePort not answer, bye     \n");
echo "Handshaked, will recieve data from $dataSourceHumanName\n";
if(!$devicePresent) echo"but no required devices present     \n";

$messages = array(); 	// 
/*$messages: массив "номер сокета в массиве $sockets" => "массив [
'output'=> array(сообщений), // сообщения для отправки через этот сокет на следующем обороте
'PUT'=>TRUE/FALSE,	// признак, что данные надо брать из этого сокета, а не от gpsd. А оно надо?
'POLL'=>TRUE/FALSE/WATCH,	// признак режима, в котором функционирует сейчас этот сокет
'greeting'=>TRUE/FALSE,	// признак, что приветствие протокола gpsd послано
'inBuf'=>''	// буфер для сбора строк обращения клиента, когда их больше одной
'protocol'=>''/'WS'	// признак, что общение происходит по протоколу socket (''), или websocket ('WS')
'zerocnt' => 0	// счётчик подряд посланных пустых сообщений. 
]" номеров сокетов подключившихся клиентов
*/
$dataSourceZeroCNT = 0;	// счётчик пустых строк, пришедших подряд от источника данных

$socksRead = array(); $socksWrite = array(); $socksError = array(); 	// массивы для изменивших состояние сокетов (с учётом, что они в socket_select() по ссылке, и NULL прямо указать нельзя)
echo "gpsdPROXY ready to connection on $gpsdProxyHost:$gpsdProxyPort\n";
do {
	//$startTime = microtime(TRUE);
	$socksRead = $sockets; 	// мы собираемся читать все сокеты
	$socksRead[] = $masterSock; 	// 
	if($sockets) {
		if($dataSock){	// может не быть, если данные берутся не прямо из сокета, а через какую-нибудь прокладку, как для VenusOS
			//echo "gpsdSock=$dataSock; gettype(gpsdSock):".gettype($dataSock).";\n";
			if(gettype($dataSock)==='resource (closed)') chkSocks($dataSock);	// а как ещё узнать, что сокет закрыт? Массив error socket_select не помогает.
			$socksRead[] = $dataSock; 	// есть клиенты -- нам нужно соединение с источником данных
			$socksError[] = $dataSock; 	// 
		}
		$info = " and $dataSourceHumanName";
	}
	else {	// клиентов нет -- можно закрыть соединеие с источником данных, чтобы он заснул приёмник гпс.
		if((time()-$lastClientExchange)>$noClientTimeout){
			if( dataSourceClose($dataSourceConnectionObject)){
				echo "\n$dataSourceHumanName socket closed by no clients\n";
			}
			else echo "No clients                                                          \r";
			$info = "";
		}
	}
	// сокет всегда готов для чтения, есть там что-нибудь или нет, поэтому если в socksWrite что-то есть, socket_select никогда не ждёт, возвращая socksWrite неизменённым
	$socksWrite = array(); 	// очистим массив 
	foreach($messages as $n => $data){ 	// пишем только в сокеты, полученные от masterSock путём socket_accept
		if($data['output'])	$socksWrite[] = $sockets[$n]; 	// если есть, что писать -- добавим этот сокет в те, в которые будем писать
	}
	//print_r($socksRead);
	$socksError = $sockets; 	// 
	$socksError[] = $masterSock; 	// 

	//echo "\n\nНачало. Ждём, пока что-нибудь произойдёт\n";
	if($pollWatchExist) $SocketTimeout = $minSocketTimeout;	// в принципе, $SocketTimeout можно назначать вместе с $pollWatchExist?
	else $SocketTimeout = 30;	// при тишине раз в  провернём цикл на предмет очистки от умерших сокетов
	if(function_exists('altReadData')){
		// Возьмём откуда-то данные каким-то левым способом. Применяется для venusos
		if( altReadData($dataSourceConnectionObject) ) $SocketTimeout = 0;	// если данные были получены слева, из надо обработать, поэтому отключим ожидание чтения сокета
		//else $SocketTimeout = $minSocketTimeout;	// если левый поток данных прервётся -- основной цикл обернётся только через $minSocketTimeout. Считаем, что возобновившийся поток не переполнит буфер сокета, и мф получим всё, что туда накидали. Но оно не надо, POLL пусть сам собирает данные.
	}
	//echo "pollWatchExist=$pollWatchExist; minSocketTimeout=$minSocketTimeout; SocketTimeout=$SocketTimeout;        \n";
	$num_changed_sockets = socket_select($socksRead, $socksWrite, $socksError, $SocketTimeout); 	// должно ждать
	echo "Has ".(count($sockets))." client socks, and master$info socks. Ready ".count($socksRead)." read and ".count($socksWrite)." write socks\r";	// в начале, потому что continue

	// теперь в $socksRead только те сокеты, куда пришли данные, в $socksWrite -- те, откуда НЕ считали, т.е., не было, что читать, но они готовы для чтения
	if ($socksError) { 	// Warning не перехватываются, включая supplied resource is not a valid Socket resource И смысл?
		echo "socket_select: Error on sockets: " . socket_strerror(socket_last_error()) . "\n";
		foreach($socksError as $socket){
			chkSocks($socket);
		}
	}

	//echo "\n Пишем в сокеты ".count($socksWrite)."\n"; //////////////////
	// Здесь пишется в сокеты то, что попало в $messages на предыдущем обороте. Тогда соответствующие сокеты проверены на готовность, и готовые попали в $socksWrite. 
	// в ['output'] всегда текст или массив из текста [0] и параметров передачи (для websocket)
	foreach($socksWrite as $socket){
		$n = array_search($socket,$sockets);	// 
		foreach($messages[$n]['output'] as &$msg) { 	// все накопленные сообщения. & для экономии памяти, но что-то не экономится...
			//echo "to $n:\n|$msg|\n";
			$msgParams = null;
			if(is_array($msg)) list($msg,$msgParams) = $msg;	// второй элемент -- тип фрейма
			switch($messages[$n]['protocol']){
			case 'WS':
				$msg = wsEncode($msg,$msgParams);	
				break;
			case 'WS handshake':
				$messages[$n]['protocol'] = 'WS';
			}
			
			$msgLen = strlen($msg);
			$res = socket_write($socket, $msg, $msgLen);
			if($res === FALSE) { 	// клиент умер
				echo "\n\nFailed to write data to socket by: " . socket_strerror(socket_last_error($sock)) . "\n";
				chkSocks($socket);
				continue 3;	// к следующему сокету
			}
			elseif($res <> $msgLen){	// клиент не принял всё. У него проблемы?
				echo "\n\nNot all data was writed to socket by: " . socket_strerror(socket_last_error($sock)) . "\n";
				chkSocks($socket);
				continue 3;	// к следующему сокету
			}
			$lastClientExchange = time();
		}
		$messages[$n]['output'] = array();
		unset($msg);
	}
	
	//echo "\n Читаем из сокетов ".count($socksRead)."\n"; ///////////////////////
	if(!$socksRead and !$socksWrite) { 	// socket_select прошёл по таймауту 
		//echo "\nSockets read timeout!       \n";
		updAndPrepare(array());	// проверим кеш на предмет протухших данных
		continue;
	}
	foreach($socksRead as $socket){
		//if($socket == $dataSock) echo "Read: $dataSourceHumanName socket\n";
		if($socket == $masterSock) { 	// новое подключение
			$sock = socket_accept($socket); 	// новый сокет для подключившегося клиента
			if(!$sock or (get_resource_type($sock) != 'Socket')) {
				echo "Failed to accept incoming by: " . socket_strerror(socket_last_error($socket)) . "\n";
				chkSocks($socket); 	// recreate masterSock
				continue;
			}
			$lastClientExchange = time();
			$sockets[] = $sock; 	// добавим новое входное подключение к имеющимся соединениям
			$sockKey = array_search($sock,$sockets);	// Resource id не может быть ключём массива, поэтому используем порядковый номер. Что стрёмно.
			$messages[$sockKey]['greeting']=FALSE;	// укажем, что приветствие не посылали. Запрос может быть не только как к gpsd, но и как к серверу websocket
			//echo "New client connected:$sockKey!                                                      \n";
		    continue; 	// 
		}
		// Читаем сокет
		$sockKey = @array_search($socket,$sockets); 	// 
		socket_clear_error($socket);
		if($messages[$sockKey]['protocol']=='WS'){ 	// с этим сокетом уже общаемся по протоколу websocket
			$buf = @socket_read($socket, 1048576,  PHP_BINARY_READ); 	// читаем до 1MB
		}
		else {
			$buf = @socket_read($socket, 2048, PHP_NORMAL_READ); 	// читаем построчно
			// строки могут разделяться как \n, так и \r\n, но при PHP_NORMAL_READ reading stops at \n or \r, соотвественно, сперва строка заканчивается на \r, а после следующего чтения - на \r\n, и только тогда можно заменить
			if($buf[-1]=="\n") $buf = trim($buf)."\n";
			else $buf = trim($buf);
		}
		//echo "\nbuf has type ".gettype($buf)." and=|$buf|\nwith error ".socket_last_error($socket)."\n";		
		if($err = socket_last_error($socket)) { 	// с сокетом проблемы
			//echo "\nbuf has type ".gettype($buf)." and=|$buf|\nwith error ".socket_last_error($socket)."\n";		
			switch($err){
			case 114:	// Operation already in progress
			case 115:	// Operation now in progress
			//case 104:	// Connection reset by peer		если клиент сразу закроет сокет, в который он что-то записал, то ещё не переданная часть записанного будет отброшена. Поэтому клиент не закрывает сокет вообще, и он закрывается системой с этим сообщением. Но на этой стороне к моменту получения ошибки уже всё считано?
			//	break;
			default:
				echo "Failed to read data from socket $sockKey by: " . socket_strerror(socket_last_error($socket)) . "                                 \n"; 	// в общем-то -- обычное дело. Клиент закрывает соединение, мы об этом узнаём при попытке чтения. Если $sockKey == false, то это сокет к gpsd.
				chkSocks($socket);
			}
		    continue;	// к следующему сокету
		}
		$lastClientExchange = time();
		
		// Собственно, содержательная часть
		//echo "\nПринято:$buf|\n"; 	// здесь что-то прочитали из какого-то сокета
		if(($socket == $dataSock) or (@$messages[$sockKey]['PUT'] == TRUE)){ 	// прочитали из соединения с источником данных
			if($buf) $dataSourceZeroCNT = 0;
			else $dataSourceZeroCNT++;
			if($dataSourceZeroCNT>10){
				echo "\n\nTo many empty strings from $dataSourceHumanName socket\n"; 	// бывает, источник данных умер, а сокет -- нет. Тогда из него читается пусто.
				chkSocks($socket);
				continue;	// к следующему сокету
			}
			//echo "\nbuf has type ".gettype($buf)." and=|$buf|\nwith error ".socket_last_error($socket)."\n";		
			$inInstrumentsData = instrumentsDataDecode($buf);	// одно сообщение конкретного класса из потока
			// А оно надо? Здесь игнорируются устройства, не представленные на этапе установления соединения 
			// в ответ на WATCH. А вновь подключенные?
			/*
			if(!in_array($inInstrumentsData['device'],$devicePresent)) {  	// это не то устройство, которое потребовали
				continue;
			}
			*/
			//echo "\n inInstrumentsData\n"; print_r($inInstrumentsData);
			// Ok, мы получили требуемое
			//if($messages[$sockKey]['PUT'] == TRUE) {
			//	echo "\n Другой источник данных:	\n"; print_r($inInstrumentsData);
			//}
			updAndPrepare($inInstrumentsData); // обновим кеш и отправим данные для режима WATCH
			//echo "\n gpsdData\n"; print_r($instrumentsData);
			continue; 	// к следующему сокету
		}

		// прочитали из клиентского соединения
		if($buf) $messages[$sockKey]['zerocnt'] = 0;
		else $messages[$sockKey]['zerocnt']++;
		if($messages[$sockKey]['zerocnt']>10){
			echo "\n\nTo many empty strings from socket $sockKey\n"; 	// бывает, клиент умер, а сокет -- нет. Тогда из него читается пусто.
			chkSocks($socket);
		    continue;	// к следующему сокету
		}
		//echo "\nПРИНЯТО ОТ КЛИЕНТА $sockKey:\n|$buf|\n";
		//print_r($messages[$sockKey]);
		if($messages[$sockKey]['greeting']===TRUE){ 	// с этим сокетом уже беседуем, значит -- пришли данные	
			switch($messages[$sockKey]['protocol']){
			case 'WS':	// ответ за запрос через websocket, здесь нет конца передачи, посылается сколько-то фреймов.
				//echo "\nПРИНЯТО из вебсокета:\n|$buf|\n";
				//print_r(wsDecode($buf));
				$n = 0;
				do{	// склеенные фреймы
					list($decodedData,$type,$FIN,$tail) = wsDecode($buf);
					$buf = $tail;
					$n++;
					//echo "type=$type; FIN=$FIN;|$tail|\n";
					//echo "$decodedData\n";
					if($type != 'text'){
						switch($type){
						case 'close':
							chkSocks($socket);	// закроет сокет
							break 3;
						case 'binary':
						case 'ping':
						case 'pong':
						default:
							echo "A frame of type '$type' was dropped                               \n";
							if($decodedData === NULL){
								echo "Frame decode fails, will close websocket\n";
								chkSocks($socket);	// закроет сокет
								break 3;
							}
							continue 2;
						}
					}
					//echo "type=$type; FIN=$FIN;n=$n;|$tail||$buf|\n";
					//echo "type=$type; FIN=$FIN;n=$n;\n";
					//echo "$decodedData\n";
					if($FIN){ 	// сообщение (возможно, из нескольких фреймов) закончилось
						$messages[$sockKey]['inBuf'] .= rtrim($decodedData,';').';';	// полагая, что это команда gpsd
					}
					else $messages[$sockKey]['inBuf'] .= $decodedData;
				}while($buf);
				if($FIN){
					$buf = $messages[$sockKey]['inBuf'];
					$messages[$sockKey]['inBuf'] = '';
					if(strlen($buf)==1) $buf = '';	// ;
				}
				//echo "websocket buf=|$buf|\n";
				if(!$buf) continue 2;
				break;	// case
			}
		}
		else{ 	// с этим сокетом ещё не беседовали, значит, пришёл заголовок или команда gpsd или ничего, если сокет просто открыли
			if(trim($buf[0])!='?'){ 	// это не команда протокола gpsd	PHP Notice:  Uninitialized string offset
				// разберёмся с заголовком
				if(!isset($messages[$sockKey]['inBuf'])) $messages[$sockKey]['inBuf'] = '';
				$messages[$sockKey]['inBuf'] .= "$buf";	// собираем заголовок
				//echo "Собрано:|{$messages[$sockKey]['inBuf']}|";
				if(substr($messages[$sockKey]['inBuf'],-2)=="\n\n"){	// конец заголовков (и вообще сообщения) -- пустая строка
					//echo "Заголовок: |{$messages[$sockKey]['inBuf']}|\n";
					$messages[$sockKey]['inBuf'] = explode("\n",$messages[$sockKey]['inBuf']);
					foreach($messages[$sockKey]['inBuf'] as $msg){	// поищем в заголовке
						$msg = explode(':',$msg,2);
						array_walk($msg,function(&$str,$key){$str=trim($str);});
						if($msg[0]=='Sec-WebSocket-Key'){
							$SecWebSocketAccept = base64_encode(pack('H*',sha1($msg[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));	// https://datatracker.ietf.org/doc/html/rfc6455#section-1.2 https://habr.com/ru/post/209864/
						}
						elseif($msg[0]=='Upgrade' and $msg[1]=='websocket') $messages[$sockKey]['protocol'] = 'WS handshake';	// это запрос на общение по websocket
					}
					// определился протокол
					switch($messages[$sockKey]['protocol']){
					case 'WS handshake':	// ответ за запрос через websocket, в минимальной форме, иначе Chrom не понимает
						$SecWebSocketAccept = 
							"HTTP/1.1 101 Switching Protocols\r\n"
							."Upgrade: websocket\r\n"
							."Connection: Upgrade\r\n"
							."Sec-WebSocket-Accept: ".$SecWebSocketAccept."\r\n"
							."\r\n";			
						//echo "SecWebSocketAccept=$SecWebSocketAccept;\n";
						//echo "header sockKey=$sockKey;\n";
						$messages[$sockKey]['output'] = array($SecWebSocketAccept,$greeting);	// 
						break;
					default:	// ответ вообще в сокет, как это для протокола gpsd
						$messages[$sockKey]['output'][] = $greeting."\r\n\r\n";	// приветствие gpsd
					}
					//echo "sockKey=$sockKey;\n";
					$messages[$sockKey]['greeting']=TRUE;
					$messages[$sockKey]['inBuf'] = '';					
				}
				//else continue;	// продолжаем собирать заголовок
				continue;
			}
			else{ 	// это команда протокола gpsd. Она всегда одна строка, поэтому её не надо собирать, и неважно, на что она оканчивается.
				$messages[$sockKey]['output'][] = $greeting."\n\n";	// приветствие gpsd
				$messages[$sockKey]['greeting']=TRUE;
			}
		}

		// выделим команду и параметры
		//echo "buf=|$buf|\n";
		if($buf[0]!='?'){ 	// это не команда протокола gpsd
			$buf = '';
			continue;
		}
		$commands = explode(';',$buf); 	// 
		foreach($commands as $command){
			if(!$command) continue;
			$command = substr($command,1);	// ?
			list($command,$params) = explode('=',$command);
			$params = trim($params);
			//echo "\nClient $sockKey| command=$command; params=$params;\n";
			if($params) $params = json_decode($params,TRUE);
			// Обработаем команду
			switch($command){
			case 'WATCH': 	// default: ?WATCH={"enable":true};
				if($params['enable'] == TRUE){
					if(!$params or count($params)>1){ 	// 
						$messages[$sockKey]['POLL'] = 'WATCH'; 	// отметим, что WATCH получили в виде, означающем, что это не POLL, надо слать данные непрерывно
						$messages[$sockKey]['subscribe'] = @$params['subscribe'];
						$messages[$sockKey]['minPeriod'] = @$params['minPeriod'];
						$pollWatchExist = TRUE;	// отметим, что есть сокет с режимом WATCH
						// Отправим ему первые данные
						if($messages[$sockKey]['subscribe']=="TPV") $messages[$sockKey]['output'][] = json_encode(makeWATCH())."\r\n\r\n";
						elseif($messages[$sockKey]['subscribe']=="AIS") $messages[$sockKey]['output'][] = json_encode(makeAIS())."\r\n\r\n";
						elseif(!$messages[$sockKey]['subscribe']) {
							$messages[$sockKey]['output'][] = json_encode(makeWATCH())."\r\n\r\n";
							$messages[$sockKey]['output'][] = json_encode(makeAIS())."\r\n\r\n";
						}
						if($instrumentsData["MOB"] and $instrumentsData["MOB"]['status']) $messages[$sockKey]['output'][] = json_encode($instrumentsData["MOB"])."\r\n\r\n";
					}
					else {
						$messages[$sockKey]['POLL'] = TRUE; 	// отметим, что WATCH получили, можно отвечать на POLL
					}
					// вернуть DEVICES
					$msg = array('class' => 'DEVICES', 'devices' => array($gpsdProxydevice));
					$msg = json_encode($msg)."\r\n\r\n";
					$messages[$sockKey]['output'][] = $msg;
					// вернуть статус WATCH
					$msg = '{"class":"WATCH","enable":"true","json":"true"}'."\r\n\r\n";
					$messages[$sockKey]['output'][] = $msg;
				}
				elseif($params['enable'] == FALSE){ 	// клиент сказал: всё
					if($messages[$n]['protocol'] == 'WS'){
						$messages[$sockKey]['output'][] = array("It's all",'close');	// скажем послать фрейм, прекращающий соединение. Клиент закрое сокет, потом этот сокет обработается как дефектный
					}
					else chkSocks($socket);	// просто закроем сокет
				}
				break;
			case 'POLL':
				if(!$messages[$sockKey]['POLL']) continue 2; 	// на POLL будем отзываться только после ?WATCH={"enable":true}
				// $POLL заполняется при каждом поступлении от источника данных новых данных					
				$POLL = makePOLL();
				switch(@$params['subscribe']){
				case "TPV":
					unset($POLL["ais"]);
					break;
				case "AIS":
					$POLL["tpv"]=array();	// tpv -- обязательно
					break;
				}
				$messages[$sockKey]['output'][] = json_encode($POLL)."\r\n\r\n"; 	// будем копить сообщения, вдруг клиент не готов их принять
				unset($POLL);
				break;
			case 'CONNECT':	// подключение к другому источнику данных. Используется, например, в netAISclient
				//echo "\nrecieved CONNECT !\n";
				if(@$params['host'] and @$params['port']) { 	// указано подключиться туда
					// Видимо, разрешать переподключаться за пределы локальной сети как-то неправильно...
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
			case 'UPDATE':
				//echo "\n UPDATE |$sockKey|\n"; print_r($params);
				foreach($params['updates'] as $update){
					updAndPrepare($update,$sockKey); // обновим кеш и отправим данные для режима WATCH
				}
				break;
			}
		}
	}
	
} while (true);

foreach($sockets as $socket) {
	socket_close($socket);
}
socket_close($masterSock);
socket_close($dataSock);


function IRun() {
/**/
global $phpCLIexec;
$pid = getmypid();
//echo "ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME),"'\n";
$toFind = pathinfo(__FILE__,PATHINFO_BASENAME);
@exec("ps -A w | grep '$toFind'",$psList);
if(!$psList) { 	// for OpenWRT. For others -- let's hope so all run from one user
	exec("ps w | grep '$toFind'",$psList);
	echo "IRun: BusyBox based system found\n";
}
//echo "__FILE__=".__FILE__."; pid=$pid; phpCLIexec=$phpCLIexec; toFind=$toFind;\n"; print_r($psList); //
$run = FALSE;
foreach($psList as $str) {
	if(strpos($str,(string)$pid)!==FALSE) continue;
	if((strpos($str,"$phpCLIexec ")!==FALSE) and (strpos($str,$toFind)!==FALSE)){
		$run=TRUE;
		break;
	}
}
//echo "run=$run;\n";
return $run;
}

?>
