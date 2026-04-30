#!/bin/bash

## script to poll given GPU card (presume only 1 in system) whilst running args to this script
## mkbane (Dec2023)

CMD="nvidia-smi --query-gpu=timestamp,power.draw" 
INTERVAL_MS=10

# if OUTFILE not set already then use a default value based upon code to be run
OUTFILE=${OUTFILE:=${1}_gpuInfo.csv}

echo We will use
echo $CMD
echo to poll GPU every $INTERVAL_MS milliseconds whilst running\:
echo $@
echo GPU data is saved to ${OUTFILE} \(NB this is overwritten for each run\)

# run script in background (via use of "&")
${CMD} --format=csv,nounits --loop-ms=${INTERVAL_MS} > ${OUTFILE} 2>&1 &
BG=$! # job number

# run actual code to measure
echo '---'
$@
echo '---'

# kill background job once actual code has finished
kill $BG

echo All finished\!

