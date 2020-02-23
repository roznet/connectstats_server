#!/bin/zsh

# this script is intended to be run regularly to clean up files and do maintenance
cd ../api/connectstats
php migrate_assets.php 250 > log/migrate.log


