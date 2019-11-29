#/bin/bash

## SETUP From scratch:
# delete all db
# init a user (will create all tables)

function reset_db {
	${QUERY} -s "${base_url}/api/connectstats/reset"
}

function build_local_from_scratch {
	${QUERY} "${base_url}/api/connectstats/user_register?userAccessToken=testtoken&userAccessTokenSecret=testsecret"
	${QUERY} "${base_url}/api/connectstats/user_register?userAccessToken=testtoken2&userAccessTokenSecret=testsecret2"
	# upload some fit files from the simulator
	cat sample-file-local.json | sed -e "s/1557209607/`date +%s`/" | ${CURL} -H "Content-Type: application/json;charset=utf-8" -d @- "${base_url}/api/garmin/file"
	# upload activities
	cat sample-backfill-activities.json | sed -e "s/1557209607/`date +%s`/" | ${CURL} -H "Content-Type: application/json;charset=utf-8" -d @- "${base_url}/api/garmin/activities"
}


function test_user_deregister {
	# test register/deregister a user
	${CURL} "${base_url}/api/garmin/user_register?userAccessToken=testtoken&userAccessTokenSecret=testsecret"
	${CURL} -H "Content-Type: application/json;charset=utf-8" -d @setup/sample-deregister.json "${base_url}/api/garmin/deregistration"
}

CURL="curl"
QUERY="./query.py -v"
base_url='http://localhost/dev'

reset_db
build_local_from_scratch

# Should be unauthorized
${QUERY} -t=2 "${base_url}/api/connectstats/search?token_id=1"
# Check get back the list and fit file
rm t.json t.fit f.json
echo "waiting a bit for tasks to complete"
sleep 2

${QUERY} -v -t=1 -o=t.json "${base_url}/api/connectstats/search?token_id=1&start=0&limit=50"
${QUERY} -v -t=1 -o=t.fit  "${base_url}/api/connectstats/file?token_id=1&activity_id=1"

${QUERY} -v -t=1 -o=f.json  "${base_url}/api/connectstats/json?token_id=1&limit=50&table=fitsession"


ls -lrt t.fit t.json f.json
php test.php validate
