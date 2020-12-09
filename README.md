# Web Service to ConnectStats and Garmin Health API

![Icon](https://github.com/roznet/connectstats/raw/master/ConnectStats/Media.xcassets/ConnectStatsNewAppIcon.appiconset/ConnectStatsNewAppIcon76.png) 
This is an implementation of a server for [ConnectStats](https://github.com/roznet/connectstats) using [php](https://www.php.net) and [mysql](https://www.mysql.com)

This is intended to both provide and API to be registered from [Garmin Health API](https://developer.garmin.com/health-api/overview/) and a server that can query the resulting saved data.

This is used by the app [ConnectStats](https://github.com/roznet/connectstats) and is open source.

If you find an issue with security or ability to extract data not intended for the proper authorised user, please let me know.

## Setup

### Getting the code ready

Checkout the code in your web server directory, and copy/edit `config.sample.php` into `config.php` with the appropriate info, directory configurations and keys

If you want to also include weather data, you can obtain a key from [darkSkyNet](https://darksky.net/dev) and add it to the config file.

You can clone this repository under multiple subdirectory with different configuration, for example, if your webserver base url is {baseurl}, you can have a directory `dev` and `prod` with different databases and keys

### Getting the database ready

You'll need to setup a mysql database and enter the database name, host, user and password in the `config.php` file. When the api is called it will automatically setup the tables it needs.

## Using the api with Garmin Health

Assuming a `{baseurl}` for your server, it will provide implementation for the following end point from the [Garmin Health API](https://developer.garmin.com/health-api/overview/):

| End Point                  | URL                                           | 
|----------------------------|-----------------------------------------------|
| Activities                 | `https://{baseurl}/api/garmin/activities`     |
| Manually Update Activities | `https://{baseurl}/api/garmin/activities`     |
| Activity Files             | `https://{baseurl}/api/garmin/file`           |
| Deregistration             | `https://{baseurl}/api/garmin/deregistration` |

Note that this is assuming your are using apache and using the [`.htaccess`](https://github.com/roznet/connectstats_server/blob/master/.htaccess) file in the distribution that redirect url to the corresponding file with php extension


## Using the api from an app

In order to you the server, your app should run an oauth authentification from garmin as described in the API documentation and call the server via the api point `https:{baseurl}/api/connectstats/user_register`. This will make sure the user information is recorded in the database so the server can process and authenticate call back from the garmin API. There are two major call back to use from then on.

Each entry point will need to be authenticated with the same oauth 1.0 methodology as authenticating callback from the garmin API. The authentication will need to match the keys recorded by the user-register call for the corresponding token_id.

### List of activities 

You can then use the `https:{baseurl}/api/connectstats/search` entry point to obtain list of activities for the user. 

### Fit File

You can use the `https:{baseurl}/api/connectstats/file` entry point to obtain the fit file, if available, for the corresponding activity_id.

# External Dependencies

This project uses [phpFITFileAnalysis.php](https://github.com/adriangibbons/php-fit-file-analysis) for parsing FIT files in php and [phpliteadmin](https://www.phpliteadmin.org) for convenience to look at data in bug reports.
