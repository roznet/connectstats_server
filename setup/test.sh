#/bin/bash

## SETUP From scratch:
# delete all db
# init a user (will create all tables)

function build_local_from_scratch {
	curl -v "${base_url}/api/connectstats/user_register?userAccessToken=testtoken&userAccessTokenSecret=testsecret"
	# upload some fit files from the simulator
	curl -v -H "Content-Type: application/json;charset=utf-8" -d @sample-file-local.json "${base_url}/api/garmin/file"
	# upload activities
	curl -v -H "Content-Type: application/json;charset=utf-8" -d @sample-backfill-activities.json "${base_url}/api/garmin/activities"
}



function test_user_deregister {
	# test register/deregister a user
	curl -v "${base_url}/api/garmin/user_register?userAccessToken=testtoken&userAccessTokenSecret=testsecret"
	curl -v -H "Content-Type: application/json;charset=utf-8" -d @setup/sample-deregister.json "${base_url}/api/garmin/deregistration"
}

function reset_db {
	curl -v "${base_url}/api/connectstats/reset"
}

function signed_curl {
	echo $1 $2
	auth=`(cd ../garmin;php sign.php $1 "$2")`
	echo $auth
	curl -v -H "Authorization: $auth" "$2"
}

base_url='http://localhost/dev'

reset_db
build_local_from_scratch
# check we can recover the list
signed_curl 1 'http://localhost/dev/api/connectstats/search?token_id=1'
