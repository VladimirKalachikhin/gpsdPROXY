<?php
// createSocketServer
// createSocketClient
// connectToGPSD
// chkSocks

// updAndPrepare
// updGPSDdata
// savepsdData()
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
	//return FALSE;
	exit('1');
}
if(! @socket_connect($sock,$host,$port)){ 	// подключаемся к серверу
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
		break 2; 	// приветствие завершилось
	}
	
}while($WATCHsend or in_array($buf['class'],$controlClasses));
//echo "buf: "; print_r($buf);
if(!$devicePresent) {
	echo "\nno required devices present\n";
	//exit();
	return FALSE;
}
$devicePresent = array_unique($devicePresent);
return $devicePresent;
} // end function connectToGPSD

function chkSocks($socket) {
/**/
global $gpsdSock, $masterSock, $sockets, $socksRead, $socksWrite, $socksError, $messages, $devicePresent,$gpsdProxyGPSDhost,$gpsdProxyGPSDport;
if($socket == $gpsdSock){ 	// умерло соединение с gpsd
	echo "\nGPSD socket die. Try to reconnect.\n";
	@socket_close($gpsdSock); 	// он может быть уже закрыт
	$gpsdSock = createSocketClient($gpsdProxyGPSDhost,$gpsdProxyGPSDport); 	// Соединение с gpsd
	echo "Socket to gpsd reopen, do handshaking\n";
	$newDevices = connectToGPSD($gpsdSock);
	if(!$newDevices) exit("gpsd not run or no required devices present, bye       \n");
	$devicePresent = array_unique(array_merge($devicePresent,$newDevices));
	echo "New handshaking, will recieve data from gpsd\n";
}
elseif($socket == $masterSock){ 	// умерло входящее подключение
	echo "\nIncoming socket die. Try to recreate.\n";
	@socket_close($masterSock); 	// он может быть уже закрыт
	$masterSock = createSocketServer($gpsdProxyHost,$gpsdProxyPort,20); 	// Входное соединение
}
else {
	$n = array_search($socket,$sockets);	// 
	//echo "Close client socket $n type ".gettype($socket)." by error or by life\n";
	unset($sockets[$n]);
	unset($messages[$n]);
	$n = array_search($socket,$socksRead);	// 
	unset($socksRead[$n]);
	$n = array_search($socket,$socksWrite);	// 
	unset($socksWrite[$n]);
	$n = array_search($socket,$socksError);	// 
	unset($socksError[$n]);
	@socket_close($socket); 	// он может быть уже закрыт
}
//echo "\nchkSocks sockets: "; print_r($sockets);
} // end function chkSocks

function updAndPrepare($inGpsdData=array(),$sockKey=null){
/* Обновляет кеш данных и готовит к отправке, если надо, данные для режима WATCH, 
так, что на следующем обороте они будут отправлены */
global $messages, $pollWatchExist, $gpsdData;

//echo "sockKey=$sockKey;                   \n";
$gpsdDataUpdated = updGPSDdata($inGpsdData,$sockKey);
savegpsdData(); 	// сохраним в файл, если пора
//echo "\npollWatchExist=$pollWatchExist;"; print_r($inGpsdData);
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
			if((@$sockData['subscribe']=="TPV") and $gpsdDataUpdated["TPV"]){
				if(!$WATCH) $WATCH = makeWATCH();
				$messages[$n]['output'][] = json_encode($WATCH)."\r\n\r\n";
			}
			elseif((@$sockData['subscribe']=="AIS") and $gpsdDataUpdated["AIS"]){
				if(!$ais) $ais = makeAIS();
				$out = array('class' => 'AIS');	// это не вполне правильный класс, но ничему не противоречит
				$out['ais'] = $ais;
				$messages[$n]['output'][] = json_encode($out)."\r\n\r\n";
				unset($out);
			}
			elseif(!@$sockData['subscribe']){	// не указали подписку, шлём всё
				if($gpsdDataUpdated["TPV"]){
					if(!$WATCH) $WATCH = makeWATCH();
					$messages[$n]['output'][] = json_encode($WATCH)."\r\n\r\n";
				}
				if($gpsdDataUpdated["AIS"]){	// 
					if(!$ais) $ais = makeAIS();
					$out = array('class' => 'AIS');
					$out['ais'] = $ais;
					$messages[$n]['output'][] = json_encode($out)."\r\n\r\n";
					unset($out);
				}
			}
			//echo "gpsdDataUpdated[MOB]={$gpsdDataUpdated["MOB"]};        \n";			
			if(isset($gpsdDataUpdated["MOB"]) and $gpsdDataUpdated["MOB"]!==$n){	// не тот сокет, который прислал данные. Если вернуть данные тому же, то он может их снова прислать из каких-то своих соображений, и так бесконечно.
			//if(isset($gpsdDataUpdated["MOB"]) and $gpsdDataUpdated["MOB"]===$n){	// тот сокет, который прислал данные, для тестовых целей
				//echo "Prepare to send MOB data to WACH'ed socket #$n;                      \n";
				//print_r($gpsdData["MOB"]);
				$messages[$n]['output'][] = json_encode($gpsdData["MOB"])."\r\n\r\n";
			}
			
		}
	}
}
} // end function updAndPrepare

