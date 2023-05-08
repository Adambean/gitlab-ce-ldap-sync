#!/bin/bash

### 
echo "-------------------------------------------------------------"
echo " Executing Cron Tasks: $(date)"
echo "-------------------------------------------------------------"
set -e

WORK_DIR=/app
CONFIG_FILE_DEFAULT=$WORK_DIR/config.yml

if [ -z "$CONFIG_FILE" ]; then
    CONFIG_FILE=$CONFIG_FILE_DEFAULT
fi

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Config file not found, use default config file."
    $CONFIG_FILE=$WORK_DIR/config.yml.dist
fi

if [ ! -f "$CONFIG_FILE_DEFAULT" ]; then
    ln -s $CONFIG_FILE $WORK_DIR/config.yml
fi


if [ -z "$DRY_RUN" ]; then
    DRY_RUN=false
fi

if [ -z "$DEBUG_V" ]; then
    DEBUG_V="-v"
elif [ $DEBUG_V = "NULL" ]; then
    DEBUG_V=""
else
    DEBUG_V=-$DEBUG_V
fi

PHP_SCRIPT=$WORK_DIR/bin/console
if [ $DRY_RUN = true ]; then
    CMD="update-ca-certificates && php $PHP_SCRIPT ldap:sync -d $DEBUG_V"
else
    CMD="update-ca-certificates && php $PHP_SCRIPT ldap:sync $DEBUG_V"
fi

echo "Start to run cron task : $CMD"
eval $CMD
echo "Done"
