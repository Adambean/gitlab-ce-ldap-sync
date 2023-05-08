#!/bin/bash


set -x

# Make sure cron daemon is still running
ps -o comm | grep crond || exit 1