function updGPSDdata($inGpsdData=array(),$sockKey=null) {
/* Обновляет глобальный кеш $gpsdData отдельными сообщениями $inGpsdData
$inGpsdData -- один ответ gpsd в режиме ?WATCH={"enable":true,"json":true};, когда оно передаёт поток отдельных сообщений
*/
global $gpsdData,$gpsdProxyTimeouts,$noVehicleTimeout;
$gpsdDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.
$now = time();
switch(@$inGpsdData['class']) {	// Notice if $inGpsdData empty
case 'SKY':
	break;
case 'TPV':
	// собирает данные по устройствам, в том числе и однородные
	foreach($inGpsdData as $type => $value){ 	// обновим данные
		$gpsdData['TPV'][$inGpsdData['device']]['data'][$type] = $value; 	// php создаёт вложенную структуру, это не python
		if($type == 'time') { // надеемся, что время прислали до содержательных данных
			$dataTime = strtotime($value);
			if(!$dataTime) $dataTime = $now;
		}
		else $dataTime = $now;
		$gpsdData['TPV'][$inGpsdData['device']]['cachedTime'][$type] = $dataTime;
		$gpsdDataUpdated['TPV'] = TRUE;
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
			$gpsdDataUpdated['AIS'] = true;
		}
	}
	break;
case 'AIS':
	//echo "JSON AIS Data:\n"; print_r($inGpsdData); echo "\n";
	$vehicle = trim((string)$inGpsdData['mmsi']);
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
		$gpsdDataUpdated['AIS'] = TRUE;
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
		$gpsdDataUpdated['AIS'] = TRUE;
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
		$gpsdDataUpdated['AIS'] = TRUE;
		break;
	}
case 'MOB':
	$gpsdData['MOB']['class'] = 'MOB';
	$gpsdData['MOB']['status'] = $inGpsdData['status'];
	$gpsdData['MOB']['points'] = $inGpsdData['points'];
	$gpsdDataUpdated['MOB'] = $sockKey;
	//print_r($gpsdData['MOB']);
	break;
}

