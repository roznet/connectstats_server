#!/bin/zsh

# this script is intended to be run regularly to clean up files and do maintenance

function cleanup {
		target=${1}
		
		if [ -d ${target} ]; then
				echo "Cleaning tmp directory ${target}"
				echo "  files: `/usr/bin/find ${target} | wc -l`"
				echo "  size:  `/usr/bin/du -h ${target}`"
				echo "Deny from all" > ../api/garmin/tmp/.htaccess
				/usr/bin/find ${target} -mtime +7 -delete
				echo "After cleanup ${target}"
				echo "  files: `/usr/bin/find ${target} | wc -l`"
				echo "  size:  `/usr/bin/du -h ${target}`"
				echo
		fi
}
cleanup ../api/garmin/tmp
cleanup ../api/garmin/log



