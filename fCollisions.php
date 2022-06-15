<?php

if(!$collisionDistance) $collisionDistance = 10;	// minutes of movement

function chkCollisions(){
/* Проверяет возможность столкновений
Заполняет $instrumentsData['collisions']
Возвращает ['AIS' => TRUE] или []
*/
global $instrumentsData,$boatInfo;
$instrumentsDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.

//echo "chkCollisions instrumentsData['AIS']:"; print_r($instrumentsData['AIS']); echo "\n";
if(!$instrumentsData['AIS']) return $instrumentsDataUpdated;

// Определим свежие координаты, курс и скорость себя
// последние данные от какого-нибудь устройства, заведомо актуальные
// Считаем здесь, поскольку изменения себя происходят гораздо чаще, чем целей AIS, и нестрашно
// сделать лишнее при изменении цели AIS.
// Зато здесь данные после проверки на свежесть.
$freshTtime = 0; $freshPtime = 0; $freshVtime = 0;
foreach($instrumentsData['TPV'] as $device => $data){
	foreach($data['cachedTime'] as $type => $cachedTime){
		switch($type){
		case 'lat':
			if($freshPtime > $cachedTime) continue;
			$freshPtime = $cachedTime;
			$boatInfo['lat'] = $data['data'][$type];
			break;
		case 'lon':
			if($freshPtime > $cachedTime) continue;
			$freshPtime = $cachedTime;
			$boatInfo['lon'] = $data['data'][$type];
			break;
		case 'track':
			if($freshTtime > $cachedTime) continue;
			$freshTtime = $cachedTime;
			$boatInfo['track'] = $data['data'][$type];
			$boatInfo['course'] = $data['data'][$type];	// в AIS оно course, так что для совместимости
			break;
		case 'speed':
			//echo "\nspeed={$data['data'][$type]}\n";
			if($freshVtime > $cachedTime) continue;
			$freshVtime = $cachedTime;
			$boatInfo['speed'] = $data['data'][$type];
			break;
		default:
			continue;
		}
	}
}
if(!$boatInfo['lat'] or !$boatInfo['lon']) return $instrumentsDataUpdated;
list($boatInfo['collisionArea'],$boatInfo['squareArea']) = updCollisionArea($boatInfo,$collisionDistance);	// 
//echo "chkCollisions boatInfo:"; print_r($boatInfo); echo "\n";

$instrumentsData['collisions'] = array();
foreach($instrumentsData['AIS'] as $id => $vehicle){	// для каждого судна из AIS
	if(!$vehicle['data']['lat'] or !$vehicle['data']['lon']) continue;
	if(chkCollision($id)) {	// проверим возможность столкновения
		$instrumentsData['collisions'][] = $id;
		$instrumentsDataUpdated = array('AIS' => true);
		echo "\n Collision with $id\n";
	}
}
return $instrumentsDataUpdated;
} // end function chkCollisions

