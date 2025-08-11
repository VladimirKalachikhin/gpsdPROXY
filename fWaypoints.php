<?php
/**/
function wayFileLoad($wayFileName){
/* Импортирует указанный rte из указанного файла gpx в массив.
Если rte не указан - импортирует последний.
Если нет rte - импортирует wpt.
*/
	function wpts2array($way){
	$rte = array();
	foreach($way->wpt as $wpt){
		$rte[] = array('lat'=>(float)$wpt['lat'],'lon'=>(float)$wpt['lon']);
	};
	if(!$rte) return null;
	return $rte;
	}; // end function wpts2array

$way = simplexml_load_file($wayFileName,null,LIBXML_NOENT);
if($way === null){
	echo "The $wayFileName is not well-formed gpx\n";
	return $way;
};
$rte = null;
//
foreach($way->rte as $rte){
	//print_r($rte); echo "\n";
	if(strpos($rte->cmt,'current') !== false) break;
};
//
if($rte) $rte = rte2array($rte);
else $rte = wpts2array($way);

return $rte;
}; // end function wayFileLoad


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
$minDist = 999999999;
$n = null;
foreach($way as $i=>$wpt){
	$dist = equirectangularDistance($pos,$wpt);
	if($dist<$minDist){
		$minDist = $dist;
		$n = $i;
	};
};
return $n;
};

function chkWPT(){
/**/
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
if($instrumentsDataUpdated) {echo "[chkWPT] instrumentsData['WPT']: "; print_r($instrumentsData['WPT']); echo "\n";};
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
global $instrumentsData,$way;
$wpt = next($way);
if($wpt === false) return;	// текущаяя точка - уже последняя
$instrumentsData['WPT']['lat'] = $wpt['lat'];
$instrumentsData['WPT']['lon'] = $wpt['lon'];
$instrumentsData['WPT']['index'] = key($way);
}; // end function nextWPT


function prevWPT(){
/**/
global $instrumentsData,$way;
$wpt = prev($way);
if($wpt === false) return;	// текущаяя точка - уже первая
$instrumentsData['WPT']['lat'] = $wpt['lat'];
$instrumentsData['WPT']['lon'] = $wpt['lon'];
$instrumentsData['WPT']['index'] = key($way);
}; // end function prevWPT


function toIndexWPT($index){
/**/
global $instrumentsData,$way;
reset($way);
$wpt = current($way);
for($i=0;$i<$index;$i++){
	$wpt = next($way);
	if($wpt === false){	// однако, такой точки нет?
		prev($way);
		break;
	};
};
$instrumentsData['WPT']['lat'] = $wpt['lat'];
$instrumentsData['WPT']['lon'] = $wpt['lon'];
$instrumentsData['WPT']['index'] = key($way);
}; // end function toIndexWPT

?>
