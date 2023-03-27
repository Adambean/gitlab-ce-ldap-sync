#!/bin/bash
###
 # @Descripttion: 
 # @version: 
 # @Author: Tao Chen
 # @Date: 2023-03-27 22:36:03
 # @LastEditors: Tao Chen
 # @LastEditTime: 2023-03-28 00:01:44
### 

set -x

# Make sure cron daemon is still running
ps -o comm | grep crond || exit 1
