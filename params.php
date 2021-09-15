<?php
$gpsdProxydHost='localhost'; 	//  gpsdPROXY host
//$gpsdProxyHost='192.168.10.10'; 	// 
$gpsdProxyPort=3838; 	// gpsdPROXY port

// перечень типов данных каждого источника в gpsd, для которых требуется контролтровать время жизни
// gpsd data types and their lifetime, sec
$gpsdProxyTimeouts = array(  	// время в секундах после последнего обновления, после которого считается, что данные протухли. Поскольку мы спрашиваем gpsd POLL, легко не увидеть редко передаваемые данные
'TPV' => array( 	// time-position-velocity report datatypes
	'altHAE' => 20, 	// Altitude, height above ellipsoid, in meters. Probably WGS84.
	'altMSL' => 20, 	// MSL Altitude in meters. 
	'lat' => 10,
	'lon' => 10,
	'track' => 10, 	// курс
	'speed' => 5,	// Speed over ground, meters per second.
	'errX' => 30,
	'errY' => 30,
	'errS' => 30,
	'magtrack' => 10, 	// магнитный курс
	'magvar' => 3600, 	// магнитное склонение
	'depth' => 5, 	// глубина
	'wanglem' => 3, 	// Wind angle magnetic in degrees.
	'wangler' => 3, 	// Wind angle relative in degrees.
	'wanglet' => 3, 	// Wind angle true in degrees.
	'wspeedr' => 3, 	// Wind speed relative in meters per second.
	'wspeedt' => 3 	// Wind speed true in meters per second.
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
// время в секундах, в течении которого цель AIS сохраняется в кеше после получения от неё последней информации
$noVehicleTimeout = 600; 	// seconds, time of continuous absence of the vessel in AIS, when reached - is deleted from the data. "when a ship is moored or at anchor, the position message is only broadcast every 180 seconds;"

// gpsd host and port
$gpsdProxyGPSDhost = 'localhost';
$gpsdProxyGPSDport = 2947;
//$gpsdProxyGPSDport = 2222;

// system
$phpCLIexec = 'php'; 	// php-cli executed name on your OS
//$phpCLIexec = '/usr/bin/php-cli'; 	// php-cli executed name on your OS
?>
