#!/bin/bash

## script to remove commas and headers from GPU data file
FILE=${1}
OUT=${1%.csv}.dat
awk 'NR>1' ${FILE}| sed 's/,//'  > $OUT
