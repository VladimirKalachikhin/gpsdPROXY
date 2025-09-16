<?php
/* Демон.
Кеширует данные TPV и AIS от gpsd, и отдаёт их по запросу ?POLL; протокола gpsd

Кроме того, можно обратиться к демону с запросом ?WATCH={“enable”:true,“json”:true} и получить поток. Можно
обратиться по протоколу websocket -- скорее всего, будет работать.

Основная идея в том, что каждый ответ от демона, будь то по POLL или в потоке WATCH,
содержит _всю_ имеющуюсю информацию как о себе, так и о целях AIS. В результате клиент
может быть проще, и реагировать живее, потому что сразу имеет все изменения.
Но зато если целей AIS много -- весь канал связи будет забит непрерывной передачей информации AIS,
и координаты и сообщения об опасностях не пролезут.
Имеется механизм, несколько купирующий эту проблему, суть которого в сокращении и прекращении
передачи сообщений AIS, если обнаруживается затор сообщений. Если не обнаруживается - в интерфейсе
GaladrielMap есть кнопочка.

Daemon
Caches TPV and AIS data from gpsd, and returns them on request ?POLL; of the gpsd protocol
As side: daemon keeps instruments alive and power consuming.  

Added some new parameters for commands:
    "subscribe":"TPV[,AIS[,ALARM]]" parameter for ?POLL and ?WATCH={"enable":true,"json":true} commands.
    This indicates to return TPV or AIS or ALARM data only, or a combination of them. Default - all.
    For example: ?POLL={"subscribe":"AIS"} return class "POLL" with "ais":[], not with "tpv":[].
    "minPeriod":"", sec. for WATCH={"enable":true,"json":true} command. Normally the data is sent at the same speed as they come from sensors. Setting this allow get data not more often than after the specified number of seconds. For example:
    WATCH={"enable":true,"json":true,"minPeriod":"2"} sends data every 2 seconds.

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
?DEVICES;
?WATCH={"enable":true};
?POLL;

?WATCH={"enable":true,"json":true}
*/
/*
Version 1.3.2

1.3.0	authorisation & following the route
1.2.0	work on PHP8
1.1.0	ATT class
1.0.0	up to base & optimise
0.8.0	works without GNSS data source & AIS SART support
0.6.9	support heading and course sepately
0.6.5	restart by cron
0.6.0	add collision detections
0.5.1	add Signal K data source
0.5.0	rewritten to module structure and add VenusOS data source. Used https://github.com/bluerhinos/phpMQTT with big changes.
0.4.1	remove lat lon from WATCH flow if mode < 2 (no fix). On POLL stay as received.
*/
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);
chdir(__DIR__); // задаем директорию выполнение скрипта

require('params.php'); 	// 
require('fCommon.php'); 	// 
require('fGeodesy.php'); 	// 
require('fGeometry.php'); 	// 
require('fCollisions.php'); 	// 
require('fWaypoints.php'); 	// 
if($grantsAddrList)	require('fNetGrants.php');

if(IRun()) { 	// Я ли?
	echo "I'm already running, exiting.\n"; 
	return;
};

// Self data
// собственно собираемые / кешируемые данные
@mkdir(pathinfo($backupFileName, PATHINFO_DIRNAME));
if(@filemtime($backupFileName)<(time()-86400)) @unlink($backupFileName);	// файл был обновлён более суток назад
else $instrumentsData = @json_decode(@file_get_contents($backupFileName), true);
if(!$instrumentsData) $instrumentsData = array(); 	
//echo "instrumentsData from backup:  "; print_r($instrumentsData); echo "\n";
// Переменные
$defaultSubscribe = array('TPV'=>TRUE,'ATT'=>TRUE,'AIS'=>TRUE,'ALARM'=>TRUE,'WPT'=>TRUE);
$lastBackupSaved = 0;	// время последнего сохранения кеша
$lastClientExchange = time();	// время последней коммуникации какого-нибудь клиента

$greeting = '{"class":"VERSION","release":"gpsdPROXY_1","rev":"beta","proto_major":3,"proto_minor":0}';
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$gpsdProxydevice = array(
'class' => 'DEVICE',
'path' => 'gpsdPROXY',
'activated' => date('c'),
'flags' => $SEEN_GPS | $SEEN_AIS,
'stopbits' => 1
);
if(!$gpsdProxyHosts) $gpsdProxyHosts=array(array('localhost',3838)); 	// я сам. Хосты/порты для обращения к gpsdProxy


$pollWatchExist = array();	// флаг, что есть сокеты в режиме WATCH, когда данные им посылаются непрерывно, и список имеющихся подписок
$minSocketTimeout = 86400;	// сек., сутки
// определим, какое минимальное время протухания величины указано в конфиге
array_walk_recursive($gpsdProxyTimeouts, function($val){
											global $minSocketTimeout;
											if(is_numeric($val) and ($val<$minSocketTimeout)) $minSocketTimeout = $val;
										});
if($minSocketTimeout == 86400) $minSocketTimeout = 10;
//echo "minSocketTimeout=$minSocketTimeout;\n";

// Характеристики судна, в основном для контроля столкновений, но mmsi необходим для netAIS
if(@$netAISconfig) {	// params.php
	$saveBoatInfo = $boatInfo;	// params.php
	$boatInfo = parse_ini_file($netAISconfig,FALSE,INI_SCANNER_TYPED);
	if($boatInfo===false) {
		echo "\nFound netAISconfig parm in params.php, but loading netAIS boatInfo.ini false.\n";
		$boatInfo = $saveBoatInfo;
	}
	else {
		if(!$boatInfo['shipname']) $boatInfo['shipname'] = $saveBoatInfo['shipname'];
		if(!$boatInfo['mmsi']) $boatInfo['mmsi'] = $saveBoatInfo['mmsi'];
		if(!$boatInfo['length']) $boatInfo['length'] = $saveBoatInfo['length'];
		if(!$boatInfo['beam']) $boatInfo['beam'] = $saveBoatInfo['beam'];
		if(!$boatInfo['to_bow']) $boatInfo['to_bow'] = $saveBoatInfo['to_bow'];
		if(!$boatInfo['to_stern']) $boatInfo['to_stern'] = $saveBoatInfo['to_stern'];
		if(!$boatInfo['to_port']) $boatInfo['to_port'] = $saveBoatInfo['to_port'];
		if(!$boatInfo['to_starboard']) $boatInfo['to_starboard'] = $saveBoatInfo['to_starboard'];
	}
	unset($saveBoatInfo);
}
if(!@$boatInfo['shipname']) $boatInfo['shipname'] = (string)uniqid();
if(!@$boatInfo['mmsi']) $boatInfo['mmsi'] = str_pad(substr(crc32($boatInfo['shipname']),0,9),9,'0'); 	// левый mmsi, похожий на настоящий -- для тупых, кому не всё равно (SignalK, к примеру)
//echo "boatInfo:"; print_r($boatInfo); echo "\n";

