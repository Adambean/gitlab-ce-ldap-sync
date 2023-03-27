## how to use docker

### Volume
    - /etc/localtime:/etc/localtime:ro
    - ./config.yml:/app/config.yml
you can mount config.yml at /app/config.yml as default. If you mount at different location, you shoulf
set the CONFIG_FILE as your file location

### Enviriment

#### SYNC_INTERVAL_DAY
default is 0;

#### SYNC_INTERVAL_HOUR
default is 0;

#### SYNC_INTERVAL_MINUTE
default is 5;

#### CONFIG_FILE
where is the config.yml. default is /app/config.yml

#### DRY_RUN
default is false. If you set as true, this docker don't sysn really.

#### DEBUG_V
default is "v". if set as "NULL", there are no output


