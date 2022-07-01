<?php
// Подключение к gpsdPROXY
// по возможности не указывайте $gpsdProxyHost='0.0.0.0' -- это не очень безопасно.
// The gpsdPROXY connection info.
//$gpsdProxyHost='localhost'; 	//  gpsdPROXY host
//$gpsdProxyHost='192.168.10.10'; 	// 
$gpsdProxyHost='0.0.0.0'; 	// bad practice! For security reasons, set a real address from your LOCAL network or localhost.
$gpsdProxyPort=3838; 	// gpsdPROXY port

// перечень типов данных каждого источника в gpsd, для которых требуется контролтровать время жизни
// gpsd data types and their lifetime, sec
$gpsdProxyTimeouts = array(  	// время в секундах после последнего обновления, после которого считается, что данные протухли. Поскольку мы спрашиваем gpsd POLL, легко не увидеть редко передаваемые данные
'TPV' => array( 	// time-position-velocity report datatypes
	'altHAE' => 20, 	// Altitude, height above ellipsoid, in meters. Probably WGS84.
	'altMSL' => 20, 	// MSL Altitude in meters. 
	'alt' => 20, 	// legacy Altitude in meters. 
	'lat' => 10,
	'lon' => 10,
	'track' => 10, 	// курс
	'speed' => 5,	// Speed over ground, meters per second.
	'errX' => 30,
	'errY' => 30,
	'errS' => 30,
	'magtrack' => 10, 	// магнитный курс
	'magvar' => 3600, 	// магнитное склонение
	'depth' => 5, 		// глубина
	'wanglem' => 3, 	// Wind angle magnetic in degrees.
	'wangler' => 3, 	// Wind angle relative in degrees.
	'wanglet' => 3, 	// Wind angle true in degrees.
	'wspeedr' => 3, 	// Wind speed relative in meters per second.
	'wspeedt' => 3, 	// Wind speed true in meters per second.
	'time' => 10		// Set same as lat lon. Regiure!
),
'AIS' => array( 	// AIS datatypes
	'status' => 86400, 	// Navigational status, one day сутки
	'accuracy' => 600, 	// Position accuracy
	'turn' => 7, 	// 
	'lon' => 600, 	// 
	'lat' => 600, 	// 
	'speed' => 60, 	// 
	'course' => 60, 	// 
	'heading' => 60, 	// 
	'maneuver' => 60 	// 
)
);

// Характеристики судна
// Если используется netAIS -- укажите его конфигурационный файл, иначе -- укажите необходимое здесь
// Информация из конфигурационного файла netAIS имеет преимущество.
// Vehacle description
// If netAIS is used -- specify its configuration file, otherwise -- specify the necessary here.
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
'to_starboard'=>0.75	// к правому борту. Reference point for reported position.
);
*/

// время в секундах, в течении которого цель AIS сохраняется в кеше после получения от неё последней информации
$noVehicleTimeout = 10*60; 	// seconds, time of continuous absence of the vessel in AIS, when reached - is deleted from the data. "when a ship is moored or at anchor, the position message is only broadcast every 180 seconds;"
// адрес и порт источника координат и остальных данных, по умолчанию -- gpsd
// host and port of instruments data source, gpsd by default

// Контроль возможности столкновений
// Collision detector
// 	Дистанция, до которой определяется возможность столкновения, в минутах движения
$collisionDistance = 10;	// minutes of movement

//$dataSourceHost = 'localhost';	// default
//$dataSourcePort = 2947;	// default gpsd

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
$noClientTimeout = 3;	// sec., disconnect from gpsd on no any client present

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
