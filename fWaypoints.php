<?php
/* depends on fGeodesy.php */
function wayFileLoad($wayFileName){
/* Импортирует указанный rte из указанного файла gpx в массив.
Если rte не указан - импортирует последний.
Если нет rte - импортирует wpt.
*/
$way = @simplexml_load_file($wayFileName,null,LIBXML_NOENT);
if($way === false){
	echo "[wayFileLoad] The $wayFileName is not well-formed gpx, no waypoints available.\n";
	return $way;
};
//echo "[wayFileLoad] The way file $wayFileName is loaded\n";
$rteArr = null;
//
$found = false;
foreach($way->rte as $rte){
	//print_r($rte); echo "\n";
	if(strpos($rte->cmt,'current') !== false) {
		$found = true;
		break;
	};
};
//echo "[wayFileLoad] rte:"; print_r($rte); echo "\n";
if($found) $rteArr = rte2array($rte);	// явно указанный маршрут нашёлся
else {	// явно указанного маршрута нет
	$rteArr = wpts2array($way);	// возьмём тогда путевые точки, начиная, возможно, от явно указанной
	if(!$rteArr and $rte) $rteArr = rte2array($rte);	// если с точками облом, но какой-то маршрут был - возьмём его
};
return $rteArr;
}; // end function wayFileLoad


function wpts2array($way){
$rte = array();
foreach($way->wpt as $wpt){
	//if((strpos($wpt->type,'anchor') === false) and (strpos($wpt->type,'point') === false)) continue;	// точка должна быть только такого типа. Но интерфейс предлагает кнопки только трёх типов, кто их знает, как они будут использоваться.
	if(strpos($wpt->cmt,'current') !== false) $rte = array();	// если нашлась точка, помеченная current, то это будет первая точка следования
	$rte[] = array('lat'=>(float)$wpt['lat'],'lon'=>(float)$wpt['lon']);
};
if(!$rte) return null;
return $rte;
}; // end function wpts2array


function rte2array($rte){
$way = array();
foreach($rte->rtept as $rtept){
	$way[] = array('lat'=>(float)$rtept['lat'],'lon'=>(float)$rtept['lon']);
};
if(!$way) return null;
return $way;
}; // end function rte2array


function getNearestWPTi($pos){
/* $pos = aray('lat'=> ,'lon'=>)
*/
global $way;
//echo "[getNearestWPTi] pos:";print_r($pos);echo"\n";
//echo "[getNearestWPTi] way:";print_r($way);echo"\n";
$minDist = 999999999;
$n = null;
foreach($way as $i=>$wpt){
	$dist = equirectangularDistance($pos,$wpt);
	//echo "[getNearestWPTi] dist=$dist; minDist=$minDist;\n";
	if($dist<$minDist){
		$minDist = $dist;
		$n = $i;
	};
};
return $n;
};

function chkWPT(){
/* Находимся ли мы в указанных окрестностях wpt */
global $instrumentsData;
$instrumentsDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.
if(!$instrumentsData['WPT']) return array();
foreach($instrumentsData['TPV'] as $device => $data){
	if(!isset($data['data']['lat']) or !isset($data['data']['lon'])) continue;
	if(nearWPT(array('lat'=>$data['data']['lat'],'lon'=>$data['data']['lon']))){	// по данным этого приёмника гпс, мы доехали до следующей путевой точки
		nextWPT();
		$instrumentsDataUpdated['WPT'] = true;
		break;	// не будем проверять, доехали ли по данным других приёмников. Тогда мы сменим точку в худшем случае раньше, чем надо, но зато точно сменим.
	};
};
//echo "[chkWPT] instrumentsDataUpdated: "; print_r($instrumentsDataUpdated); echo "\n";
//if($instrumentsDataUpdated) {echo "[chkWPT] instrumentsData['WPT']: "; print_r($instrumentsData['WPT']); echo "\n";};
return $instrumentsDataUpdated;
}; // end function chkWPT


