<?php
/*
pixResolution - Размер пикселя указанного масштаба на указанной долготе в метрах 	 tileproxy/fcommon.php
tileNum2degree - Tile numbers to lon./lat. left top corner	 tileproxy/fcommon.php
tileNum2mercOrd - Tile numbers to linear coordinates left top corner on mercator ellipsoidal	 tileproxy/fcommon.php
merc_x - Долготу в линейную координату x, Меркатор на эллипсоиде	tileproxy/fcommon.php
merc_y - Широту в линейную координату y, Меркатор на эллипсоиде	tileproxy/fcommon.php
coord2tileNum - координаты в номер тайла	 tileproxy/fcommon.php
bearing Азимут между точками	map/dashboard.php
equirectangularDistance
destinationPoint
*/

function pixResolution($lat_deg,$zoom,$tile_size=256,$equator=40075016.686){
/* Размер пикселя указанного масштаба на указанной долготе в метрах
$equator - длина экватора в метрах, по умолчанию -- WGS-84
https://wiki.openstreetmap.org/wiki/Slippy_map_tilenames#Resolution_and_Scale
*/
$z0rez = $equator / $tile_size; 	// разрешение тайла масштаба 0 на экваторе
return $z0rez * cos(deg2rad($lat_deg)) / pow(2, $zoom);
} // end function pixResolution

function tileNum2degree($zoom,$xtile,$ytile) {
/* Tile numbers to lon./lat. left top corner
// http://wiki.openstreetmap.org/wiki/Slippy_map_tilenames
*/
$n = pow(2, $zoom);
$lon_deg = $xtile / $n * 360.0 - 180.0;
$lat_deg = rad2deg(atan(sinh(pi() * (1 - 2 * $ytile / $n))));
return array('lon'=>$lon_deg,'lat'=>$lat_deg);
}

function tileNum2mercOrd($zoom,$xtile,$ytile,$r_major=6378137.000,$r_minor=6356752.3142) {
/* Меркатор на эллипсоиде
Tile numbers to linear coordinates left top corner on mercator ellipsoidal
*/
$deg = tileNum2degree($zoom,$xtile,$ytile);
$lon_deg = $deg['lon'];
$lat_deg = $deg['lat'];
//return array('x'=>round(merc_x($lon_deg),10),'y'=>round(merc_y($lat_deg),10));
return array('x'=>merc_x($lon_deg,$r_major),'y'=>merc_y($lat_deg,$r_major,$r_minor));
}

function merc_x($lon,$r_major=6378137.000) {
/* Меркатор на эллипсоиде
Долготу в линейную координату x
// http://wiki.openstreetmap.org/wiki/Mercator#PHP_implementation
*/
return $r_major * deg2rad($lon);
}

function merc_y($lat,$r_major=6378137.000,$r_minor=6356752.3142) {
/* Меркатор на эллипсоиде
Широту в линейную координату y
// http://wiki.openstreetmap.org/wiki/Mercator#PHP_implementation
*/
	if ($lat > 89.5) $lat = 89.5;
	if ($lat < -89.5) $lat = -89.5;
    $temp = $r_minor / $r_major;
	$es = 1.0 - ($temp * $temp);
    $eccent = sqrt($es);
    $phi = deg2rad($lat);
    $sinphi = sin($phi);
    $con = $eccent * $sinphi;
    $com = 0.5 * $eccent;
	$con = pow((1.0-$con)/(1.0+$con), $com);
	$ts = tan(0.5 * ((M_PI*0.5) - $phi))/$con;
    $y = - $r_major * log($ts);
    return $y;
}

function coord2tileNum($lon,$lat,$zoom){
/* координаты в градусах в номер тайла */
$xtile = floor((($lon + 180) / 360) * pow(2, $zoom));
$ytile = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) /2 * pow(2, $zoom));
return array($xtile,$ytile);
} // end function coord2tileNum


function bearing($pair) {
/* Азимут между точками
$pair = array(array($lon,$lat),array($lon,$lat))
*/
//echo "<pre>"; print_r($pair); echo "</pre>";
$lat1 = deg2rad($pair[0][1]);
$lat2 = deg2rad($pair[1][1]);
$lon1 = deg2rad($pair[0][0]);
$lon2 = deg2rad($pair[1][0]);
//echo "lat1=$lat1; lat2=$lat2; lon1=$lon1; lon2=$lon2;<br>\n";

$y = sin($lon2 - $lon1) * cos($lat2);
$x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lon2 - $lon1);
//echo "x=$x; y=$y;<br>\n";

$bearing = rad2deg(atan2($y, $x));
//echo "$bearing<br>";
if($bearing >= 360) $bearing = $bearing-360;
elseif($bearing < 0) $bearing = $bearing+360;

return $bearing;
} // end function bearing


function equirectangularDistance($from,$to){
/* https://www.movable-type.co.uk/scripts/latlong.html
Расстояние между двумя точками, выраженными координатами.
Меркатор на сфере
from,to: [lon: xx, lat: xx], degrees
return distanse in meters
*/
$fi1 = deg2rad($from['lat']);
$fi2 = deg2rad($to['lat']);
$dLambda = deg2rad($to['lon']-$from['lon']);
$R = 6371000;	// метров
$x = $dLambda * cos(($fi1+$fi2)/2);
$y = ($fi2-$fi1);
$d = sqrt($x*$x + $y*$y) * $R;	// метров
return $d;
} // end function equirectangularDistance

function destinationPoint($from,$distance,$bearing){
/* http://www.movable-type.co.uk/scripts/latlong.html
Координаты точки на расстоянии в направлении по дуге большого круга.
Земля -- шар.
from: [lon: xx, lat: xx], degrees
distance: meters
bearing: clockwise from north degrees
*/
$R = 6371000;	// метров
$bearing = deg2rad($bearing);
$fi1 = deg2rad($from['lat']);
$lambda1 = deg2rad($from['lon']);
$sigma = $distance / $R; // angular distance in radians
$fi2 = asin( sin($fi1)*cos($sigma) + cos($fi1)*sin($sigma)*cos($bearing));
$lambda2 = $lambda1 + atan2(sin($bearing)*sin($sigma)*cos($fi1),cos($sigma)-sin($fi1)*sin($fi2));
return array('lon'=> rad2deg($lambda2), 'lat'=> rad2deg($fi2));
} // end function destinationPoint


?>
