<?php
/* Функции для работы с источником данных VenusOS */
require_once('phpMQTT.php');

$dataSourceHumanName = 'VenusOS';	// Обязательно!!! Required!!!
$dataSourceConnectionObject = new Bluerhinos\phpMQTT($dataSourceHost, $dataSourcePort, uniqid());	// объект соединения с сервером MQTT
$dataSourceConnectionObject->userData = array(); 	// там есть типа хранилище, пусть оно будет массивом

function dataSourceConnect(&$mqtt){
/* Return array(deviceID) of devices that return data */
if(!$mqtt->connect_auto(true,null,null,null,20)) return FALSE;	// будем пытаться законнектится 20 сек.
return findVenusOSdevices($mqtt);
} // end function connectToDataSource

function dataSourceClose($dataSock){
/* Close data source connection 
Не будем ничего закрывать, потому что незачем и непонятно, как потом открывать
*/
return false;
} // end function dataSourceClose

function instrumentsDataDecode($buf){
/* Делает из полученного из сокета $buf данные в формате $instrumentsData, т.е. приводит их к формату 
ответа gpsd в режиме ?WATCH={"enable":true,"json":true};, когда оно передаёт поток отдельных сообщений, типа:
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
return null; 	// в данном случае такой функции нет, аналогичная по смыслу функция venusosDataDecode, которая вызывается как collback
} // end function instrumentsDataDecode

function altReadData(&$mqtt){
/* Функция альтернативного чтения данных, вызывается перед ожиданием сокетов socket_select 
возвращает true если данные были получены
*/
global $devicePresent,$VenusOSsystemSerial,$dataSourceHost,$dataSourcePort;
// 
//var_dump($mqtt);
if(!$devicePresent){
	$mqtt->topics = array();	// новое подключение может быть к новому устройству, а подписки -- к старому
	$devicePresent = findVenusOSdevices($mqtt);	// если найдёт -- подпишется
	//echo "devicePresent: "; print_r($devicePresent);
}
$ret = FALSE;
//echo "mqtt->userData['keepaliveTimeStamp']={$mqtt->userData['keepaliveTimeStamp']}; ".(time()-$mqtt->userData['keepaliveTimeStamp'])."      \n";
if((time()-$mqtt->userData['keepaliveTimeStamp'])>30){	// периодическое пинание mosguito чтобы посылал что просят
	$mqtt->publish("R/$VenusOSsystemSerial/keepalive", '["gps/#"]', 0, false);
	$mqtt->userData['keepaliveTimeStamp'] = time();
	$ret = TRUE;
	//echo "Новый keepalive                                           \n";
}
$payload=$mqtt->proc(TRUE);	// не ждать 1/10 секунды после чтения из сокета пусто
//echo "\nЦикл чтения, payload:$payload           \n";
//print_r($payload); 
if($payload){
	$ret = TRUE;
	
	$topic = end(explode('/',$payload[0]));
	$msg = json_decode($payload[1],TRUE)['value'];
	//echo "topic=$topic; msg=$msg; timestamp ".(time()-$mqtt->userData['keepaliveTimeStamp'])." sec.                \n";
	if(!$topic) {	// что-то совсем сломалось: сеть, сервер MQTT или что-то такое
		$mqtt->close();	// отсоединимся от сервера
		$mqtt->topics = array();	// новое подключение может быть к новому устройству, а подписки -- к старому
		if(!$mqtt->connect()) exit("VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort die. Bye.\n");	
		$devicePresent = findVenusOSdevices($mqtt);
		if(!$devicePresent) exit("On the VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort not found GNSS devices. Bye.\n");	
	}
	if($topic=='Connected' and !$msg) {	// Устройство не подключено?
		$mqtt->topics = array();	// новое подключение может быть к новому устройству, а подписки -- к старому
		$devicePresent = findVenusOSdevices($mqtt);
		if(!$devicePresent) exit("All GNSS devices on the VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort die. Bye.\n");	
	};
	if($topic=='Fix' and !$msg) {	// Не видно спутников
		$mqtt->topics = array();	// новое подключение может быть к новому устройству, а подписки -- к старому
		$devicePresent = findVenusOSdevices($mqtt);
		if(!$devicePresent) exit("All GNSS devices on the VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort die. Bye.\n");	
	};
	$accordance = array(
	'Altitude' => 'altHAE',
	'Course' => 'track',
	'Latitude' => 'lat',
	'Longitude' => 'lon',
	//'NrOfSatellites' => '',
	'Speed' => 'speed'
	);
	if($accordance[$topic]){
		// здесь мы всегда работаем с одним приёмником ГПС (глубина в VenusOS где-то в другом месте, и её сейчас нет, также как и AIS), и нет способа выбрать лучший приёмник. Поэтому -- просто первый в списке.
		$inInstrumentsData = array('class' => 'TPV', 'device' => $devicePresent[0], $accordance[$topic] => $msg);	// время -- время получения, поскольку времени в VenusOS нет. Но реально данные могли быть получены давно: у этих людей нет разницы между неизменным значением и новым значением с той же величиной.
		//print_r($inInstrumentsData);
		updAndPrepare(array($inInstrumentsData)); // обновим кеш и отправим данные для режима WATCH
	}

}
elseif($payload===null){	// сокет умер
	echo "MQTT service on the VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort die. Try to find new.";
	$mqtt->topics = array();	// новое подключение может быть к новому устройству, а подписки -- к старому
	$devicePresent = findVenusOSdevices($mqtt);	// если найдёт -- подпишется
	//echo "devicePresent: "; print_r($devicePresent);
	if(!$devicePresent) exit("MQTT service on the VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort die and not found other. Bye.        \n");
}
return $ret;
} // end function altReadData






