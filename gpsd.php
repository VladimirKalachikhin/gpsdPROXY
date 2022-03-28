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
$buf = explode("\n",$buf);
array_walk($buf,function (&$oneBuf){$oneBuf=json_decode($oneBuf,TRUE);});
//echo "gpsd instrumentsDataDecode "; print_r($buf); echo "\n";
return $buf;
} // end function instrumentsDataDecode

//function altReadData($dataSourceConnectionObject){
/* Функция альтернативного чтения данных, вызывается перед ожиданием сокетов socket_select 
возвращает true если данные были получены
Может не быть.
*/
//return false;
//} // end function altReadData

?>