function nearWPT($pos){
/* Возвращает true, если $pos находится рядом с текущей путевой точкой.
Не проверяет вообще наличия путевой точки: считается, что раз вызвали, то всё ok.
*/
global $instrumentsData,$wptPrecision;
if(equirectangularDistance($pos,$instrumentsData['WPT'])<$wptPrecision) return true;
return false;
}; // end function nearWPT

function nextWPT(){
/**/
global $instrumentsData,$way,$wptPrecision;
$wpt = next($way);
//echo "[nextWPT] wpt=".key($way)."; "; print_r($wpt); echo "\n";
if($wpt === false) {	// текущаяя точка - уже последняя
	end($way);
	return;
};
$instrumentsData['WPT']['lat'] = $wpt['lat'];
$instrumentsData['WPT']['lon'] = $wpt['lon'];
$instrumentsData['WPT']['index'] = key($way);
$instrumentsData['WPT']['wptPrecision'] = $wptPrecision;
}; // end function nextWPT


function prevWPT(){
/**/
global $instrumentsData,$way,$wptPrecision;
$wpt = prev($way);
//echo "[prevWPT] wpt=".key($way)."; "; print_r($wpt); echo "\n";
if($wpt === false) {	// текущаяя точка - уже первая
	reset($way);	// после попытки сдвинуться за пределы массива оно ломается, и больше двигаться не хочет. Нужно специальное действие.
	return;
};
$instrumentsData['WPT']['lat'] = $wpt['lat'];
$instrumentsData['WPT']['lon'] = $wpt['lon'];
$instrumentsData['WPT']['index'] = key($way);
$instrumentsData['WPT']['wptPrecision'] = $wptPrecision;
}; // end function prevWPT


function toIndexWPT($index){
/**/
global $instrumentsData,$way,$wptPrecision;
reset($way);
$wpt = current($way);
for($i=0;$i<$index;$i++){
	$wpt = next($way);
	if($wpt === false){	// однако, такой точки нет?
		end($way);
		break;
	};
};
//echo "[toIndexWPT] wpt:"; print_r($wpt); echo "\n";
$instrumentsData['WPT']['lat'] = $wpt['lat'];
$instrumentsData['WPT']['lon'] = $wpt['lon'];
$instrumentsData['WPT']['index'] = key($way);
$instrumentsData['WPT']['wptPrecision'] = $wptPrecision;
}; // end function toIndexWPT


function actionWPT($params){
/* Выполнение команды, пришедшей через ws 
Команда может быть:
?WPT={"action":"nextWPT | prevWPT | cancel | start"};
*/
global $instrumentsData,$way;
//echo "[actionWPT] params: ";print_r($params); echo "\n";
$instrumentsDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.
switch($params['action']){
case 'nextWPT':
	nextWPT();
	$instrumentsDataUpdated['WPT'] = true;
	break;
case 'prevWPT':
	prevWPT();
	$instrumentsDataUpdated['WPT'] = true;
	break;
case 'cancel':
	$instrumentsData['WPT'] = array();	// укажем, что путевые точки были, но теперь их нет
	$instrumentsDataUpdated['WPT'] = true;
	break;
case 'start':
	$way = wayFileLoad($params['wayFileName']);
	//echo "[actionWPT] start: way: ";print_r($way); echo "    \n";
	if(!$way) break;
	$instrumentsData['WPT']['wayFileName'] = $params['wayFileName'];
	// Установим текущей ближайшую точку к текущему положению по данным первого попавшегося
	// датчика положения.
	foreach($instrumentsData['TPV'] as $device => $data){
		if(!isset($data['data']['lat']) or !isset($data['data']['lon'])) continue;
		$pos = array('lat'=>$data['data']['lat'],'lon'=>$data['data']['lon']);
		//echo "[actionWPT] start: getNearestWPTi:";print_r(getNearestWPTi($pos)); echo ";\n";
		toIndexWPT(getNearestWPTi($pos));	// на ближайшую точку
		break;
	};
	//echo "[actionWPT] start: instrumentsData['WPT']";print_r($instrumentsData['WPT']); echo "\n";
	$instrumentsDataUpdated['WPT'] = true;
	break;
};
return $instrumentsDataUpdated;
}; // end function actionWPT

?>
