#/bin/ash
###
 # @Descripttion: 
 # @version: 
 # @Author: Tao Chen
 # @Date: 2023-03-27 18:11:58
 # @LastEditors: Tao Chen
 # @LastEditTime: 2023-03-27 23:18:07
### 
###
 # @Descripttion: 
 # @version: 
 # @Author: Tao Chen
 # @Date: 2023-03-27 18:11:58
 # @LastEditors: Tao Chen
 # @LastEditTime: 2023-03-27 22:59:26
### 

if [ -z "$SYNC_INTERVAL_DAY" ]; then
    SYNC_INTERVAL_DAY=0
fi

if [ -z "$SYNC_INTERVAL_HOUR" ]; then
    SYNC_INTERVAL_HOUR=0
fi

if [ -z "$SYNC_INTERVAL_MINUTE" ]; then
    SYNC_INTERVAL_MINUTE=5
fi

if [ $SYNC_INTERVAL_DAY -gt 0 ]; then
    DAY_SYMBOL="*/$SYNC_INTERVAL_DAY"
else
    DAY_SYMBOL="*"
fi

if [ $SYNC_INTERVAL_HOUR -gt 0 ]; then
    HOUR_SYMBOL="*/$SYNC_INTERVAL_HOUR"
else
    HOUR_SYMBOL="*"
fi

if [ $SYNC_INTERVAL_MINUTE -gt 0 ]; then
    MINUTE_SYMBOL="*/$SYNC_INTERVAL_MINUTE"
else
    MINUTE_SYMBOL="*"
fi

echo "$MINUTE_SYMBOL $SYNC_INTERVAL_HOUR $SYNC_INTERVAL_DAY * * /cron-task.sh" > /var/spool/cron/crontabs/root

echo "Starting crond"
exec crond -f -l 0