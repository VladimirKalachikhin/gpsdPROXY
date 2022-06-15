<?php

function segmentIntersection($a1,$a2,$b1,$b2){
// https://acmp.ru/article.asp?id_text=170
// Определяет пересечение отрезков A(ax1,ay1,ax2,ay2) и B (bx1,by1,bx2,by2),
// функция возвращает TRUE - если отрезки пересекаются, а если пересекаются 
// в концах или вовсе не пересекаются, возвращается FALSE (ложь)
list($ax1,$ay1) = $a1;
list($ax2,$ay2) = $a2;
list($bx1,$by1) = $b1;
list($bx2,$by2) = $b2;
$v1=($bx2-$bx1)*($ay1-$by1)-($by2-$by1)*($ax1-$bx1);
$v2=($bx2-$bx1)*($ay2-$by1)-($by2-$by1)*($ax2-$bx1);
$v3=($ax2-$ax1)*($by1-$ay1)-($ay2-$ay1)*($bx1-$ax1);
$v4=($ax2-$ax1)*($by2-$ay1)-($ay2-$ay1)*($bx2-$ax1);
return (($v1*$v2)<0) and (($v3*$v4)<0);
};

function isInTriangle_Vector($A, $B, $C, $P){
// http://cyber-code.ru/tochka_v_treugolnike/
// Находится ли точка в треугольнике
// точки A [x,y], B,C -- треугольник
// P [x,y] -- проверяемая точка
list($aAx, $aAy) = $A;
list($aBx, $aBy) = $B;
list($aCx, $aCy) = $C;
list($aPx, $aPy) = $P;
// переносим треугольник точкой А в (0;0).
$Bx = $aBx - $aAx; $By = $aBy - $aAy;
$Cx = $aCx - $aAx; $Cy = $aCy - $aAy;
$Px = $aPx - $aAx; $Py = $aPy - $aAy;

$mu = ($Px*$By - $Bx*$Py) / ($Cx*$By - $Bx*$Cy);
if(($mu >= 0) and ($mu <= 1)){
	$lambda = ($Px - $mu*$Cx) / $Bx;
	return (($lambda >= 0) and (($mu + $lambda) <= 1));
};
return false;
}; // end function isInTriangle_Vector

?>
