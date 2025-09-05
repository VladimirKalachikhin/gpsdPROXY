[Русское описание](README.ru-RU.md)  
# gpsdPROXY daemon [![License: CC BY-NC-SA 4.0](screenshots/Cc-by-nc-sa_icon.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/deed.en)
**version 1**

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
However, currently the **gpsdPROXY** is actually a back-end for [GaladrielMap](https://github.com/VladimirKalachikhin/Galadriel-map/tree/master). As such, it has many features poor described in the documentation.

This code is written without the use of AI, "best practices," OOP and IDE.

## Features
This cache/proxy daemon collect AIS and all TPV data from **gpsd** or other source during the user-defined lifetime and gives them by [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll) request of the **gpsd** protocol.  
So data from AIS stream and instruments such as echosounder and wind meter become available via ?POLL; request.  
In addition, you may use ?WATCH={"enable":true,"json":true} stream, just like from original **gpsd**.   

Also it is a data multiplexer, collecting various data from various sources to provide them to clients in unify interface.

You can specify multiple addresses and ports to connect to, for example, in ipv4 and ipv6 networks.

### Data source
Normally, the gpspPROXY works with **gpsd** on the same or the other machine. In this case, the data is the most complete and reliable.  

#### VenusOS
The **gpsdPROXY** can work in VenusOS v2.80~38 or above. Or get data from any version via LAN. To do this, you need to enable "MQTT on LAN" feature. On VenusOS remote console go Settings -> Services -> MQTT on LAN (SSL) and Enable.

##### limitations
* VenusOS does not provide depth and AIS services.
* The data provided by VenusOS are not reliable enough, so be careful.

#### Signal K
The **gpsdPROXY** can get data from Signal K local or via LAN. If it possible, **gpsdPROXY** find Signal K by yourself via zeroconf service or jast on standard port.

##### Limitations
Indeed, SignalK can be used from **gpsdPROXY** only local. Via LAN it's odd.

### Collision detections
The **gpsdPROXY** tries to determine the possibility of a collision according to the adopted simplified collision model based on the specified detection distance and the probability of deviations from the course.  
![collision model](screenshots/s1.jpeg)<br>  
Output collisions data contains a list of mmsi and position of vessels that have a risk of collision. The [GaladrielMap](https://github.com/VladimirKalachikhin/Galadriel-map) highlights such vessels on the map and indicates the direction to them on self cursor.  
For the Collision detector to work correctly, you must specify the boat parameters in _params.php_.

### MOB info
The **gpsdPROXY** supports the exchange of "man overboard" information between connected clients. Output MOB data contains a GeoJSON-like object with MOB points and lines.  
In addition, there is a support for AIS Search and Rescue Transmitter (SART) messages AIS-MOB and AIS-EPIRB as a local MOB alarm. Besides, the [netAIS](https://github.com/VladimirKalachikhin/netAIS) alarm and MOB messages also supported.

### Following the route
In response to the command `?WPT={"action":"start","wayFileName":"fileName.gpx"};` the **gpsdPROXY** loads the file *fileName.gpx* and searches for a \<rte\> object with the text "current" in the \<cmt\> field. If this, the **gpsdPROXY** finds the \<wpt\> closest to the current position in this \<rte\>,  and makes it the current waypoint.  
The **gpsdPROXY** makes sure that the current position is no further than the specified distance from the waypoint and, when it is reached, determines the next waypoint.  
The result is given to clients subscribed to the "WPT" messages as object {"class" : "WPT"}.  
You can cancel following with the command `?WPT={"action":"cancel"};`  
Control: `?WPT={"action":"nextWPT"};`, `?WPT={"action":"prevWPT"};`  
If the file *fileName.gpx* does not contain a \<rte\> object with the text "current" in the \<cmt\> field, then will take the \<wpt\>'s, starting from the one marked as "current" if it is. If there are no \<wpt\>'s, the last \<rte\> will be used.  
If the file "fileName.gpx" is changed, it is reloaded and following continues from the nearest point.


## Compatibility
Linux, PHP\<8. The cretinous decisions made at PHP 8 do not allow the **gpsdPROXY** to work at PHP 8, and I do not want to follow these decisions.

## Install
Just copy files to any dir and configure.

## Configure
See _params.php_  

### Authorisation
A simple authorization system is designed to divide users into those who have access to all features and those whose possibilities are limited.  
The limitations are that there is no access to the next commands:  

* `CONNECT`
* `UPDATE`
* `WPT`

You can specify a list of addresses or/and subnets from which full access is allowed (white list) or, conversely, a list of addresses and subnets from which full access is prohibited (black list). See `params.php` for details.  

## Usage
```
$ php gpsdPROXY.php
```
Connect to the daemon on host:port from _params.php_ by **gpsd** protocol via BSD socket or websocket.

### Control
**gpsdPROXY** daemon checks whether the instance is already running, and exit if it.  

### gpsd Protocol extensions
Added some new parameters for commands:

* "subscribe":"TPV[,AIS[,ALARM,[WPT]]]" parameter for ?POLL and ?WATCH={"enable":true,"json":true} commands.  
This indicates to return TPV or AIS or ALARM data only, or a combination of them. Default - all.  
For example: `?POLL={"subscribe":"AIS"}` return class "POLL" with "ais":[], not with "tpv":[].
* "minPeriod":"", sec. for WATCH={"enable":true,"json":true} command. Normally the data is sent at the same speed as they come from sensors. Setting this allow get data not more often than after the specified number of seconds. For example:  
WATCH={"enable":true,"json":true,"minPeriod":"2"} sends data every 2 seconds.

New commands:

* `?CONNECT={"host":"","port":""};` Requires you to connect to the specified address as to **gpsd**.
* `?UPDATE={"updates":""};` Getting data in **gpsd** format.
* `?WPT={"action":"start","wayFileName":"fileName.gpx"};` WPT control.
* `?WPT={"action":"cancel"};`
* `?WPT={"action":"nextWPT"};`
* `?WPT={"action":"prevWPT"};`


### Output
The output same as described for **gpsd**, exept:  

* The DEVICES response of the WATCH command include one device only: the daemon self. So no need to merge data from similar devices -- the daemon do it.
* _sky_ array in POLL object is empty.
* The AIS object does not contain _scaled_ and _device_ fields, it contains the _ais_ array only: `ais:{mmsi:{}}` 
with value as described in [AIS DUMP FORMATS](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_ais_dump_formats) section, except:  

* All data (include AIS) in SI units:
>* Speed in m/sec
>* Location in degrees
>* Angles in degrees
>* Draught in meters
>* Length in meters
>* Beam in meters
* undefined values is __null__
* No 'second' field, but has 'timestamp' as unix time.
* The 'depth' value from the TPV class data is also present in the ATT class data
* The 'temp' value from the TPV class data is also present in the ATT class data
* The all wind values from the TPV class data is also present in the ATT class data
* The 'wtemp' value from the TPV class data is also present in the ATT class data
>In the future, all these values will remain only in the data of the ATT class.
* The AIS class contain only:
```
{"class":"AIS",
"ais":{
	"vessel_mmsi":{
		...
		vessel data
		...
```
* Added _ALARM_ array to MOB and collisions.

### Typical client code
```
let webSocket = new WebSocket("ws://"+gpsdProxyHost+":"+gpsdProxyPort);

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
	case 'TPV':
		console.log('webSocket: recieved TPV.');
		break;
	case 'ATT':
		console.log('webSocket: recieved ATT.');
		break;
	case 'AIS':
		console.log('webSocket: recieved AIS.');
		break;
	case 'ALARM':
		for(const alarmType in data.alarms){
			switch(alarmType){
			case 'MOB':
				console.log('webSocket: recieved MOB alarm.');
				break;
			case 'collisions':
				console.log('webSocket: recieved collision alarm.');
				break;
			};
		};
		break;
	};
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
