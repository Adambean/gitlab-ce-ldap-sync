#/bin/ash
###
 # @Descripttion: 
 # @version: 
 # @Author: Tao Chen
 # @Date: 2023-03-27 18:11:58
 # @LastEditors: Tao Chen
 # @LastEditTime: 2023-03-27 22:59:20
### 
# update-ca-certificates
set -e

if [ -z "$WORK_DIR" ]; then
    WORK_DIR=/app
fi

if [ -z "$CONFIG_FILE" ]; then
    CONFIG_FILE=$WORK_DIR/config.yml
else
    ln -s $WORK_DIR/config.yml /app/config.yml
fi

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