function findVenusOSdevices(&$mqtt){
/* Обнаруживает устройства gps, оставляет те, у кого есть fix и подписывается на какое-то из них
*/
global $VenusOSsystemSerial;
//echo "[findVenusOSdevices] Поищем устройства \n";//var_dump($mqtt);
$devicePresent = array();
$mqtt->publish("R/$VenusOSsystemSerial/keepalive", '["gps/#"]', 0, false);
$payload = $mqtt->subscribeAndWaitForMessage("N/$VenusOSsystemSerial/gps/+/DeviceInstance", 0);
unset($mqtt->topics["N/$VenusOSsystemSerial/gps/+/DeviceInstance"]);
if($payload) {
	$devicePresent = array_map(function ($str){return substr($str,0,-15);},array_keys($payload));	// путь к устройству с номером устройства, но без завершающего /
	//echo "[findVenusOSdevices] devicePresent:"; print_r($devicePresent);
	foreach($devicePresent as $key => $device){
		$payload = $mqtt->subscribeAndWaitForMessage("$device/Connected", 0);
		//echo "[findVenusOSdevices] payload:"; print_r($payload);
		if($payload){
			if(!json_decode($payload["$device/Connected"],TRUE)['value']) unset($devicePresent[$key]);
		}
		else unset($devicePresent[$key]);
	}
	if($devicePresent) {
		// Нет способа выбрать лучшее устройство!!! Берём первое в списке.
		$topics[$devicePresent[0]."/#"] = array('qos' => 0, 'function' => '__direct_return_message__');	// используем не callback, а возврат результата. callback -- это очень неудобно.
		$mqtt->subscribe($topics, 0);
		$mqtt->userData['keepaliveTimeStamp'] = time();
	}
	else {
		$mqtt->userData['keepaliveTimeStamp'] = null;
	}
}
return $devicePresent;
} // end function findVenusOSdevices

/*
function venusosDataDecode($topic, $msg){
global $dataSourceConnectionObject,$VenusOSsystemSerial,$dataSourceHost,$dataSourcePort;
//echo "\n[venusosDataDecode] topic $topic:\t$msg\n\n";
$topic = end(explode('/',$topic));
if(!$topic) {	// что-то совсем сломалось: сеть, сервер MQTT или что-то такое
	$dataSourceConnectionObject->close();	// отсоединимся от сервера
	$dataSourceConnectionObject->topics = array();	// новое подключение может быть к новому устройству, а подписки -- к старому
	if(!$dataSourceConnectionObject->connect()) exit("VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort die. Bye.");	
	$devicePresent = findVenusOSdevices($dataSourceConnectionObject);
	if(!$devicePresent) exit("On the VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort not found GNSS devices. Bye.");	
}
$msg = json_decode($msg,TRUE)['value'];
if($topic=='Connected' and !$msg) {	// Устройство не подключено?
	$dataSourceConnectionObject->topics = array();	// новое подключение может быть к новому устройству, а подписки -- к старому
	$devicePresent = findVenusOSdevices($dataSourceConnectionObject);
	if(!$devicePresent) exit("All GNSS devices on the VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort die. Bye.");	
};
if($topic=='Fix' and !$msg) {	// Не видно спутников
	$dataSourceConnectionObject->topics = array();	// новое подключение может быть к новому устройству, а подписки -- к старому
	$devicePresent = findVenusOSdevices($dataSourceConnectionObject);
	if(!$devicePresent) exit("All GNSS devices on the VenusOS # $VenusOSsystemSerial on $dataSourceHost:$dataSourcePort die. Bye.");	
};
$accordance = array(
'Altitude' => 'altHAE',
'Course' => 'track',
'Latitude' => 'lat',
'Longitude' => 'lon',
//'NrOfSatellites' => '',
'Speed' => 'speed'
);
if($accordance[$topic]){
	$inInstrumentsData = array('class' => 'TPV', 'device' => $devicePresent[0], $accordance[$topic] => $msg);
	//print_r($inInstrumentsData);
	updAndPrepare(array($inInstrumentsData)); // обновим кеш и отправим данные для режима WATCH
}

} // end function venusosDataDecode
*/


?>
