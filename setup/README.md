# Testing 

This contains information how to do a serie of tests for a server setup

## Testing a server without any call back to garmin

You can do a basic test of the code by creating an empty database where you create and empty table called `dev`, updating the config.php script accordingly, and running the script `test.sh`

This should create all the tables and test the activities and file API entry point from Garmin Health API

## Testing interaction with Garmin Health API

This should be run on a dev setup with a dev key and a dev mysql database.

You should use an application that will let the user login to Garmin Health API and obtain a userAccessToken and Secret and update it into the tokens database at least once.

1. Create a new uat table that is a copy from the tokens database, you can use

```
CREATE TABLE uat SELECT * FROM tokens;
```

2. 