function chkCollision($vesselID){
/* Определяет вероятность столкновения себя как $boatInfo с $instrumentsData['AIS'][$vesselID]
*/
global $instrumentsData,$boatInfo;

$isIntersection = false;
//echo "\n instrumentsData['AIS'][vesselID]['squareArea'] "; print_r($instrumentsData['AIS'][$vesselID]['squareArea']); echo "          \n";
if(!$instrumentsData['AIS'][$vesselID]['squareArea']) return $isIntersection;	// оно не сразу
// Проверяем пересечение прямоугольных областей
if(	$instrumentsData['AIS'][$vesselID]['squareArea']['topLeft']['lon'] > $boatInfo['squareArea']['bottomRight']['lon']
	or $instrumentsData['AIS'][$vesselID]['squareArea']['bottomRight']['lon'] < $boatInfo['squareArea']['topLeft']['lon']
	or $instrumentsData['AIS'][$vesselID]['squareArea']['topLeft']['lat'] < $boatInfo['squareArea']['bottomRight']['lat']
	or $instrumentsData['AIS'][$vesselID]['squareArea']['bottomRight']['lat'] > $boatInfo['squareArea']['topLeft']['lat']
) {
	return $isIntersection;	// эти области не пересекаются
}
// Области пересекаются -- определим общий горизонтальный прямоугольник
$unitedSquareArea = array(
	'topLeft'=>array(
		'lon'=>min($instrumentsData['AIS'][$vesselID]['squareArea']['topLeft']['lon'],$boatInfo['squareArea']['topLeft']['lon']), 
		'lat'=>max($instrumentsData['AIS'][$vesselID]['squareArea']['topLeft']['lat'],$boatInfo['squareArea']['topLeft']['lat'])
	),
	'bottomRight'=>array(
		'lon'=>max($instrumentsData['AIS'][$vesselID]['squareArea']['bottomRight']['lon'],$boatInfo['squareArea']['bottomRight']['lon']), 
		'lat'=>min($instrumentsData['AIS'][$vesselID]['squareArea']['bottomRight']['lat'],$boatInfo['squareArea']['bottomRight']['lat'])
	)
);

// Пересчитаем координаты точек collisionArea относительно общего прямоугольника,
// от верхнего левого угла, в метрах
$selfLocalCollisionArea = array(); $targetLocalCollisionArea = array();
foreach($boatInfo['collisionArea'] as $point){
	$x = equirectangularDistance($unitedSquareArea['topLeft'],array('lon'=>$point['lon'], 'lat'=>$unitedSquareArea['topLeft']['lat']));	// fGeodesy.php
	$y = equirectangularDistance($unitedSquareArea['topLeft'],array('lon'=>$unitedSquareArea['topLeft']['lon'], 'lat'=>$point['lat']));
	$selfLocalCollisionArea[] = array($x,$y);
};
foreach($instrumentsData['AIS'][$vesselID]['collisionArea'] as $point){
	$x = equirectangularDistance($unitedSquareArea['topLeft'],array('lon'=>$point['lon'], 'lat'=>$unitedSquareArea['topLeft']['lat']));	// fGeodesy.php
	$y = equirectangularDistance($unitedSquareArea['topLeft'],array('lon'=>$unitedSquareArea['topLeft']['lon'], 'lat'=>$point['lat']));
	$targetLocalCollisionArea[] = array($x,$y);
};

// Определим, пересекаются ли какие-либо отрезки фигур collisionArea
// на самих и цели
$isIntersection = false;
$lenI = count($selfLocalCollisionArea); $lenJ = count($targetLocalCollisionArea);
for($i=0; $i<$lenI; $i++){	// для каждого отрезка своей области нахождения
	$nextI = $i+1;
	if($nextI==$lenI) $nextI = 0;
	for($j=0; $j<$lenJ; $j++){	// узнаем, пересекается ли он с каждым отрезком области другого судна
		$nextJ = $j+1;
		if($nextJ==$lenJ) $nextJ = 0;
		if(segmentIntersection($selfLocalCollisionArea[$i],$selfLocalCollisionArea[$nextI],$targetLocalCollisionArea[$j],$targetLocalCollisionArea[$nextJ])){	// две точки первого отрезка, две точки второго отрезка fGeometry.php
			$isIntersection = true;
			break 2;
		}
	}
}

// Возможно, вся область вероятного нахождения цели лежит внутри области
// нашего вероятного нахождения?
// наша область вероятного нахождения -- всегда треугольник в этот момент (иначе -- цель уже у нас на палубе).
if(!$isIntersection){
	foreach($targetLocalCollisionArea as $point){	// для каждой точки области цели
		if(!isInTriangle_Vector($selfLocalCollisionArea[0], $selfLocalCollisionArea[1], $selfLocalCollisionArea[2], $point)){	// точка вне нашего треугольника
			break;
		};
	};
	$isIntersection = true;	// все точки лежат внутри треугольника		
};
// Возможно, вся область нашего вероятного нахождения лежит внутри области
// вероятного нахождения цели?
// область вероятного нахождения цели -- всегда треугольник в этот момент (иначе -- мы уже на палубе цели).
if(!$isIntersection){
	foreach($selfLocalCollisionArea as $point){	// для каждой точки области цели
		if(!isInTriangle_Vector($targetLocalCollisionArea[0], $targetLocalCollisionArea[1], $targetLocalCollisionArea[2], $point)){	// точка вне нашего треугольника
			break;
		};
	};
	$isIntersection = true;	// все точки лежат внутри треугольника
};

return $isIntersection;
} // end function chkCollision



function updCollisionArea($boatInfo,$collisionDistance=10){
/* Определим координаты точек опасной зоны и координаты объемлющего
горизонтального прямоугольника для судна
$boatInfo: AIS data -like array
*/
//echo "updCollisionArea boatInfo:"; print_r($boatInfo); echo "\n";

$collisionArea = array();
$squareArea = array();
if(!@$boatInfo['lat'] or !@$boatInfo['lon']) return array($collisionArea,$squareArea);
$toBack = 30;	// метров
if($boatInfo['length']) $toBack = $boatInfo['length'];
$bearing = null;
if($boatInfo['course'] or ($boatInfo['course']===0)) $bearing = $boatInfo['course'];	// degrees
elseif($boatInfo['track'] or ($boatInfo['track']===0)) $bearing = $boatInfo['track'];	// degrees
$position = array('lat'=>$boatInfo['lat'],'lon'=>$boatInfo['lon']);
$collisionArea[] = destinationPoint($position,$toBack,$bearing+180);	// назад fGeodesy.php
if($boatInfo['speed']>1) $toFront = $boatInfo['speed'] * $collisionDistance * 60 + $toBack;	// speed is real, so cannot be compared to equal
else $toFront = 2*$toBack;
if(($bearing === null) or (($bearing == 0) and ($boatInfo['speed']))) {	// ромбик
	$aside = $toFront/2;
	$collisionArea[] = destinationPoint($position,$aside,$bearing-90);	// 
	$collisionArea[] = destinationPoint($collisionArea[0],$toFront,$bearing);	// 
	$collisionArea[] = destinationPoint($position,$aside,$bearing+90);	// 
}
else {	// треугольник
	$collisionArea[] = destinationPoint($collisionArea[0],$toFront,$bearing-5.73);	// 0.1 radian
	$collisionArea[] = destinationPoint($collisionArea[0],$toFront,$bearing+5.73);	// 
}
$longs = array(); $lats = array();
foreach($collisionArea as $point) {
	$longs[] = $point['lon'];
	$lats[] = $point['lat'];
};
$squareArea = array(
	'topLeft'=>array(
		'lon'=>min($longs),
		'lat'=>max($lats)
	),
	'bottomRight'=>array(
		'lon'=>max($longs),
		'lat'=>min($lats)
	)
);	// 
return array($collisionArea,$squareArea);
} // end function updCollisionArea


?>
