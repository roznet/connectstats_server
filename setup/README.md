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

## Testing rerunning from another database/server

This is a test to make sure rerunning the feed from garmin will work on a new code/new database.
It will bring back the callback from garmin and try to rerun them locally.
It will also switcht the callback url from garmin to get the file from the source backp server

1. in the setup directory, run `php backup_cache.php`, this will sync the tokens and users, as well as the ping and push garmin sent. This will create a backup from `url_backup_source` from config.php into the database pointed by `database` in config.php.
2. in the setup directory, run `php backup_tasks.php -t=XX NN` where `XX` is a token_id and `NN` is a number of activities you want to sync up from the user
3. Alternatively run one cache process with `php runactivities CC` or `php runfitfiles CC` where `CC` is the cache id to re-run from the correct table

# Stats/Status queries

## Number of queries by users

```
SELECT num AS total_queries, COUNT(cs_user_id) AS user_count FROM ( SELECT cs_user_id, count(*) as num FROM `usage` WHERE  ts > NOW() - INTERVAL 1 DAY GROUP BY cs_user_id ORDER BY NUM DESC ) AS table1 GROUP BY num
```

## Number of users by day

```
SELECT `date`, COUNT(cs_user_id) AS user_count FROM ( SELECT DATE(ts) as `date`,cs_user_id, count(*) as num FROM garmin_new.`usage` WHERE  ts > NOW() - INTERVAL 5 DAY GROUP BY cs_user_id, `date` ORDER BY NUM DESC ) AS table1 GROUP BY `date`;
```

## Number of pushed activities by day in the last week

```
SELECT count(*),DATE(ts) as `date` FROM cache_activities WHERE ts > NOW() - INTERVAL 1 WEEK GROUP BY DATE(ts);
SELECT count(*),DATE(ts) as `date` FROM cache_fitfiles WHERE ts > NOW() - INTERVAL 1 WEEK GROUP BY DATE(ts);
```

## Number of tasks by day in the last week and max time to execute

```
SELECT DATE(ts) as `date`, count(*), MAX( time(finished_ts-started_ts) ), MAX( time(finished_ts-created_ts)) FROM tasks WHERE ts > NOW() - INTERVAL 1 WEEK GROUP BY `date`;
```

## task that took more than 30 seconds in the last week

```
SELECT task_id, queue_id, started_ts, finished_ts, timediff(finished_ts,started_ts) AS exec_time, task_command FROM tasks WHERE ts > NOW() - INTERVAL 1 WEEK AND SECOND(TIMEDIFF(finished_ts,started_ts) )> 30 ORDER BY exec_time DESC;
```

## number of use and time since first use by users

```
SELECT cs_user_id, MAX(ts) AS `last`, MIN(ts) AS `first`, TIMEDIFF(MAX(ts),MIN(ts)) AS `days`, COUNT(*) AS total FROM `usage` GROUP BY cs_user_id ORDER BY last DESC;
```

# Usage Summary

```
CREATE TABLE usage_summary (usage_summary_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY, cs_user_id BIGINT(20) UNSIGNED, `day` DATE, `count` BIGINT(20) UNSIGNED, max_ts TIMESTAMP, min_ts TIMESTAMP);
CREATE INDEX usage_summary_day ON usage_summary (`day`);
CREATE INDEX usage_summary_cs_user_id ON usage_summary (`cs_user_id`);
INSERT INTO usage_summary (`day`,cs_user_id,`count`,`max_ts`,`min_ts`) SELECT date(ts) AS `day`,cs_user_id,COUNT(*) AS `count`,MAX(ts) AS `max_ts`,MIN(ts) AS `min_ts` FROM `usage` WHERE date(ts) < SUBDATE(CURDATE(),3) GROUP BY date(ts),cs_user_id ORDER BY `day`,cs_user_id;

```


# Indexes and queries use beside primary index

## Indexes

```
CREATE INDEX assets_file_id_index ON assets (file_id);

CREATE INDEX fitfiles_startTimeInSeconds_index ON fitfiles (startTimeInSeconds);
CREATE INDEX fitfiles_summaryId_index ON fitfiles (summaryId);

CREATE INDEX activities_startTimeInSeconds_index ON activities (startTimeInSeconds);
CREATE INDEX activities_cs_user_id ON activities (cs_user_id);

CREATE INDEX tokens_userAccess ON tokens (userAccessToken);
CREATE INDEX activities_summaryId ON activities ( summaryId );

CREATE INDEX usage_ts_index ON `usage` (ts);
```

## user_info, authenticate_header()

```
SELECT * FROM tokens WHERE userAccessToken = '%s'
SELECT userAccessTokenSecret,token_id FROM tokens WHERE userAccessToken = '$userAccessToken'
```

## find file_id or activity_id from summaryId in `process()` 


```
SELECT file_id FROM `fitfiles` WHERE summaryId = %s
```

## Find asset_id for fitfile in file_callback_one

```
SELECT asset_id FROM assets WHERE file_id=%s AND tablename='%s'
```

## Maintenance after process

```
SELECT activity_id,json,parent_activity_id FROM activities WHERE startTimeInSeconds >= %d AND startTimeInSeconds <= %d AND userAccessToken = '%s'
SELECT activity_id FROM activities WHERE summaryId = '%s'
UPDATE activities SET parent_activity_id = %d WHERE summaryId = %d
SELECT userId FROM users WHERE userId = '$userId'
```

Link fitfiles and activities

```
SELECT * FROM `fitfiles` WHERE userId = '%s' AND startTimeInSeconds = %d
SELECT * FROM `activities` WHERE userId = '%s' AND startTimeInSeconds = %d
```

## query_file



## query_list

