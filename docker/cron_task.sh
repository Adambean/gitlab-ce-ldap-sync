#/bin/ash
###
 # @Descripttion: 
 # @version: 
 # @Author: Tao Chen
 # @Date: 2023-03-27 18:11:58
 # @LastEditors: Tao Chen
 # @LastEditTime: 2023-03-27 23:23:53
### 
# update-ca-certificates
set -e

WORK_DIR=/app

if [ -z "$CONFIG_FILE" ]; then
    CONFIG_FILE=$WORK_DIR/config.yml
fi
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Config file not found, use default config file."
    $CONFIG_FILE=$WORK_DIR/config.yml.dist
fi

ln -s $CONFIG_FILE $WORK_DIR/config.yml

if [ -z "$DRY_RUN" ]; then
    DRY_RUN=false
fi

if [ -z "DEBUG_V" ]; then
    DEBUG_V="v"
fi

PHP_SCRIPT=$WORK_DIR/bin/console
if [ $DRY_RUN = true ]; then
    $CMD="update-ca-certificates && php $PHP_SCRIPT ldap:sync -d -$DEBUG_V"
else
    $CMD="update-ca-certificates && php $PHP_SCRIPT ldap:sync -$DEBUG_V"
fi

echo "================================"
echo "Start to run cron task : $CMD"
eval $CMD
echo "End"
echo "================================"
