#!/bin/bash
## script to determine how many microJoules consumed by $1 command
## via RAPL counters
# presumes that RAPL counters are readable
# NB on MMU systems you can enable access to RAPL counters:
#   sudo expose-intel-rapl
# which may work only in certain MMU labs

# set variable for file name of RAPL counter
# NB location of RAPL counters may be system specific
ENERGYFILE=/sys/class/powercap/intel-rapl\:0/energy_uj

# save initial RAPL counter value
start=`cat $ENERGYFILE`

# run command $1 and all its args
$@

# save final RAPL counter value
finish=`cat $ENERGYFILE`

# calc energy consumed
let uj=finish-start
echo Energy consumed\: ${uj} microJoules

