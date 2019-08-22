# Testing 

This contains information how to do a serie of tests for a server setup

## Testing a server without any call back to garmin

You can do a basic test of the code by creating an empty database where you create and empty table called `dev`, updating the config.php script accordingly, and running the script `test.sh`

This should create all the tables and test the activities and file API entry point from Garmin Health API