// WayPoint
$way = array();	// путь, которым следуем, делается из gpx rte или wpt. array('lat'=>,'lon'=>)
if(!isset($wptPrecision)){
	if($boatInfo['length']) $wptPrecision = 5*$boatInfo['length'];
	else $wptPrecision = 100;
};
if($instrumentsData['WPT']){
	if($instrumentsData['WPT']['wayFileName']){
		$way = wayFileLoad($instrumentsData['WPT']['wayFileName']);
		if($way) {
			toIndexWPT(@$instrumentsData['WPT']['index']);	// на первую точку, если вообще не указано. Здесь нет позиции, поэтому нельзя на ближайшую точку.
			$instrumentsData['WPT']['wayFileTimestamp'] = filemtime($instrumentsData['WPT']['wayFileName']);
		}
		else $instrumentsData['WPT'] = array();	// укажем, что путевые точки были, но теперь их нет
	}
	else $instrumentsData['WPT'] = array();	// укажем, что путевые точки были, но теперь их нет
	//echo "instrumentsData['WPT']:"; print_r($instrumentsData['WPT']); echo "\n";
};

// Удалим себя из cron, на всякий случай
exec("crontab -l | grep -v '".__FILE__."'  | crontab -"); 	


// Поехали
$dataSourceConnectionObject = NULL; 	
$requireFile = NULL;	// имя файла с параметрами основного источника данных

$messages = array(); 	// 
$devicePresent = [];
/*$messages: массив "номер сокета в массиве $sockets" => "массив [
'output'=> array(сообщений), // сообщения для отправки через этот сокет на следующем обороте
'PUT'=>TRUE/FALSE,	// признак, что данные надо брать из этого сокета, а не от gpsd. А оно надо?
'POLL'=>TRUE/FALSE/WATCH,	// признак режима, в котором функционирует сейчас этот сокет
'greeting'=>TRUE/FALSE,	// признак, что приветствие протокола gpsd послано
'inBuf'=>''	// буфер для сбора строк обращения клиента, когда их больше одной
'protocol'=>''/'WS'	// признак, что общение происходит по протоколу socket (''), или websocket ('WS')
'zerocnt' => 0	// счётчик подряд посланных пустых сообщений. 
'subscribe'=>'' // строка подписки, TPV,AIS,ALARM
]" номеров сокетов подключившихся клиентов
*/
$rotateBeam = array("|","/","-","\\");
$rBi = 0;

$dataSourceZeroCNT = 0;	// счётчик пустых строк, пришедших подряд от источника данных
$NminSocketTimeouts = 30;
$lastTryToDataSocket = time()-($NminSocketTimeouts*$minSocketTimeout+$minSocketTimeout);	// момент последней попытки поднять основной источник данных
$dataUpdated = 0;	// время последней коммуникации с источником данных, чтобы проверять свежесть данных не при каждом POLL
// флаг-костыль для обозначения ситуации, когда основной источник данных вроде жив,
// но выдаёт не то.
// Выставляется пока только в ситуации получения длинной последовательности пустых строк. Это
// реглярно случается с gpsd, когда у него нет данных, а его спрашивают.
$mainSourceHasStranges = false;	

$sockets = array(); 	// список функционирующих сокетов
$socksRead = array(); $socksWrite = array(); $socksError = array(); 	// массивы для изменивших состояние сокетов (с учётом, что они в socket_select() по ссылке, и NULL прямо указать нельзя)
// Соединения для приёма клиентов, входные соединения
// Рпкомендуется для ipv4 и ipv6 указывать разные порты: https://bugs.php.net/bug.php?id=73307
$masterSocks = array();
foreach($gpsdProxyHosts as $i => $gpsdProxyHost){
	if($sock=createSocketServer($gpsdProxyHost[0],$gpsdProxyHost[1],20)) $masterSocks[] = $sock;
	else unset($gpsdProxyHosts[$i]);
};
if(!$masterSocks) exit("Unable to open inbound connections, died.\n");
echo "gpsdPROXY ready to connection on ";
foreach($gpsdProxyHosts as $gpsdProxyHost){
	echo "$gpsdProxyHost[0]:$gpsdProxyHost[1], ";
};
echo "\n\n";

