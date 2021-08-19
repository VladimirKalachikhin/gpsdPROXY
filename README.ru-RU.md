# gpsdPROXY daemon [![License: CC BY-SA 4.0](https://img.shields.io/badge/License-CC%20BY--SA%204.0-lightgrey.svg)](https://creativecommons.org/licenses/by-sa/4.0/)
**version 0.0**  

Весьма удобно обращаться к **[gpsd](https://gpsd.io/)** из веб-приложений посредством команды [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll) в произвольный момент времени, однако есть проблемы:  
>**во-первых**, данные AIS недоступны в команде ?POLL;  
>**во-вторых**, данные, отличные от тех, что отдаёт приёмник ГПС, могут не попасть в команду ?POLL;

С деталями и дискуссией по этому поводу можно ознакомиться по следующим ссылкам (англ.):  
[https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00093.html](https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00093.html)  
[https://lists.nongnu.org/archive/html/gpsd-users/2021-06/msg00017.html](https://lists.nongnu.org/archive/html/gpsd-users/2021-06/msg00017.html)  

Предлагаемый демон собирает данные AIS и то, что передаётся **gpsd** в секции TPV и хранит их в течение указанного пользователем времени. Получить данные можно запросом [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll) протокола **gpsd**.  
Таким образом, все данные AIS и данные эхолота и анемометра (и ГПС, конечно) становятся доступными в произвольный момент времени.

## Использование
```
$ php gpsdPROXY.php
```

## Управление
Демон проверяет, не запущен ли он уже, и не запускается вторично.

## Настройка
См. файл _params.php_

## Результат
Демон возвращает данные, как описано в команде  [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll), за исключением:  

* массив _sky_ пуст
* время везде UNIX time
* добавлен массив _ais_  с ключами mmsi и данными в формате, описанном в [AIS DUMP FORMATS](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_ais_dump_formats), за исключением:

>* скорость в м/сек
>* координаты в десятичных градусах
>* углы в градусах
>* осадка в метрах
>* длина в метрах
>* ширина в метрах