// Проверим актуальность всех данных
// TPV
if($gpsdData['TPV']){
	foreach($gpsdData['TPV'] as $device => $data){
		foreach($gpsdData['TPV'][$device]['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
			//echo "type=$type; data['data'][type]={$data['data'][$type]}; gpsdProxyTimeouts['TPV'][type]={$gpsdProxyTimeouts['TPV'][$type]}; now=$now; cachedTime=$cachedTime;\n";
			if(is_numeric($data['data'][$type]) and $gpsdProxyTimeouts['TPV'][$type] and (($now - $cachedTime) > $gpsdProxyTimeouts['TPV'][$type])) {	// Notice if on $gpsdProxyTimeouts not have this $type
				unset($gpsdData['TPV'][$device]['data'][$type]);
				$gpsdDataUpdated['TPV'] = TRUE;
				//echo "Данные ".$type." от устройства ".$device." протухли.                     \n";
			}
		}
	}
}
// AIS
if($gpsdData['AIS']) {	// IF быстрей, чем обработка Warning?
	foreach($gpsdData['AIS'] as $id => $vehicle){
		if(($now - $vehicle['timestamp'])>$noVehicleTimeout) {
			unset($gpsdData['AIS'][$id]); 	// удалим цель, последний раз обновлявшуюся давно
			$gpsdDataUpdated['AIS'] = TRUE;
			continue;	// к следующей цели AIS
		}
		foreach($gpsdData['AIS'][$id]['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
			if(is_numeric($vehicle['data'][$type]) and $gpsdProxyTimeouts['AIS'][$type] and (($now - $cachedTime) > $gpsdProxyTimeouts['AIS'][$type])) {
				unset($gpsdData['AIS'][$id]['data'][$type]);
				$gpsdDataUpdated['AIS'] = TRUE;
				//echo "Данные AIS ".$type." для судна ".$id." протухли.                                       \n";
			}
		}
	}
}

//echo "\n gpsdDataUpdated\n"; print_r($gpsdDataUpdated);
//echo "\n gpsdData\n"; print_r($gpsdData);
//echo "\n gpsdData AIS\n"; print_r($gpsdData['AIS']);
return $gpsdDataUpdated;
} // end function updGPSDdata

function savegpsdData(){
/**/
global $gpsdData,$backupFileName,$backupTimeout,$lastBackupSaved;

if((time()-$lastBackupSaved)>$backupTimeout){
	file_put_contents($backupFileName,json_encode($gpsdData)); 
}
} // end function savepsdData

function makeAIS(){
/* делает массив ais */
global $gpsdData;

$ais = array();
if($gpsdData['AIS']) {
	foreach($gpsdData['AIS'] as $vehicle => $data){
		//$data['data']["class"] = "AIS"; 	// вроде бы, тут не надо?...
		$data['data']["timestamp"] = $data["timestamp"];		
		$ais[$data['data']['mmsi']] = $data['data'];
	}
}
return $ais;
} // end function makeAIS

function makePOLL(){
/* Из глобального $gpsdData формирует массив ответа на ?POLL протокола gpsd
*/
global $gpsdData;

$POLL = array(	// данные для передачи клиенту как POLL, в формате gpsd
	"class" => "POLL",
	"time" => time(),
	"active" => 0,
	"tpv" => array(),
	"sky" => array(),	// обязательно по спецификации, пусто
);
//echo "\n gpsdData\n"; print_r($gpsdData['TPV']);
if($gpsdData['TPV']){
	foreach($gpsdData['TPV'] as $device => $data){
		$POLL["active"] ++;
		$POLL["tpv"][] = $data['data'];
	}
}
$POLL["ais"] = makeAIS();
if($gpsdData["MOB"]){
	$POLL["mob"] = $gpsdData["MOB"];
}
return $POLL;
} // end function makePOLL

function makeWATCH(){
/* Из глобального $gpsdData формирует массив ответа потока ?WATCH протокола gpsd
*/
global $gpsdData;

// нужно собрать свежие данные от всех устройств в одно "устройство". 
// При этом окажется, что координаты от одного приёмника ГПС, а ошибка этих координат -- от другого, если первый не прислал ошибку
$WATCH = array();
$lasts = array();
foreach($gpsdData['TPV'] as $device => $data){
	// при отсутствии надёжных координат от этого устройства не будем собирать координаты
	if($data['data']['mode']<2){	// no fix, mode всегда есть
		unset($data['data']['lat']);
		unset($data['data']['lon']);
		echo "No fix, lat lon removed from WATCH flow                          \n";
	}
	foreach($data['data'] as $type => $value){
		if($type=='device') continue;	// необязательный параметр. Указать своё устройство?
		if($data['cachedTime'][$type]<=@$lasts[$type]) continue;	// что лучше -- старый 3D fix, или свежий 2d fix?
		// присвоим только свежие значения
		$WATCH[$type] = $value;
		$lasts[$type] = $data['cachedTime'][$type];
	}
}
return $WATCH;
} // end function makeWATCH

function wsDecode($data){
/* Возвращает:
данные или false -- что-то пошло не так, непонятно, что делать
тип данных или "", если данные в нескольких фреймах, и это не первый фрейм
признак последнего фрейма (TRUE) или FALSE, если фрейм не последний
один или несколько склееных фреймов, оставшихся после выделения первого
*/
$unmaskedPayload = '';
$decodedData = null;

// estimate frame type:
$firstByteBinary = sprintf('%08b', ord($data[0])); 	// преобразование первого байта в битовую строку
$secondByteBinary = sprintf('%08b', ord($data[1])); 	// преобразование второго байта в битовую строку
$opcode = bindec(substr($firstByteBinary, 4, 4));	// последние четыре бита первого байта -- в десятичное число из текста
$payloadLength = ord($data[1]) & 127;	// берём как число последние семь бит второго байта

$isMasked = $secondByteBinary[0] == '1';	// первый бит второго байта -- из текстового представления.
$FIN = $firstByteBinary[0] == '1';

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
	$type = $opcode;
}

if ($payloadLength === 126) {
	if (strlen($data) < 4) return false;
	$mask = substr($data, 4, 4);
	$payloadOffset = 8;
	$dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
} 
elseif ($payloadLength === 127) {
	if (strlen($data) < 10) return false;
	$mask = substr($data, 10, 4);
	$payloadOffset = 14;
	$tmp = '';
	for ($i = 0; $i < 8; $i++) {
		$tmp .= sprintf('%08b', ord($data[$i + 2]));
	}
	$dataLength = bindec($tmp) + $payloadOffset;
	unset($tmp);
} 
else {
	$mask = substr($data, 2, 4);
	$payloadOffset = 6;
	$dataLength = $payloadLength + $payloadOffset;
}

/**
 * We have to check for large frames here. socket_recv cuts at 1024 (65536 65550?) bytes
 * so if websocket-frame is > 1024 bytes we have to wait until whole
 * data is transferd.
 */
//echo "strlen(data)=".strlen($data)."; dataLength=$dataLength;\n";
if (strlen($data) < $dataLength) {
	echo "\nwsDecode: recievd ".strlen($data)." byte, but frame length $dataLength byte. Skip tail, frame bad.\n";
	return false;	// надо продолжать читать и склеивать двоичное сообщение до $dataLength. Ломает...
}
else {
	$tail = substr($data,$dataLength);
}

if($isMasked) {
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
	$decodedData = substr($data, $payloadOffset);
}

return array($decodedData,$type,$FIN,$tail);
} // end function wsDecode

function wsEncode($payload, $type = 'text', $masked = false){
/* https://habr.com/ru/post/209864/ 
Кодирует $payload как один фрейм
*/
if(!$type) $type = 'text';
$frameHead = array();
$payloadLength = strlen($payload);

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
