#!/bin/bash

# this script is intended to be run regularly to clean up files and do maintenance

if [ -d ../api/garmin/tmp ]; then
	echo "Deny from all" > ../api/garmin/tmp/.htaccess
	/bin/find ../api/garmin/tmp -mtime +15 -delete
fi


