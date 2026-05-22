<?php
/* При обслуживании PANO сервер ничего не хранит, в отличие от других классов. Он должен просто
передавать сообщения от одного клиента к другому.
*/
function actionPANO($params){
/* Выполнение команды, пришедшей через ws 
Команда может быть:
?PANO={["clientToName":"Какая-то строка"],"action":"getList | "};
*/
global $instrumentsData,$messages;
//echo "[actionPANO] params: ";print_r($params); echo "\n";
$instrumentsDataUpdated = array('PANO'=>array('class'=>'PANO')); // массив, где указано, какие классы изменениы и кем.
switch($params['action']){
case 'getList':
	$instrumentsDataUpdated['PANO']['masters'] = array();	// для индикации отсутствия сам массив должен быть
	$instrumentsDataUpdated['PANO']['slaves'] = array();
	foreach($messages as $sockKey => $sockData){
		switch($sockData['PANOrole']){
		case 'master':
			$instrumentsDataUpdated['PANO']['masters'][] = $sockData['clientName'];
			break;
		case 'slave':
			$instrumentsDataUpdated['PANO']['slaves'][] = $sockData['clientName'];
			break;
		case 'both':
			$instrumentsDataUpdated['PANO']['masters'][] = $sockData['clientName'];
			$instrumentsDataUpdated['PANO']['slaves'][] = $sockData['clientName'];
			break;
		};
		$instrumentsDataUpdated['PANO']['masters'] = array_unique($instrumentsDataUpdated['PANO']['masters']);
		$instrumentsDataUpdated['PANO']['slaves'] = array_unique($instrumentsDataUpdated['PANO']['slaves']);
		//echo "[actionPANO] instrumentsDataUpdated: ";print_r($instrumentsDataUpdated); echo "\n";
	};
	break;
default:	// По умолчанию просто скопируем вход на выход
	$instrumentsDataUpdated['PANO'] = $params;
	$instrumentsDataUpdated['PANO']['class'] = 'PANO';
};
return $instrumentsDataUpdated;
}; // end 
?>
