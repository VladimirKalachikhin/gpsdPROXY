[Русское описание](https://github.com/VladimirKalachikhin/gpsdPROXY/blob/master/README.ru-RU.md)  
# gpsdPROXY daemon [![License: CC BY-NC-SA 4.0](screenshots/Cc-by-nc-sa_icon.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/deed.en)
**version 0.6**

It is very convenient to access the **[gpsd](https://gpsd.io/)** from web apps with asynchronous request [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll) But there are problems:  

>**First**, the AIS data not available by ?POLL; request.  
>**Second**, the data other them time-position-velocity (from GNSS reciever, in general) may not be included to ?POLL; request.

The reason is that **gpsd** collect data during "epoch" from one GNSS fix recive to another. But "epoch" for AIS and instruments data is longer. So this data is not available for the ?POLL; request that returns the data collected during **gpsd** epoch in request moment.  
Details and discussion see:  
[https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00093.html](https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00093.html)  
[https://lists.nongnu.org/archive/html/gpsd-users/2021-06/msg00017.html](https://lists.nongnu.org/archive/html/gpsd-users/2021-06/msg00017.html)  

But this is a some strange software. Because the same functionality is present actually in **gpsd**: it collects a stream of data, aggregates it, and gives structured data on demand. The difference in the lifetime of the data. In **gpsdPROXY** it can be set explicitly and  separately by data type.  
I believe that such functionality must be in **gpsd**. But there is no such thing.

As a side, you may use **gpsdPROXY** to collect data from sources that do not have data lifetime control. For example, from VenusOS where there are no instruments data reliability control, or from SignalK, where there it timestamp at least.  
Other side effect is storing MOB data and calculate of collision capabilities for AIS targets.  
But you can just use **gpsdPROXY** as websocket proxy to **gpsd**.

## Features
This cache/proxy daemon collect AIS and all TPV data from **gpsd** or other source during the user-defined lifetime and gives them by [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll) request of the **gpsd** protocol.  
So data from AIS stream and instruments such as echosounder and wind meter become available via ?POLL; request.  

In addition, you may use ?WATCH={"enable":true,"json":true} stream, just like from original **gpsd**.   

### Data source
Normally, the gpspPROXY works with **gpsd** on the same or the other machine. In this case, the data is the most complete and reliable.

#### VenusOS
The gpsdPROXY can work in VenusOS v2.80~38 or above. Or get data from any version via LAN. To do this, you need to enable "MQTT on LAN" feature. On VenusOS remote console go Settings -> Services -> MQTT on LAN (SSL) and Enable.

##### limitations
* VenusOS does not provide depth and AIS services.
* The data provided by VenusOS are not reliable enough, so be careful.

#### Signal K
The gpsdPROXY can get data from Signal K local or via LAN. If it possible, gpsdPROXY find Signal K by yourself via zeroconf service or jast on standard port.

##### Limitations
Indeed, SignalK can be used from gpsdPROXY only local. Via LAN it's odd.

### Collision detections
The gpsdPROXY tries to determine the possibility of a collision according to the adopted simplified collision model based on the specified detection distance and the probability of deviations from the course.  
![collision model](screenshots/s1.jpeg)<br>  
 Object `{"class":"ALARM","alarms":{"collisions":[]}}` contains a list of mmsi and position of vessels that have a risk of collision. The [GaladrielMap](https://github.com/VladimirKalachikhin/Galadriel-map) highlights such vessels on the map and indicates the direction to them on self cursor.  
For the Collision detector to work correctly, you must specify the boat parameters in _params.php_.

## Compatibility
Linux, PHP < 8. The cretinous decisions made at PHP 8 do not allow the **gpsdPROXY** to work at PHP 8, and I do not want to follow these decisions.

## Configure
See _params.php_

## Usage
```
$ php gpsdPROXY.php
```
Connect to the daemon on host:port from _params.php_ by **gpsd** protocol via BSD socket or websocket.

### Control
gpsdPROXY daemon checks whether the instance is already running, and exit if it.  

### gpsd Protocol extensions
Added some new parameters for commands:

* "subscribe":"TPV[,AIS[,ALARM]]" parameter for ?POLL and ?WATCH={"enable":true,"json":true} commands.  
This indicates to return TPV or AIS or ALARM data only, or a combination of them. Default - all.  
For example: `?POLL={"subscribe":"AIS"}` return class "POLL" with "ais":[], not with "tpv":[].
* "minPeriod":"", sec. for WATCH={"enable":true,"json":true} command. Normally the data is sent at the same speed as they come from sensors. Setting this allow get data not more often than after the specified number of seconds. For example:  
WATCH={"enable":true,"json":true,"minPeriod":"2"} sends data every 2 seconds.

### Output
The output same as described for **gpsd**, exept:  

* The DEVICES response of the WATCH command include one device only: the daemon self. So no need to merge data from similar devices -- the daemon do it.
* _sky_ array in POLL object is empty.
* AIS object missing in WATCH response, instead, this object is sent separately.
* Added _ais_ array to POLL object and WATCH response with key = mmsi and value as described [AIS DUMP FORMATS](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_ais_dump_formats) section, except:  

>* Speed in m/sec
>* Location in degrees
>* Angles in degrees
>* Draught in meters
>* Length in meters
>* Beam in meters
>* No 'second' field, but has 'timestamp' as unix time.

### Typical client code
```
webSocket = new WebSocket("ws://"+gpsdProxyHost+":"+gpsdProxyPort);

webSocket.onopen = function(e) {
	console.log("spatialWebSocket open: Connection established");
};

webSocket.onmessage = function(event) {
	let data;

	data = JSON.parse(event.data);

	switch(data.class){
	case 'VERSION':
		console.log('webSocket: Handshaiking with gpsd begin: VERSION recieved. Sending WATCH');
		webSocket.send('?WATCH={"enable":true,"json":true,"subscribe":"TPV,AIS,ALARM","minPeriod":"0"};');
		break;
	case 'DEVICES':
		console.log('webSocket: Handshaiking with gpsd proceed: DEVICES recieved');
		break;
	case 'WATCH':
		console.log('webSocket: Handshaiking with gpsd complit: WATCH recieved.');
		break;
	case 'POLL':
		break;
	case 'TPV':
		realtimeTPVupdate(data);
		break;
	case 'AIS':
		realtimeAISupdate(data);
		break;
	case 'ALARM':
		for(const alarmType in data.alarms){
			switch(alarmType){
			case 'MOB':
				realtimeMOBupdate(data.alarms.MOB);
				break;
			case 'collisions':
				realtimeCollisionsUpdate(data.alarms.collisions);
				break;
			}
		}
		break;
	}
};

webSocket.onclose = function(event) {
	console.log('webSocket closed: connection broken with code '+event.code+' by reason ${event.reason}');
};

webSocket.onerror = function(error) {
	console.log('webSocket error');
};

```

## Support
[Forum](https://github.com/VladimirKalachikhin/Galadriel-map/discussions)

The forum will be more lively if you make a donation at [ЮMoney](https://sobe.ru/na/galadrielmap)

[Paid personal consulting](https://kwork.ru/it-support/20093939/galadrielmap-installation-configuration-and-usage-consulting)  
