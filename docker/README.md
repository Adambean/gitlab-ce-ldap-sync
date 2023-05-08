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
default is "v".


## Example
```yaml
version: "3.7"

services:

    gitlab-ldap-sync:
        build: 
            context: ./ldap-sync/github
            dockerfile: Dockerfile
        image: my/gitlab-ldap-sync
        container_name: gitlab-ldap-sync
        hostname: gitlab-ldap-sync
        privileged: false
        network_mode: host
        volumes:
            - /etc/localtime:/etc/localtime:ro
            - ./ldap-sync/config.yml:/app/config.yml
        environment:
            DRY_RUN: false
            SYNC_INTERVAL_MINUTE: 5
            DEBUG_V: "v"
```

