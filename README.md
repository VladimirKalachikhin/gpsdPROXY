# gpsdPROXY daemon [![License: CC BY-SA 4.0](https://img.shields.io/badge/License-CC%20BY--SA%204.0-lightgrey.svg)](https://creativecommons.org/licenses/by-sa/4.0/)
**version 0.1**

It is very convenient to access the **[gpsd](https://gpsd.io/)** from web apps with asynchronous request [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll) But there are problems:  
>**First**, the AIS data not available by ?POLL; request.  
>**Second**, the data other them time-position-velocity (from GNSS reciever, in general) may not be included to ?POLL; request.

The reason is that **gpsd** collect data during "epoch" from one GNSS fix recive to another. But "epoch" for AIS and instruments data is longer. So this data is not available for the ?POLL; request that returns the data collected during **gpsd** epoch in request moment.  
Details and discussion see:  
[https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00093.html](https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00093.html)  
[https://lists.nongnu.org/archive/html/gpsd-users/2021-06/msg00017.html](https://lists.nongnu.org/archive/html/gpsd-users/2021-06/msg00017.html)  

This cache/proxy daemon collect AIS and TPV data from **gpsd** during the user-defined lifetime and gives them by [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll) request of the **gpsd** protocol.  
So data from AIS stream and instruments such as echosounder and wind meter become available via ?POLL; request.

## Usage
```
$ php gpsdPROXY.php
```

## Control
gpsdPROXY daemon checks whether the instance is already running, and exit if it. 

## Configure
See _params.php_

## Output
The output same as described for **gpsd** [?POLL;](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_poll) request, exept:  

* _sky_ array is empty
* time are UNIX timestamp
* added _ais_ array with key = mmsi and value as described [AIS DUMP FORMATS](https://gpsd.gitlab.io/gpsd/gpsd_json.html#_ais_dump_formats) section, except:  

>* Speed in m/sec
>* Location in degrees
>* Angles in degrees
>* Draught in meters
>* Length in meters
>* Beam in meters
>* time are UNIX timestamp