do {
	//$startTime = microtime(TRUE);
	//echo "\n";
	//echo "gpsdSock type=".gettype($dataSourceConnectionObject).";\n";
	//echo "sockets:\n"; print_r($sockets);
	$SocketTimeout = $minSocketTimeout;	// сделаем, чтобы основной цикл не стоял вечно, для проверки протухания
	
	// Если нет основного источника данных, и пора попытаться его поднять.
	// он может быть, но молчать, потому что его источник данных отвалился
	// для того, чтобы он (gpsd, да) переконнектился к источнику данных -- его надо пнуть.
	// Поэтому, если из основного источника давно не приходили данные - переконнектимся.
	// Тут вопрос, что значит !$dataSourceConnectionObject?
	if((time()-$lastTryToDataSocket)>=$NminSocketTimeouts*$SocketTimeout){	// чтобы не каждый оборот, иначе никакакой handshaking никогда не завершится
		echo "No main data source. Trying to open.                                 \n";
		chkSocks($dataSourceConnectionObject);	// а как ещё узнать, что сокет закрыт? Массив error socket_select не помогает.
		// В результате chkSocks старый $dataSourceConnectionObject будет переоткрыт, если он вообще сокет
		//echo "dataSourceConnectionObject=$dataSourceConnectionObject;\n";
		if(!$dataSourceConnectionObject){
			// Определим, к кому подключаться для получения данных
			$res = findSource(@$dataSourceType,@$dataSourceHost,@$dataSourcePort); // Определим, к кому подключаться для получения данных, переменные из params.php
			if($res) {
				list($dataSourceHost,$dataSourcePort,$requireFileNew) = $res;
				if(($requireFile !== NULL) and ($requireFileNew !== $requireFile)){	// уже был определён источник, но новыйьисточник не тот, что был раньше
					// однако, функции в PHP переопределить нельзя, поэтому просто подключиться к источнику
					// данных другого типа невозможно. Поэтому надо убиться и запуститься снова, тогда
					// будет найден новый источник данных и соответствующие функции будут определены для него.
					// перезапускать будем кроном, потому что busybox не имеет команды at
					exec('(crontab -l ; echo "* * * * * '.$phpCLIexec.' '.__FILE__.'") | crontab -'); 	// каждую минуту
					exit("Main data source died, I die too. But Cron will revive me.\n");
				};
				$requireFile = $requireFileNew;
				//echo "Source $requireFile on $dataSourceHost:$dataSourcePort\n";
				require($requireFile);	// загрузим то что нужно для работы с указанным или найденным источником данных
				//echo "masterSocks:"; print_r($masterSocks); echo "dataSourceConnectionObject=$dataSourceConnectionObject;\n";
				// dataSourceConnectionObject создаётся в require($requireFile)
				// Сокет к источнику данных, может не быть, как оно в VenusOS. Определяется в require.
				// Предполагается, что из этого сокета только читается непрерывный поток цельных сообщений, ибо оно gpsd.
				// Handshaking осуществляется в функции dataSourceConnect, определённой в require($requireFile)
				echo "Begin of data source: socket to $dataSourceHumanName opened, do handshaking                                   \n";
				$devicePresent = dataSourceConnect($dataSourceConnectionObject);	// реально $devicePresent нигде не используются, кроме как ниже. Можно использовать как-нибудь?
				if(!$devicePresent) $devicePresent = [];	// может не быть основного источника данных
				//var_dump($devicePresent);
				// Но там может быть какой-то другой источник данных через CONNECT, как это
				// делает netAISclient и inetAIS или через UPDATE
				// поэтому комментируем следующие две строки
				//if($devicePresent===FALSE) exit("Handshaking fail: $dataSourceHumanName on $dataSourceHost:$dataSourcePort not answer, bye     \n");
				//echo "Begin: handshaked, will recieve data from $dataSourceHumanName\n";
				if(!$devicePresent) echo"but no required devices present     \n";

				// После того, как стало понятно, что всё нормально, удалим себя из cron
				exec("crontab -l | grep -v '".__FILE__."'  | crontab -"); 	
			};	// а если не нашли, откуда получать главные данные - будем крутится так, 
				// для показа AIS, передачи MOB и, возможно, других подключенных источников.
				// Тип, мультиплексор данных. Хотя gpsd и сам так может.
		};
		$lastTryToDataSocket = time();
		$lastClientExchange = time();	// чтобы отсчёт начался заново, иначе оно сразу убъётся по отсутствию клиентов.
		if($dataSourceConnectionObject) $mainSourceHasStranges = false;
		else echo "The reopening of the main data source failed. I'll try it later.\n\n";		
	};
	
	$socksRead = $sockets; 	// мы собираемся читать все сокеты
	$socksError = $sockets; 	// 
	foreach($masterSocks as $masterSock){
		$socksRead[] = $masterSock; 	// 
		$socksError[] = $masterSock; 	// 
	};
	//echo "sockets:\n"; print_r($sockets);
	$info = "";
	if($sockets) {	// есть, возможно, клиенты, включая тех, кто с CONNECT и UPDATE
		// А зачем было сделано принудительное переоткрытие закрытого главного источника?
		// Как минимум, это приводит к зацикливанию, если у сокета съехала крыша, и он
		// всё время возвращает пусто вместо ошибки. Тогда сокет будет закрыт по подсчёту
		// пустых строк, но тут же открыт здесь, если есть клиенты CONNECT и UPDATE.
		// А, это если появились клиенты - немедленно открыть главный источник.
		// Если это переоткрытие отключить, главный источник будет открываться выше
		// периодически через время. Возможно, через время он придёт в себя.
		// Лучше всё же сделать флаг-костыль...
		if(gettype($dataSourceConnectionObject)==='resource (closed)' and !$mainSourceHasStranges) {	// главный источник есть, но мы его ранее закрыли
			chkSocks($dataSourceConnectionObject);	// 
			//echo "\nПереоткрыли главный сокет, gettype(dataSourceConnectionObject)=".gettype($dataSourceConnectionObject)."\n";
		}
		//
		//echo "Главный источник данных имеет тип ",gettype($dataSourceConnectionObject),"    \n";
		if(gettype($dataSourceConnectionObject)==='resource'){	// главный источник данных в порядке
			$socksRead[] = $dataSourceConnectionObject; 	// есть клиенты -- нам нужно соединение с источником данных
			$socksError[] = $dataSourceConnectionObject; 	// 
			$info = " and $dataSourceHumanName";
		}
		elseif(gettype($dataSourceConnectionObject)==='object'){	// главный источник данных в порядке, и это venusos
			if($dataSourceHumanName !== 'venusos'){	// в гавёном PHP8 сокет - объект, и отличить его от других объектов невозможно
				$socksRead[] = $dataSourceConnectionObject; 	// есть клиенты -- нам нужно соединение с источником данных
				$socksError[] = $dataSourceConnectionObject; 	// 
			};
			$info = " and $dataSourceHumanName";
		}	// иначе $dataSourceConnectionObject == null, и через оборот по таймауту снова будет предпринята попытка открыть главный источник данных
	}
	else {	// клиентов нет -- можно закрыть соединение с источником данных, чтобы он заснул приёмник гпс.
		//echo "No clients present. noClientTimeout=$noClientTimeout; lastClientExchange=".(time()-$lastClientExchange)."          \n";
		//echo "time=".time().";              \n";
		if($noClientTimeout and ((time()-$lastClientExchange)>=$noClientTimeout)){
			if($dataSourceConnectionObject){
				if( dataSourceClose($dataSourceConnectionObject)){
					echo "$dataSourceHumanName connection closed by no clients                                         \r";
				}
				else echo "No clients, but data source socket did not close by unknown reason.                           \r";
			};
			// Клиентов нет, а есть ли нужные данные?
			//echo "instrumentsData['ALARM']:"; print_r($instrumentsData['ALARM']);
			$noData = true;
			foreach($instrumentsData['ALARM'] as $type => $value){	// считаем нужными только оповещения
				switch($type){
				case 'MOB':
					if($value['status']){
						$noData = false;
						break 2;
					};
					break;
				case 'collisions':
				default:
					if($value){
						$noData = false;
						break 2;
					};
				};
			};
			if($noData){	// нужных данных нет
				exec('(crontab -l ; echo "*/3 * * * * '.$phpCLIexec.' '.__FILE__.'") | crontab -'); 	// каждые 3 минуты
				exit("No clients and data, bye. But Cron will revive me.                     \n");
			};
			$info = "";
		};
	};
	
	// сокет всегда готов для чтения, есть там что-нибудь или нет, поэтому если в socksWrite что-то есть, socket_select никогда не ждёт, возвращая socksWrite неизменённым
	$socksWrite = array(); 	// очистим массив 
	//$socksWriteDummy = array(); 	// очистим массив 
	foreach($messages as $n => $data){ 	// пишем только в сокеты, полученные от masterSock путём socket_accept
		//echo " в sockets объект № $n является ";var_dump($sockets[$n]); echo "    \n";
		if(@$data['output'])	$socksWrite[] = $sockets[$n]; 	// если есть, что писать -- добавим этот сокет в те, в которые будем писать
	}
	//$socksWriteDummy = $socksWrite;
	//echo "\n sockets:"; print_r($sockets); echo "\n";
	//echo "\n socksRead:"; print_r($socksRead); echo "\n";
	//echo "\n socksWrite:"; print_r($socksWrite); echo "\n";
	//echo "\n socksWrite содержит ".count($socksWrite)." сокетов до socket_select\n";

	//echo "\n\nНачало. Ждём, пока что-нибудь произойдёт\n";
	// при тишине раз в  провернём цикл на предмет очистки от умерших сокетов и протухших данных
	if(function_exists('altReadData')){
		// Возьмём откуда-то данные каким-то левым способом. 
		// Жуткий костыль для venusos, потому что переделывать библиотеку работы с MQTT, написаную в мудацком объектном стиле, нет никакого желания
		// в altReadData вызывается updAndPrepare, так что больше с этим ничего не надо делать 
		if( altReadData($dataSourceConnectionObject) ) {
			$SocketTimeout = 0;	// если данные были получены слева, их надо обработать, поэтому отключим ожидание чтения сокета
		}
	}
	//echo "\n pollWatchExist="; print_r($pollWatchExist); echo "minSocketTimeout=$minSocketTimeout; SocketTimeout=$SocketTimeout;        \n";

	// Ожидаем сокеты.
	$num_changed_sockets = socket_select($socksRead, $socksWrite, $socksError, $SocketTimeout); 	// должно ждать


	//echo "\nnum_changed_sockets=$num_changed_sockets;      \n";
	//echo "\n socksWrite содержит ".count($socksWrite)." сокетов после socket_select              \n";
	echo($rotateBeam[$rBi]);	// вращающаяся палка
	echo "Has ".(count($sockets))." client socks, and master$info socks. Ready ".count($socksRead)." read and ".count($socksWrite)." write socks\r";
	$rBi++;
	if($rBi>=count($rotateBeam)) $rBi = 0;

	// теперь в $socksRead только те сокеты, куда пришли данные, в $socksWrite -- те, откуда НЕ считали, т.е., не было, что читать, но они готовы для чтения
	if (($num_changed_sockets === FALSE) or $socksError) { 	// Warning не перехватываются, включая supplied resource is not a valid Socket resource И смысл?
		echo "socket_select: Error on sockets: " . socket_strerror(socket_last_error()) . "\n";
		foreach($socksError as $socket){
			chkSocks($socket);
			unset($socket);
		}
	}
	elseif($num_changed_sockets === 0) {	// оборот по таймауту
		//echo "\nLoop by timeout. SocketTimeout=$SocketTimeout;\n";
		updAndPrepare();	// проверим кеш на предмет протухших данных
		continue;	// оборот основного цикла, ведь больше ничего не произошло
	}

	//echo "\n Пишем в сокеты ".count($socksWrite)."\n"; //////////////////
	// Здесь пишется в сокеты то, что попало в $messages на предыдущем обороте. Тогда соответствующие сокеты проверены на готовность, и готовые попали в $socksWrite. 
	// в ['output'] элемент - всегда текст или массив из текста [0] и параметров передачи (для websocket). Но у нас всегда текст, так что - никаких параметров.

/*
	$sCnt = 0;
	foreach($messages as $n => $data){
		if($data['output'])	$sCnt+=1;
	}
	if(!$socksWrite and $sCnt) echo "нет готовых для записи сокетов, хотя имеются сообщения для $sCnt клиентов   \n";
*/
	foreach($socksWrite as $socket){
	//foreach($socksWriteDummy as $socket){
		$n = array_search($socket,$sockets);	// 
		//echo "\nДля клиента $n есть ".count($messages[$n]['output'])." сообщений         \n";
		$msg='';
		foreach($messages[$n]['output'] as $msg) { 	// все накопленные сообщения. & для экономии памяти, но что-то не экономится... Оно приводит к странному сбою памяти здесь, но нормально работает в gpsd2websocket. ???
			//echo "длиной ".mb_strlen($msg,'8bit')." байт\n";
			//echo "\nto $n:\n|$msg|\n";
			$msgParams = null;
			if(is_array($msg)) list($msg,$msgParams) = $msg;	// второй элемент -- тип фрейма
			switch($messages[$n]['protocol']){
			case 'WS':
				//if(!is_string($msg)) echo "Send no WS: |$msg| не строка!\n";
				$msg = wsEncode($msg,$msgParams);	
				break;
			case 'WS handshake':
				$messages[$n]['protocol'] = 'WS';
			}
			
			$msgLen = mb_strlen($msg,'8bit');
			socket_clear_error($socket);
			$res = @socket_write($socket, $msg, $msgLen);
			if($res === FALSE) { 	// клиент умер
				echo "\nFailed to write data to socket #$n by: " . socket_strerror(@socket_last_error($sock)) . "\n";	// $sock уже может не быть сокетом
				chkSocks($socket);
				exit;
				continue 3;	// к следующему сокету
			}
			elseif($res <> $msgLen){	// клиент не принял всё. У него проблемы?
				echo "\n\nNot all data was writed to socket #$n by: " . socket_strerror(socket_last_error($sock)) . "\n";
				chkSocks($socket);
				unset($socket);
				continue 3;	// к следующему сокету
			};
			$lastClientExchange = time();
		};
		$messages[$n]['output'] = array();
		unset($msg);
	};
	
	//echo "\n Читаем из сокетов ".count($socksRead)."\n"; ///////////////////////
	foreach($socksRead as $socket){
		socket_clear_error($socket);
		if(in_array($socket,$masterSocks,true)) { 	// новое подключение
			$sock = socket_accept($socket); 	// новый сокет для подключившегося клиента
			// Это не работает в PHP 8, где socket - это пустой объект, поэтому == false
			// а get_resource_type даёт ошибку, потому что аргумент не ресурс.
			//if(!$sock or (get_resource_type($sock) != 'Socket')) {
			if(!$sock) {	// В говняном PHP8 это объект, а в нормальном PHP - ресурс. Поэтому проверить, что именно вернулось от socket_accept довольно громоздко, а потому - не нужно.
				echo "Failed to accept incoming by: " . socket_strerror(socket_last_error($socket)) . "\n";
				chkSocks($socket); 	// recreate masterSock
				continue;	// к следующему сокету
			};
			$lastClientExchange = time();
			$sockets[] = $sock; 	// добавим новое входное подключение к имеющимся соединениям
			$sockKey = array_search($sock,$sockets);	// Resource id не может быть ключём массива, поэтому используем порядковый номер. Что стрёмно.
			$messages[$sockKey]['greeting']=FALSE;	// укажем, что приветствие не посылали. Запрос может быть не только как к gpsd, но и как к серверу websocket
			$messages[$sockKey]['zerocnt'] = 0;
			$messages[$sockKey]['privileged'] = true;
			echo "New client connected: with key $sockKey                                                      \n";
			if($grantsAddrList){	// 
				$remoteAddress = '';
				$remotePort = null;
				$res = socket_getpeername($sock,$remoteAddress,$remotePort);
				//echo "Входящее соединение $res $remoteAddress,$remotePort               \n";
				$messages[$sockKey]['privileged'] = chkPrivileged($remoteAddress);
			};
		    continue; 	//  к следующему сокету
		}
		elseif($socket === $dataSourceConnectionObject){ 	// соединение с главным источником данных
			// при этом, если есть altReadData (venusos, ага) -- всё то же самое происходит перед socket_select
			// и могут быть клиенты, передающие данные потоко: $messages[$sockKey]['PUT'] == TRUE
			// которые образовались по команде CONNECT. Для них то же самое ниже.
			// Ещё updAndPrepare вызывается по приходу команды UPDATE для аргументов этой команды.
			$buf = @socket_read($socket, 1048576, PHP_NORMAL_READ); 	// читаем построчно, gpsd передаёт сообщение целиком в одной строке
			if($err = socket_last_error($socket)) { 	// с сокетом проблемы
				switch($err){
				case 114:	// Operation already in progress
				case 115:	// Operation now in progress
				//case 104:	// Connection reset by peer		если клиент сразу закроет сокет, в который он что-то записал, то ещё не переданная часть записанного будет отброшена. Поэтому клиент не закрывает сокет вообще, и он закрывается системой с этим сообщением. Но на этой стороне к моменту получения ошибки уже всё считано?
				//	break;
				default:
					echo "Failed to read data from $dataSourceHumanName socket $socket by: " . socket_strerror(socket_last_error($socket)) . "                                 \n"; 	// в общем-то -- обычное дело. Клиент закрывает соединение, мы об этом узнаём при попытке чтения. Если $sockKey == false, то это сокет к gpsd.
					chkSocks($socket);
				}
				continue;	// к следующему сокету
			}
			$buf = trim($buf);
			if($buf) $dataSourceZeroCNT = 0;
			else {
				$dataSourceZeroCNT++;
				if($dataSourceZeroCNT>20){
					echo "To many empty strings from $dataSourceHumanName socket $socket                     \n"; 	// бывает, источник данных умер, а сокет -- нет. Тогда из него читается пусто.
					//chkSocks($socket);	// закрыть и открыть снова
					dataSourceClose($dataSourceConnectionObject);	// вместо переоткрытия главного источника - закроем его. Откроем потом, через время.
					//echo "\n socksRead:"; print_r($socksRead); echo "\n";
					//echo "\n socksWrite:"; print_r($socksWrite); echo "\n";
					//exit;
					$mainSourceHasStranges = true;
				}
				continue;	// к следующему сокету
			};
			$lastTryToDataSocket = time();	// отметим, когда главный источник был жив
			//echo "\nbuf from gpsd=|$buf|\n";		
			$inInstrumentsData = instrumentsDataDecode($buf);	// одно сообщение конкретного класса из потока
			// А оно надо? Здесь игнорируются устройства, не представленные на этапе установления соединения 
			// в ответ на WATCH. А вновь подключенные?
			//if(!in_array($inInstrumentsData['device'],$devicePresent)) {  	// это не то устройство, которое потребовали
			//	continue;
			//}
			//echo "\nИз основного источника inInstrumentsData:\n"; print_r($inInstrumentsData);
			// Ok, мы получили требуемое
			updAndPrepare($inInstrumentsData); // обновим кеш и отправим данные для режима WATCH
			//echo "\n gpsdData\n"; print_r($instrumentsData);
			continue; 	// к следующему сокету
		};
		
		// Читаем клиентские сокеты
		$sockKey = array_search($socket,$sockets); 	// 
		//echo "socket #$sockKey $socket"; print_r($messages[$sockKey]);
		
		if(@$messages[$sockKey]['protocol']=='WS'){ 	// с этим сокетом уже общаемся по протоколу websocket
			$buf = @socket_read($socket, 1048576,  PHP_BINARY_READ); 	// читаем до 1MB
			//echo "\nПРИНЯТО ОТ WS КЛИЕНТА #$sockKey ".mb_strlen($buf,'8bit')." байт, PUT={$messages[$sockKey]['PUT']};\n";
		}
		else {
			// Считаем, что буфер указан достаточно большой, и всё сообщение считывается за раз.
			// Трудно представить нормальную ситуацию, когда это было бы не так.
			// А если кто решит зафлудить, то он обломается: никогда не будет принято больше буфера.
			$buf = @socket_read($socket, 1048576, PHP_NORMAL_READ); 	// читаем построчно
			// строки могут разделяться как \n, так и \r\n, но при PHP_NORMAL_READ reading stops at \n or \r,
			// соотвественно, сперва строка заканчивается на \r, а после следующего чтения - на \r\n,
			// и только тогда можно заменить. В результате строки составного сообщения (заголовки, например)
			// всегда кончаются только \n
			if($buf[-1]=="\n") $buf = trim($buf)."\n";
			else $buf = trim($buf);
		}
		if($err = socket_last_error($socket)) { 	// с сокетом проблемы
			//echo "\nbuf has type ".gettype($buf)." and=|$buf|\nwith error ".socket_last_error($socket)."\n";		
			switch($err){
			case 114:	// Operation already in progress
			case 115:	// Operation now in progress
			//case 104:	// Connection reset by peer		если клиент сразу закроет сокет, в который он что-то записал, то ещё не переданная часть записанного будет отброшена. Поэтому клиент не закрывает сокет вообще, и он закрывается системой с этим сообщением. Но на этой стороне к моменту получения ошибки уже всё считано?
			//	break;
			default:
				echo "Failed to read data from socket #$sockKey $socket by: " . socket_strerror(socket_last_error($socket)) . "                                 \n"; 	// в общем-то -- обычное дело. Клиент закрывает соединение, мы об этом узнаём при попытке чтения. Если $sockKey == false, то это сокет к gpsd.
				chkSocks($socket);
				unset($socket);
			}
		    continue;	// к следующему сокету
		}
		$lastClientExchange = time();
		
		// Собственно, содержательная часть
		// прочитали из клиентского соединения
		if(trim($buf)) $messages[$sockKey]['zerocnt'] = 0;	// \n может быть частью составного сообщения, поэтому без trim. Но не 100 же штук?
		else $messages[$sockKey]['zerocnt']++;
		if($messages[$sockKey]['zerocnt']>10){
			echo "\n\nTo many empty strings from client socket #$sockKey \n"; 	// бывает, клиент умер, а сокет -- нет. Тогда из него читается пусто.
			chkSocks($socket);	// обычный сокет в этом случае будет просто закрыт и отовсюду удалён
			unset($socket);
		    continue;	// к следующему сокету
		}
		//echo "\nПРИНЯТО ОТ КЛИЕНТА #$sockKey ".mb_strlen($buf,'8bit')." байт, PUT={$messages[$sockKey]['PUT']};\n";
		//print_r($messages[$sockKey]);
		if(@$messages[$sockKey]['PUT'] == TRUE){ 	// прочитали из соединения с каким-то источником данных с протоколом типа gpsg
			//echo "\n buf from other # $sockKey $socket: $buf \n";
			$inInstrumentsData = GPSDlikeInstrumentsDataDecode($buf);	// одно сообщение конкретного класса из потока
			//echo "\n inInstrumentsData from other \n"; print_r($inInstrumentsData);
			updAndPrepare($inInstrumentsData); // обновим кеш и отправим данные для режима WATCH
			//echo "\n gpsdData\n"; print_r($instrumentsData);
			continue; 	// к следующему сокету
		}
		if($messages[$sockKey]['greeting']===TRUE){ 	// с этим сокетом уже беседуем, значит -- пришли данные	
			switch($messages[$sockKey]['protocol']){
			case 'WS':	// ответ за запрос через websocket, здесь нет конца передачи, посылается сколько-то фреймов.
				//echo "\nПРИНЯТО  из вебсокета ОТ КЛИЕНТА #$sockKey ".mb_strlen($buf,'8bit')." байт\n";
				//print_r(wsDecode($buf));
				// бывают склеенные и неполные фреймы
				// там может быть: 1) неполный фрейм; 2) сколько-то полных фреймов, и, возможно, неполный
				// но нет полного сообщения; 3) завершение сообщения, плюс что-то ещё; 4) полное сообщение,
				// плюс, возможно, что-то ещё
				$n = 0;
				do{	// выделим из полученного полные фреймы
					$n++;
					if(@$messages[$sockKey]['FIN']=='partFrame') {
						//echo "предыдущий фрейм был неполный, к имеющимся ".mb_strlen($messages[$sockKey]['partFrame'],'8bit')." байт добавлено полученные ".mb_strlen($buf,'8bit')." байт, получилось ".(mb_strlen($messages[$sockKey]['partFrame'],'8bit')+mb_strlen($buf,'8bit'))." байт $n \n";
						$buf = $messages[$sockKey]['partFrame'].$buf;	
					}
					
					$res = wsDecode($buf);	// собственно декодирование: вытаскивание из потока байт фреймов
					//echo "Результат декодирования принятого через websocket:   "; print_r($res); echo "\n";
					$saveBuf = $buf;
					$buf = null;
					if($res == FALSE){
						echo "Bufer decode fails, will close websocket\n";
						chkSocks($socket);	// закроет сокет
						unset($socket);
						continue 3;	// к следующему сокету						
					}
					else list($decodedData,$type,$FIN,$tail) = $res;

					$messages[$sockKey]['FIN'] = $FIN;
					
					// ping -- это фрейм, а не сообщение, как сказано в rfc6455, 
					// однако этот фрейм имеет первый бит ws-frame раный 1, т.е., это последний фрейм сообщения.
					// Таким образом, ping -- это сообщение из одного фрейма, которое может придти посередине другого сообщения?
					
					switch($FIN){
					case 'messageComplete':	// СООБЩЕНИЕ ПРИНЯТО: в буфере последний фрейм сообщения -- полностью, он имеет тип $messages[$sockKey]['frameType'] и декодирован в $decodedData. Возможно, есть ещё полные или неполные фреймы, они находятся в $tail и не декодированы
						$buf = $tail;	// возможно, там ещё есть полные фреймы
						
						//echo "Сообщение принято $n \n";
						if($type) {
							//echo "в одном фрейме\n";
							$realType = $type;
						}
						else {
							//echo "в нескольких фреймах\n";
							$realType = $messages[$sockKey]['frameType'];
						}
						//
						if($tail) {	// есть уже следующее сообщение
							echo "однако, в буфере ".mb_strlen($tail,'8bit')." байт \n";
						}
						//
						switch($realType){	// 
						case 'text':	// требуемое
							$messages[$sockKey]['inBuf'] .= $decodedData;	// 
							//echo "Принято текстовое сообщение длиной ".mb_strlen($messages[$sockKey]['inBuf'],'8bit')." байт\n";
							//echo "decoded data=|{$messages[$sockKey]['inBuf']}|\n";
							if(rtrim($messages[$sockKey]['inBuf'])){	// пустые строки, пришедшие отдельным сообщением не записываем
								$messages[$sockKey]['inBufS'][] = $messages[$sockKey]['inBuf'];	// всегда для websockets будем складывать сообщения в массив
							}
							$messages[$sockKey]['inBuf'] = '';	// было $tail. Зачем?
							//$messages[$sockKey]['inBuf'] = $tail;	// было $tail. Зачем? Так работает, без - нет.
							$messages[$sockKey]['partFrame'] = '';
							$messages[$sockKey]['frameType'] = null;
							break;
						case 'close':
							//echo "От клиента принято требование закрыть соединение.    \n";
							chkSocks($socket);	// закроет сокет
							unset($socket);
							continue 5;	// к следующему сокету
						case 'ping':	// An endpoint MAY send a Ping frame any time after the connection is    established and before the connection is closed.
						case 'pong':
						case 'binary':
						default:
							echo "A frame of type '$type' was dropped $n                              \n";
							if($decodedData === null){
								echo "Frame decode fails, will close websocket\n";
								chkSocks($socket);	// закроет сокет
								unset($socket);
								continue 5;	// к следующему сокету
							};
						};
						//echo "type={$messages[$sockKey]['frameType']}; FIN=$FIN;n=$n; tail:|$tail|                 \n";
						break;
					case 'partFrame':	// в буфере -- неполный фрейм, он не декодирован ($decodedData==null) и возвращён в $tail
						//echo "Принят неполный фрейм типа $type, размером ".mb_strlen($tail,'8bit')." байт на $n-м обороте.    \n";
						if($type) {	// это первый фрейм. 
							$messages[$sockKey]['frameType'] = $type;
							//echo "это первый фрейм $n\n";
						}
						if($messages[$sockKey]['frameType']){
							$messages[$sockKey]['partFrame'] = $tail;	// я присоединяю перед декодированием
							continue 4;	// к следующему сокету
						}
						else {	// всё кривое, скажем, после приёма нормального фрейма. Однако, принятое надо обработать.
							//echo "однако тип его неизвестен. Игнорируем остаток данных.          \n";
							$messages[$sockKey]['inBuf'] = '';
							$messages[$sockKey]['partFrame'] = '';
						}
						break;
					default:	// непоследний фрейм сообщения полностью, и, возможно, что-то ещё
						if($type) {	// это первый фрейм. 
							$messages[$sockKey]['frameType'] = $type;
							//echo "Получен первый фрейм $n\n";
						}
						//echo "Собираем сообщение типа {$messages[$sockKey]['frameType']}, декодировано ".mb_strlen($decodedData,'8bit')." байт на $n-й оборот\n";
						$messages[$sockKey]['inBuf'] .= $decodedData;	// собираем сообщение
						$buf = $tail;	// для декодирования на следующем обороте ближайшего do
					}
				}while($buf);	// выбрали полные фреймы, в $tail -- неполный
				if(!$tail) $messages[$sockKey]['inBuf'] = '';

				$buf = $messages[$sockKey]['inBufS'];
				unset($messages[$sockKey]['inBufS']);	// должно помочь с памятью?
				//echo "Принято от websocket'а:"; print_r($buf); print_r($messages[$sockKey]);
				$messages[$sockKey]['inBufS'] = array();	// очистим буфер сообщений
				if(!$buf) continue 2;	// к следующему сокету
				break;	// case protocol WS
			default:
				//echo "Какой-то другой протокол.          \n";
			}; // end switch protocol
		}
		else{ 	// с этим сокетом ещё не беседовали, значит, пришёл заголовок или команда gpsd или ничего, если сокет просто открыли
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
					$messages[$sockKey]['inBufS'] = array();	// для websocket будет ещё и буфер сообщений
					break;
				default:	// ответ вообще в сокет, как это для протокола gpsd
					$messages[$sockKey]['output'][] = $greeting."\r\n\r\n";	// приветствие gpsd
				};
				//echo "sockKey=$sockKey;\n";
				$messages[$sockKey]['greeting']=TRUE;
				$messages[$sockKey]['inBuf'] = '';					
			};
			continue;	// к следующему сокету
		};

		// выделим команду и параметры
		if(!is_array($buf))	$buf = explode(';',$buf); 	// 
		//echo "Сообщение от клиента №$sockKey: "; print_r($buf);echo "          \n";
		foreach($buf as $command){
			if(!$command) continue;
			if($command[0]!='?') continue; 	// это не команда протокола gpsd
			$command = rtrim(substr($command,1),';');	// ? ;
			list($command,$params) = explode('=',$command);
			$params = trim($params);
			//echo "\n\nRecieved command from Client #$sockKey $socket command=$command; params=$params;\n";
			if($params) $params = json_decode($params,TRUE);
			else $params = array();
			if($params['subscribe']) {
				$params['subscribe'] = array_fill_keys(explode(',',$params['subscribe']),TRUE);
			};
			//echo "\n\nparams:"; print_r($params); echo "\n";
			// Обработаем команду
			switch($command){
			case 'WATCH': 	// default: ?WATCH={"enable":true}; без параметров === {"enable":false} Это правильно?
				if($params['enable'] === TRUE){
					if(!$params['subscribe']) $params['subscribe'] = $defaultSubscribe;
					//echo "\n count(params)=".(count($params)); print_r($params);
					if(count($params)>2){ 	// всегда есть $params['subscribe'], POLL имеет "enable":true, WATCH -- ещё "json":true
						$messages[$sockKey]['POLL'] = 'WATCH'; 	// отметим, что WATCH получили в виде, означающем, что это не POLL, надо слать данные непрерывно
						$messages[$sockKey]['minPeriod'] = @$params['minPeriod'];
						$messages[$sockKey]['subscribe'] = $params['subscribe'];
						// Сразу отправим ему все уже существующие данные в соответствии с его подпиской
						foreach($messages[$sockKey]['subscribe'] as $subscribe=>$v){
							$pollWatchExist[$subscribe] = TRUE;	// отметим, что есть сокет с режимом WATCH и некоторой подпиской
							switch($subscribe){
							case "TPV":
								$messages[$sockKey]['output'][] = json_encode(makeWATCH("TPV"), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";
								break;
							case "ATT":
								$messages[$sockKey]['output'][] = json_encode(makeWATCH("ATT"), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";
								break;
							case "AIS":
								$messages[$sockKey]['output'][] = json_encode(makeAIS(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";
								break;
							case "ALARM":
								$messages[$sockKey]['output'][] = json_encode(makeALARM(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";
								break;
							case "WPT":
								$messages[$sockKey]['output'][] = json_encode(makeWPT(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)."\r\n\r\n";
								break;
							}
						}
					}
					else {
						$messages[$sockKey]['POLL'] = TRUE; 	// отметим, что WATCH получили, можно отвечать на POLL
					};
					// вернуть DEVICES
					$msg = array('class' => 'DEVICES', 'devices' => array($gpsdProxydevice));
					$msg = json_encode($msg)."\r\n\r\n";
					$messages[$sockKey]['output'][] = $msg;
					// вернуть статус WATCH
					$msg = '{"class":"WATCH","enable":true,"json":true}'."\r\n\r\n";
					$messages[$sockKey]['output'][] = $msg;
				}
				elseif($params['enable'] === FALSE){ 	// клиент сказал: всё
					if($messages[$n]['protocol'] == 'WS'){
						$messages[$sockKey]['output'][] = array("It's all",'close');	// скажем послать фрейм, прекращающий соединение. Клиент закрое сокет, потом этот сокет обработается как дефектный
					}
					else {
						//echo "Socket to client close by command from client                           \n";	// это сообщение будет появляться каждый POLL, так что не надо.
						chkSocks($socket);	// просто закроем сокет
						unset($socket);
					}
				};
				break;
			case 'POLL':
				if(!$messages[$sockKey]['POLL']) continue 2; 	// на POLL будем отзываться только после ?WATCH={"enable":true}
				if(!$params['subscribe']) $params['subscribe'] = $defaultSubscribe;
				$POLL = makePOLL($params['subscribe']);	// подготовим данные в соответствии с подпиской
				//echo "\nPOLL recieved, prepare data:"; print_r($POLL);
				//echo "\n\nPOLL  params:"; print_r($params); echo "\n";
				$messages[$sockKey]['output'][] = json_encode($POLL)."\r\n\r\n"; 	// будем копить сообщения, вдруг клиент не готов их принять
				unset($POLL);
				break;
			case 'CONNECT':	// подключиться к этому сокету как к gpsd. Используется, например, в netAISclient. Эта операция требует авторизации.
				if(!$messages[$sockKey]['privileged']) break;	// авторизация. Клиенту сообщать не будем?
				echo "recieved CONNECT! #$sockKey $socket                                     \n";
				if(@$params['host'] and @$params['port']) { 	// указано подключиться туда
					// Видимо, разрешать переподключаться за пределы локальной сети как-то неправильно...
				}
				else { 	// данные будут из этого сокета
					//echo "\nby CONNECT, begin handshaking\n";
					$newDevices = connectToGPSD($socket);	// все будут ждать, пока тут всё подключится
					if(!$newDevices) break;
					$messages[$sockKey]['PUT'] = TRUE; 	//
					$devicePresent = array_unique(array_merge($devicePresent,$newDevices));	// плоские массивы
					//echo "\nCONNECT!\n";
				}
				break;
			case 'UPDATE':	// Эта операция требует авторизации.
				//echo "\n UPDATE #$sockKey $socket \n"; 
				//print_r($params); echo "\n";
				if(!$messages[$sockKey]['privileged']) break;	// авторизация.
				updAndPrepare($params['updates'],$sockKey); // обновим кеш и отправим данные для режима WATCH
				break;
			case 'WPT':	// Эта операция требует авторизации.
				//echo "\n WPT #$sockKey $socket privileged={$messages[$sockKey]['privileged']}\n"; 
				//print_r($params); echo "\n";
				if(!$messages[$sockKey]['privileged']) break;	// авторизация.
				updAndPrepare(null,null,actionWPT($params));	// 
				break;
			};
		};
	};
	//echo "\n messages: "; print_r($messages); 
} while (true);

foreach($sockets as $socket) {
	socket_close($socket);
}
foreach($masterSocks as $masterSock){
	socket_close($masterSock);
};
dataSourceClose($dataSourceConnectionObject);


function IRun() {
/**/
global $phpCLIexec;
$pid = getmypid();
//echo "ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME),"'\n";
$toFind = pathinfo(__FILE__,PATHINFO_BASENAME);
@exec("ps -A w | grep '$toFind'",$psList);	// конечно, проще через pgrep -f , но не везде есть
if(!$psList) { 	// for OpenWRT. For others -- let's hope so all run from one user
	exec("ps w | grep '$toFind'",$psList);
	echo "IRun: BusyBox based system found\n";
}
//echo "__FILE__=".__FILE__."; pid=$pid; phpCLIexec=$phpCLIexec; toFind=$toFind;\n"; print_r($psList); //
//file_put_contents('IRun.txt', "__FILE__=".__FILE__."; pid=$pid; phpCLIexec=$phpCLIexec; toFind=$toFind;\n".print_r($psList,true)); //
$run = FALSE;
foreach($psList as $str) {
	if(strpos($str,(string)$pid)!==FALSE) continue;
	//echo "$str\n";
	if((strpos($str,'sh ')!==FALSE) or (strpos($str,'bash ')!==FALSE) or (strpos($str,'ps ')!==FALSE) or (strpos($str,'grep ')!==FALSE)) continue;
	//echo "str=$str;\n";
	//if((strpos($str,"$phpCLIexec ")!==FALSE) and (strpos($str,$toFind)!==FALSE)){	
	// В docker image  thecodingmachine/docker-images-php $phpCLIexec===php, но реально запускается /usr/bin/real_php
	// поэтому ищем имя скрипта, а в том, чем его запустили -- php
	if(strpos($str,$toFind)!==FALSE){	
		$str = explode(' ',$str);
		//print_r($str);
		foreach($str as $st){
			if(strpos($st,"php")!==FALSE){
				$run=TRUE;
				break 2;
			}
		}
	}
}
//echo "run=$run;\n";
return $run;
};

?>
