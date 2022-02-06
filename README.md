# gpsdPROXY daemon [![License: CC BY-SA 4.0](https://img.shields.io/badge/License-CC%20BY--SA%204.0-lightgrey.svg)](https://creativecommons.org/licenses/by-sa/4.0/)
**version 0.5**

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
Or just use **gpsdPROXY** as websocket proxy to **gpsd**.

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

## Usage
```
$ php gpsdPROXY.php
```
Connect to the daemon on host:port from _params.php_ by **gpsd** protocol via BSD socket or websocket.

## Control
gpsdPROXY daemon checks whether the instance is already running, and exit if it.  
Added some new parameters for commands:

* "subscribe":"TPV|AIS" parameter for ?POLL and ?WATCH={"enable":true,"json":true} commands.  
This indicates to return TPV or AIS data only, not both. For example:  
?POLL={"subscribe":"AIS"} return class "POLL" with "ais":[], not with "tpv":[].
* "minPeriod":"", sec. for WATCH={"enable":true,"json":true} command. Normally the data is sent at the same speed as they come from sensors. Setting this allow get data not more often than after the specified number of seconds. For example:  
WATCH={"enable":true,"json":true,"minPeriod":"2"} sends data every 2 seconds.

## Configure
See _params.php_

## Output
The output same as described for **gpsd**, exept:  

* The DEVICES response of the WATCH command include one device only: the daemon self. So no need to merge data from similar devices -- the daemon do it.
* _sky_ array in POLL object is empty.
* AIS object missing in WATCH response
* Added _ais_ array to POLL object and WATCH response with key = mmsi and value as described [AIS DUMP FORMATS](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_ais_dump_formats) section, except:  

>* Speed in m/sec
>* Location in degrees
>* Angles in degrees
>* Draught in meters
>* Length in meters
>* Beam in meters
>* No 'second' field, but has 'timestamp' as unix time.

