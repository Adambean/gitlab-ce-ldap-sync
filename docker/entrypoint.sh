#!/bin/bash

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

CRON_FILE=/var/spool/cron/crontabs/root
# if [ -f "$CRON_FILE" ]; then
#     rm -rf $CRON_FILE
# fi

CRON_TASK_CMD="$MINUTE_SYMBOL $HOUR_SYMBOL $DAY_SYMBOL * * /cron_task.sh"

echo "-------------------------------------------------------------"
echo " Start at : $(date)"
echo "-------------------------------------------------------------"
echo "manual excute: /cron_task.sh"
bash /cron_task.sh
echo "Done"
echo "-------------------------------------------------------------"

echo "Cron task: $CRON_TASK_CMD"
echo "$CRON_TASK_CMD" > $CRON_FILE

echo "Starting crond"
exec crond -f -l 0