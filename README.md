# connectstats_server

Implementation of a server for [ConnectStats](https://github.com/roznet/connectstats).

This is intended to both provide and API to be registered from Garmin Health API and a server that can query the resulting saved data.

This is used by the app ConnectStats and is open source.

If you find an issue with security or ability to extract data not intended for the proper authorised user, please let me know.

## Setup

Checkout the code in your web server directory, and copy/edit config.sample.php into config.php with the appropriate info

Create a tmp directory in api/garmin with permission for web user (to save tmp log files):

```
mkdir api/garmin/tmp
chmod ugo+wc api/garmin/tmp
```
