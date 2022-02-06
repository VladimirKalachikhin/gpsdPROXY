<?php
/* Функции для работы с источником данных gpsd */

$dataSourceHumanName = 'gpsd';	// 
$dataSourceConnectionObject = createSocketClient($dataSourceHost,$dataSourcePort); 	// Соединение с источником данных

function dataSourceConnect($dataSock){
/* Return array(deviceID) of devices that return data */
return connectToGPSD($dataSock);	// по историческим причинам
} // end function connectToDataSource

function dataSourceClose($dataSock){
$msg = '?WATCH={"enable":false}'."\n";
$res = @socket_write($dataSock, $msg, strlen($msg));
@socket_close($dataSock);
return true;
} // end function dataSourceClose

function instrumentsDataDecode($buf){
/* Делает из полученного из сокета $buf данные в формате $instrumentsData, т.е. приводит их к формату 
массива ответов gpsd в режиме ?WATCH={"enable":true,"json":true};, когда оно передаёт поток отдельных сообщений, типа:
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
return array(json_decode($buf,TRUE));
} // end function instrumentsDataDecode

//function altReadData($dataSourceConnectionObject){
/* Функция альтернативного чтения данных, вызывается перед ожиданием сокетов socket_select 
возвращает true если данные были получены
Может не быть.
*/
//return false;
//} // end function altReadData






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

?>
