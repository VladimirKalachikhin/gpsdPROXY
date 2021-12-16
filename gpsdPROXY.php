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

require('fCommon.php'); 	// 
require('params.php'); 	// 

if(IRun()) { 	// Я ли?
	echo "I'm already running, exiting.\n"; 
	return;
}
// Self data
$greeting = '{"class":"VERSION","release":"gpsdPROXY_0","rev":"beta","proto_major":3,"proto_minor":0}';
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
/*массив "номер сокета в массиве $sockets" => "массив [
'output'=> array(сообщений), // сообщения для отправки через этот сокет на следующем обороте
'PUT'=>TRUE/FALSE,	// признак, что данные надо брать из этого сокета, а не от gpsd. Не реализовано.
'POLL'=>TRUE/FALSE/WATCH,	// признак режима, в котором функционирует сейчас этот сокет
'greeting'=>TRUE/FALSE,	// признак, что приветствие протокола gpsd послано
'inBuf'=>''	// буфер для сбора строк обращения клиента, когда их больше одной
'protocol'=>''/'WS'	// признак, что общение происходит по протоколу socket (''), или websocket ('WS')
]" номеров сокетов подключившихся клиентов
*/
$messages = array(); 	// 

$socksRead = array(); $socksWrite = array(); $socksError = array(); 	// массивы для изменивших состояние сокетов (с учётом, что они в socket_select() по ссылке, и NULL прямо указать нельзя)
echo "Ready to connection from $gpsdProxyHost:$gpsdProxyPort\n";
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
	//print_r($socksWrite);
	$socksError = $sockets; 	// 
	$socksError[] = $masterSock; 	// 
	$socksError[] = $gpsdSock; 	// 

	//echo "\n\nНачало. Ждём, пока что-нибудь произойдёт\n";
	$num_changed_sockets = socket_select($socksRead, $socksWrite, $socksError, null); 	// должно ждать

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
			// строки могут разделяться как \n, так и \r\n, но при PHP_NORMAL_READ reading stops at \n or \r, соотвественно, сперва строка закансивается на \r, а после следующего чтения - на \r\n, и только тогда можно заменить
			if($buf[-1]=="\n") $buf = trim($buf)."\n";
			else $buf = trim($buf);
		}
		//echo "\nbuf has type ".gettype($buf)." and=|$buf|\nwith error ".socket_last_error($socket)."\n";		
		if($err = socket_last_error($socket)) { 	// с клиентом проблемы
			switch($err){
			case 114:	// Operation already in progress
			case 115:	// Operation now in progress
			//case 104:	// Connection reset by peer		если клиент сразу закроет сокет, в который он что-то записал, то ещё не переданная часть записанного будет отброшена. Поэтому клиент не закрывает сокет вообще, и он закрывается системой с этим сообщением. Но на этой стороне к моменту получения ошибки уже всё считано?
			//	break;
			default:
				echo "\n\nFailed to read data from socket $sockKey by: " . socket_strerror(socket_last_error($socket)) . "\n"; 	// в общем-то -- обычное дело. Клиент закрывает соединение, сы об этом узнаём при попытке чтения
				chkSocks($socket);
			}
		    continue;
		}
		
		// Собственно, содержательная часть
		//echo "\nПринято:$buf|\n"; 	// здесь что-то прочитали из какого-то сокета
		if(($socket == $gpsdSock) or (@$messages[$sockKey]['PUT'] == TRUE)){ 	// прочитали из соединения с gpsd или это сокет, с которого шлют данные
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
			$POLL = array(	// данные для передачи клиенту, в формате gpsd
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
			// Не надо ли что-нибудь сразу отправить?
			foreach($messages as $n => $data){
				if($data['POLL'] === 'WATCH'){	// для соответствующего сокета указано посылать непрерывно. === потому что $data['POLL'] на момент сравнения может иметь тип boolean, и при == произойдёт приведение 'WATCH' к boolean;
					//echo "n=$n; data:"; print_r($data);
					foreach($POLL["tpv"] as $data){
						$messages[$n]['output'][] = json_encode($data)."\r\n\r\n";
					}
					foreach($POLL["ais"] as $data){
						$messages[$n]['output'][] = json_encode($data)."\r\n\r\n";
					}
				}
			}
			//echo "\n gpsdData\n"; print_r($gpsdData);
			//echo "\n gpsdData AIS\n"; print_r($gpsdData['AIS']);
		}
		else{ 	// прочитали из клиентского соединения
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
								echo "A frame of type '$type' was dropped                                               \n";
								if($decodedData === NULL){
									echo "Frame decode fails, will close websocket\n";
									chkSocks($socket);	// закроет сокет
									break 3;
								}
								var_dump($decodedData);//var_dump($buf);
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
				//echo "\nClient $sockKey| command=$command| params=$params|\n";
				if($params) $params = json_decode($params,TRUE);
				// Обработаем команду
				switch($command){
				case 'WATCH': 	// default: ?WATCH={"enable":true};
					if($params['enable'] == TRUE){
						if(!$params or count($params)>1){ 	// 
							$messages[$sockKey]['POLL'] = 'WATCH'; 	// отметим, что WATCH получили в виде, означающем, что это не POLL, надо слать данные непрерывно
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
					// $POLL заполняется при каждом поступлении от gpsd новых данных
					$messages[$sockKey]['output'][] = json_encode($POLL)."\r\n\r\n"; 	// будем копить сообщения, вдруг клиент не готов их принять
					break;
				case 'CONNECT':	// подключение к другому gpsd. Используется, например, в netAISclient
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
				}
			}
		}
	}
	
	//echo "Пишем в сокеты ".count($socksWrite)."\n";
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
				continue 2;
			}
			elseif($res <> $msgLen){	// клиент не принял всё. У него проблемы?
				echo "\n\nNot all data was writed to socket by: " . socket_strerror(socket_last_error($sock)) . "\n";
				chkSocks($socket);
				continue 2;
			}
		}
		$messages[$n]['output'] = array();
		unset($msg);
	}
	
	echo "Has ".(count($sockets))." client socks, and master and gpsd cocks. Ready ".count($socksRead)." read and ".count($socksWrite)." write socks\r";
} while (true);

foreach($sockets as $socket) {
	socket_close($socket);
}
socket_close($masterSock);
socket_close($gpsdSock);

?>
