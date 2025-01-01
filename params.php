<?php
// Подключение к gpsdPROXY, список хост,порт
// по возможности не указывайте '0.0.0.0' и '[::]'-- это не очень безопасно.
// Лучше указать разные порты для ipv4 и ipv6, хотя в современных системах можно и один.
// Квадратные скобки [] в адресе ipv6 обязательны.
// The gpsdPROXY connection list, host,port.
// Pls, avoid set '0.0.0.0' и '[::]' for security reasons.
// The square brackets [] on ipv6 address is required.
// Commonly you must set different ports for ipv4 and ipv6, but it may be one for modern systems.
$gpsdProxyHosts = array(
	array('0.0.0.0',3838),
	array('[::]',3839),
);

// перечень типов данных каждого источника в gpsd, для которых требуется контролтровать время жизни
// gpsd data types and their lifetime, sec
$gpsdProxyTimeouts = array(  	// время в секундах после последнего обновления, после которого считается, что данные протухли. Поскольку мы спрашиваем gpsd POLL, легко не увидеть редко передаваемые данные
'TPV' => array( 	// time-position-velocity report datatypes
	'altHAE' => 20, 	// Altitude, height above ellipsoid, in meters. Probably WGS84.
	'altMSL' => 20, 	// MSL Altitude in meters. 
	'alt' => 20, 	// legacy Altitude in meters. 
	'lat' => 10,
	'lon' => 10,
	'track' => 10, 	// истинный путевой угол
	'heading' => 10,	// истинный курс
	'speed' => 5,	// Speed over ground, meters per second.
	'errX' => 30,
	'errY' => 30,
	'errS' => 30,
	'magtrack' => 10, 	// магнитный курс
	'magvar' => 3600, 	// магнитное склонение
	'mheading' => 10,	// магнитный курс
	'depth' => 5, 		// глубина
	'wanglem' => 3, 	// Wind angle magnetic in degrees.
	'wangler' => 3, 	// Wind angle relative in degrees.
	'wanglet' => 3, 	// Wind angle true in degrees.
	'wspeedr' => 3, 	// Wind speed relative in meters per second.
	'wspeedt' => 3, 	// Wind speed true in meters per second.
	'time' => 10		// Set same as lat lon. Regiure!
),
'AIS' => array( 	// AIS datatypes. Реально задержка даже от реального AIS может быть минута, а через интернет - до трёх
	'status' => 86400, 	// Navigational status, one day сутки
	'accuracy' => 60*5, 	// Position accuracy
	'turn' => 60*3, 	// 
	'lon' => 60*4, 	// 
	'lat' => 60*4, 	// 
	'speed' => 60*2, 	// 
	'course' => 60*3, 	// 
	'heading' => 60*3, 	// 
	'maneuver' => 60*3 	// 
)
);

// Характеристики судна
// Если используется netAIS -- укажите его файл с параметрами судна, иначе -- укажите необходимое здесь
// Информация из конфигурационного файла netAIS имеет преимущество.
// Vehacle description
// If netAIS is used -- specify its vessel's configuration file, otherwise -- specify the necessary here.
// The information from the netAIS configuration file has an advantage.
$netAISconfig = '../../netAIS/boatInfo.ini';
$boatInfo = array();
/*
$boatInfo = array(
'length'=>9.1,	// Длина, м.
'beam'=>3.05,	// Ширина, м.
'to_bow'=>5,	// к носу от точки координат, в метрах. Reference point for reported position. Also indicates the dimension of ship (m) (see Fig. 42 and § 3.3.3) For SAR aircraft, the use of this field may be decided by the responsible administration. If used it should indicate the maximum dimensions of the craft. As default should A = B = C = D be set to “0”
'to_stern'=>4,	// к корме. Reference point for reported position.
'to_port'=>2.25,	// к левому борту. Reference point for reported position.
'to_starboard'=>0.75,	// к правому борту. Reference point for reported position.
'to_echosounder'=>0,		// поправка к получаемой от прибора глубине до желаемой: от поверхности или от киля. Correction to the depth received from the device to the desired depth: from the surface or from the keel.
'magdev'=>0		// девиация компаса, градусы. Magnetic deviation of the compass, degrees
);
*/

// время в секундах, в течении которого цель AIS сохраняется в кеше после получения от неё последней информации
$noVehicleTimeout = 20*60; 	// seconds, time of continuous absence of the vessel in AIS, when reached - is deleted from the data. "when a ship is moored or at anchor, the position message is only broadcast every 180 seconds;"
// адрес и порт источника координат и остальных данных, по умолчанию -- gpsd
// host and port of instruments data source, gpsd by default

// Контроль возможности столкновений
// Collision detector
// 	Дистанция, до которой определяется возможность столкновения, в минутах движения
$collisionDistance = 10;	// minutes of movement

// Источник данных. Data source.
//$dataSourceHost = 'localhost';	// default
//$dataSourcePort = 2947;	// default gpsd
//$dataSourceHost = '192.168.10.105';	// SignalK
//$dataSourcePort = 3000;	// SignalK

/* Можно указать только тип источника данных: gpsd, venusos или signalk, если порт стандартный, а хост -- localhost
если же источники типа venusos или signalk не будут обнаружены на локальном компьютере, будет сделана попытка
найти их в сети как venus.local или signalk.local

You may set only dataSourceType ('gpsd', 'venusos' or 'signalk') if service present on localhost on standard port.
If service will not present on localhost will be attempt to find service in LAN as venus.local or signalk.local
*/
//$dataSourceType = 'gpsd';	// default
//$dataSourceType = 'venusos';	// 
//$dataSourceType = 'signalk';	// 

// Отключение от gpsd
// Freeing gpsd
// Время, сек., через которое происходит отключение от gpsd при отсутствии клиентов. gpsd отключит датчики
// должно быть 1.5 - 2 интервала возможных запросов POLL, или более. Например, netAIS запрашивает данные посредством POLL
// раз в 5 секунд. По POLL gpsdPROXY запросит gpsd, gpsd разбудит приёмник ГПС,
// но не успеет получить координаты, вернув пусто. gpsdPROXY вернёт пусто в ответ на POLL. Поэтому,
// если $noClientTimeout меньше имеющегося интервала запросов POLL, POLL'ящий клиент чаще всего не
// будет получать координат. Но иногда -- будет.
// Настройка этого параметра нужна для экономии электричества. 
// Если электричества много -- нужно просто поставить значение вплоть до нескольких минут, или
// 0 для предотвращения отключения приёмника ГПС.
// inetAIS по-умолчанию опрашивает источник AIS раз в 15 сек., а источник координат, соответственно
// -- раз в 10 сек., поэтому, если $noClientTimeout = 10, то inetAIS не получит координаты никогда.
$noClientTimeout = 30;	// sec., disconnect from gpsd on no any client present. Must be a 1 - 2 possible POLL requests intervals or 0 to disable.

// Параметры сохранения кеша
// Cache backup parms
// Кеш сохраняется каждые сек.
$backupTimeout = 10;	// backup period, sec.
// имя файла, куда сохраняется кеш
$backupFileName = 'backup/gpsdPROXYbackup.json';	// backup filename

// system
$phpCLIexec = 'php'; 	// php-cli executed name on your OS
//$phpCLIexec = '/usr/bin/php-cli'; 	// php-cli executed name on your OS
?>
