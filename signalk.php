<?php
/* Функции для работы с источником данных SignalK */

$dataSourceHumanName = 'SignalK';	// 

// Укажите требуемое, исходя из https://signalk.org/specification/1.5.0/doc/vesselsBranch.html
// Specify the required, based on https://signalk.org/specification/1.5.0/doc/vesselsBranch.html
$signalKnames = array(
'speed' => 'speedOverGround',			// speedThroughWater speedThroughWaterLongitudinal 
'depth' => 'belowTransducer',			// belowKeel belowSurface
);

$dataSourceConnectionObject = createSocketClient($dataSourceHost,$dataSourcePort);	// объект соединения с сервером SignalK

function dataSourceConnect($dataSock){
/* Return array(deviceID) of devices that return data or FALSE */
global $signalKnames;

$buf = @socket_read($dataSock, 2048, PHP_NORMAL_READ); 	// читаем, но сокета может не быть
//echo "$buf\n";
$buf = json_decode($buf, true);
//echo "Decoded buffer: "; print_r($buf);
$self = $buf['self'];
//echo "self=$self;\n";
unset($buf);
if(!$self) return FALSE;

$signalKsubscribe = '{
	"context": "vessels.*",
	"subscribe": [
		{
			"path": "",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "mmsi",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "name",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "registrations.imo",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "communication.callsignVhf",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "communication.netAIS",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "design.aisShipType",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "design.draft",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "design.length",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "design.beam",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.position",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.state",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.courseOverGroundTrue",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.headingTrue",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.speedOverGround",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.destination.commonName",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.destination.eta",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.headingMagnetic",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.magneticVariation",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.magneticDeviation",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.headingCompass",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.maneuver",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.rateOfTurn",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
';		
if($signalKnames['speed'] != 'speedOverGround')	{
	$signalKsubscribe .= '
		{
			"path": "navigation.'.$signalKnames['speed'].'",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
	';
}
$signalKsubscribe .= '
		{
			"path": "sensors.ais.fromBow",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "sensors.ais.fromCenter",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "environment.depth.'.$signalKnames['depth'].'",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		},
		{
			"path": "navigation.datetime",
			"format": "delta",
			"policy": "instant",
			"minPeriod": 0
		}
	]
}';
$signalKsubscribe = json_decode($signalKsubscribe, true);
//echo "signalKsubscribe: "; print_r($signalKsubscribe); echo "\n";
$signalKsubscribe = json_encode($signalKsubscribe)."\r\n";
$res = socket_write($dataSock, $signalKsubscribe, mb_strlen($signalKsubscribe,'8bit'));	// Подпишемся

return array($self);
} // end function connectToDataSource

function dataSourceClose($dataSock){
/* Close data source connection */
$signalKunSubscribe = '{"context": "*", "unsubscribe": [{"path": "*"}]}';
$res = @socket_write($dataSock, $signalKunSubscribe, mb_strlen($signalKunSubscribe,'8bit'));	// Отпишемся
@socket_close($dataSock);
return TRUE;
} // end function dataSourceClose

function instrumentsDataDecode($buf){
/* Делает из полученного из сокета $buf данные в формате $instrumentsData, т.е. приводит их к формату 
массива ответов gpsd в режиме ?WATCH={"enable":true,"json":true};, когда оно передаёт поток отдельных сообщений, типа:
Array
(
    [class] => TPV
    [time] => 2022-02-01T13:36:12.972Z
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
global $signalKnames,$devicePresent,$boatInfo;
$WATCH = array();

$buf = json_decode($buf,TRUE);
if(!$buf) return $WATCH;	// там могли быть строки из пробелов, \n, и прочее
elseif($buf['class']){
	$WATCH[]=$buf;	//	там данные от gpsd-like клиента, подключившегося сервером по команде CONNECT
	//echo "buf from CONNECT client"; print_r($WATCH);
	return $WATCH;
}

//print_r($buf);
// Перепакуем данные
$self = $devicePresent[0];	// а тут нет других девайсов, так что через этот массив передаём контекст self
//echo "self=$self; buf['context']={$buf['context']};\n";
if($buf['context'] == $self){	//echo "Сведения о себе               \n";
	foreach($buf['updates'] as $upd){
		$tpv = array('class'=>'TPV');	// TPV в смысле gpsd -- это ответ от одного устройства. Здесь, вроде, каждый элемент массива updates и есть ответ от одного устройства
		if($upd['source'])	$tpv['device'] = $upd['source']['label'];
		else $tpv['device'] = 'Signal K';	// дока говорит, что если нет device, то устройство одно
		$tpv['time'] = $upd['timestamp'];
		foreach($upd['values'] as $sample){
			switch($sample['path']){
			case 'navigation.position':
				$tpv['lat'] = $sample['value']['latitude'];
				$tpv['lon'] = $sample['value']['longitude'];		
				break;
			case ('navigation.'.$signalKnames['track']):
				$tpv['track'] = ($sample['value']*180)/M_PI;	// Units: rad (Radian)
				break;
			case 'navigation.courseOverGroundTrue':
				$tpv['track'] = ($sample['value']*180)/M_PI;	// Units: rad (Radian)
				break;
			case 'navigation.headingTrue':
				$tpv['heading'] = ($sample['value']*180)/M_PI;	// Units: rad (Radian)
				break;
			// в принципе, может быть и navigation.headingMagnetic и navigation.headingCompass
			// причём разные (с учётом navigation.magneticDeviation)
			// Кто им доктор?
			case 'navigation.headingMagnetic':
				$tpv['mheading'] = ($sample['value']*180)/M_PI;	// Units: rad (Radian)
			case 'navigation.headingCompass':
				$tpv['mheading'] = ($sample['value']*180)/M_PI;	// Units: rad (Radian)
				break;
			case 'navigation.magneticDeviation':	// Magnetic deviation of the compass at the current headingCompass погрешность компаса, магнитная девиация
				$tpv['magdev'] = ($sample['value']*180)/M_PI;	// Units: rad (Radian)
				break;
			case ('navigation.'.$signalKnames['speed']):
				$tpv['speed'] = $sample['value'];
				break;
			case ('environment.depth.'.$signalKnames['depth']):
				$tpv['depth'] = $sample['value'];
				break;
			case ('navigation.'.$signalKnames['magtrack']):
				$tpv['magtrack'] = ($sample['value']*180)/M_PI;	// Units: rad (Radian);
				break;
			case ('navigation.'.$signalKnames['magvar']):
				$tpv['magvar'] = $sample['value'];
				break;
			case 'design.length':
				$boatInfo['length'] = $sample['value']['overall'];
				break;
			case 'design.beam':
				$boatInfo['beam'] = $sample['value'];
				break;
			case 'sensors.ais.fromBow':
				$boatInfo['to_bow'] = $sample['value'];
				break;
			case 'sensors.ais.fromCenter':
				if($sample['value']>0) $boatInfo['to_port'] = $sample['value'];
				else $boatInfo['to_starboard'] = -$sample['value'];
				break;
			}
		}
		$WATCH[] = $tpv;
	};
}
else {		//echo "Сведения про другие лоханки        \n"; 
	// AIS в смысле gpsd -- это сведения про одно судно. Здесь одно судно -- это context.
	$ais = array('class'=>'AIS','type'=>1,"scaled"=>true);	// type -- любой из полных, scaled -- данные в нормальных единицах, кроме скорости, которая в УЗЛАХ!!!
	$ais['mmsi'] = substr($buf['context'],strrpos($buf['context'],'mmsi:')+5);	// здесь mmsi не может не быть
	foreach($buf['updates'] as $upd){
		//print_r($upd);
		$ais['second'] = strtotime($upd['timestamp']);	// это неправильно с точки зрения спецификации AIS, но мы поймём.
		foreach($upd['values'] as $sample){
			//if($sample['path']=='navigation.position') echo "Судно {$ais['mmsi']}: {$sample['path']}                         \n";
			//echo "Судно {$ais['mmsi']}: {$sample['path']}                         \n";
			//print_r($sample['value']); echo "\n";
			switch($sample['path']){
			case '':	// косяк SignalK
				foreach($sample['value'] as $key => $value){
					//echo "Empty path, $key => $value                 \n";
					switch($key){
					case 'name':
						$ais['shipname'] = $value;
						break;
					case 'mmsi':
						$ais['mmsi'] = $value;
						break;
					case 'registrations':
						$ais['imo'] = $value['imo'];		
						break;
					case 'communication':
						//print_r($value);
						if($value['callsignVhf']) $ais['callsign'] = $value['callsignVhf'];		
						if($value['netAIS']) $ais['netAIS'] = $value['netAIS'];		
						break;
					}
				}
				break;
			case 'name':
				$ais['shipname'] = $sample['value']['name'];		
				break;
			case 'registrations.imo':
				$ais['imo'] = $sample['value'];		
				break;
			case 'communication.callsignVhf':
				$ais['callsign'] = $sample['value'];		
				break;
			case 'communication.netAIS':
				$ais['netAIS'] = $sample['value'];
				break;
			case 'navigation.courseOverGroundTrue':
				$ais['course'] = (($sample['value']*180)/M_PI)*10;	// Units: rad (Radian), а AIS type 1 оно в десятых градуса
				break;
			case 'navigation.headingTrue':
				$ais['heading'] = ($sample['value']*180)/M_PI;	// Units: rad (Radian) А вот истинный курс - в градусах.
				break;
			case 'navigation.destination.commonName':
				$ais['destination'] = $sample['value'];		
				break;
			case 'navigation.position':
				$ais['lat'] = $sample['value']['latitude'];
				$ais['lon'] = $sample['value']['longitude'];		
				break;
			case 'navigation.maneuver':
				$ais['turn'] = $sample['value'];		
				break;
			case 'navigation.rateOfTurn':
				$ais['turn'] = $sample['value'];		
				break;
			case 'navigation.speedOverGround':
				$ais['speed'] = $sample['value']*1.9438444924;	// надо сделать в узлах, как это оно по параметру scaled
				break;
			case 'design.aisShipType':
				$ais['shiptype'] = $sample['value']['id'];		
				$ais['shiptype_text'] = $sample['value']['name'];		
				//echo "shiptype_text: {$sample['value']['name']}\n";
				break;
			case 'design.draft':
				$ais['draught'] = $sample['value']['current'];	// Для других судов SignalK всегда пишет осадку в current
				break;
			case 'design.length':
				$ais['length'] = $sample['value']['overall'];	// Аналогично
				break;
			case 'design.beam':
				$ais['beam'] = $sample['value'];
				break;
			case 'sensors.ais.fromBow':
				$ais['to_bow'] = $sample['value'];
				break;
			case 'sensors.ais.fromCenter':
				if($sample['value']>0) $ais['to_port'] = $sample['value'];
				else $ais['to_starboard'] = -$sample['value'];
				break;
			};
		};
	};
	//echo "ais: "; print_r($ais);
	$WATCH[] = $ais;	// все updates к одному судну
}
//print_r($WATCH);
return $WATCH; 	// 
} // end function instrumentsDataDecode

//function altReadData($gpsdSock){
/* Функция альтернативного чтения данных, вызывается перед ожиданием сокетов socket_select 
возвращает true если данные были получены
*/
//return $ret;
//} // end function altReadData

?>
